<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;

/**
 * Laravel's "?" placeholders on a ClickHouse connection: the grammar writes "?", and the connection writes the
 * bindings into the SQL with QueryGrammar::prepareQueryForClient() before the query is sent. toSql() and the query
 * log show "?", and toRawSql() the values, as ClickHouse literals.
 */
class QueryGrammarBindingsTest extends TestCase
{
    /**
     * A query builder of the package on a stub ClickHouse connection, whose builder grammar writes dates at the
     * given precision and whose prepareBindings() hands the bindings on as they are.
     *
     * @param string $precision
     * @return QueryBuilder
     */
    private function query(string $precision = DateTimePrecision::SECOND): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(
            fn (): Grammar => (new Grammar())->setDateTimePrecision($precision)
        );
        $connection->method('prepareBindings')->willReturnArgument(0);
        $grammar = new QueryGrammar($connection);
        $connection->method('getQueryGrammar')->willReturn($grammar);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from('t1');
    }

    /**
     * Get the SQL that the connection sends for a compiled query and its bindings, which the client then gets
     * without bindings.
     *
     * @param QueryGrammar $grammar
     * @param string $sql
     * @param array<int, mixed> $bindings
     * @return string
     */
    private function sentSql(QueryGrammar $grammar, string $sql, array $bindings): string
    {
        [$sent, $clientBindings] = $grammar->prepareQueryForClient($sql, $bindings);
        $this->assertSame([], $clientBindings, 'the bindings are written into the SQL');

        return $sent;
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string}>
     */
    public static function whereProvider(): array
    {
        return [
            'a string with a quote and a backslash' => [
                fn (QueryBuilder $query) => $query->where('name', "it's a \\ test"),
                "select * from \"t1\" where \"name\" = 'it\\'s a \\\\ test'",
            ],
            'false, null via whereIn, and an int' => [
                fn (QueryBuilder $query) => $query->where('flag', false)->whereIn('note', [null, 'n'])->where('id', 3),
                "select * from \"t1\" where \"flag\" = 0 and \"note\" in (null, 'n') and \"id\" = 3",
            ],
            'an exact float' => [
                fn (QueryBuilder $query) => $query->where('f', 0.1 + 0.2)->orWhere('f', 1 / 3),
                'select * from "t1" where "f" = 0.30000000000000004 or "f" = 0.3333333333333333',
            ],
            'a Stringable is quoted' => [
                fn (QueryBuilder $query) => $query->where('id', Str::of('0 or 1 = 1')),
                "select * from \"t1\" where \"id\" = '0 or 1 = 1'",
            ],
            'backed enums' => [
                fn (QueryBuilder $query) => $query->where('a', IntBackedEnumFixture::High)->where('b', StringBackedEnumFixture::Active),
                "select * from \"t1\" where \"a\" = 3 and \"b\" = 'active'",
            ],
            'a value that names a format stays a literal' => [
                fn (QueryBuilder $query) => $query->where('name', 'a FORMAT CSV'),
                "select * from \"t1\" where \"name\" = 'a FORMAT CSV'",
            ],
            'a raw condition with a "?" placeholder' => [
                fn (QueryBuilder $query) => $query->whereRaw('id > ?', [1])->where('name', 'c'),
                "select * from \"t1\" where id > 1 and \"name\" = 'c'",
            ],
            'a "?" inside a literal and a comment of raw SQL' => [
                fn (QueryBuilder $query) => $query->whereRaw("name != '?' -- why?\nand id = ?", [2]),
                "select * from \"t1\" where name != '?' -- why?\nand id = 2",
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     */
    #[DataProvider('whereProvider')]
    public function test_bindings_are_written_into_the_sql_as_literals(Closure $build, string $sql): void
    {
        $query = $build($this->query());

        $this->assertStringNotContainsString('#@', $query->toSql());
        $this->assertSame($sql, $this->sentSql($query->getGrammar(), $query->toSql(), $query->getBindings()));
        $this->assertSame($sql, $query->toRawSql());
    }

    /**
     * With 41 bindings smi2 read :30 in 'a:30' as the binding of :30; the literal is now left alone.
     */
    public function test_a_colon_inside_a_string_literal_is_kept_with_many_bindings(): void
    {
        $query = $this->query()->whereIn('id', range(100, 140))->orWhereRaw("name = 'a:30'");

        $sent = $this->sentSql($query->getGrammar(), $query->toSql(), $query->getBindings());

        $this->assertStringEndsWith("140) or name = 'a:30'", $sent);
    }

    public function test_insert_writes_null_false_arrays_and_dates(): void
    {
        $query = $this->query();
        $values = [
            'id' => 10,
            'name' => 'arr',
            'flag' => false,
            'tags' => ['p', 'q'],
            'empty' => [],
            'created_at' => Carbon::parse('2024-01-01 00:00:00'),
            'note' => null,
        ];

        $sql = $query->getGrammar()->compileInsert($query, $values);

        $this->assertSame(
            'insert into "t1" ("id", "name", "flag", "tags", "empty", "created_at", "note") values (?, ?, ?, ?, ?, ?, ?)',
            $sql
        );
        $this->assertSame(
            "insert into \"t1\" (\"id\", \"name\", \"flag\", \"tags\", \"empty\", \"created_at\", \"note\") values"
            . " (10, 'arr', 0, ['p', 'q'], [], '2024-01-01 00:00:00', null)",
            $this->sentSql($query->getGrammar(), $sql, array_values($values))
        );
    }

    /**
     * smi2 replaced {0} in a column name by the first binding.
     */
    public function test_a_column_name_with_braces_is_kept(): void
    {
        $query = $this->query()->where('id', 1);
        $sql = $query->getGrammar()->compileUpdate($query, ['{0}' => 'ABC']);

        $this->assertSame(
            "alter table \"t1\" update \"{0}\" = 'ABC' where \"id\" = 1",
            $this->sentSql($query->getGrammar(), $sql, ['ABC', 1])
        );
    }

    public function test_a_question_mark_count_mismatch_throws_before_anything_is_sent(): void
    {
        $query = $this->query()->whereRaw('id = ? and name = ?', [1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The query has 2 "?" placeholders, but 1 binding was given. Write a literal "?" as "??".');

        $query->getGrammar()->prepareQueryForClient($query->toSql(), $query->getBindings());
    }

    public function test_mixing_question_marks_with_smi2_placeholders_throws(): void
    {
        $query = $this->query()->whereRaw('id = :0')->where('name', 'x');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The query mixes "?" placeholders with smi2 placeholders (:0, {0}) or query parameters');

        $query->getGrammar()->prepareQueryForClient($query->toSql(), [1, 'x']);
    }

    public function test_to_raw_sql_writes_the_values_without_markers(): void
    {
        $query = $this->query()->where('name', "it's")->whereIn('id', [1, 2])->where('d', Carbon::parse('2024-01-02 03:04:05'));

        $this->assertSame('select * from "t1" where "name" = ? and "id" in (?, ?) and "d" = ?', $query->toSql());
        $this->assertSame(
            "select * from \"t1\" where \"name\" = 'it\\'s' and \"id\" in (1, 2) and \"d\" = '2024-01-02 03:04:05'",
            $query->toRawSql()
        );
    }

    /**
     * On this package's connection, literals are written by the connection's builder grammar, so dates follow its
     * datetime_precision.
     */
    public function test_literals_follow_the_datetime_precision_of_the_connection(): void
    {
        $date = Carbon::parse('2024-01-02 03:04:05.123456');

        $this->assertSame("'2024-01-02 03:04:05'", $this->query()->getGrammar()->compileLiteral($date));
        $this->assertSame(
            "'2024-01-02 03:04:05.123456'",
            $this->query(DateTimePrecision::MICROSECOND)->getGrammar()->compileLiteral($date)
        );
    }

    /**
     * A Laravel expression inside an array value is written through this grammar.
     */
    public function test_an_expression_inside_an_array_is_written_as_its_sql(): void
    {
        $this->assertSame('[1, now()]', $this->query()->getGrammar()->compileLiteral([1, new Expression('now()')]));
    }

    /**
     * A query grammar on another connection, whose config has the given datetime_precision option.
     *
     * @param mixed $precision
     * @return QueryGrammar
     */
    private function grammarOnAnotherConnection(mixed $precision): QueryGrammar
    {
        $connection = $this->createStub(LaravelConnection::class);
        $connection->method('getConfig')->willReturnCallback(
            fn (?string $option = null): mixed => $option === 'datetime_precision' ? $precision : null
        );

        return new QueryGrammar($connection);
    }

    /**
     * On another connection, the grammar reads the connection's datetime_precision option itself, as the package's
     * connection reads it: null and blank mean 'second', and the two values are read in any letter case.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function anotherConnectionPrecisionProvider(): array
    {
        return [
            'microsecond' => ['microsecond', "'2024-01-02 03:04:05.500000'"],
            'left out or null' => [null, "'2024-01-02 03:04:05'"],
            'blank, as an empty environment variable gives' => ['', "'2024-01-02 03:04:05'"],
            'spaces' => ['  ', "'2024-01-02 03:04:05'"],
            'Second' => ['Second', "'2024-01-02 03:04:05'"],
            'MICROSECOND with surrounding spaces' => [' MICROSECOND ', "'2024-01-02 03:04:05.500000'"],
            'an enum instance' => [new DateTimePrecision(DateTimePrecision::MICROSECOND),"'2024-01-02 03:04:05.500000'"],
        ];
    }

    #[DataProvider('anotherConnectionPrecisionProvider')]
    public function test_another_connection_uses_its_datetime_precision_option(mixed $precision, string $literal): void
    {
        $this->assertSame(
            $literal,
            $this->grammarOnAnotherConnection($precision)->compileLiteral(Carbon::parse('2024-01-02 03:04:05.5'))
        );
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidAnotherConnectionPrecisionProvider(): array
    {
        return [
            'another unit, named as given' => [' Millisecond ', '[ Millisecond ]'],
            'an int' => [6, '[int]'],
            'a bool' => [true, '[bool]'],
        ];
    }

    /**
     * Any other datetime_precision of another connection is refused with an InvalidArgumentException, also a value
     * that is no string, which setDateTimePrecision() would refuse with a TypeError.
     */
    #[DataProvider('invalidAnotherConnectionPrecisionProvider')]
    public function test_another_connection_with_any_other_datetime_precision_throws(mixed $precision, string $given): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid datetime precision {$given}: use 'second' or 'microsecond'.");

        $this->grammarOnAnotherConnection($precision)->compileLiteral('a');
    }

    /**
     * The builder grammar of the connection is created once per query grammar.
     */
    public function test_the_literal_grammar_is_created_once(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('newBuilderGrammar')->willReturn(new Grammar());
        $grammar = new QueryGrammar($connection);

        $this->assertSame("'a'", $grammar->compileLiteral('a'));
        $this->assertSame('[1]', $grammar->compileLiteral([1]));
    }

    /**
     * Laravel's base builder gives the same placeholders.
     */
    public function test_laravels_base_builder_shows_question_marks(): void
    {
        $query = $this->query();
        $base = (new Builder($query->getConnection(), $query->getGrammar(), new QueryProcessor()))->from('t1')->where('a', 1);

        $this->assertSame('select * from "t1" where "a" = ?', $base->toSql());
    }
}
