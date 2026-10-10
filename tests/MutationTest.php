<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Closure;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;

use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

class MutationRow extends BaseModel
{
    protected $table = 'mutation_rows';
}

class MutationOrderAliasRow extends BaseModel
{
    protected $table = 'mutation_order_aliases';
}

class MutationTest extends TestCase
{
    private const TABLE = 'mutation_rows';

    /**
     * A table whose name is not a plain identifier, so it only works quoted.
     */
    private const QUOTED_TABLE = 'mutation-rows';

    /**
     * First ClickHouse release that accepts DELETE FROM ... IN PARTITION (ClickHouse PR #67805).
     */
    private const LIGHTWEIGHT_DELETE_IN_PARTITION_SINCE = '24.9';

    private const LARAVEL_GRAMMAR_CONNECTION = 'clickhouse-laravel-grammar';

    /**
     * A table to join with: it holds the ids 2 and 4.
     */
    private const KEYS_TABLE = 'mutation_keys';

    /**
     * A SummingMergeTree table, whose rows FINAL merges.
     */
    private const SUMMING_TABLE = 'mutation_sums';

    /**
     * A table of the columns a and b, which holds ORDER_ALIAS_ROWS.
     */
    private const ORDER_ALIAS_TABLE = 'mutation_order_aliases';

    /**
     * The rows (a, b) of ORDER_ALIAS_TABLE: the condition b < 5 matches the row (10, 1)
     * when it reads the column b, and the rows 1, 2 and 3 when it reads an alias b of
     * the column a.
     */
    private const ORDER_ALIAS_ROWS = [[1, 10], [2, 20], [3, 30], [10, 1]];

    /**
     * A table of the columns a, b and arr, which holds ORDER_FUNCTION_ROWS.
     */
    private const ORDER_FUNCTION_TABLE = 'mutation_order_functions';

    /**
     * The rows (a, b, arr) of ORDER_FUNCTION_TABLE: ORDER_ALIAS_ROWS with an array, which is empty for a = 2 and has
     * two elements for a = 3, so that arrayJoin(arr) drops the row 2 and repeats the row 3.
     */
    private const ORDER_FUNCTION_ROWS = [[1, 10, [1]], [2, 20, []], [3, 30, [1, 2]], [10, 1, [5]]];

    /**
     * Create TABLE with four rows in two partitions. The tags of id 2 are empty and id 3
     * has two, so an ORDER BY that calls arrayJoin(tags) drops id 2 and repeats id 3.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $client = $this->client();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, d Date, v String, tags Array(String))'
            . ' ENGINE = MergeTree PARTITION BY toYYYYMM(d) ORDER BY id'
        );
        $client->insert(self::TABLE, [
            [1, '2024-01-10', 'a', ['x']],
            [2, '2024-01-20', 'b', []],
            [3, '2024-02-10', 'c', ['x', 'y']],
            [4, '2024-02-20', 'd', ['x']],
        ], ['id', 'd', 'v', 'tags']);
    }

    protected function tearDown(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write('DROP TABLE IF EXISTS `' . self::QUOTED_TABLE . '` SYNC');
        $this->client()->write('DROP TABLE IF EXISTS ' . self::KEYS_TABLE . ' SYNC');
        $this->client()->write('DROP TABLE IF EXISTS ' . self::SUMMING_TABLE . ' SYNC');
        $this->client()->write('DROP TABLE IF EXISTS ' . self::ORDER_ALIAS_TABLE . ' SYNC');
        $this->client()->write('DROP TABLE IF EXISTS ' . self::ORDER_FUNCTION_TABLE . ' SYNC');
        DB::purge(self::LARAVEL_GRAMMAR_CONNECTION);
        parent::tearDown();
    }

    public function testLightweightDeleteHidesTheRowsBeforeItReturns(): void
    {
        $this->table()->where('id', 1)->delete(true);

        $this->assertSame([2, 3, 4], $this->ids(), 'rows must be gone without waiting for a mutation');
        $this->assertStringStartsWith('UPDATE _row_exists = 0', $this->lastMutationCommand());
    }

    public function testModelLightweightDelete(): void
    {
        MutationRow::where('id', 2)->delete(true);

        $this->assertSame([1, 3, 4], $this->ids());
    }

    public function testAlterDeleteIsAMutation(): void
    {
        $this->table()->where('id', 1)->delete(false);
        $this->waitForMutations();

        $this->assertSame([2, 3, 4], $this->ids());
        $this->assertStringStartsWith('DELETE WHERE', $this->lastMutationCommand());
    }

    /**
     * @return array<string, array{int|string|Expression, int[]}>
     */
    public static function partitionProvider(): array
    {
        return [
            'int' => [202401, [3, 4]],
            'string' => ['202402', [1, 2]],
            'partition ID' => [new RawColumn("ID '202401'"), [3, 4]],
        ];
    }

    /**
     * @param int|string|Expression $partition
     * @param int[] $expectedIds
     */
    #[DataProvider('partitionProvider')]
    public function testAlterDeleteInPartitionOnlyTouchesThatPartition(int|string|Expression $partition, array $expectedIds): void
    {
        $this->table()->where('id', '>', 0)->delete(false, $partition);
        $this->waitForMutations();

        $this->assertSame($expectedIds, $this->ids());
    }

