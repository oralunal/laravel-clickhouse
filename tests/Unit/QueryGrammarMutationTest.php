<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The grammar of Laravel's own query builder, used when fix_default_query_builder is false.
 */
class QueryGrammarMutationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @param bool $withWhere Whether the query gets the where condition f_int = 1
     * @return array{QueryGrammar, Builder}
     */
    private function grammarAndQuery(array $config = [], bool $withWhere = true): array
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null) => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $grammar = new QueryGrammar($connection);
        $query = (new Builder($connection, $grammar, new Processor()))->from('examples');

        return [$grammar, $withWhere ? $query->where('f_int', 1) : $query];
    }

    /**
     * A query builder of the package on a stub connection that records the deletes and updates it is given, with
     * their bindings, instead of sending them.
     *
     * @param list<array{string, list<mixed>}> $statements The recorded statements
     * @param array<string, mixed> $config
     * @param string $table
     * @return QueryBuilder
     */
    private function recordingQuery(array &$statements, array $config = [], string $table = 'examples'): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null) => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $record = function (string $sql, array $bindings = []) use (&$statements): int {
            $statements[] = [$sql, $bindings];

            return 1;
        };
        $connection->method('delete')->willReturnCallback($record);
        $connection->method('update')->willReturnCallback($record);
        $grammar = new QueryGrammar($connection);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from($table);
    }

    public function test_delete_is_an_alter_table_mutation_by_default(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
    }

    public function test_delete_is_an_alter_table_mutation_when_lightweight_deletes_are_off(): void
    {
        [$grammar, $query] = $this->grammarAndQuery(['use_lightweight_delete' => false]);

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
    }

    public function test_delete_is_lightweight_when_the_connection_says_so(): void
    {
        [$grammar, $query] = $this->grammarAndQuery(['use_lightweight_delete' => true]);

        $this->assertSame('delete from "examples" where "f_int" = ?', $grammar->compileDelete($query));
    }

    public function test_update_is_an_alter_table_mutation(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        $this->assertSame(
            'alter table "examples" update "f_string" = ?, "f_int2" = ? where "f_int" = ?',
            $grammar->compileUpdate($query, ['f_string' => 'x', 'f_int2' => 2])
        );
    }

    /**
     * Without a where condition ClickHouse would get "alter table ... delete" or
     * "delete from ..." with nothing after it, a syntax error.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function deleteConfigProvider(): array
    {
        return [
            'alter table' => [[]],
            'lightweight' => [['use_lightweight_delete' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('deleteConfigProvider')]
    public function test_delete_without_where_throws(array $config): void
    {
        [$grammar, $query] = $this->grammarAndQuery($config, withWhere: false);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete without a where condition');

        $grammar->compileDelete($query);
    }

    public function test_update_without_where_throws(): void
    {
        [$grammar, $query] = $this->grammarAndQuery(withWhere: false);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot update without a where condition');

        $grammar->compileUpdate($query, ['f_string' => 'x']);
    }

    public function test_where_raw_one_updates_every_row(): void
    {
        [$grammar, $query] = $this->grammarAndQuery(withWhere: false);

        $this->assertSame(
            'alter table "examples" update "f_string" = ? where 1',
            $grammar->compileUpdate($query->whereRaw('1'), ['f_string' => 'x'])
        );
    }

    /**
     * Laravel's grammar leaves these clauses out of a delete or update, and a ClickHouse
     * mutation only takes the where conditions, so the statement could change rows that
     * the query does not select. A join would be sent in MySQL's syntax, which
     * ClickHouse rejects.
     *
     * @return array<string, array{array<string, mixed>, Closure(QueryGrammar, Builder): string, string}>
     */
    public static function mutationWithIgnoredClauseProvider(): array
    {
        return [
            'delete with a select alias' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->select('f_int', 'f_string as f_int2')),
                'Cannot delete with a query that uses SELECT aliases or expressions: ',
            ],
            'update with a select alias in upper case' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->select('f_string AS f_int2'), ['f_string' => 'x']),
                'Cannot update with a query that uses SELECT aliases or expressions: ',
            ],
            'delete with a select alias given as a Stringable, as $request->string() gives' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->select('f_int', Str::of('f_string as f_int2'))),
                'Cannot delete with a query that uses SELECT aliases or expressions: ',
            ],
            'delete with a raw select column' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->selectRaw('f_int')),
                'Cannot delete with a query that uses SELECT aliases or expressions: ',
            ],
            'update with a select sub-query' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->select('f_int')->selectSub(fn (Builder $sub) => $sub->from('examples2')->selectRaw('1'), 'one'),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses SELECT aliases or expressions: ',
            ],
            'alter delete with a join' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->join('banned', 'examples.f_int', '=', 'banned.f_int')),
                'Cannot delete with a query that uses JOIN: ',
            ],
            'lightweight delete with a join' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->leftJoin('banned', 'examples.f_int', '=', 'banned.f_int')),
                'Cannot delete with a query that uses JOIN: ',
            ],
            'update with a join' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->join('banned', 'examples.f_int', '=', 'banned.f_int'),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses JOIN: ',
            ],
            'delete with a group by' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->groupBy('f_string')),
                'Cannot delete with a query that uses GROUP BY: ',
            ],
            'update with a having' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->havingRaw('count() > 1'), ['f_string' => 'x']),
                'Cannot update with a query that uses HAVING: ',
            ],
            'delete with a union all' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->unionAll(fn (Builder $other) => $other->from('examples2'))),
                'Cannot delete with a query that uses UNION ALL: ',
            ],
            'update with a union' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->union(fn (Builder $other) => $other->from('examples2')),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses UNION: ',
            ],
            'delete with an orderByRaw() that calls arrayJoin()' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderByRaw('arrayJoin(tags)')),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'lightweight delete with an orderByRaw() expression that calls arrayJoin()' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete(
                    $query->orderBy('f_int')->orderByRaw(new Expression('arrayJoin(tags) desc'))
                ),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'update with an orderBy() expression that calls arrayJoin()' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->orderBy(new Expression('length(arrayJoin(tags))'), 'desc'),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses ORDER BY: ',
            ],
            'delete with an inOrderOf() expression that calls arrayJoin()' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete(
                    $query->inOrderOf(new Expression('arrayJoin(tags)'), ['a', 'b'])
                ),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'update with an inOrderOf() expression value that calls arrayJoin()' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->inOrderOf('f_int', [1, new Expression('length(arrayJoin(tags))')]),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses ORDER BY: ',
            ],
            'alter delete with an orderByRaw() with LIMIT ... BY' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderByRaw('ts desc limit 1 by f_string')),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'lightweight delete with an orderByRaw() with LIMIT' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderBy('f_int')->orderByRaw('f_string LIMIT 1')),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'update with an orderByRaw() with OFFSET ... ROWS' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->orderByRaw('f_int offset 1 rows'), ['f_string' => 'x']),
                'Cannot update with a query that uses ORDER BY: ',
            ],
            'delete with an orderByRaw() with FETCH' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderByRaw("f_int\nFetch first 2 rows only")),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'alter delete with an orderByRaw() with EXCEPT, which leaves out the rows of another select' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete(
                    $query->orderByRaw('f_int except select * from examples where f_int = 2')
                ),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'lightweight delete with an orderByRaw() with INTERSECT' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete(
                    $query->orderBy('f_int')->orderByRaw('f_int INTERSECT SELECT * FROM examples WHERE f_int = 2')
                ),
                'Cannot delete with a query that uses ORDER BY: ',
            ],
            'update with an orderByRaw() with UNION ALL, which adds the rows of another select' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->orderByRaw('f_int union all select * from examples2'),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses ORDER BY: ',
            ],
            'update with an orderBy() expression with LIMIT after parentheses and quotes' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate(
                    $query->orderBy(new Expression("position(f_string, ')') limit 1 by tuple(f_int, 'a''(')")),
                    ['f_string' => 'x']
                ),
                'Cannot update with a query that uses ORDER BY: ',
            ],
            'delete with a group limit' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderBy('f_int')->groupLimit(1, 'f_string')),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'update with a group limit of 0' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->groupLimit(0, 'f_string'), ['f_string' => 'x']),
                'Cannot update with a query that uses LIMIT: ',
            ],
            'alter delete with a limit' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->orderBy('f_int')->limit(1)),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'lightweight delete with a limit' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->limit(1)),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'delete with a limit of 0' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->limit(0)),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'delete with an offset' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->offset(5)),
                'Cannot delete with a query that uses OFFSET: ',
            ],
            'delete of a page' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query->forPage(2, 10)),
                'Cannot delete with a query that uses LIMIT and OFFSET: ',
            ],
            'update with a limit' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->limit(1), ['f_string' => 'x']),
                'Cannot update with a query that uses LIMIT: ',
            ],
            'update with an offset' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query->offset(5), ['f_string' => 'x']),
                'Cannot update with a query that uses OFFSET: ',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): string $compile
     */
    #[DataProvider('mutationWithIgnoredClauseProvider')]
    public function test_a_mutation_with_a_clause_it_would_ignore_throws(array $config, Closure $compile, string $message): void
    {
        [$grammar, $query] = $this->grammarAndQuery($config);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);

        $compile($grammar, $query);
    }

    public function test_the_limit_is_named_in_the_message(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot delete with a query that uses LIMIT: a ClickHouse DELETE only takes the where and prewhere'
            . ' conditions, so this clause would be ignored, and the delete could remove rows that the query does'
            . " not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id'))."
            . ' Methods such as first(), chunk(), paginate() and simplePaginate() leave a LIMIT on the query they'
            . ' run on, so start a new query.'
        );

        $grammar->compileDelete($query->limit(1000));
    }

    public function test_the_ignored_clauses_are_named_in_the_order_of_a_select(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot update with a query that uses SELECT aliases or expressions, JOIN, GROUP BY, HAVING, ORDER BY, LIMIT,'
            . ' OFFSET, UNION ALL and UNION: a ClickHouse UPDATE only takes the where and prewhere conditions, so these'
            . ' clauses would be ignored, and the update could change rows that the query does not select.'
        );

        $other = fn (Builder $other) => $other->from('examples2');
        $grammar->compileUpdate(
            $query
                ->offset(20)
                ->limit(10)
                ->orderByRaw('arrayJoin(tags)')
                ->unionAll($other)
                ->union($other)
                ->unionAll($other)
                ->havingRaw('count() > 1')
                ->groupBy('f_string')
                ->join('banned', 'examples.f_int', '=', 'banned.f_int')
                ->selectRaw('count() AS c'),
            ['f_string' => 'x']
        );
    }

    /**
     * A query without a limit or an offset gets no hint about the methods that leave one.
     */
    public function test_the_message_names_the_methods_that_leave_a_limit_only_for_a_limit_or_an_offset(): void
    {
        [$grammar, $grouped] = $this->grammarAndQuery();

        try {
            $grammar->compileDelete($grouped->groupBy('f_string'));
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringEndsWith("whereIn('id', \$query->pluck('id')).", $exception->getMessage());
        }

        [$grammar, $offset] = $this->grammarAndQuery();

        try {
            $grammar->compileDelete($offset->offset(5));
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot delete with a query that uses OFFSET: ', $exception->getMessage());
            $this->assertStringEndsWith(
                ' Methods such as first(), chunk(), paginate() and simplePaginate() leave a LIMIT on the query they run on,'
                . ' so start a new query.',
                $exception->getMessage()
            );
        }
    }

    /**
     * Laravel's updateOrInsert() updates an existing row with limit(1)->update(), so the message of an
     * update with a LIMIT says that it cannot; an update with only an OFFSET does not name it, and
     * test_the_limit_is_named_in_the_message() shows that a delete does not either.
     */
    public function test_the_message_of_an_update_with_a_limit_names_update_or_insert(): void
    {
        [$grammar, $limit] = $this->grammarAndQuery();

        try {
            $grammar->compileUpdate($limit->limit(1), ['f_string' => 'x']);
            $this->fail('The update should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                'Cannot update with a query that uses LIMIT: a ClickHouse UPDATE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the update could change rows that the query does'
                . " not select. Select the keys first, and update by them instead: whereIn('id', \$query->pluck('id'))."
                . ' Methods such as first(), chunk(), paginate() and simplePaginate() leave a LIMIT on the query they'
                . ' run on, so start a new query; updateOrInsert() adds a LIMIT itself, so it cannot update an existing'
                . ' row.',
                $exception->getMessage()
            );
        }

        [$grammar, $offset] = $this->grammarAndQuery();

        try {
            $grammar->compileUpdate($offset->offset(5), ['f_string' => 'x']);
            $this->fail('The update should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot update with a query that uses OFFSET: ', $exception->getMessage());
            $this->assertStringEndsWith(
                ' Methods such as first(), chunk(), paginate() and simplePaginate() leave a LIMIT on the query they run on,'
                . ' so start a new query.',
                $exception->getMessage()
            );
        }
    }

    public function test_a_mutation_without_where_reports_the_missing_where_before_the_limit(): void
    {
        [$grammar, $query] = $this->grammarAndQuery(withWhere: false);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete without a where condition');

        $grammar->compileDelete($query->limit(1));
    }

    /**
     * An offset of 0 skips no row, and an ORDER BY or a select list of column names
     * does not change which rows match.
     */
    public function test_an_offset_of_zero_an_order_and_plain_columns_are_left_out(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();
        $query->select('f_int', 'examples.f_string', '*')->distinct()->orderBy('f_int')->offset(0);

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame(
            'alter table "examples" update "f_string" = ? where "f_int" = ?',
            $grammar->compileUpdate($query, ['f_string' => 'x'])
        );
    }

    /**
     * An order that does not call arrayJoin() only sorts the rows, and WITH FILL only adds rows that the
     * table does not have, so the mutation is sent without the ORDER BY.
     */
    public function test_an_order_that_does_not_call_array_join_is_left_out(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();
        $query
            ->orderByRaw('f_int WITH FILL FROM 0 TO 10')
            ->orderBy(new Expression('length(f_string)'), 'desc')
            ->orderByRaw('arrayJoined_at desc')
            ->inRandomOrder();

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame(
            'alter table "examples" update "f_string" = ? where "f_int" = ?',
            $grammar->compileUpdate($query, ['f_string' => 'x'])
        );
    }

    /**
     * Orders that define an alias, which ClickHouse lets the where conditions read instead of the table's
     * column of that name: a name with ' as ', in any case, which Laravel compiles as an alias, and raw SQL
     * or a sub-query with the word AS in it. Raw SQL with AS in it for another reason, such as
     * CAST(a AS String), is refused too.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function orderThatDefinesAnAliasProvider(): array
    {
        return [
            'orderBy() of a name with as' => [fn (Builder $query): Builder => $query->orderBy('a as b')],
            'orderBy() of a name with AS' => [fn (Builder $query): Builder => $query->orderBy('a AS b')],
            'orderBy() of a name with as, after a plain order' => [fn (Builder $query): Builder => $query->orderBy('f_int')->orderBy('a as b')],
            'latest() of a name with as' => [fn (Builder $query): Builder => $query->latest('a as b')],
            'orderByDesc() of a name with as' => [fn (Builder $query): Builder => $query->orderByDesc('a as b')],
            'orderByRaw() with AS' => [fn (Builder $query): Builder => $query->orderByRaw('a AS b')],
            'orderByRaw() with as in lower case' => [fn (Builder $query): Builder => $query->orderByRaw('toString(f_int) as f_string')],
            'orderBy() of an expression with AS, as DB::raw() gives' => [fn (Builder $query): Builder => $query->orderBy(new Expression('a AS b'))],
            'orderBy() of a sub-query that selects an alias' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Builder $sub) => $sub->from('examples2')->select('f_int as b')->limit(1)),
            ],
            'orderByRaw() with AS for another reason' => [fn (Builder $query): Builder => $query->orderByRaw('CAST(a AS String)')],
            'orderBy() of a Stringable name with as, as $request->string() gives' => [
                fn (Builder $query): Builder => $query->orderBy(Str::of('a as b')),
            ],
            'latest() of a Stringable name with as' => [fn (Builder $query): Builder => $query->latest(Str::of('a as b'))],
            'inOrderOf() of a name with as' => [fn (Builder $query): Builder => $query->inOrderOf('a as b', [1, 2])],
            'inOrderOf() with an expression value that defines an alias' => [
                fn (Builder $query): Builder => $query->inOrderOf('a', [1, new Expression('(a AS b)')]),
            ],
        ];
    }

    /**
     * Each statement, with the whole message of its refusal for ORDER BY alone: it names neither the
     * methods that leave a LIMIT nor updateOrInsert(), which are named for a LIMIT or an OFFSET only.
     *
     * @return array<string, array{array<string, mixed>, Closure(QueryGrammar, Builder): string, string}>
     */
    public static function mutationRefusedForOrderByProvider(): array
    {
        return [
            'alter delete' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'Cannot delete with a query that uses ORDER BY: a ClickHouse DELETE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the delete could remove rows that the query does'
                . " not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'Cannot delete with a query that uses ORDER BY: a ClickHouse DELETE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the delete could remove rows that the query does'
                . " not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'update' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']),
                'Cannot update with a query that uses ORDER BY: a ClickHouse UPDATE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the update could change rows that the query does'
                . " not select. Select the keys first, and update by them instead: whereIn('id', \$query->pluck('id')).",
            ],
        ];
    }

    /**
     * Every order that defines an alias with every statement.
     *
     * @return array<string, array{Closure(Builder): Builder, array<string, mixed>, Closure(QueryGrammar, Builder): string, string}>
     */
    public static function mutationOfAQueryWithAnOrderThatDefinesAnAliasProvider(): array
    {
        $dataSets = [];
        foreach (self::orderThatDefinesAnAliasProvider() as $orderLabel => [$order]) {
            foreach (self::mutationRefusedForOrderByProvider() as $mutationLabel => [$config, $compile, $message]) {
                $dataSets["{$orderLabel}, {$mutationLabel}"] = [$order, $config, $compile, $message];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $order
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): string $compile
     */
    #[DataProvider('mutationOfAQueryWithAnOrderThatDefinesAnAliasProvider')]
    public function test_a_mutation_of_a_query_with_an_order_that_defines_an_alias_throws(
        Closure $order,
        array $config,
        Closure $compile,
        string $message
    ): void {
        [$grammar, $query] = $this->grammarAndQuery($config);

        try {
            $compile($grammar, $order($query));
            $this->fail('The mutation should throw');
        } catch (QueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * With a LIMIT next to it, the message of an update names both clauses, the methods that leave a LIMIT,
     * and updateOrInsert().
     */
    public function test_the_message_of_an_update_with_an_order_alias_and_a_limit_names_update_or_insert(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot update with a query that uses ORDER BY and LIMIT: a ClickHouse UPDATE only takes the where and'
            . ' prewhere conditions, so these clauses would be ignored, and the update could change rows that the'
            . " query does not select. Select the keys first, and update by them instead: whereIn('id',"
            . " \$query->pluck('id')). Methods such as first(), chunk(), paginate() and simplePaginate() leave a"
            . ' LIMIT on the query they run on, so start a new query; updateOrInsert() adds a LIMIT itself, so it'
            . ' cannot update an existing row.'
        );

        $grammar->compileUpdate($query->orderBy('a as b')->limit(1), ['f_string' => 'x']);
    }

    /**
     * Orders that define no alias only sort the rows, so the mutation is sent without the ORDER BY: a name,
     * also one with "as" only inside a word, raw SQL whose "as" is only part of a word, such as ASC, and
     * latest(), which orders by created_at.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function orderThatDefinesNoAliasProvider(): array
    {
        return [
            'orderBy() of a name' => [fn (Builder $query): Builder => $query->orderBy('a')],
            'orderByRaw() with ASC' => [fn (Builder $query): Builder => $query->orderByRaw('a ASC')],
            'orderByRaw() of a name with as inside a word' => [fn (Builder $query): Builder => $query->orderByRaw('has_items desc')],
            'orderBy() of a name with as inside a word' => [fn (Builder $query): Builder => $query->orderBy('has_as')],
            'orderBy() of a name that starts with as' => [fn (Builder $query): Builder => $query->orderBy('alias')],
            'latest()' => [fn (Builder $query): Builder => $query->latest()],
            'orderBy() of a Stringable name' => [fn (Builder $query): Builder => $query->orderBy(Str::of('a'))],
            'inOrderOf() of a name with plain values' => [fn (Builder $query): Builder => $query->inOrderOf('a', [1, 'b'])],
        ];
    }

    /**
     * @param Closure(Builder): Builder $order
     */
    #[DataProvider('orderThatDefinesNoAliasProvider')]
    public function test_an_order_that_defines_no_alias_is_left_out(Closure $order): void
    {
        [$grammar, $query] = $this->grammarAndQuery();
        $order($query);

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame(
            'alter table "examples" update "f_string" = ? where "f_int" = ?',
            $grammar->compileUpdate($query, ['f_string' => 'x'])
        );
    }

    /**
     * Orders whose raw SQL has LIMIT, OFFSET, FETCH, UNION, INTERSECT or EXCEPT only inside parentheses, quotes or a
     * longer word do not limit or change the rows, so the mutation is sent without the ORDER BY: a sub-query in an
     * order, such as orderBy() of a query with limit(1), which only limits the sub-query, and quoted names or text.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function orderThatDoesNotLimitTheRowsProvider(): array
    {
        return [
            'orderBy() of a sub-query with a limit' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Builder $sub) => $sub->from('examples2')->selectRaw('max(f_int)')->limit(1)),
            ],
            'orderByRaw() of a sub-query with LIMIT and OFFSET' => [
                fn (Builder $query): Builder => $query->orderByRaw('(select f_int from examples2 order by f_int limit 1 offset 1) desc'),
            ],
            'orderByRaw() of names that are quoted or longer words' => [
                fn (Builder $query): Builder => $query->orderByRaw('"limit" desc, `offset`, limits, fetched_at'),
            ],
            'orderByRaw() with the words in a string literal' => [
                fn (Builder $query): Builder => $query->orderByRaw("f_string = 'it''s a limit 1 (' desc, f_string = 'offset\\' 2' desc"),
            ],
            'orderByRaw() of a sub-query with EXCEPT' => [
                fn (Builder $query): Builder => $query->orderByRaw('(select max(f_int) from examples2 except select 1) desc'),
            ],
            'orderByRaw() of names that start with set operation words or are quoted' => [
                fn (Builder $query): Builder => $query->orderByRaw('except_flag desc, union_id, "intersect"'),
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $order
     */
    #[DataProvider('orderThatDoesNotLimitTheRowsProvider')]
    public function test_an_order_that_does_not_limit_the_rows_is_left_out(Closure $order): void
    {
        [$grammar, $query] = $this->grammarAndQuery();
        $order($query);

        $this->assertFalse($grammar->hasAnOrderThatLimitsRows($query));
        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame(
            'alter table "examples" update "f_string" = ? where "f_int" = ?',
            $grammar->compileUpdate($query, ['f_string' => 'x'])
        );
    }

    /**
     * A mutation leaves the order out, so it leaves out the bindings of the order too: those of orderByRaw(),
     * inOrderOf() and orderBy() of a sub-query. Otherwise the statement would get more bindings than placeholders.
     *
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder}>
     */
    public static function orderWithBindingsProvider(): array
    {
        return [
            'orderByRaw() with a binding' => [fn (QueryBuilder $query): QueryBuilder => $query->orderByRaw('f_string = ? desc', ['b'])],
            'inOrderOf()' => [fn (QueryBuilder $query): QueryBuilder => $query->inOrderOf('f_string', ['c', 'a'])],
            'orderBy() of a sub-query with a binding' => [
                fn (QueryBuilder $query): QueryBuilder => $query->orderBy(fn (Builder $sub) => $sub->from('examples2')->selectRaw('max(f_int)')->where('k', 7)),
            ],
            'a plain order after an order with bindings' => [
                fn (QueryBuilder $query): QueryBuilder => $query->orderByRaw('f_string = ? desc', ['b'])->orderBy('id'),
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $order
     */
    #[DataProvider('orderWithBindingsProvider')]
    public function test_a_mutation_leaves_the_bindings_of_the_order_out(Closure $order): void
    {
        $statements = [];
        $order($this->recordingQuery($statements)->where('id', '>', 2))->delete();
        $order($this->recordingQuery($statements, ['use_lightweight_delete' => true])->where('id', '>', 2))->delete();
        $order($this->recordingQuery($statements)->where('id', '>', 2))->update(['v' => 'x', 'tags' => ['t']]);

        $this->assertSame([
            ['alter table "examples" delete where "id" > ?', [2]],
            ['delete from "examples" where "id" > ?', [2]],
            ["alter table \"examples\" update \"v\" = ?, \"tags\" = ['t'] where \"id\" > ?", ['x', 2]],
        ], $statements);
    }

    public function test_the_bindings_of_a_delete_and_an_update_leave_the_order_out(): void
    {
        [$grammar] = $this->grammarAndQuery();
        $bindings = [
            'select' => [1], 'from' => [2], 'join' => [3], 'where' => [4], 'groupBy' => [5], 'having' => [6],
            'order' => [7], 'union' => [8], 'unionOrder' => [9],
        ];

        $this->assertSame([2, 3, 4, 5, 6, 8], $grammar->prepareBindingsForDelete($bindings));
        $this->assertSame([3, 'x', 2, 4, 5, 6, 8], $grammar->prepareBindingsForUpdate($bindings, ['v' => 'x']));
    }

    /**
     * ClickHouse rejects a column name qualified by the mutation's table, such as the "examples"."id" that
     * Laravel's delete($id) writes, so the table's qualifiers are dropped from the column names of a mutation.
     *
     * @return array<string, array{string, Closure(QueryBuilder): mixed, string, list<mixed>}>
     */
    public static function qualifiedMutationProvider(): array
    {
        return [
            'delete($id)' => [
                'examples',
                fn (QueryBuilder $query) => $query->delete(99),
                'alter table "examples" delete where "id" = ?',
                [99],
            ],
            'qualified where, whereIn and whereColumn' => [
                'examples',
                fn (QueryBuilder $query) => $query->where('examples.id', 1)->whereIn('examples.f_int', [2, 3])
                    ->whereColumn('examples.a', '<', 'examples.b')->delete(),
                'alter table "examples" delete where "id" = ? and "f_int" in (?, ?) and "a" < "b"',
                [1, 2, 3],
            ],
            'qualified update keys and conditions' => [
                'examples',
                fn (QueryBuilder $query) => $query->where('examples.id', 3)->update(['examples.f_string' => 'cc']),
                'alter table "examples" update "f_string" = ? where "id" = ?',
                ['cc', 3],
            ],
            'a database-qualified table' => [
                'db.examples',
                fn (QueryBuilder $query) => $query->where('db.examples.id', 3)->where('examples.f_int', 4)
                    ->update(['db.examples.f_string' => 'cc']),
                'alter table "db"."examples" update "f_string" = ? where "id" = ? and "f_int" = ?',
                ['cc', 3, 4],
            ],
            'delete($id) of a database-qualified table' => [
                'db.examples',
                fn (QueryBuilder $query) => $query->delete(7),
                'alter table "db"."examples" delete where "id" = ?',
                [7],
            ],
            'a table alias, which ClickHouse rejects after the table' => [
                'examples as e',
                fn (QueryBuilder $query) => $query->where('e.id', 2)->delete(),
                'alter table "examples" delete where "id" = ?',
                [2],
            ],
            'another table keeps its qualifier' => [
                'examples',
                fn (QueryBuilder $query) => $query->where('other.id', 1)->delete(),
                'alter table "examples" delete where "other"."id" = ?',
                [1],
            ],
            'a nested where' => [
                'examples',
                fn (QueryBuilder $query) => $query->where(fn ($nested) => $nested->where('examples.id', 1)->orWhere('examples.id', 2))->delete(),
                'alter table "examples" delete where ("id" = ? or "id" = ?)',
                [1, 2],
            ],
            'an exists sub-query keeps its qualifiers' => [
                'examples',
                fn (QueryBuilder $query) => $query->whereExists(
                    fn ($sub) => $sub->from('banned')->whereColumn('banned.f_int', 'examples.f_int')
                )->delete(),
                'alter table "examples" delete where exists (select * from "banned" where "banned"."f_int" = "examples"."f_int")',
                [],
            ],
            'a whereIn sub-query keeps its qualifiers' => [
                'examples',
                fn (QueryBuilder $query) => $query->whereIn('examples.id', fn ($sub) => $sub->select('examples.id')->from('examples')->where('examples.f_int', 9))->delete(),
                'alter table "examples" delete where "id" in (select "examples"."id" from "examples" where "examples"."f_int" = ?)',
                [9],
            ],
            'a raw condition is written as it is' => [
                'examples',
                fn (QueryBuilder $query) => $query->whereRaw('examples.id = ?', [1])->delete(),
                'alter table "examples" delete where examples.id = ?',
                [1],
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): mixed $mutation
     * @param list<mixed> $bindings
     */
    #[DataProvider('qualifiedMutationProvider')]
    public function test_a_mutation_drops_the_qualifiers_of_its_table(string $table, Closure $mutation, string $sql, array $bindings): void
    {
        $statements = [];
        $mutation($this->recordingQuery($statements, table: $table));

        $this->assertSame([[$sql, $bindings]], $statements);
    }

    public function test_a_lightweight_delete_drops_the_qualifiers_of_its_table(): void
    {
        $statements = [];
        $this->recordingQuery($statements, ['use_lightweight_delete' => true])->delete(5);

        $this->assertSame([['delete from "examples" where "id" = ?', [5]]], $statements);
    }

    /**
     * A select keeps every qualifier, also after a mutation of the same grammar, and the mutation leaves the
     * query's table, alias included, as it was.
     */
    public function test_a_select_keeps_its_qualifiers_after_a_mutation(): void
    {
        $statements = [];
        $query = $this->recordingQuery($statements, table: 'examples as e')->where('e.id', 1);
        $query->clone()->delete();

        $this->assertSame('examples as e', $query->from);
        $this->assertSame('select * from "examples" as "e" where "e"."id" = ?', $query->toSql());

        try {
            $query->limit(1)->delete();
            $this->fail('The delete should throw');
        } catch (QueryException) {
        }

        $this->assertSame('examples as e', $query->from, 'a refused mutation leaves the table as it was');
        $this->assertSame('select * from "examples" as "e" where "e"."id" = ? limit 1', $query->toSql());
        $this->assertCount(1, $statements);
    }

    public function test_the_guard_still_refuses_a_qualified_delete_with_a_limit(): void
    {
        $statements = [];

        try {
            $this->recordingQuery($statements)->where('examples.id', 1)->limit(1)->delete();
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot delete with a query that uses LIMIT: ', $exception->getMessage());
        }

        $this->assertSame([], $statements, 'nothing is sent');
    }

    /**
     * Laravel binds the elements of an array or a collection one by one, so they took the places of the following
     * placeholders. They are written as an array literal instead.
     *
     * @return array<string, array{array<string, mixed>, string, list<mixed>}>
     */
    public static function updateValueProvider(): array
    {
        return [
            'an array' => [
                ['tags' => ['a', "b'c"], 'v' => 'x'],
                "alter table \"examples\" update \"tags\" = ['a', 'b\\'c'], \"v\" = ? where \"id\" = ?",
                ['x', 3],
            ],
            'a collection' => [['tags' => collect(['x', 'y'])], "alter table \"examples\" update \"tags\" = ['x', 'y'] where \"id\" = ?", [3]],
            'a lazy collection' => [
                ['tags' => LazyCollection::make(fn () => yield from [1, 2])],
                'alter table "examples" update "tags" = [1, 2] where "id" = ?',
                [3],
            ],
            'a nested array with a date and null' => [
                ['m' => [[1, null], [Carbon::parse('2024-01-02 03:04:05')]]],
                "alter table \"examples\" update \"m\" = [[1, null], ['2024-01-02 03:04:05']] where \"id\" = ?",
                [3],
            ],
            'an empty array' => [['tags' => []], 'alter table "examples" update "tags" = [] where "id" = ?', [3]],
            'a nested collection' => [
                ['m' => collect([collect([1]), [2]])],
                'alter table "examples" update "m" = [[1], [2]] where "id" = ?',
                [3],
            ],
            'an expression' => [
                ['v' => new Expression('upper(v)'), 'n' => 1],
                'alter table "examples" update "v" = upper(v), "n" = ? where "id" = ?',
                [1, 3],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<mixed> $bindings
     */
    #[DataProvider('updateValueProvider')]
    public function test_update_writes_arrays_and_collections_as_array_literals(array $values, string $sql, array $bindings): void
    {
        $statements = [];
        $this->recordingQuery($statements)->where('id', 3)->update($values);

        $this->assertSame([[$sql, $bindings]], $statements);
    }

    public function test_update_with_a_sub_query_value_keeps_its_bindings_in_order(): void
    {
        $statements = [];
        $query = $this->recordingQuery($statements);
        $query->where('id', 3)->update([
            'tags' => ['a'],
            'v' => $query->newQuery()->from('other')->select('v')->where('k', 7)->limit(1),
            'n' => 2,
        ]);

        $this->assertSame([[
            "alter table \"examples\" update \"tags\" = ['a'], \"v\" = (select \"v\" from \"other\" where \"k\" = ? limit 1),"
            . ' "n" = ? where "id" = ?',
            [7, 2, 3],
        ]], $statements);
    }

    public function test_update_refuses_an_array_element_that_cannot_be_written(): void
    {
        $statements = [];

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->recordingQuery($statements)->where('id', 3)->update(['tags' => [new \stdClass()]]);
        } finally {
            $this->assertSame([], $statements, 'nothing is sent');
        }
    }

    /**
     * The where variants of this grammar only read the table's columns, so a mutation sends them.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function conditionThatAMutationSendsProvider(): array
    {
        return [
            'whereDate()' => [fn (Builder $query) => $query->whereDate('d', '2024-01-05'), 'toDate32("d") = ?'],
            'whereTime()' => [fn (Builder $query) => $query->whereTime('d', '>=', '10:00'), "formatDateTime(\"d\", '%H:%i:%S') >= ?"],
            'whereDay()' => [fn (Builder $query) => $query->whereDay('d', 5), 'toDayOfMonth("d") = ?'],
            'whereLike()' => [fn (Builder $query) => $query->whereLike('v', 'a%'), '"v" ilike ?'],
            'whereNullSafeEquals()' => [fn (Builder $query) => $query->whereNullSafeEquals('v', null), '["v"] = [?]'],
            'a bitwise condition' => [fn (Builder $query) => $query->where('f', '&~', 1), 'bitAnd("f", ?) != "f"'],
            'distinct' => [fn (Builder $query) => $query->distinct()->where('id', 1), '"id" = ?'],
            'a timeout and an index hint' => [fn (Builder $query) => $query->timeout(5)->forceIndex('idx')->where('id', 1), '"id" = ?'],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('conditionThatAMutationSendsProvider')]
    public function test_a_mutation_sends_the_where_variants_of_this_grammar(Closure $condition, string $where): void
    {
        [$grammar, $query] = $this->grammarAndQuery(withWhere: false);
        $condition($query);

        $this->assertSame('alter table "examples" delete where ' . $where, $grammar->compileDelete($query));
        $this->assertSame(
            'alter table "examples" update "v" = ? where ' . $where,
            $grammar->compileUpdate($query, ['v' => 'x'])
        );
    }

    /**
     * union() sends UNION DISTINCT now, and is still refused as a UNION; inRandomOrder() and an order that adds
     * rows with WITH FILL are still left out.
     */
    public function test_a_union_is_still_refused_and_a_random_order_left_out(): void
    {
        [$grammar, $query] = $this->grammarAndQuery();

        try {
            $grammar->compileDelete((clone $query)->union(fn (Builder $other) => $other->from('examples2')));
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot delete with a query that uses UNION: ', $exception->getMessage());
        }

        $this->assertSame(
            'alter table "examples" delete where "f_int" = ?',
            $grammar->compileDelete($query->inRandomOrder()->orderByRaw('f_int WITH FILL TO 10'))
        );
    }

    /**
     * A stub connection that reads its options from the config, has the given default cluster, answers
     * hasTemporaryTable() and pretending() as given, and refuses select() with the given exception.
     *
     * @param array<string, mixed> $config
     * @param string|null $defaultCluster
     * @param bool $temporaryTable What hasTemporaryTable() answers
     * @param bool $pretending
     * @return Connection&MockObject
     */
    private function mutationConnection(
        array $config = [],
        ?string $defaultCluster = null,
        bool $temporaryTable = false,
        bool $pretending = false
    ): Connection {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null) => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $connection->method('getDefaultCluster')->willReturn($defaultCluster);
        $connection->method('hasTemporaryTable')->willReturn($temporaryTable);
        $connection->method('pretending')->willReturn($pretending);

        return $connection;
    }

    /**
     * @param Connection $connection
     * @param string $table
     * @return array{QueryGrammar, Builder}
     */
    private function grammarAndQueryOn(Connection $connection, string $table = 'examples'): array
    {
        $grammar = new QueryGrammar($connection);

        return [$grammar, (new Builder($connection, $grammar, new Processor()))->from($table)->where('f_int', 1)];
    }

    /**
     * use_on_cluster sends deletes, updates and truncates of Laravel's query builder ON CLUSTER to the cluster_name.
     *
     * @return array<string, array{array<string, mixed>, Closure(QueryGrammar, Builder): mixed, mixed}>
     */
    public static function defaultClusterProvider(): array
    {
        return [
            'alter delete' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'alter table "examples" on cluster \'company_cluster\' delete where "f_int" = ?',
            ],
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'delete from "examples" on cluster \'company_cluster\' where "f_int" = ?',
            ],
            'update' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']),
                'alter table "examples" on cluster \'company_cluster\' update "f_string" = ? where "f_int" = ?',
            ],
            'truncate' => [
                [],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileTruncate($query),
                ['truncate table "examples" on cluster \'company_cluster\'' => []],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): mixed $compile
     */
    #[DataProvider('defaultClusterProvider')]
    public function test_the_default_cluster_of_the_connection(array $config, Closure $compile, mixed $sql): void
    {
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection($config, 'company_cluster'));

        $this->assertSame($sql, $compile($grammar, $query));
    }

    public function test_without_a_default_cluster_nothing_changes(): void
    {
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection());

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame('alter table "examples" update "f_string" = ? where "f_int" = ?', $grammar->compileUpdate($query, ['f_string' => 'x']));
        $this->assertSame(['truncate table "examples"' => []], $grammar->compileTruncate($query));
    }

    public function test_truncate_writes_the_table_without_its_alias(): void
    {
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection(), 'db.examples as e');

        $this->assertSame(['truncate table "db"."examples"' => []], $grammar->compileTruncate($query));
    }

    public function test_a_cluster_name_with_a_quote_is_written_as_a_string_literal(): void
    {
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection([], "it's"));

        $this->assertSame('alter table "examples" on cluster \'it\\\'s\' delete where "f_int" = ?', $grammar->compileDelete($query));
    }

    /**
     * On a connection with use_on_cluster, the default cluster is left out inside the connection's withoutOnCluster()
     * callback.
     */
    public function test_the_connection_without_on_cluster_leaves_the_default_cluster_out(): void
    {
        $connection = new class(null, 'db', '', ['name' => 'clickhouse', 'use_on_cluster' => true, 'cluster_name' => 'company_cluster']) extends Connection {
            public function inSession(): bool
            {
                return false;
            }
        };
        $grammar = $connection->getQueryGrammar();
        $query = fn (): Builder => $connection->table('examples')->where('f_int', 1);

        $this->assertSame('alter table "examples" on cluster \'company_cluster\' delete where "f_int" = ?', $grammar->compileDelete($query()));
        $this->assertSame(
            ['alter table "examples" delete where "f_int" = ?', ['truncate table "examples"' => []]],
            $connection->withoutOnCluster(fn () => [$grammar->compileDelete($query()), $grammar->compileTruncate($query())])
        );
    }

    /**
     * Inside a session, a lightweight delete of a name that a temporary table of the session has is refused, and so
     * is any statement with ON CLUSTER.
     *
     * @return array<string, array{array<string, mixed>, string|null, Closure(QueryGrammar, Builder): mixed, string}>
     */
    public static function statementThatMissesATemporaryTableProvider(): array
    {
        $withoutOnCluster = "Run it in the connection's withoutOnCluster() callback, so that it is sent without ON CLUSTER"
            . ' and reaches the temporary table.';

        return [
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                null,
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'Cannot send DELETE FROM for examples: the session has a temporary table named examples, and ClickHouse'
                . ' (24.8 checked) runs a lightweight DELETE on the table examples of the database instead. Send ALTER'
                . " TABLE ... DELETE, which reaches the temporary table, with the connection's statement(), such as"
                . " statement('ALTER TABLE ... DELETE WHERE ...').",
            ],
            'lightweight delete on a cluster' => [
                ['use_lightweight_delete' => true],
                'company_cluster',
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'Cannot send DELETE FROM ... ON CLUSTER for examples: the session has a temporary table named examples,'
                . ' and ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the session, on the'
                . ' table examples of the database. Send ALTER TABLE ... DELETE without ON CLUSTER, which reaches the'
                . " temporary table, with the connection's statement(), such as statement('ALTER TABLE ... DELETE WHERE"
                . " ...').",
            ],
            'alter delete on a cluster' => [
                [],
                'company_cluster',
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'Cannot send ALTER TABLE ... ON CLUSTER ... DELETE for examples: the session has a temporary table named'
                . ' examples, and ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the'
                . " session, on the table examples of the database. {$withoutOnCluster}",
            ],
            'update on a cluster' => [
                [],
                'company_cluster',
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']),
                'Cannot send ALTER TABLE ... ON CLUSTER ... UPDATE for examples: the session has a temporary table named'
                . ' examples, and ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the'
                . " session, on the table examples of the database. {$withoutOnCluster}",
            ],
            'truncate on a cluster' => [
                [],
                'company_cluster',
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileTruncate($query),
                'Cannot send TRUNCATE TABLE ... ON CLUSTER for examples: the session has a temporary table named'
                . ' examples, and ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the'
                . " session, on the table examples of the database. {$withoutOnCluster}",
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): mixed $compile
     */
    #[DataProvider('statementThatMissesATemporaryTableProvider')]
    public function test_a_statement_that_misses_a_temporary_table_of_the_session_is_refused(
        array $config,
        ?string $cluster,
        Closure $compile,
        string $message
    ): void {
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection($config, $cluster, true));

        try {
            $compile($grammar, $query);
            $this->fail('The statement should throw');
        } catch (QueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * ALTER TABLE ... DELETE and UPDATE and TRUNCATE TABLE without ON CLUSTER reach the temporary table, so the session
     * is not asked; neither is it for a name with a database, nor while the connection pretends.
     */
    public function test_a_statement_that_reaches_the_temporary_table_is_not_checked(): void
    {
        $connection = $this->mutationConnection();
        $connection->expects($this->never())->method('hasTemporaryTable');
        [$grammar, $query] = $this->grammarAndQueryOn($connection);

        $this->assertSame('alter table "examples" delete where "f_int" = ?', $grammar->compileDelete($query));
        $this->assertSame('alter table "examples" update "f_string" = ? where "f_int" = ?', $grammar->compileUpdate($query, ['f_string' => 'x']));
        $this->assertSame(['truncate table "examples"' => []], $grammar->compileTruncate($query));

        $qualified = $this->mutationConnection(['use_lightweight_delete' => true], 'company_cluster');
        $qualified->expects($this->never())->method('hasTemporaryTable');
        [$grammar, $query] = $this->grammarAndQueryOn($qualified, 'db.examples');
        $this->assertSame('delete from "db"."examples" on cluster \'company_cluster\' where "f_int" = ?', $grammar->compileDelete($query));

        $pretending = $this->mutationConnection(['use_lightweight_delete' => true], null, true, true);
        $pretending->expects($this->never())->method('hasTemporaryTable');
        [$grammar, $query] = $this->grammarAndQueryOn($pretending);
        $this->assertSame('delete from "examples" where "f_int" = ?', $grammar->compileDelete($query));
    }

    /**
     * A lightweight delete with a set operation in its conditions fails on ClickHouse (24.8 checked) and leaves a
     * mutation that blocks the table, so it is refused; ALTER TABLE ... DELETE is compiled.
     */
    public function test_a_lightweight_delete_with_a_set_operation_is_refused(): void
    {
        $union = fn (Builder $query): Builder => $query->whereIn(
            'f_int2',
            fn (Builder $sub) => $sub->select('id')->from('u')->unionAll(fn (Builder $other) => $other->select('id')->from('v'))
        );
        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection(['use_lightweight_delete' => true]));

        try {
            $grammar->compileDelete($union($query));
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                'Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION, INTERSECT or'
                . ' EXCEPT: ClickHouse (24.8 checked) fails such a delete and leaves its mutation behind, which blocks'
                . ' every later mutation of the table until KILL MUTATION. Nothing was sent. Send ALTER TABLE ... DELETE'
                . " with the same condition, with the connection's statement() or the package builder's delete(false), or"
                . " select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
                $exception->getMessage()
            );
        }

        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection());
        $this->assertStringStartsWith(
            'alter table "examples" delete where "f_int" = ? and "f_int2" in ((select "id" from "u") union all (select "id" from "v"))',
            $grammar->compileDelete($union($query))
        );

        [$grammar, $query] = $this->grammarAndQueryOn($this->mutationConnection(['use_lightweight_delete' => true]));
        $this->assertSame(
            'delete from "examples" where "f_int" = ? and "f_string" = ? and "union" = ?',
            $grammar->compileDelete($query->where('f_string', 'a union b')->where('union', 1))
        );
    }

    /**
     * A mutation whose conditions hold a sub-query is checked with explain plan through the connection's select(),
     * with the where bindings, and refused when ClickHouse refuses the condition in a SELECT.
     *
     * @return array<string, array{array<string, mixed>, Closure(QueryGrammar, Builder): mixed, string}>
     */
    public static function mutationOfACorrelatedSubQueryProvider(): array
    {
        return [
            'alter delete' => [[], fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query), 'delete'],
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'delete',
            ],
            'update' => [[], fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']), 'update'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): mixed $compile
     */
    #[DataProvider('mutationOfACorrelatedSubQueryProvider')]
    public function test_a_mutation_whose_sub_query_clickhouse_cannot_run_is_refused(array $config, Closure $compile, string $statement): void
    {
        $refusal = DatabaseException::fromClickHouse("Resolve identifier 'examples.f_int' from parent scope", 1, 'UNSUPPORTED_METHOD');
        $connection = $this->mutationConnection($config);
        $connection->expects($this->once())
            ->method('select')
            ->with(
                'explain plan select 1 from "examples" where "f_int" = ? and exists (select * from "u" where "v" = ? and "u"."id" = "examples"."f_int")'
                    . "\nsettings use_index_for_in_with_subqueries = 0",
                [1, 'p']
            )
            ->willThrowException($refusal);
        [$grammar, $query] = $this->grammarAndQueryOn($connection);
        $query->whereExists(fn (Builder $sub) => $sub->from('u')->where('v', 'p')->whereColumn('u.id', 'examples.f_int'));

        try {
            $compile($grammar, $query);
            $this->fail('The mutation should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot {$statement} with a where condition that ClickHouse cannot run: it refused the condition in a"
                . " SELECT with UNSUPPORTED_METHOD. A sub-query that reads a column of the table of the {$statement},"
                . ' such as whereExists() with whereColumn() on that table, is such a condition: ClickHouse (24.8'
                . ' checked) accepts the mutation and then fails it again and again, which blocks every later'
                . " mutation of the table until KILL MUTATION. Nothing was sent. Select the keys first, and {$statement}"
                . " by them instead: whereIn('id', \$query->pluck('id')).",
                $exception->getMessage()
            );
            $this->assertSame($refusal, $exception->getPrevious());
        }
    }

    /**
     * Without a sub-query, and while the connection pretends, no explain is sent.
     */
    public function test_no_explain_is_sent_without_a_sub_query_or_while_pretending(): void
    {
        $connection = $this->mutationConnection();
        $connection->expects($this->never())->method('select');
        [$grammar, $query] = $this->grammarAndQueryOn($connection);
        $this->assertSame(
            'alter table "examples" delete where "f_int" = ? and "f_string" = ?',
            $grammar->compileDelete($query->where('f_string', 'select 1'))
        );

        $pretending = $this->mutationConnection([], null, false, true);
        $pretending->expects($this->never())->method('select');
        [$grammar, $query] = $this->grammarAndQueryOn($pretending);
        $this->assertSame(
            'alter table "examples" delete where "f_int" = ? and exists (select * from "u" where "u"."id" = "examples"."f_int")',
            $grammar->compileDelete($query->whereExists(fn (Builder $sub) => $sub->from('u')->whereColumn('u.id', 'examples.f_int')))
        );
    }

    /**
     * in followed by a list, also after spaces, and in inside a value or a name, need no explain.
     */
    public function test_no_explain_is_sent_for_in_with_a_list(): void
    {
        $connection = $this->mutationConnection();
        $connection->expects($this->never())->method('select');
        [$grammar, $query] = $this->grammarAndQueryOn($connection);

        $this->assertSame(
            'alter table "examples" delete where "f_int" = ? and "f_int2" in (?, ?) and "f_int2" not in (?) and f_int in  (1)'
            . ' and "f_string" = ? and "in" = ?',
            $grammar->compileDelete(
                $query->whereIn('f_int2', [1, 2])->whereNotIn('f_int2', [3])->whereRaw('f_int in  (1)')
                    ->where('f_string', 'a in b')->where('in', 1)
            )
        );
    }

    /**
     * in followed by a table names a table that ClickHouse resolves only when the mutation runs, as a sub-query does,
     * so the mutation is checked with explain as well.
     */
    public function test_a_mutation_by_in_a_table_is_refused_when_clickhouse_cannot_run_it(): void
    {
        $refusal = DatabaseException::fromClickHouse("Unknown expression or table expression identifier 'ids'", 47, 'UNKNOWN_IDENTIFIER');
        $connection = $this->mutationConnection();
        $connection->expects($this->once())
            ->method('select')
            ->with("explain plan select 1 from \"examples\" where \"f_int\" = ? and f_int2 in ids\nsettings use_index_for_in_with_subqueries = 0", [1])
            ->willThrowException($refusal);
        [$grammar, $query] = $this->grammarAndQueryOn($connection);

        try {
            $grammar->compileDelete($query->whereRaw('f_int2 in ids'));
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                'Cannot delete with a where condition that ClickHouse cannot run: it refused the condition in a SELECT'
                . ' with UNKNOWN_IDENTIFIER. ',
                $exception->getMessage()
            );
            $this->assertSame($refusal, $exception->getPrevious());
        }
    }

    /**
     * A connection in the session sid without a server, whose client's requests the given curler answers.
     *
     * @param GrammarSessionCurler $curler
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function sessionConnection(GrammarSessionCurler $curler, array $config = []): Connection
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '8123', 'username' => 'default', 'password' => '']);
        $client->settings()->set('session_id', 'sid');
        $client->settings()->set('session_timeout', 60);
        $client->settings()->set('session_check', 1);
        $client->transport()->setDirtyCurler($curler);

        $connection = new class(null, 'db', '', $config + ['name' => 'clickhouse']) extends Connection {
            public Client $testClient;

            public function getClient(): Client
            {
                return $this->testClient;
            }
        };
        $connection->testClient = $client;

        return $connection;
    }

    /**
     * A curler for a session whose temporary table ids holds keys, and which may have a temporary table named
     * examples: EXISTS TEMPORARY TABLE answers whether it does, an explain that reads ids fails outside the session
     * with UNKNOWN_TABLE, as ClickHouse (24.8 checked) answers it, and any other request succeeds.
     *
     * @param bool $examplesIsATemporaryTable
     * @return GrammarSessionCurler
     */
    private function curlerOfASessionWithKeys(bool $examplesIsATemporaryTable): GrammarSessionCurler
    {
        return new GrammarSessionCurler(function (string $sql, bool $inSession) use ($examplesIsATemporaryTable): array {
            if (str_starts_with($sql, 'EXISTS TEMPORARY TABLE')) {
                return [200, '{"meta":[{"name":"result","type":"UInt8"}],"data":[{"result":' . (int) $examplesIsATemporaryTable . '}],"rows":1}'];
            }
            if (str_starts_with($sql, 'explain') && !$inSession && str_contains($sql, '"ids"')) {
                return [404, "Code: 60. DB::Exception: Unknown table expression identifier 'ids' in scope (SELECT id FROM ids). (UNKNOWN_TABLE) (version 24.8.14.39 (official build))\n"];
            }

            return [200, '{"meta":[{"name":"explain","type":"String"}],"data":[{"explain":"Expression"}],"rows":1}'];
        });
    }

    /**
     * In a session, ClickHouse (24.8 checked) runs a mutation of a table of the database in the background, outside
     * the session, where a temporary table that its condition reads does not exist. The explain of such a mutation is
     * sent outside the session: for a lightweight delete, a statement with ON CLUSTER, and an alter of a name that is
     * not a temporary table of the session.
     *
     * @return array<string, array{array<string, mixed>, Closure(QueryGrammar, Builder): mixed, string}>
     */
    public static function laravelMutationOfADatabaseTableInASessionProvider(): array
    {
        return [
            'alter delete' => [[], fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query), 'delete'],
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileDelete($query),
                'delete',
            ],
            'update' => [[], fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']), 'update'],
            'update on the default cluster' => [
                ['use_on_cluster' => true, 'cluster_name' => 'company_cluster'],
                fn (QueryGrammar $grammar, Builder $query) => $grammar->compileUpdate($query, ['f_string' => 'x']),
                'update',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(QueryGrammar, Builder): mixed $compile
     */
    #[DataProvider('laravelMutationOfADatabaseTableInASessionProvider')]
    public function test_in_a_session_a_mutation_of_a_database_table_is_explained_outside_the_session(
        array $config,
        Closure $compile,
        string $statement
    ): void {
        $curler = $this->curlerOfASessionWithKeys(false);
        $connection = $this->sessionConnection($curler, $config);
        $query = $connection->table('examples')->whereIn('f_int', fn (Builder $sub) => $sub->select('id')->from('ids'));

        try {
            $compile($connection->getQueryGrammar(), $query);
            $this->fail('The mutation should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot {$statement} with a where condition that ClickHouse cannot run: it refused the condition in a"
                . ' SELECT outside the session with UNKNOWN_TABLE. ClickHouse (24.8 checked) runs the mutation outside'
                . ' the session, where the temporary tables of the session do not exist, so a condition that reads one'
                . " is such a condition, and so is a sub-query that reads a column of the table of the {$statement}:"
                . ' ClickHouse accepts the mutation and then fails it again and again, which blocks every later'
                . " mutation of the table until KILL MUTATION. Nothing was sent. Select the keys first, and {$statement}"
                . " by them instead: whereIn('id', \$query->pluck('id')).",
                $exception->getMessage()
            );
        }

        $this->assertSame(
            [
                ['EXISTS TEMPORARY TABLE `examples` FORMAT JSON', true],
                ["explain plan select 1 from \"examples\" where \"f_int\" in (select \"id\" from \"ids\")\nsettings use_index_for_in_with_subqueries = 0", false],
            ],
            $curler->requests
        );
    }

    /**
     * The explain that is sent outside the session runs as the connection's select() runs a query: the bindings are
     * written into its SQL, and it is logged with them when it succeeds.
     */
    public function test_an_explain_outside_the_session_is_logged_with_its_bindings(): void
    {
        $curler = $this->curlerOfASessionWithKeys(false);
        $connection = $this->sessionConnection($curler);
        $connection->enableQueryLog();
        $query = $connection->table('examples')->where('f_int', 1)
            ->whereIn('f_int2', fn (Builder $sub) => $sub->select('id')->from('u')->where('v', 'p'));

        $this->assertSame(
            'alter table "examples" delete where "f_int" = ? and "f_int2" in (select "id" from "u" where "v" = ?)',
            $connection->getQueryGrammar()->compileDelete($query)
        );

        $explain = "explain plan select 1 from \"examples\" where \"f_int\" = ? and \"f_int2\" in (select \"id\" from \"u\" where \"v\" = ?)\n"
            . 'settings use_index_for_in_with_subqueries = 0';
        $this->assertSame(
            [['EXISTS TEMPORARY TABLE `examples`', []], [$explain, [1, 'p']]],
            array_map(fn (array $logged): array => [$logged['query'], $logged['bindings']], $connection->getQueryLog())
        );
        $this->assertSame(
            ["explain plan select 1 from \"examples\" where \"f_int\" = 1 and \"f_int2\" in (select \"id\" from \"u\" where \"v\" = 'p')\n"
                . 'settings use_index_for_in_with_subqueries = 0', false],
            end($curler->requests)
        );
    }

    /**
     * An alter of a temporary table of the session runs in the session, which knows the temporary table that the
     * condition reads, so its explain goes through the connection's select(), in the session.
     */
    public function test_in_a_session_a_mutation_of_a_temporary_table_is_explained_in_the_session(): void
    {
        $curler = $this->curlerOfASessionWithKeys(true);
        $connection = $this->sessionConnection($curler);
        $query = $connection->table('examples')->whereIn('f_int', fn (Builder $sub) => $sub->select('id')->from('ids'));

        $this->assertSame(
            'alter table "examples" delete where "f_int" in (select "id" from "ids")',
            $connection->getQueryGrammar()->compileDelete($query)
        );
        $this->assertSame(
            [
                ['EXISTS TEMPORARY TABLE `examples` FORMAT JSON', true],
                ["explain plan select 1 from \"examples\" where \"f_int\" in (select \"id\" from \"ids\")\nsettings use_index_for_in_with_subqueries = 0 FORMAT JSON", true],
            ],
            $curler->requests
        );
    }
}

/**
 * A curler that answers the requests of a smi2 client without a server, and records the SQL of each request and
 * whether its URL carries a session id.
 */
final class GrammarSessionCurler extends CurlerRolling
{
    /**
     * @var list<array{string, bool}>
     */
    public array $requests = [];

    /**
     * @param Closure(string, bool): array{int, string} $answer The HTTP code and the body of the response to the SQL of
     *                                                         a request, and whether the request carries the session
     */
    public function __construct(private readonly Closure $answer)
    {
    }

    public function execOne(CurlerRequest $request, bool $auto_close = false): int
    {
        $sql = (string) $request->getRequestExtendedInfo('sql');
        $parameters = [];
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $parameters);
        $inSession = isset($parameters['session_id']);
        $this->requests[] = [$sql, $inSession];

        [$code, $body] = ($this->answer)($sql, $inSession);
        $response = new CurlerResponse();
        $response->_info = [
            'http_code' => $code,
            'content_type' => str_starts_with($body, '{') ? 'application/json; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'total_time' => 0.0,
        ];
        $response->_body = $body;
        $response->_headers = str_starts_with($body, '{') ? ['X-ClickHouse-Format' => 'JSON'] : [];
        $request->setResponse($response);

        return $code;
    }
}
