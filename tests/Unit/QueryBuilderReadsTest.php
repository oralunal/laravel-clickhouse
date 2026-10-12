<?php

declare(strict_types=1);

namespace Tests\Unit;

use BadMethodCallException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\LiteralGrammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\TestCase;

/**
 * The reads and the argument checks of the package's QueryBuilder that differ from Laravel's: exists(), the
 * aggregates and where exists of INTERSECT and EXCEPT, pluck() of a joined column, getStatement(), the prewhere
 * forms of the where methods, and the refusals of delete(true) and of an empty row.
 */
class QueryBuilderReadsTest extends TestCase
{
    /**
     * The selects that the stub connection was given, each with its bindings.
     *
     * @var list<array{string, list<mixed>}>
     */
    private array $selects = [];

    /**
     * A query of the package's QueryBuilder on a stub connection that records each select and answers with the
     * given rows; selectStatement() answers with a stub statement and records its SQL too.
     *
     * @param list<array<string, mixed>> $rows
     * @return QueryBuilder
     */
    private function query(array $rows = []): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newLiteralGrammar')->willReturnCallback(fn (): LiteralGrammar => new LiteralGrammar());
        $connection->method('getInsertFormat')->willReturn(Format::Values);
        $connection->method('select')->willReturnCallback(function (string $sql, array $bindings = []) use ($rows): array {
            $this->selects[] = [$sql, $bindings];

            return $rows;
        });
        $connection->method('selectStatement')->willReturnCallback(function (string $sql, array $bindings = []): Statement {
            $this->selects[] = [$sql, $bindings];

            return $this->createStub(Statement::class);
        });