    public function testUpdateInPartitionOnlyTouchesThatPartition(): void
    {
        $this->table()->where('id', '>', 0)->update(['v' => 'x'], '202402');
        $this->waitForMutations();

        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'x'], [4, 'x']], $this->rows());
    }

    public function testModelUpdateInPartition(): void
    {
        MutationRow::where('v', '!=', 'b')->update(['v' => new RawColumn("concat(v, '!')")], 202401);
        $this->waitForMutations();

        $this->assertSame([[1, 'a!'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
    }

    public function testUpdateWritesAnArrayValueAsAnArrayLiteral(): void
    {
        $this->table()->where('id', 2)->update(['tags' => ['x', "it's"]]);
        $this->table()->where('id', 3)->update(['tags' => []]);
        $this->waitForMutations();

        $this->assertSame(
            [[1, ['x']], [2, ['x', "it's"]], [3, []], [4, ['x']]],
            array_map(
                fn (array $row): array => [(int) $row['id'], $row['tags']],
                $this->client()->select('SELECT id, tags FROM ' . self::TABLE . ' ORDER BY id')->rows()
            )
        );
    }

    /**
     * Mutations have no PREWHERE, so the prewhere condition becomes part of the
     * WHERE clause, and the mutation touches the rows the query selects: id 2.
     *
     * @return array<string, array{Closure(Builder): mixed, array<int, array{int, string}>}>
     */
    public static function preWhereMutationProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->delete(false), [[1, 'a'], [3, 'c'], [4, 'd']]],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), [[1, 'a'], [3, 'c'], [4, 'd']]],
            'update' => [fn (Builder $query) => $query->update(['v' => 'x']), [[1, 'a'], [2, 'x'], [3, 'c'], [4, 'd']]],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     * @param array<int, array{int, string}> $expectedRows
     */
    #[DataProvider('preWhereMutationProvider')]
    public function testMutationTouchesOnlyTheRowsThePrewhereAndWhereSelect(Closure $mutation, array $expectedRows): void
    {
        $query = fn (): Builder => $this->table()->preWhere('d', '<', '2024-02-01')->where('v', '!=', 'a');
        $this->assertSame([2], array_map('intval', array_column($query()->select('id')->getRows(), 'id')), 'the query selects id 2');

        $mutation($query());
        $this->waitForMutations();

        $this->assertSame($expectedRows, $this->rows());
    }

    public function testMutationWithOnlyAPrewhere(): void
    {
        $this->table()->preWhere('d', '<', '2024-02-01')->delete(false);
        $this->waitForMutations();

        $this->assertSame([3, 4], $this->ids());
    }

    /**
     * Queries whose clauses decide which rows they select, but that a mutation
     * would ignore: it would delete or update every row with an id above 0 or 2,
     * or, for the aliases, the rows whose column v is 'c' instead of the rows
     * whose alias v is 'c'. An ORDER BY that calls arrayJoin() drops id 2, whose
     * tags are empty, and repeats id 3, which has two.
     *
     * @return array<string, array{Closure(Builder): Builder, int[], string}>
     */
    public static function queryWithIgnoredClausesProvider(): array
    {
        return [
            'select alias that shadows a column' => [
                fn (Builder $query): Builder => $query->select('id', new RawColumn("if(id > 2, 'c', 'z')", 'v'))->where('v', 'c'),
                [3, 4],
                'SELECT aliases or expressions',
            ],
            'raw select column that defines an alias without as' => [
                fn (Builder $query): Builder => $query->select('id', new RawColumn("if(id > 2, 'c', 'z') v"))->where('v', 'c'),
                [3, 4],
                'SELECT aliases or expressions',
            ],
            'from sub-query' => [
                fn (Builder $query): Builder => $query
                    ->setSourcesTable(self::TABLE)
                    ->from($query->newQuery()->from(self::TABLE)->where('id', '<', 4))
                    ->where('id', '>', 2),
                [3],
                'a FROM sub-query',
            ],
            'setting that filters the rows' => [
                fn (Builder $query): Builder => $query
                    ->select('id')
                    ->where('id', '>', 0)
                    ->settings(['additional_table_filters' => new RawColumn("{'" . self::TABLE . "': 'id > 2'}")]),
                [3, 4],
                'SETTINGS',
            ],
            'limit setting' => [
                fn (Builder $query): Builder => $query->select('id')->where('id', '>', 0)->orderBy('id')->settings(['limit' => 1]),
                [1],
                'SETTINGS',
            ],
            'inner join' => [
                fn (Builder $query): Builder => $query->select('id')->allInnerJoin(self::KEYS_TABLE, ['id'])->where('id', '>', 0),
                [2, 4],
                'JOIN',
            ],
            'semi join' => [
                fn (Builder $query): Builder => $query->select('id')->semiLeftJoin(self::KEYS_TABLE, ['id'])->where('id', '>', 0),
                [2, 4],
                'JOIN',
            ],
            'anti join' => [
                fn (Builder $query): Builder => $query->select('id')->antiLeftJoin(self::KEYS_TABLE, ['id'])->where('id', '>', 0),
                [1, 3],
                'JOIN',
            ],
            'order by arrayJoin()' => [
                fn (Builder $query): Builder => $query->select('id')->where('id', '>', 0)->orderByRaw('arrayJoin(tags)'),
                [1, 3, 3, 4],
                'ORDER BY',
            ],
            'order by an expression that calls arrayJoin()' => [
                fn (Builder $query): Builder => $query->select('id')->where('id', '>', 0)->orderBy(new RawColumn('arrayJoin(tags)'), 'desc'),
                [1, 3, 3, 4],
                'ORDER BY',
            ],
            'model query ordered by arrayJoin()' => [
                fn (Builder $query): Builder => MutationRow::where('id', '>', 0)->orderByRaw('arrayJoin(tags)'),
                [1, 3, 3, 4],
                'ORDER BY',
            ],
            'limit' => [
                fn (Builder $query): Builder => $query->select('id')->where('id', '>', 0)->orderBy('id')->limit(1),
                [1],
                'LIMIT',
            ],
            'with alias that shadows a column' => [
                fn (Builder $query): Builder => $query->select('id')->withAlias('v', new RawColumn("if(id > 2, 'c', 'z')"))->where('v', 'c'),
                [3, 4],
                'WITH',
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function mutationProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->delete(false), 'delete'],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), 'delete'],
            'update' => [fn (Builder $query) => $query->update(['v' => 'x']), 'update'],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, int[], string, Closure(Builder): mixed, string}>
     */
    public static function mutationOfAQueryWithIgnoredClausesProvider(): array
    {
        $dataSets = [];
        foreach (self::queryWithIgnoredClausesProvider() as $queryLabel => [$query, $selectedIds, $clause]) {
            foreach (self::mutationProvider() as $mutationLabel => [$mutation, $statement]) {
                $dataSets["{$queryLabel}, {$mutationLabel}"] = [$query, $selectedIds, $clause, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param int[] $selectedIds
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfAQueryWithIgnoredClausesProvider')]
    public function testAMutationOfAQueryWithClausesItWouldIgnoreThrowsAndChangesNothing(
        Closure $query,
        array $selectedIds,
        string $clause,
        Closure $mutation,
        string $statement
    ): void {
        $this->createKeysTable();
        $this->assertSame($selectedIds, $this->selectedIds($query($this->table())), 'the rows the query selects');

        try {
            $mutation($query($this->table()));
            $this->fail('A mutation of a query that uses ' . $clause . ' should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses {$clause}: ", $exception->getMessage());
        }

        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
        $this->assertSame([], $this->mutationCommands(), 'nothing is sent');
    }

    /**
     * Queries of ORDER_ALIAS_TABLE whose ORDER BY defines the alias b of the column a.
     * ClickHouse lets the condition b < 5 read that alias, so they select the rows 1, 2
     * and 3, while a mutation, which only takes the condition, would read the column b
     * and change the row (10, 1) instead.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function queryThatReadsAnOrderByAliasProvider(): array
    {
        return [
            'order by a name with as' => [fn (Builder $query): Builder => $query->select('a')->where('b', '<', 5)->orderBy('a as b')],
            'raw order by with AS' => [fn (Builder $query): Builder => $query->select('a')->where('b', '<', 5)->orderByRaw('a AS b')],
            'model query ordered by a name with as' => [
                fn (Builder $query): Builder => MutationOrderAliasRow::where('b', '<', 5)->orderBy('a as b'),
            ],
            'model query with a raw order by with AS' => [
                fn (Builder $query): Builder => MutationOrderAliasRow::where('b', '<', 5)->orderByRaw('a AS b'),
            ],
        ];
    }

    /**
     * The mutations of ORDER_ALIAS_TABLE: the update sets the column b.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function orderAliasMutationProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->delete(false), 'delete'],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), 'delete'],
            'update' => [fn (Builder $query) => $query->update(['b' => 99]), 'update'],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, Closure(Builder): mixed, string}>
     */
    public static function mutationOfAQueryThatReadsAnOrderByAliasProvider(): array
    {
        $dataSets = [];
        foreach (self::queryThatReadsAnOrderByAliasProvider() as $queryLabel => [$query]) {
            foreach (self::orderAliasMutationProvider() as $mutationLabel => [$mutation, $statement]) {
                $dataSets["{$queryLabel}, {$mutationLabel}"] = [$query, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfAQueryThatReadsAnOrderByAliasProvider')]
    public function testAMutationOfAQueryWhoseConditionReadsAnOrderByAliasThrowsAndChangesNothing(
        Closure $query,
        Closure $mutation,
        string $statement
    ): void {
        $this->createOrderAliasTable();
        $this->assertSame([1, 2, 3], $this->selectedIds($query($this->orderAliasTable()), 'a'), 'the rows the query selects');

        try {
            $mutation($query($this->orderAliasTable()));
            $this->fail('A mutation of a query whose condition reads an ORDER BY alias should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses ORDER BY: ", $exception->getMessage());
        }

        $this->assertSame(self::ORDER_ALIAS_ROWS, $this->orderAliasRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_ALIAS_TABLE), 'nothing is sent');
    }

    /**
     * FINAL sums the rows of each id before the condition reads them, so the query
     * selects id 2 only, while hits < 6 also matches the first part of id 1: the
     * mutation would change the sum of id 1.
     *
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationProvider')]
    public function testAMutationOfAFinalQueryThrowsAndChangesNothing(Closure $mutation, string $statement): void
    {
        $this->client()->write(
            'CREATE TABLE ' . self::SUMMING_TABLE . ' (month UInt32, id UInt32, hits UInt64, v String)'
            . ' ENGINE = SummingMergeTree(hits) PARTITION BY month ORDER BY id'
        );
        $this->client()->write('INSERT INTO ' . self::SUMMING_TABLE . " VALUES (202401, 1, 5, 'a'), (202401, 2, 3, 'b')");
        $this->client()->write('INSERT INTO ' . self::SUMMING_TABLE . " VALUES (202402, 1, 7, 'a')");
        $sums = fn (): array => array_map(
            fn (array $row): array => [(int) $row['id'], (int) $row['hits']],
            $this->client()->select('SELECT id, hits FROM ' . self::SUMMING_TABLE . ' FINAL ORDER BY id')->rows()
        );
        $query = fn (): Builder => DB::connection('clickhouse')->table(self::SUMMING_TABLE)->final()->where('hits', '<', 6);
        $this->assertSame([[1, 12], [2, 3]], $sums());
        $this->assertSame([2], $this->selectedIds($query()), 'the rows the query selects');

        try {
            $mutation($query());
            $this->fail('A mutation of a query that uses FINAL should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses FINAL: ", $exception->getMessage());
        }

        $this->assertSame([[1, 12], [2, 3]], $sums());
        $this->assertSame([], $this->mutationCommands(self::SUMMING_TABLE), 'nothing is sent');
    }

    /**
     * WITH FILL only adds rows that the table does not have, the ids 5 and 6, so a
     * mutation of a query ordered with it is sent, and it changes the rows of the
     * table that the query selects: 3 and 4.
     *
     * @return array<string, array{Closure(Builder): mixed, array<int, array{int, string}>}>
     */
    public static function mutationOfAQueryOrderedWithFillProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->delete(false), [[1, 'a'], [2, 'b']]],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), [[1, 'a'], [2, 'b']]],
            'update' => [fn (Builder $query) => $query->update(['v' => 'x']), [[1, 'a'], [2, 'b'], [3, 'x'], [4, 'x']]],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     * @param array<int, array{int, string}> $expectedRows
     */
    #[DataProvider('mutationOfAQueryOrderedWithFillProvider')]
    public function testAMutationOfAQueryOrderedWithFillIsSent(Closure $mutation, array $expectedRows): void
    {
        $query = fn (): Builder => $this->table()->select('id')->where('id', '>', 2)->orderByRaw('id WITH FILL TO 7');
        $this->assertSame([3, 4, 5, 6], $this->selectedIds($query()), 'the rows the query selects, with the filled ids 5 and 6');

        $mutation($query());
        $this->waitForMutations();

        $this->assertSame($expectedRows, $this->rows());
    }

    /**
     * Selecting the keys first, as the exception suggests, changes exactly the
     * rows that the query with the JOIN and the LIMIT selects: id 4.
     *
     * A sub-query in the mutation is not used here: ClickHouse can run it again
     * for data parts that it mutates later, after the parts it mutated first have
     * changed, so a sub-query with a LIMIT on the mutated table can change more
     * rows than it selected.
     *
     * @return array<string, array{Closure(Builder, Builder): mixed, array<int, array{int, string}>}>
     */
    public static function mutationByTheSelectedKeysProvider(): array
    {
        $keys = fn (Builder $selection) => $selection->pluck('id');

        return [
            'alter delete' => [
                fn (Builder $table, Builder $selection) => $table->whereIn('id', $keys($selection))->delete(false),
                [[1, 'a'], [2, 'b'], [3, 'c']],
            ],
            'lightweight delete' => [
                fn (Builder $table, Builder $selection) => $table->whereIn('id', $keys($selection))->delete(true),
                [[1, 'a'], [2, 'b'], [3, 'c']],
            ],
            'update' => [
                fn (Builder $table, Builder $selection) => $table->whereIn('id', $keys($selection))->update(['v' => 'x']),
                [[1, 'a'], [2, 'b'], [3, 'c'], [4, 'x']],
            ],
            'model delete' => [
                fn (Builder $table, Builder $selection) => MutationRow::where('id', $keys($selection))->delete(false),
                [[1, 'a'], [2, 'b'], [3, 'c']],
            ],
        ];
    }

    /**
     * @param Closure(Builder, Builder): mixed $mutation
     * @param array<int, array{int, string}> $expectedRows
     */
    #[DataProvider('mutationByTheSelectedKeysProvider')]
    public function testAMutationByTheSelectedKeysChangesExactlyTheSelectedRows(Closure $mutation, array $expectedRows): void
    {
        $this->createKeysTable();
        $selection = fn (): Builder => $this->table()->select('id')->allInnerJoin(self::KEYS_TABLE, ['id'])->orderBy('id', 'desc')->limit(1);
        $this->assertSame([4], $this->selectedIds($selection()), 'the rows the query selects');

        $mutation($this->table(), $selection());
        $this->waitForMutations();

        $this->assertSame($expectedRows, $this->rows());
    }

    /**
     * A sub-query that only joins another table, without a LIMIT, selects a row
     * whatever happens to the other rows, so it is safe in a mutation.
     *
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationProvider')]
    public function testAMutationByASubQueryWithAJoinChangesExactlyTheSelectedRows(Closure $mutation, string $statement): void
    {
        $this->createKeysTable();
        $selection = fn (): Builder => $this->table()->select('id')->allInnerJoin(self::KEYS_TABLE, ['id']);
        $this->assertSame([2, 4], $this->selectedIds($selection()), 'the rows the query selects');

        $mutation($this->table()->whereIn('id', $selection()));
        $this->waitForMutations();

        $this->assertSame(
            $statement === 'update' ? [[1, 'a'], [2, 'x'], [3, 'c'], [4, 'x']] : [[1, 'a'], [3, 'c']],
            $this->rows()
        );
    }

    /**
     * A column name from user input is one identifier: the injected assignments
     * and comment become part of a column name that the table does not have.
     */
    public function testUpdateQuotesEachColumnNameAsOneIdentifier(): void
    {
        $this->client()->write('ALTER TABLE ' . self::TABLE . ' ADD COLUMN `we``ird` String');

        $this->table()->where('id', 1)->update(['we`ird' => 'x']);
        $this->waitForMutations();
        $this->assertSame(
            ['x', '', '', ''],
            array_column($this->client()->select('SELECT `we``ird` FROM ' . self::TABLE . ' ORDER BY id')->rows(), 'we`ird')
        );

        try {
            $this->table()->where('id', 1)->update(["v` = 'x', `d` = '2000-01-01' WHERE 1 -- " => 'ignored']);
            $this->fail('ClickHouse should reject the unknown column');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString("v` = 'x', `d` = '2000-01-01' WHERE 1 --", $exception->getMessage());
        }
        $this->waitForMutations();

        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
        $this->assertSame(
            ['2024-01-10', '2024-01-20', '2024-02-10', '2024-02-20'],
            array_column($this->client()->select('SELECT d FROM ' . self::TABLE . ' ORDER BY id')->rows(), 'd')
        );
    }

    /**
     * The query builder quotes the from() table in mutations and inserts, so a
     * database-qualified name and a name that needs quoting both work.
     */
    public function testWritesToADatabaseQualifiedTableWhoseNameNeedsQuoting(): void
    {
        $connection = DB::connection('clickhouse');
        $this->client()->write(
            'CREATE TABLE `' . self::QUOTED_TABLE . '` (id UInt32, v String, tags Array(String)) ENGINE = MergeTree ORDER BY id'
        );
        $table = fn (): Builder => $connection->table($connection->getDatabaseName() . '.' . self::QUOTED_TABLE);
        $rows = fn (): array => array_map(
            fn (array $row): array => [(int) $row['id'], $row['v'], $row['tags']],
            $this->client()->select('SELECT id, v, tags FROM `' . self::QUOTED_TABLE . '` ORDER BY id')->rows()
        );

        $connection->table(self::QUOTED_TABLE)->insert([['id' => 1, 'v' => 'a', 'tags' => []]]);
        $table()->insert([['id' => 2, 'v' => 'b', 'tags' => ['x']], ['id' => 3, 'v' => 'c', 'tags' => []]]);
        $this->assertSame([[1, 'a', []], [2, 'b', ['x']], [3, 'c', []]], $rows());

        $table()->where('id', 1)->delete(true);
        $table()->where('id', 2)->update(['v' => 'x', 'tags' => ['y', 'z']]);
        $this->waitForMutations(self::QUOTED_TABLE);
        $this->assertSame([[2, 'x', ['y', 'z']], [3, 'c', []]], $rows());

        $table()->where('id', 3)->delete(false);
        $this->waitForMutations(self::QUOTED_TABLE);
        $this->assertSame([[2, 'x', ['y', 'z']]], $rows());

        $this->assertTrue($table()->exists());
        $table()->truncate();
        $this->assertSame([], $rows());
        $this->assertFalse($table()->exists());
    }

    public function testInsertThroughTheQueryBuilder(): void
    {
        $connection = DB::connection('clickhouse');

        $connection->table(self::TABLE)->insert([['id' => 5, 'd' => '2024-03-01', 'v' => 'e']]);
        $connection->table($connection->getDatabaseName() . '.' . self::TABLE)
            ->insert([['id' => 6, 'd' => '2024-03-02', 'v' => 'f']]);

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->ids());
    }

    /**
     * ClickHouse before LIGHTWEIGHT_DELETE_IN_PARTITION_SINCE, 24.8 included, has no
     * IN PARTITION for lightweight deletes and answers with a syntax error; 24.9 and
     * later delete in that partition only.
     */
    public function testLightweightDeleteInPartitionFollowsTheServerVersion(): void
    {
        if ($this->serverSupportsLightweightDeleteInPartition()) {
            $this->table()->where('id', '>', 0)->delete(true, 202401);

            $this->assertSame([3, 4], $this->ids());

            return;
        }

        try {
            $this->table()->where('id', '>', 0)->delete(true, 202401);
            $this->fail('DELETE FROM ... IN PARTITION should be rejected by ClickHouse ' . $this->serverVersion());
        } catch (DatabaseException $e) {
            $this->assertSame('SYNTAX_ERROR', $e->getClickHouseExceptionName());
        }
        $this->assertSame([1, 2, 3, 4], $this->ids());
    }

    public function testTruncate(): void
    {
        $this->table()->truncate();

        $this->assertSame([], $this->ids());
    }

    /**
     * Mirrors Laravel's DatabaseTruncation, which calls exists() and then truncate()
     * on $connection->table($schemaQualifiedName).
     */
    public function testExistsAndTruncateWorkLikeDatabaseTruncationCallsThem(): void
    {
        $connection = DB::connection('clickhouse');
        $schemaQualifiedName = $connection->getDatabaseName() . '.' . self::TABLE;

        $connection->withoutTablePrefix(function ($connection) use ($schemaQualifiedName) {
            $table = $connection->table($schemaQualifiedName);

            if ($table->exists()) {
                $table->truncate();
            }
        });

        $this->assertSame([], $this->ids());
        $this->assertFalse($connection->table($schemaQualifiedName)->exists());
    }

    public function testUseLightweightDeleteConfigDecidesWhenTheArgumentIsOmitted(): void
    {
        $original = config('database.connections.clickhouse');

        try {
            $this->reconnect('clickhouse', ['use_lightweight_delete' => true]);
            MutationRow::where('id', 1)->delete();

            $this->assertSame([2, 3, 4], $this->ids());
            $this->assertStringStartsWith('UPDATE _row_exists = 0', $this->lastMutationCommand());

            $this->table()->where('id', 2)->delete(false);
            $this->waitForMutations();

            $this->assertStringStartsWith('DELETE WHERE', $this->lastMutationCommand(), 'an explicit false wins over the config');

            $this->reconnect('clickhouse', ['use_lightweight_delete' => false]);
            MutationRow::where('id', 3)->delete();
            $this->waitForMutations();

            $this->assertSame([4], $this->ids());
            $this->assertStringStartsWith('DELETE WHERE', $this->lastMutationCommand());
        } finally {
            config(['database.connections.clickhouse' => $original]);
            DB::purge('clickhouse');
        }
    }

    public function testLaravelQueryBuilderUpdatesAndDeletesWithMutations(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);
        $this->assertInstanceOf(LaravelBuilder::class, $connection->table(self::TABLE));
        $connection->enableQueryLog();

        $connection->table(self::TABLE)->where('id', 1)->update(['v' => 'x']);
        $connection->table(self::TABLE)->where('id', 2)->delete();
        $this->waitForMutations();

        $this->assertSame([
            'alter table "mutation_rows" update "v" = ? where "id" = ?',
            'alter table "mutation_rows" delete where "id" = ?',
        ], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame([[1, 'x'], [3, 'c'], [4, 'd']], $this->rows());
    }

    public function testLaravelQueryBuilderSendsALightweightDeleteWhenConfigured(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => true]);
        $connection->enableQueryLog();

        $connection->table(self::TABLE)->where('id', 2)->delete();

        $this->assertSame(
            ['delete from "mutation_rows" where "id" = ?'],
            array_column($connection->getQueryLog(), 'query')
        );
        $this->assertSame([1, 3, 4], $this->ids());
    }

    /**
     * @return array<string, array{array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelMutationWithoutWhereProvider(): array
    {
        return [
            'alter delete' => [
                ['use_lightweight_delete' => false],
                fn (LaravelBuilder $query) => $query->delete(),
                'Cannot delete without a where condition',
            ],
            'lightweight delete' => [
                ['use_lightweight_delete' => true],
                fn (LaravelBuilder $query) => $query->delete(),
                'Cannot delete without a where condition',
            ],
            'update' => [[], fn (LaravelBuilder $query) => $query->update(['v' => 'x']), 'Cannot update without a where condition'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(LaravelBuilder): mixed $mutation
     * @param string $message
     */
    #[DataProvider('laravelMutationWithoutWhereProvider')]
    public function testLaravelQueryBuilderRefusesAMutationWithoutWhere(array $config, Closure $mutation, string $message): void
    {
        $connection = $this->laravelGrammarConnection($config);
        $connection->enableQueryLog();

        try {
            $mutation($connection->table(self::TABLE));
            $this->fail('A mutation without a where condition should throw');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
    }

    /**
     * Laravel's grammar leaves these clauses out of a delete or update, so the
     * mutation could change rows that the query does not select: every row with
     * an id above 0, or for the select alias the row whose column v is 'c'. A
     * join was sent in MySQL's syntax, which ClickHouse rejects.
     *
     * @return array<string, array{array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelMutationWithIgnoredClauseProvider(): array
    {
        $join = fn (LaravelBuilder $query): LaravelBuilder => $query
            ->join(self::KEYS_TABLE, self::TABLE . '.id', '=', self::KEYS_TABLE . '.id')
            ->where(self::TABLE . '.id', '>', 0);

        return [
            'alter delete with a select alias' => [
                ['use_lightweight_delete' => false],
                fn (LaravelBuilder $query) => $query->select('id', new LaravelExpression("if(id > 2, 'c', 'z') as v"))->where('v', 'c')->delete(),
                'Cannot delete with a query that uses SELECT aliases or expressions: ',
            ],
            'update with a select alias' => [
                [],
                fn (LaravelBuilder $query) => $query->select('id', 'd as v')->where('id', '>', 0)->update(['v' => 'x']),
                'Cannot update with a query that uses SELECT aliases or expressions: ',
            ],
            'lightweight delete with a raw select column' => [
                ['use_lightweight_delete' => true],
                fn (LaravelBuilder $query) => $query->selectRaw('id')->where('id', '>', 0)->delete(),
                'Cannot delete with a query that uses SELECT aliases or expressions: ',
            ],
            'alter delete with a join' => [
                ['use_lightweight_delete' => false],
                fn (LaravelBuilder $query) => $join($query)->delete(),
                'Cannot delete with a query that uses JOIN: ',
            ],
            'lightweight delete with a join' => [
                ['use_lightweight_delete' => true],
                fn (LaravelBuilder $query) => $join($query)->delete(),
                'Cannot delete with a query that uses JOIN: ',
            ],
            'update with a join' => [
                [],
                fn (LaravelBuilder $query) => $join($query)->update(['v' => 'x']),
                'Cannot update with a query that uses JOIN: ',
            ],
            'delete with a group by and a having' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->groupBy('v')->havingRaw('count() > 0')->delete(),
                'Cannot delete with a query that uses GROUP BY and HAVING: ',
            ],
            'update with a group by' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->groupBy('v')->update(['v' => 'x']),
                'Cannot update with a query that uses GROUP BY: ',
            ],
            'delete with a union all' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', 1)->unionAll(fn (LaravelBuilder $other) => $other->from(self::TABLE)->where('id', 2))->delete(),
                'Cannot delete with a query that uses UNION ALL: ',
            ],
            'update with a union' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', 1)->union(fn (LaravelBuilder $other) => $other->from(self::TABLE)->where('id', 2))->update(['v' => 'x']),
                'Cannot update with a query that uses UNION: ',
            ],
            'alter delete with a limit' => [
                ['use_lightweight_delete' => false],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->orderBy('id')->limit(1)->delete(),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'lightweight delete with a limit' => [
                ['use_lightweight_delete' => true],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->orderBy('id')->limit(1)->delete(),
                'Cannot delete with a query that uses LIMIT: ',
            ],
            'delete with an offset' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->offset(3)->delete(),
                'Cannot delete with a query that uses OFFSET: ',
            ],
            'update of a page' => [
                [],
                fn (LaravelBuilder $query) => $query->where('id', '>', 0)->forPage(2, 2)->update(['v' => 'x']),
                'Cannot update with a query that uses LIMIT and OFFSET: ',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param Closure(LaravelBuilder): mixed $mutation
     * @param string $message
     */
    #[DataProvider('laravelMutationWithIgnoredClauseProvider')]
    public function testLaravelQueryBuilderRefusesAMutationWithAClauseItWouldIgnore(array $config, Closure $mutation, string $message): void
    {
        $this->createKeysTable();
        $connection = $this->laravelGrammarConnection($config);
        $connection->enableQueryLog();

        try {
            $mutation($connection->table(self::TABLE));
            $this->fail('A mutation with a clause it would ignore should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith($message, $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
        $this->assertSame([], $this->mutationCommands());
    }

    /**
     * Laravel's grammar leaves ORDER BY and groupLimit() out of a delete or update,
     * though they change which rows the query selects: arrayJoin() drops id 2, whose
     * tags are empty, and repeats id 3, which has two, and a group limit of 1 keeps
     * the first id of each set of tags, so id 4 is left out. The mutation would
     * change every row with an id above 0.
     *
     * @return array<string, array{Closure(LaravelBuilder): LaravelBuilder, int[], string}>
     */
    public static function laravelQueryThatSelectsFewerRowsProvider(): array
    {
        return [
            'orderByRaw() that calls arrayJoin()' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('id')->where('id', '>', 0)->orderByRaw('arrayJoin(tags)'),
                [1, 3, 3, 4],
                'ORDER BY',
            ],
            'orderBy() of an expression that calls arrayJoin()' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query
                    ->select('id')
                    ->where('id', '>', 0)
                    ->orderBy(new LaravelExpression('arrayJoin(tags)'), 'desc'),
                [1, 3, 3, 4],
                'ORDER BY',
            ],
            'groupLimit()' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('id')->where('id', '>', 0)->orderBy('id')->groupLimit(1, 'tags'),
                [1, 2, 3],
                'LIMIT',
            ],
            'orderByRaw() with LIMIT ... BY' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('id')->where('id', '>', 0)->orderByRaw('id desc limit 1 by toYYYYMM(d)'),
                [2, 4],
                'ORDER BY',
            ],
            'orderByRaw() with LIMIT' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('id')->where('id', '>', 0)->orderByRaw('id limit 2'),
                [1, 2],
                'ORDER BY',
            ],
            'orderByRaw() with OFFSET ... ROWS' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('id')->where('id', '>', 0)->orderByRaw('id offset 3 rows'),
                [4],
                'ORDER BY',
            ],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelMutationProvider(): array
    {
        return [
            'alter delete' => [['use_lightweight_delete' => false], fn (LaravelBuilder $query) => $query->delete(), 'delete'],
            'lightweight delete' => [['use_lightweight_delete' => true], fn (LaravelBuilder $query) => $query->delete(), 'delete'],
            'update' => [[], fn (LaravelBuilder $query) => $query->update(['v' => 'x']), 'update'],
        ];
    }

    /**
     * @return array<string, array{Closure(LaravelBuilder): LaravelBuilder, int[], string, array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelMutationOfAQueryThatSelectsFewerRowsProvider(): array
    {
        $dataSets = [];
        foreach (self::laravelQueryThatSelectsFewerRowsProvider() as $queryLabel => [$query, $selectedIds, $clause]) {
            foreach (self::laravelMutationProvider() as $mutationLabel => [$config, $mutation, $statement]) {
                $dataSets["{$queryLabel}, {$mutationLabel}"] = [$query, $selectedIds, $clause, $config, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(LaravelBuilder): LaravelBuilder $query
     * @param int[] $selectedIds
     * @param array<string, mixed> $config
     * @param Closure(LaravelBuilder): mixed $mutation
     */
    #[DataProvider('laravelMutationOfAQueryThatSelectsFewerRowsProvider')]
    public function testLaravelQueryBuilderRefusesAMutationOfAQueryThatSelectsFewerRows(
        Closure $query,
        array $selectedIds,
        string $clause,
        array $config,
        Closure $mutation,
        string $statement
    ): void {
        $connection = $this->laravelGrammarConnection($config);
        $this->assertSame($selectedIds, $this->laravelSelectedIds($query($connection->table(self::TABLE))), 'the rows the query selects');
        $connection->enableQueryLog();

        try {
            $mutation($query($connection->table(self::TABLE)));
            $this->fail('A mutation of a query that uses ' . $clause . ' should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses {$clause}: ", $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
        $this->assertSame([], $this->mutationCommands());
    }

    /**
     * The queries of queryThatReadsAnOrderByAliasProvider() on Laravel's builder, which
     * compiles orderBy('a as b') to order by "a" as "b": they select the rows 1, 2 and 3,
     * while Laravel's grammar would leave the ORDER BY out of the mutation, which would
     * then change the row (10, 1).
     *
     * @return array<string, array{Closure(LaravelBuilder): LaravelBuilder}>
     */
    public static function laravelQueryThatReadsAnOrderByAliasProvider(): array
    {
        return [
            'orderBy() of a name with as' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('a')->where('b', '<', 5)->orderBy('a as b'),
            ],
            'orderByRaw() with AS' => [
                fn (LaravelBuilder $query): LaravelBuilder => $query->select('a')->where('b', '<', 5)->orderByRaw('a AS b'),
            ],
        ];
    }

    /**
     * The mutations of ORDER_ALIAS_TABLE on Laravel's builder: the update sets the column b.
     *
     * @return array<string, array{array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelOrderAliasMutationProvider(): array
    {
        return [
            'alter delete' => [['use_lightweight_delete' => false], fn (LaravelBuilder $query) => $query->delete(), 'delete'],
            'lightweight delete' => [['use_lightweight_delete' => true], fn (LaravelBuilder $query) => $query->delete(), 'delete'],
            'update' => [[], fn (LaravelBuilder $query) => $query->update(['b' => 99]), 'update'],
        ];
    }

    /**
     * @return array<string, array{Closure(LaravelBuilder): LaravelBuilder, array<string, mixed>, Closure(LaravelBuilder): mixed, string}>
     */
    public static function laravelMutationOfAQueryThatReadsAnOrderByAliasProvider(): array
    {
        $dataSets = [];
        foreach (self::laravelQueryThatReadsAnOrderByAliasProvider() as $queryLabel => [$query]) {
            foreach (self::laravelOrderAliasMutationProvider() as $mutationLabel => [$config, $mutation, $statement]) {
                $dataSets["{$queryLabel}, {$mutationLabel}"] = [$query, $config, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(LaravelBuilder): LaravelBuilder $query
     * @param array<string, mixed> $config
     * @param Closure(LaravelBuilder): mixed $mutation
     */
    #[DataProvider('laravelMutationOfAQueryThatReadsAnOrderByAliasProvider')]
    public function testLaravelQueryBuilderRefusesAMutationOfAQueryWhoseConditionReadsAnOrderByAlias(
        Closure $query,
        array $config,
        Closure $mutation,
        string $statement
    ): void {
        $this->createOrderAliasTable();
        $connection = $this->laravelGrammarConnection($config);
        $this->assertSame(
            [1, 2, 3],
            $this->laravelSelectedIds($query($connection->table(self::ORDER_ALIAS_TABLE)), 'a'),
            'the rows the query selects'
        );
        $connection->enableQueryLog();

        try {
            $mutation($query($connection->table(self::ORDER_ALIAS_TABLE)));
            $this->fail('A mutation of a query whose condition reads an ORDER BY alias should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses ORDER BY: ", $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame(self::ORDER_ALIAS_ROWS, $this->orderAliasRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_ALIAS_TABLE));
    }

    /**
     * WITH FILL only adds rows that the table does not have, the ids 5 and 6, so the
     * mutations are sent without the ORDER BY, and they change the rows of the table
     * that the query selects: 3 and 4.
     */
    public function testLaravelQueryBuilderSendsAMutationOfAQueryOrderedWithFill(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);
        $query = fn (): LaravelBuilder => $connection->table(self::TABLE)->select('id')->where('id', '>', 2)->orderByRaw('id WITH FILL TO 7');
        $this->assertSame([3, 4, 5, 6], $this->laravelSelectedIds($query()), 'the rows the query selects, with the filled ids 5 and 6');
        $connection->enableQueryLog();

        $query()->update(['v' => 'x']);
        $this->waitForMutations();
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'x'], [4, 'x']], $this->rows());

        $query()->delete();
        $this->waitForMutations();
        $this->assertSame([[1, 'a'], [2, 'b']], $this->rows());

        $this->assertSame([
            'alter table "mutation_rows" update "v" = ? where "id" > ?',
            'alter table "mutation_rows" delete where "id" > ?',
        ], array_column($connection->getQueryLog(), 'query'));
    }

    /**
     * An order with bindings only sorts the rows, so the mutations are sent without the
     * ORDER BY and without its bindings: those of orderByRaw(), inOrderOf() and orderBy()
     * of a sub-query.
     */
    public function testLaravelQueryBuilderSendsAMutationOfAQueryWhoseOrderHasBindings(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);
        $connection->enableQueryLog();

        $connection->table(self::TABLE)->where('id', '>', 3)->orderByRaw('v = ? desc', ['b'])->delete();
        $connection->table(self::TABLE)->where('id', 3)->inOrderOf('v', ['c', 'a'])->update(['v' => 'x']);
        $connection->table(self::TABLE)->where('id', 2)
            ->orderBy(fn (LaravelBuilder $sub) => $sub->from(self::TABLE)->selectRaw('max(id)')->where('id', '<', 9))
            ->update(['v' => 'y']);
        $this->waitForMutations();

        $lightweight = $this->laravelGrammarConnection(['use_lightweight_delete' => true]);
        $lightweight->enableQueryLog();
        $lightweight->table(self::TABLE)->where('id', 1)->orderByRaw('v = ? desc', ['a'])->delete();

        $this->assertSame([
            ['alter table "mutation_rows" delete where "id" > ?', [3]],
            ['alter table "mutation_rows" update "v" = ? where "id" = ?', ['x', 3]],
            ['alter table "mutation_rows" update "v" = ? where "id" = ?', ['y', 2]],
        ], array_map(fn (array $query): array => [$query['query'], $query['bindings']], $connection->getQueryLog()));
        $this->assertSame(
            [['delete from "mutation_rows" where "id" = ?', [1]]],
            array_map(fn (array $query): array => [$query['query'], $query['bindings']], $lightweight->getQueryLog())
        );
        $this->assertSame([[2, 'y'], [3, 'x']], $this->rows());
    }

    /**
     * Laravel's updateOrInsert() updates an existing row with limit(1)->update(), and
     * a mutation cannot change one row of several, so it is refused, as the message
     * says; a new row is still inserted.
     */
    public function testLaravelUpdateOrInsertRefusesToUpdateAnExistingRow(): void
    {
        $connection = $this->laravelGrammarConnection([]);
        $connection->enableQueryLog();

        try {
            $connection->table(self::TABLE)->updateOrInsert(['id' => 1], ['v' => 'x']);
            $this->fail('updateOrInsert() of an existing row should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot update with a query that uses LIMIT: ', $exception->getMessage());
            $this->assertStringEndsWith(
                ' so start a new query; updateOrInsert() adds a LIMIT itself, so it cannot update an existing row.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            ['select exists(select * from "mutation_rows" where ("id" = ?)) as "exists"'],
            array_column($connection->getQueryLog(), 'query'),
            'only the exists() query is sent'
        );
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());

        $this->assertTrue($connection->table(self::TABLE)->updateOrInsert(['id' => 5], ['d' => '2024-03-01', 'v' => 'e']));
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd'], [5, 'e']], $this->rows());
    }

    /**
     * first() leaves limit(1) on Laravel's builder, so a delete on the same builder is refused.
     */
    public function testLaravelDeleteAfterFirstOnTheSameBuilderIsRefused(): void
    {
        $connection = $this->laravelGrammarConnection([]);
        $query = $connection->table(self::TABLE)->where('id', 2);
        $this->assertSame('b', ((array) $query->first())['v']);
        $connection->enableQueryLog();

        try {
            $query->delete();
            $this->fail('A delete after first() on the same builder should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot delete with a query that uses LIMIT: ', $exception->getMessage());
            $this->assertStringEndsWith(
                'Methods such as first(), chunk(), paginate() and simplePaginate() leave a LIMIT on the query they run on,'
                . ' so start a new query.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
    }

    /**
     * An offset of 0, an ORDER BY and a select list of column names do not change
     * which rows match, so the mutations are sent without them.
     */
    public function testLaravelQueryBuilderSendsAMutationWithAnOffsetOfZeroAnOrderAndColumnNames(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);
        $connection->enableQueryLog();

        $connection->table(self::TABLE)->select('id', 'v')->where('id', 1)->orderBy('id')->offset(0)->update(['v' => 'x']);
        $connection->table(self::TABLE)->select('id')->where('id', 2)->orderBy('id', 'desc')->offset(0)->delete();
        $this->waitForMutations();

        $this->assertSame([
            'alter table "mutation_rows" update "v" = ? where "id" = ?',
            'alter table "mutation_rows" delete where "id" = ?',
        ], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame([[1, 'x'], [3, 'c'], [4, 'd']], $this->rows());
    }

    /**
     * Column functions in ORDER BY whose raw SQL defines an alias or calls arrayJoin(): the query selects other rows
     * than its conditions match, so a mutation of it throws and changes nothing, and count() counts the rows that
     * get() returns.
     *
     * The condition b < 5 matches the row (10, 1) when it reads the column b, and the rows 1, 2 and 3 when it reads
     * an alias b of the column a; arrayJoin(arr) drops the row 2, whose array is empty, and repeats the row 3.
     *
     * @return array<string, array{Closure(Builder): Builder, int[]}>
     */
    public static function queryOrderedByAColumnFunctionProvider(): array
    {
        return [
            'plus() of raw sql with AS' => [
                fn (Builder $query): Builder => $query->select('a')->where('b', '<', 5)
                    ->orderBy(fn (Column $column) => $column->name('a')->plus(raw('0 AS b'))),
                [1, 2, 3],
            ],
            'plus() of raw sql that calls arrayJoin()' => [
                fn (Builder $query): Builder => $query->select('a')->where('a', '>', 0)
                    ->orderBy(fn (Column $column) => $column->name('a')->plus(raw('arrayJoin(arr)'))),
                [1, 3, 3, 10],
            ],
            'multiple() of raw sql that calls arrayJoin()' => [
                fn (Builder $query): Builder => $query->select('a')->where('a', '>', 0)
                    ->orderBy(fn (Column $column) => $column->name('a')->multiple(raw('arrayJoin(arr)'))),
                [1, 3, 3, 10],
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, int[], Closure(Builder): mixed, string}>
     */
    public static function mutationOfAQueryOrderedByAColumnFunctionProvider(): array
    {
        $dataSets = [];
        foreach (self::queryOrderedByAColumnFunctionProvider() as $queryLabel => [$query, $selectedIds]) {
            foreach (self::mutationProvider() as $mutationLabel => [$mutation, $statement]) {
                $dataSets["{$queryLabel}, {$mutationLabel}"] = [$query, $selectedIds, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param int[] $selectedIds
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfAQueryOrderedByAColumnFunctionProvider')]
    public function testAMutationOfAQueryOrderedByAColumnFunctionThrowsAndChangesNothing(
        Closure $query,
        array $selectedIds,
        Closure $mutation,
        string $statement
    ): void {
        $this->createOrderFunctionTable();
        $this->assertSame($selectedIds, $this->selectedIds($query($this->orderFunctionTable()), 'a'), 'the rows the query selects');
        $this->assertSame(count($selectedIds), $query($this->orderFunctionTable())->count(), 'count() counts the rows of get()');

        try {
            $mutation($query($this->orderFunctionTable()));
            $this->fail('A mutation of a query ordered by such a column function should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot {$statement} with a query that uses ORDER BY: ", $exception->getMessage());
        }

        $this->assertSame(self::ORDER_FUNCTION_ROWS, $this->orderFunctionRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_FUNCTION_TABLE), 'nothing is sent');
    }

    /**
     * An aggregate or DISTINCT column function in ORDER BY makes the SELECT fail, so a mutation of the query, which
     * would change every row its condition matches, throws and changes nothing.
     *
     * @return array<string, array{Closure(Column): Column}>
     */
    public static function aggregatingColumnFunctionProvider(): array
    {
        return [
            'sum()' => [fn (Column $column): Column => $column->sum('a')],
            'max()' => [fn (Column $column): Column => $column->max('a')],
            'sumIf()' => [fn (Column $column): Column => $column->name('a')->sumIf('a > 1')],
            'count()' => [fn (Column $column): Column => $column->name('a')->count()],
            'distinct()' => [fn (Column $column): Column => $column->name('a')->distinct()],
        ];
    }

    /**
     * @param Closure(Column): Column $order
     */
    #[DataProvider('aggregatingColumnFunctionProvider')]
    public function testAMutationOfAQueryOrderedByAnAggregateThrowsAndChangesNothing(Closure $order): void
    {
        $this->createOrderFunctionTable();

        foreach (self::mutationProvider() as [$mutation, $statement]) {
            try {
                $mutation($this->orderFunctionTable()->where('a', '>', 1)->orderBy($order));
                $this->fail("A {$statement} of a query ordered by an aggregate should throw");
            } catch (QueryException $exception) {
                $this->assertStringStartsWith("Cannot {$statement} with a query that uses ORDER BY: ", $exception->getMessage());
            }
        }

        $this->assertSame(self::ORDER_FUNCTION_ROWS, $this->orderFunctionRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_FUNCTION_TABLE), 'nothing is sent');
    }

    /**
     * Raw SQL in from() that is not a table name: a model query writes to its sources table, which would ignore the
     * raw sub-query, the table function or the FINAL, and change rows that the query does not select.
     *
     * @return array<string, array{Expression}>
     */
    public static function rawFromProvider(): array
    {
        return [
            'a raw sub-query' => [raw('(SELECT * FROM ' . self::ORDER_ALIAS_TABLE . ' WHERE a < 3)')],
            'a table function' => [raw('numbers(3)')],
            'raw sql with FINAL' => [raw(self::ORDER_ALIAS_TABLE . ' FINAL')],
        ];
    }

    #[DataProvider('rawFromProvider')]
    public function testAModelMutationOfAQueryWithRawSqlInFromThrowsAndChangesNothing(Expression $from): void
    {
        $this->createOrderAliasTable();

        foreach (self::mutationProvider() as [$mutation, $statement]) {
            try {
                $mutation(MutationOrderAliasRow::where('b', '<', 25)->from($from));
                $this->fail("A {$statement} of a query with raw sql in from() should throw");
            } catch (QueryException $exception) {
                $this->assertStringStartsWith(
                    "Cannot {$statement} with a query that uses a FROM table function or raw SQL: ",
                    $exception->getMessage()
                );
            }
        }

        $this->assertSame(self::ORDER_ALIAS_ROWS, $this->orderAliasRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_ALIAS_TABLE), 'nothing is sent');
    }

    public function testAMutationOfAQueryWithARawTableNameInFromIsSent(): void
    {
        $this->createOrderAliasTable();

        MutationOrderAliasRow::where('a', 10)->from(raw(self::ORDER_ALIAS_TABLE))->delete(true);
        DB::connection('clickhouse')->table(raw(self::ORDER_ALIAS_TABLE))->where('a', 1)->delete(true);

        $this->assertSame([[2, 20], [3, 30]], $this->orderAliasRows());
    }

    /**
     * A lightweight delete whose condition holds UNION, INTERSECT or EXCEPT fails on ClickHouse 24.8 and leaves a
     * mutation behind that blocks every later mutation of the table until KILL MUTATION, so it is refused before
     * anything is sent. ALTER TABLE ... DELETE runs the same condition.
     *
     * @return array<string, array{Closure(Builder): Builder, array<int, array{int, string}>}>
     */
    public static function setOperationConditionProvider(): array
    {
        $ids = fn (int $id): Closure => fn (Builder $query) => $query->select('id')->from(self::TABLE)->where('id', $id);

        return [
            'whereIn with union all' => [
                fn (Builder $query): Builder => $query->whereIn('id', fn (Builder $sub) => $ids(1)($sub)->unionAll($ids(2))),
                [[3, 'c'], [4, 'd']],
            ],
            'whereIn with except' => [
                fn (Builder $query): Builder => $query->whereIn(
                    'id',
                    fn (Builder $sub) => $sub->select('id')->from(self::TABLE)->where('id', '<', 3)->except($ids(1))
                ),
                [[1, 'a'], [3, 'c'], [4, 'd']],
            ],
            'whereExists with intersect' => [
                fn (Builder $query): Builder => $query->where('id', 4)->whereExists(fn (Builder $sub) => $ids(1)($sub)->intersect($ids(1))),
                [[1, 'a'], [2, 'b'], [3, 'c']],
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     * @param array<int, array{int, string}> $rowsAfterAlterDelete
     */
    #[DataProvider('setOperationConditionProvider')]
    public function testALightweightDeleteWithASetOperationIsRefusedAndAlterDeleteRuns(Closure $condition, array $rowsAfterAlterDelete): void
    {
        try {
            $condition($this->table())->delete(true);
            $this->fail('A lightweight delete with a set operation should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                'Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION, INTERSECT or EXCEPT',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->mutationCommands(), 'nothing is sent');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());

        $condition($this->table())->delete(false);
        $this->waitForMutations();

        $this->assertSame($rowsAfterAlterDelete, $this->rows());
    }

    public function testALaravelLightweightDeleteWithASetOperationIsRefused(): void
    {
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => true]);

        try {
            $connection->table(self::TABLE)->whereIn(
                'id',
                fn (LaravelBuilder $sub) => $sub->select('id')->from(self::TABLE)->where('id', 1)
                    ->unionAll(fn (LaravelBuilder $other) => $other->select('id')->from(self::TABLE)->where('id', 2))
            )->delete();
            $this->fail('A lightweight delete with a set operation should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot send a lightweight DELETE FROM whose where condition holds', $exception->getMessage());
        }

        $this->assertSame([], $this->mutationCommands(), 'nothing is sent');
    }

    /**
     * A sub-query that reads a column of the mutated table: ClickHouse 24.8 accepts it in ALTER TABLE ... DELETE and
     * UPDATE, and then fails the mutation again and again, which blocks every later mutation of the table. The
     * EXPLAIN that runs first refuses it, so nothing is sent. On a server that runs such a sub-query, the mutation
     * is sent and must finish.
     *
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationProvider')]
    public function testAMutationWithACorrelatedSubQueryIsRefusedOrFinishes(Closure $mutation, string $statement): void
    {
        $this->createKeysTable();
        $query = $this->table()->whereExists(
            fn (Builder $sub) => $sub->from(self::KEYS_TABLE)->whereColumn(self::KEYS_TABLE . '.id', self::TABLE . '.id')
        );

        try {
            $mutation($query);
            $this->waitForMutations();
            $this->assertSame(
                $statement === 'update' ? [[1, 'a'], [2, 'x'], [3, 'c'], [4, 'x']] : [[1, 'a'], [3, 'c']],
                $this->rows()
            );
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                "Cannot {$statement} with a where condition that ClickHouse cannot run: it refused the condition in a SELECT with ",
                $exception->getMessage()
            );
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
            $this->assertSame([], $this->mutationCommands(), 'nothing is sent');
            $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows());
        }

        $this->table()->where('id', 1)->delete(false);
        $this->waitForMutations();
        $this->assertNotContains(1, $this->ids(), 'a later mutation is not blocked');
    }

    public function testALaravelMutationWithACorrelatedSubQueryIsRefusedOrFinishes(): void
    {
        $this->createKeysTable();
        $connection = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);

        try {
            $connection->table(self::TABLE)->where('id', '>', 0)->whereExists(
                fn (LaravelBuilder $sub) => $sub->from(self::KEYS_TABLE)->whereColumn(self::KEYS_TABLE . '.id', self::TABLE . '.id')
            )->delete();
            $this->waitForMutations();
            $this->assertSame([[1, 'a'], [3, 'c']], $this->rows());
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot delete with a where condition that ClickHouse cannot run: ', $exception->getMessage());
            $this->assertSame([], $this->mutationCommands(), 'nothing is sent');
        }

        $connection->table(self::TABLE)->where('id', 1)->delete();
        $this->waitForMutations();
        $this->assertNotContains(1, $this->ids(), 'a later mutation is not blocked');
    }

    /**
     * A sub-query that ClickHouse runs in a SELECT passes the EXPLAIN, and the mutation is sent.
     */
    public function testAMutationWithASubQueryThatClickHouseRunsIsSent(): void
    {
        $this->createKeysTable();

        $this->table()->where('id', '>', 2)->whereExists(fn (Builder $sub) => $sub->from(self::KEYS_TABLE)->where('id', 4))->delete(false);
        $this->waitForMutations();
        $this->table()->whereIn('id', fn (Builder $sub) => $sub->select('id')->from(self::KEYS_TABLE))->update(['v' => 'k']);
        $this->waitForMutations();

        $this->assertSame([[1, 'a'], [2, 'k']], $this->rows());
    }

    /**
     * Inside a session whose temporary table shadows the database table, a lightweight delete and an ON CLUSTER
     * statement would change the database table, so they are refused; ALTER TABLE ... DELETE reaches the temporary
     * table.
     */
    public function testAStatementThatWouldMissATemporaryTableOfTheSessionIsRefused(): void
    {
        $refused = [];
        $temporaryRows = DB::connection('clickhouse')->session(function (Connection $connection) use (&$refused): array {
            $connection->statement('CREATE TEMPORARY TABLE ' . self::TABLE . ' (id UInt32, v String) ENGINE = MergeTree ORDER BY id');
            $connection->table(self::TABLE)->insert([['id' => 1, 'v' => 't1'], ['id' => 100, 'v' => 't100']]);

            foreach ([
                'builder lightweight delete' => fn () => $connection->table(self::TABLE)->where('id', 1)->delete(true),
                'model lightweight delete' => fn () => MutationRow::where('id', 1)->delete(true),
                'builder truncate on a cluster' => fn () => $connection->table(self::TABLE)->onCluster('company_cluster')->truncate(),
                'builder update on a cluster' => fn () => $connection->table(self::TABLE)->where('id', 1)->onCluster('company_cluster')->update(['v' => 'x']),
            ] as $label => $statement) {
                try {
                    $statement();
                    $refused[$label] = false;
                } catch (QueryException $exception) {
                    $refused[$label] = str_starts_with($exception->getMessage(), 'Cannot send ');
                }
            }

            $connection->table(self::TABLE)->where('id', 100)->delete(false);
            $deadline = microtime(true) + 10;
            do {
                $rows = $connection->table(self::TABLE)->select('id')->orderBy('id')->getRows();
            } while (count($rows) > 1 && microtime(true) < $deadline && usleep(10_000) === null);

            return array_map('intval', array_column($rows, 'id'));
        });

        $this->assertSame(
            [
                'builder lightweight delete' => true,
                'model lightweight delete' => true,
                'builder truncate on a cluster' => true,
                'builder update on a cluster' => true,
            ],
            $refused
        );
        $this->assertSame([1], $temporaryRows, 'ALTER TABLE ... DELETE reached the temporary table');
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd']], $this->rows(), 'the database table is intact');
        $this->assertSame([], $this->mutationCommands());
    }

    /**
     * Inside a session, ClickHouse 24.8 runs a mutation of a table of the database in the background, outside the
     * session, where a temporary table of the session does not exist: a mutation whose condition reads one would fail
     * again and again and block every later mutation of the table. Its EXPLAIN runs outside the session too, so it
     * is refused, on both builders and for a model. Selecting the keys first works, and so does a mutation of a
     * temporary table, which runs in the session.
     */
    public function testInASessionAMutationWhoseConditionReadsATemporaryTableIsRefused(): void
    {
        $keys = 'mutation_session_keys';
        $byKeys = fn (Builder $query): Builder => $query->whereIn('id', fn (Builder $sub) => $sub->select('id')->from($keys));
        $laravel = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);

        $results = DB::connection('clickhouse')->session(function (Connection $connection) use ($keys, $byKeys): array {
            $connection->statement("CREATE TEMPORARY TABLE {$keys} (id UInt32)");
            $connection->statement("INSERT INTO {$keys} VALUES (2)");
            $results = [];
            foreach ([
                'alter delete' => fn () => $byKeys($connection->table(self::TABLE))->delete(false),
                'lightweight delete' => fn () => $byKeys($connection->table(self::TABLE))->delete(true),
                'update' => fn () => $byKeys($connection->table(self::TABLE))->update(['v' => 'x']),
                'model delete' => fn () => $byKeys(MutationRow::query())->delete(false),
                'in the temporary table' => fn () => $connection->table(self::TABLE)->whereIn('id', raw($keys))->delete(false),
            ] as $label => $mutation) {
                try {
                    $mutation();
                    $results[$label] = 'sent';
                } catch (QueryException $exception) {
                    $results[$label] = $exception->getMessage();
                }
            }

            $connection->statement('CREATE TEMPORARY TABLE mutation_session_rows (id UInt32)');
            $connection->statement('INSERT INTO mutation_session_rows VALUES (1), (2), (3)');
            $byKeys($connection->table('mutation_session_rows'))->delete(false);
            $results['temporary rows'] = array_map('intval', array_column(
                $connection->table('mutation_session_rows')->select('id')->orderBy('id')->getRows(),
                'id'
            ));

            $connection->table(self::TABLE)->whereIn('id', $connection->table($keys)->pluck('id'))->delete(false);

            return $results;
        });
        $results['laravel delete'] = $laravel->session(function (Connection $connection) use ($keys): string {
            $connection->statement("CREATE TEMPORARY TABLE {$keys} (id UInt32)");
            try {
                $connection->table(self::TABLE)->whereIn('id', fn (LaravelBuilder $sub) => $sub->select('id')->from($keys))->delete();

                return 'sent';
            } catch (QueryException $exception) {
                return $exception->getMessage();
            }
        });

        $this->assertSame([1, 3], $results['temporary rows'], 'a mutation of a temporary table runs in the session');
        unset($results['temporary rows']);
        foreach ($results as $label => $result) {
            $this->assertStringStartsWith(
                'Cannot ' . ($label === 'update' ? 'update' : 'delete') . ' with a where condition that ClickHouse'
                . ' cannot run: it refused the condition in a SELECT outside the session with ',
                $result,
                $label
            );
        }
        $this->assertCount(1, $this->mutationCommands(), 'only the delete by the selected keys is sent');
        $this->waitForMutations();
        $this->assertSame([[1, 'a'], [3, 'c'], [4, 'd']], $this->rows());
    }

    /**
     * IN followed by a table names a table that ClickHouse resolves only when the mutation runs, as a sub-query does:
     * a missing table would leave a mutation that fails again and again. The EXPLAIN refuses it, on both builders,
     * and a mutation by IN a table that exists is sent.
     */
    public function testAMutationByInATableIsRefusedForAMissingTableAndSentOtherwise(): void
    {
        $this->createKeysTable();
        $laravel = $this->laravelGrammarConnection(['use_lightweight_delete' => false]);

        foreach ([
            'builder' => fn () => $this->table()->whereIn('id', raw('mutation_missing_keys'))->delete(false),
            'laravel' => fn () => $laravel->table(self::TABLE)->whereRaw('id in mutation_missing_keys')->delete(),
        ] as $label => $mutation) {
            try {
                $mutation();
                $this->fail("The {$label} delete by IN a missing table should throw");
            } catch (QueryException $exception) {
                $this->assertStringStartsWith(
                    'Cannot delete with a where condition that ClickHouse cannot run: it refused the condition in a SELECT with ',
                    $exception->getMessage()
                );
            }
        }
        $this->assertSame([], $this->mutationCommands(), 'nothing is sent');

        $this->table()->whereIn('id', raw(self::KEYS_TABLE))->delete(false);
        $this->waitForMutations();

        $this->assertSame([[1, 'a'], [3, 'c']], $this->rows());
    }

    /**
     * ClickHouse 24.8 runs an IN sub-query on a column of the primary key while it plans a SELECT, to build the set
     * for the index, but queues a mutation at once. The EXPLAIN of the check turns that off, so the call does not
     * wait for the sub-query, which takes 1.5 seconds here.
     */
    public function testAnInSubQueryOnAKeyColumnDoesNotDelayTheMutation(): void
    {
        $slowKeys = raw('(SELECT sleepEachRow(0.5) + 100 FROM numbers(3) SETTINGS max_block_size = 1)');

        $start = microtime(true);
        $this->table()->whereIn('id', $slowKeys)->delete(false);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'the call waited for the sub-query');
        $this->assertCount(1, $this->mutationCommands());
        $this->waitForMutations();
        $this->assertSame([1, 2, 3, 4], $this->ids());
    }

    /**
     * UNION, INTERSECT or EXCEPT in the raw SQL of an order makes the query a set operation, whose rows a mutation of
     * its conditions would not match: it is refused on both builders, and count() counts the rows that get() returns.
     */
    public function testAMutationOfAQueryOrderedWithASetOperationThrowsAndCountMatchesGet(): void
    {
        $this->createOrderAliasTable();
        $except = 'a EXCEPT SELECT * FROM ' . self::ORDER_ALIAS_TABLE . ' WHERE b = 20';
        $query = fn (): Builder => $this->orderAliasTable()->where('a', '<', 5)->orderByRaw($except);
        $laravel = $this->laravelGrammarConnection([]);
        $laravelQuery = fn (): LaravelBuilder => $laravel->table(self::ORDER_ALIAS_TABLE)->where('a', '<', 5)->orderByRaw($except);

        $this->assertSame([1, 3], $this->selectedIds($query(), 'a'));
        $this->assertSame(2, $query()->count());
        $this->assertSame(2, $laravelQuery()->count());

        foreach ([
            'delete' => fn () => $query()->delete(false),
            'lightweight delete' => fn () => $query()->delete(true),
            'update' => fn () => $query()->update(['b' => 0]),
            'laravel delete' => fn () => $laravelQuery()->delete(),
            'laravel update' => fn () => $laravelQuery()->update(['b' => 0]),
        ] as $label => $mutation) {
            try {
                $mutation();
                $this->fail("The {$label} should throw");
            } catch (QueryException $exception) {
                $this->assertMatchesRegularExpression('/^Cannot (delete|update) with a query that uses ORDER BY: /', $exception->getMessage(), $label);
            }
        }

        $this->assertSame(self::ORDER_ALIAS_ROWS, $this->orderAliasRows());
        $this->assertSame([], $this->mutationCommands(self::ORDER_ALIAS_TABLE));
    }

    /**
     * A builder made with only a client whose connection the application does not configure writes as 3.0.0 did:
     * straight to the client, with ALTER TABLE ... DELETE for delete() and Values for insert().
     */
    public function testABuilderWhoseConnectionIsNotConfiguredWritesWithoutOne(): void
    {
        $builder = fn (): Builder => (new Builder($this->client(), 'not_configured'))->from(self::TABLE);

        $builder()->insert(['id' => 5, 'd' => '2024-03-01', 'v' => 'e', 'tags' => []]);
        $builder()->where('id', 1)->delete();
        $this->waitForMutations();

        $this->assertSame([[2, 'b'], [3, 'c'], [4, 'd'], [5, 'e']], $this->rows());
        $this->assertStringStartsWith('DELETE WHERE', $this->lastMutationCommand());
    }

    /**
     * Create ORDER_FUNCTION_TABLE, which holds ORDER_FUNCTION_ROWS.
     *
     * @return void
     */
    private function createOrderFunctionTable(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS ' . self::ORDER_FUNCTION_TABLE . ' SYNC');
        $this->client()->write(
            'CREATE TABLE ' . self::ORDER_FUNCTION_TABLE . ' (a UInt32, b UInt32, arr Array(UInt32)) ENGINE = MergeTree ORDER BY a'
        );
        $this->client()->insert(self::ORDER_FUNCTION_TABLE, self::ORDER_FUNCTION_ROWS, ['a', 'b', 'arr']);
    }

    private function orderFunctionTable(): Builder
    {
        return DB::connection('clickhouse')->table(self::ORDER_FUNCTION_TABLE);
    }

    /**
     * @return array<int, array{int, int, array<int, int>}>
     */
    private function orderFunctionRows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['a'], (int) $row['b'], array_map('intval', $row['arr'])],
            $this->client()->select('SELECT a, b, arr FROM ' . self::ORDER_FUNCTION_TABLE . ' ORDER BY a')->rows()
        );
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    /**
     * Create KEYS_TABLE, which holds the ids 2 and 4.
     *
     * @return void
     */
    private function createKeysTable(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS ' . self::KEYS_TABLE . ' SYNC');
        $this->client()->write('CREATE TABLE ' . self::KEYS_TABLE . ' (id UInt32) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::KEYS_TABLE . ' VALUES (2), (4)');
    }

    /**
     * Create ORDER_ALIAS_TABLE, which holds ORDER_ALIAS_ROWS.
     *
     * @return void
     */
    private function createOrderAliasTable(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS ' . self::ORDER_ALIAS_TABLE . ' SYNC');
        $this->client()->write('CREATE TABLE ' . self::ORDER_ALIAS_TABLE . ' (a UInt32, b UInt32) ENGINE = MergeTree ORDER BY a');
        $this->client()->insert(self::ORDER_ALIAS_TABLE, self::ORDER_ALIAS_ROWS, ['a', 'b']);
    }

    /**
     * @param Builder $query A query that selects the id column, or the given column
     * @param string $column
     * @return int[]
     */
    private function selectedIds(Builder $query, string $column = 'id'): array
    {
        $ids = array_map('intval', array_column($query->getRows(), $column));
        sort($ids);

        return $ids;
    }

    /**
     * @param LaravelBuilder $query A query of Laravel's builder that selects the id column, or the given column
     * @param string $column
     * @return int[]
     */
    private function laravelSelectedIds(LaravelBuilder $query, string $column = 'id'): array
    {
        $ids = array_map('intval', $query->pluck($column)->all());
        sort($ids);

        return $ids;
    }

    /**
     * Get the commands of the mutations that ClickHouse has registered on the table.
     *
     * A lightweight delete is registered as UPDATE _row_exists = 0.
     *
     * @param string $table
     * @return string[]
     */
    private function mutationCommands(string $table = self::TABLE): array
    {
        return array_column($this->client()->select(
            "SELECT command FROM system.mutations WHERE database = currentDatabase() AND table = '" . $table . "'"
        )->rows(), 'command');
    }

    private function table(): Builder
    {
        return DB::connection('clickhouse')->table(self::TABLE);
    }

    private function orderAliasTable(): Builder
    {
        return DB::connection('clickhouse')->table(self::ORDER_ALIAS_TABLE);
    }

    /**
     * @return array<int, array{int, int}>
     */
    private function orderAliasRows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['a'], (int) $row['b']],
            $this->client()->select('SELECT a, b FROM ' . self::ORDER_ALIAS_TABLE . ' ORDER BY a')->rows()
        );
    }

    /**
     * @return int[]
     */
    private function ids(): array
    {
        return array_column($this->rows(), 0);
    }

    /**
     * @return array<int, array{int, string}>
     */
    private function rows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['id'], $row['v']],
            $this->client()->select('SELECT id, v FROM ' . self::TABLE . ' ORDER BY id')->rows()
        );
    }

    private function lastMutationCommand(): string
    {
        return $this->client()->select(
            "SELECT command FROM system.mutations WHERE database = currentDatabase() AND table = '" . self::TABLE . "'"
            . ' ORDER BY create_time DESC, mutation_id DESC LIMIT 1'
        )->fetchOne('command');
    }

    /**
     * Wait until ClickHouse has applied every mutation on the table.
     *
     * ALTER TABLE ... DELETE and UPDATE only queue the mutation and return.
     *
     * @param string $table
     * @param float $timeoutSeconds
     * @return void
     */
    private function waitForMutations(string $table = self::TABLE, float $timeoutSeconds = 10.0): void
    {
        $sql = 'SELECT mutation_id, command, latest_fail_reason FROM system.mutations'
            . " WHERE database = currentDatabase() AND table = '" . $table . "' AND is_done = 0";
        $deadline = microtime(true) + $timeoutSeconds;

        while (($pending = $this->client()->select($sql)->rows()) !== []) {
            if (microtime(true) >= $deadline) {
                $this->fail('Mutations still pending after ' . $timeoutSeconds . 's: ' . json_encode($pending));
            }
            usleep(10_000);
        }
    }

    private function serverVersion(): string
    {
        return $this->client()->select('SELECT version() AS version')->fetchOne('version');
    }

    /**
     * Determine if the server accepts DELETE FROM ... IN PARTITION, which ClickHouse
     * added in LIGHTWEIGHT_DELETE_IN_PARTITION_SINCE.
     *
     * @return bool
     */
    private function serverSupportsLightweightDeleteInPartition(): bool
    {
        return version_compare($this->serverVersion(), self::LIGHTWEIGHT_DELETE_IN_PARTITION_SINCE, '>=');
    }

    /**
     * Reconnect with extra connection config.
     *
     * @param string $name
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function reconnect(string $name, array $config): Connection
    {
        config(["database.connections.{$name}" => array_merge(config("database.connections.{$name}"), $config)]);
        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * A copy of the default connection that hands out Laravel's own query builder.
     *
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function laravelGrammarConnection(array $config): Connection
    {
        config(['database.connections.' . self::LARAVEL_GRAMMAR_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false],
            $config
        )]);
        DB::purge(self::LARAVEL_GRAMMAR_CONNECTION);

        return DB::connection(self::LARAVEL_GRAMMAR_CONNECTION);
    }
}