        return new QueryBuilder($connection, new QueryGrammar($connection), new QueryProcessor());
    }

    /**
     * @param string $values
     * @return QueryBuilder
     */
    private function pairs(string $values): QueryBuilder
    {
        return $this->query()->select('g', 'n')->fromRaw("values('g String, n UInt8', {$values})");
    }

    /**
     * ClickHouse compares fewer columns of INTERSECT and EXCEPT when the outer query reads none of them, so a union
     * that holds them is selected from with where not ignore(*), and a query that reads them in a sub-query gets
     * the condition itself.
     */
    public function test_exists_reads_every_column_of_intersect_and_except(): void
    {
        $except = fn (): QueryBuilder => $this->pairs("('a', 1)")->except($this->pairs("('a', 2)"));

        $this->query([['exists' => 1]])->from('t')->exists();
        $except()->exists();
        $this->query()->fromSub($except(), 's')->exists();

        $this->assertSame([
            'select exists(select * from "t") as "exists"',
            'select exists(select * from ((select "g", "n" from values(\'g String, n UInt8\', (\'a\', 1))) except (select "g", "n"'
                . ' from values(\'g String, n UInt8\', (\'a\', 2)))) as "exists_table" where not ignore(*)) as "exists"',
            'select exists(select * from ((select "g", "n" from values(\'g String, n UInt8\', (\'a\', 1))) except (select "g", "n"'
                . ' from values(\'g String, n UInt8\', (\'a\', 2)))) as "s" where not ignore(*)) as "exists"',
        ], array_column($this->selects, 0));
    }

    /**
     * ClickHouse 26.3 and 26.8 answered exists of a query that selects arrayJoin() of an empty array with 1, so such a
     * query is selected from.
     */
    public function test_exists_selects_from_a_query_that_calls_array_join(): void
    {
        $this->query()->from('t')->selectRaw('arrayJoin(tags) as tag')->where('id', 2)->exists();
        $this->query()->from('t')->whereExists(fn (QueryBuilder $sub) => $sub->from('u')->withAlias('tag', new Expression('arrayJoin(tags)'))->select('tag'))->get();

        $this->assertSame(
            'select exists(select * from (select arrayJoin(tags) as tag from "t" where "id" = ?) as "exists_table" where not ignore(*)) as "exists"',
            $this->selects[0][0]
        );
        $this->assertSame(
            'select * from "t" where exists (select * from (with arrayJoin(tags) as "tag" select "tag" from "u") as "exists_table" where not ignore(*))',
            $this->selects[1][0]
        );
    }

    /**
     * Without the analyzer, ClickHouse names the column of exists() over a with expression or a join in(1, ...).
     */
    public function test_exists_reads_the_only_column_whatever_its_name(): void
    {
        $this->assertTrue($this->query([['in(1, _subquery7)' => 1]])->from('t')->exists());
        $this->assertFalse($this->query([['in(1, _subquery7)' => 0]])->from('t')->exists());
        $this->assertFalse($this->query()->from('t')->exists());
    }

    public function test_an_aggregate_reads_every_column_of_intersect_and_except(): void
    {
        $this->query([['aggregate' => '2']])->fromSub($this->pairs("('a', 1)")->intersect($this->pairs("('a', 1)")), 's')->count();
        $this->query([['aggregate' => '2']])->select('n')->fromRaw("values('n UInt8', 1)")->unionAll($this->pairs("('a', 1)")->except($this->pairs("('b', 1)")))->sum('n');

        $this->assertStringEndsWith(' as "s" where not ignore(*)', $this->selects[0][0]);
        $this->assertStringStartsWith('select count(*) as "aggregate" from ((select "g", "n"', $this->selects[0][0]);
        $this->assertStringStartsWith('select sum("n") as "aggregate" from (', $this->selects[1][0]);
        $this->assertStringEndsWith(') as "temp_table" where not ignore(*)', $this->selects[1][0]);
    }

    public function test_where_exists_reads_every_column_of_intersect_and_except(): void
    {
        $this->query()->from('t')->whereExists(fn (QueryBuilder $sub) => $sub->fromSub($this->pairs("('a', 1)")->except($this->pairs("('a', 2)")), 's'))->get();
        $this->query()->withExpression('w', $this->pairs("('a', 1)")->except($this->pairs("('a', 2)")))->from('t')
            ->whereNotExists(fn (QueryBuilder $sub) => $sub->from('w'))->get();
        $this->query()->from('t')->whereExists(fn (QueryBuilder $sub) => $sub->from('u'))->get();

        $this->assertStringEndsWith(' as "s" where not ignore(*))', $this->selects[0][0]);
        $this->assertStringEndsWith('select * from "t" where not exists (select * from "w" where not ignore(*))', $this->selects[1][0]);
        $this->assertSame('select * from "t" where exists (select * from "u")', $this->selects[2][0]);
    }

    /**
     * Laravel drops the order of an aggregated query, which in a union belongs to its first query and decides, with
     * its limit, which rows that query returns.
     */
    public function test_an_aggregate_of_a_union_keeps_the_order_of_the_first_query(): void
    {
        $this->query([['aggregate' => 22]])->from('a')->select('x')->orderBy('x')->limit(1)
            ->unionAll($this->query()->from('b')->select('x'))
            ->sum('x');
        $this->query([['aggregate' => 3]])->from('a')->orderBy('x')->max('x');

        $this->assertSame([
            'select sum("x") as "aggregate" from ((select "x" from "a" order by "x" asc limit 1) union all (select "x" from "b")) as "temp_table"',
            'select max("x") as "aggregate" from "a"',
        ], array_column($this->selects, 0));
    }

    /**
     * ClickHouse names a column of a joined table with its table, and a column of the query's own table without it.
     */
    public function test_pluck_reads_a_column_by_the_name_that_clickhouse_gives_it(): void
    {
        $rows = [['id' => 2, 's' => 'y', 'u.s' => 'q'], ['id' => 3, 's' => 'z', 'u.s' => 'r']];

        $this->assertSame(['q', 'r'], $this->query($rows)->from('t')->select('t.id', 't.s', 'u.s')->pluck('u.s')->all());
        $this->assertSame([2 => 'y', 3 => 'z'], $this->query($rows)->from('t')->select('t.id', 't.s', 'u.s')->pluck('t.s', 't.id')->all());
        $this->assertSame([2, 3], $this->query([['x' => 2], ['x' => 3]])->from('t')->pluck('id as x')->all());
    }

    public function test_get_statement_names_the_format_after_the_sql(): void
    {
        $this->query()->from('t')->where('a', 1)->getStatement();
        $this->query()->from('t')->getStatement(Format::Csv);
        $this->query()->from('t')->getStatement('jsoneachrow');
        $this->query()->from('t')->settings('max_threads', 1)->getStatement('TSVRaw');

        $this->assertSame([
            ['select * from "t" where "a" = ?', [1]],
            ['select * from "t" format CSV', []],
            ['select * from "t" format JSONEachRow', []],
            ['select * from "t" settings max_threads=1 format TSVRaw', []],
        ], $this->selects);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse format name [CSV; DROP TABLE t].');

        $this->query()->from('t')->getStatement('CSV; DROP TABLE t');
    }

    public function test_each_where_method_has_a_prewhere_form(): void
    {
        $query = $this->query()->from('t')
            ->preWhereColumn('a', '<', 'b')
            ->orPreWhereDate('d', '2024-01-02')
            ->preWhereAny(['a', 'b'], 5)
            ->where('c', 1);

        $this->assertSame(
            'select * from "t" prewhere "a" < "b" or toDate32("d") = ? and ("a" = ? or "b" = ?) where "c" = ?',
            $query->toSql()
        );
        $this->assertSame(['2024-01-02', 5, 5, 1], $query->getBindings());

        $this->expectException(BadMethodCallException::class);
        $this->query()->preWhereNoSuchMethod('a');
    }

    public function test_delete_refuses_a_bool_as_the_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('delete() takes the id of a row as its first argument, a bool given. Pass the option by name: delete(lightweight: true).');

        $this->query()->from('t')->where('a', 1)->delete(true);
    }

    public function test_an_update_without_values_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot update the rows without values: the update has no column to set. Nothing was sent.');

        $this->query()->from('t')->where('a', 1)->update([]);
    }

    public function test_an_empty_row_is_refused_in_both_formats_before_anything_is_sent(): void
    {
        foreach ([Format::Values, Format::JsonEachRow] as $format) {
            try {
                $this->query()->from('t')->insert([['a' => 1], []], $format);
                $this->fail("{$format->value}: insert() did not throw.");
            } catch (ClientQueryException $exception) {
                $this->assertSame('Inserting empty values array is not supported in ClickHouse', $exception->getMessage());
            }
        }

        $this->assertTrue($this->query()->from('t')->insert([]));
    }
}
