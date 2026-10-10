<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\Http;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

class BuilderMutationSqlTest extends TestCase
{
    private Client&MockObject $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
    }

    /**
     * Expect exactly one write() call with the given SQL, and return the statement it answers with.
     *
     * @param string $sql
     * @return Statement
     */
    private function expectWrite(string $sql): Statement
    {
        $statement = $this->createStub(Statement::class);
        $this->client->expects($this->once())
            ->method('write')
            ->with($sql)
            ->willReturn($statement);

        return $statement;
    }

    private function expectNoWrite(): void
    {
        $this->client->expects($this->never())->method('write');
    }

    private function examples(): Builder
    {
        return $this->builder()->from('examples');
    }

    /**
     * A builder on the mock client that follows the options of a connection without a server, as Connection::query()
     * makes it: the connection gives delete() its use_lightweight_delete default, the default cluster of
     * use_on_cluster, and its pretend mode.
     *
     * @param Client|null $client The client to write with, the mock client by default
     * @param Connection|null $connection The connection to follow, one without options by default
     * @return Builder
     */
    private function builder(?Client $client = null, ?Connection $connection = null): Builder
    {
        return (new Builder($client ?? $this->client))->followConnectionOptions($connection ?? $this->connection());
    }

    /**
     * A connection without a server and without a PDO, which reads its options from the given config.
     *
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    /**
     * A connection whose use_on_cluster option sends the mutations ON CLUSTER 'company_cluster'.
     *
     * @return Connection
     */
    private function clusterConnection(): Connection
    {
        return $this->connection(['use_on_cluster' => true, 'cluster_name' => 'company_cluster']);
    }

    public function test_alter_delete(): void
    {
        $statement = $this->expectWrite('ALTER TABLE `examples` DELETE WHERE `f_int` = 1');

        $this->assertSame($statement, $this->examples()->where('f_int', 1)->delete(false));
    }

    public function test_lightweight_delete(): void
    {
        $this->expectWrite('DELETE FROM `examples` WHERE `f_int` = 1');

        $this->examples()->where('f_int', 1)->delete(true);
    }

    /**
     * @return array<string, array{bool, int|string|Expression, string}>
     */
    public static function deletePartitionProvider(): array
    {
        return [
            'alter, int partition' => [
                false, 202401,
                'ALTER TABLE `examples` DELETE IN PARTITION 202401 WHERE `f_int` = 1',
            ],
            'alter, string partition' => [
                false, '202401',
                "ALTER TABLE `examples` DELETE IN PARTITION '202401' WHERE `f_int` = 1",
            ],
            'alter, partition ID expression' => [
                false, new RawColumn("ID '202401'"),
                "ALTER TABLE `examples` DELETE IN PARTITION ID '202401' WHERE `f_int` = 1",
            ],
            'alter, tuple expression' => [
                false, new Expression("tuple(202401, 'eu')"),
                "ALTER TABLE `examples` DELETE IN PARTITION tuple(202401, 'eu') WHERE `f_int` = 1",
            ],
            'alter, string partition with a quote' => [
                false, "it's",
                "ALTER TABLE `examples` DELETE IN PARTITION 'it\\'s' WHERE `f_int` = 1",
            ],
            'lightweight, int partition' => [
                true, 202401,
                'DELETE FROM `examples` IN PARTITION 202401 WHERE `f_int` = 1',
            ],
            'lightweight, string partition' => [
                true, '202401',
                "DELETE FROM `examples` IN PARTITION '202401' WHERE `f_int` = 1",
            ],
            'lightweight, partition ID expression' => [
                true, new RawColumn("ID '202401'"),
                "DELETE FROM `examples` IN PARTITION ID '202401' WHERE `f_int` = 1",
            ],
        ];
    }

    #[DataProvider('deletePartitionProvider')]
    public function test_delete_in_partition(bool $lightweight, int|string|Expression $partition, string $expectedSql): void
    {
        $this->expectWrite($expectedSql);

        $this->examples()->where('f_int', 1)->delete($lightweight, $partition);
    }

    public function test_alter_delete_on_cluster(): void
    {
        $this->expectWrite("ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1");

        $this->examples()->where('f_int', 1)->onCluster('company_cluster')->delete(false);
    }

    public function test_lightweight_delete_on_cluster(): void
    {
        $this->expectWrite("DELETE FROM `examples` ON CLUSTER 'company_cluster' WHERE `f_int` = 1");

        $this->examples()->where('f_int', 1)->onCluster('company_cluster')->delete(true);
    }

    public function test_alter_delete_on_cluster_in_partition(): void
    {
        $this->expectWrite(
            "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE IN PARTITION 202401 WHERE `f_int` = 1 AND `f_string` = 'a'"
        );

        $this->examples()
            ->where('f_int', 1)
            ->where('f_string', 'a')
            ->onCluster('company_cluster')
            ->delete(false, 202401);
    }

    public function test_lightweight_delete_on_cluster_in_partition(): void
    {
        $this->expectWrite(
            "DELETE FROM `examples` ON CLUSTER 'company_cluster' IN PARTITION '202401' WHERE `f_int` = 1"
        );

        $this->examples()->where('f_int', 1)->onCluster('company_cluster')->delete(true, '202401');
    }

    public function test_cluster_name_is_escaped_as_a_string_literal(): void
    {
        $this->expectWrite("ALTER TABLE `examples` ON CLUSTER 'it\\'s a \\\\ cluster' DELETE WHERE `f_int` = 1");

        $this->examples()->where('f_int', 1)->onCluster("it's a \\ cluster")->delete(false);
    }

    public function test_delete_targets_the_sources_table_as_written(): void
    {
        $this->expectWrite('DELETE FROM db.examples_sources WHERE `f_int` = 1');

        $this->examples()->setSourcesTable('db.examples_sources')->where('f_int', 1)->delete(true);
    }

    public function test_delete_quotes_each_part_of_a_schema_qualified_table(): void
    {
        $this->expectWrite('ALTER TABLE `default`.`examples` DELETE WHERE `f_int` = 1');

        $this->builder()->from('default.examples')->where('f_int', 1)->delete(false);
    }

    public function test_delete_quotes_a_table_name_that_needs_quoting(): void
    {
        $this->expectWrite('DELETE FROM `default`.`my-table` WHERE `f_int` = 1');

        $this->builder()->from('default.my-table')->where('f_int', 1)->delete(true);
    }

    /**
     * ALTER TABLE ... DELETE / UPDATE and DELETE FROM have no PREWHERE, so the
     * prewhere conditions become part of the WHERE clause.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function preWhereMutationProvider(): array
    {
        $query = fn (Builder $builder): Builder => $builder->preWhere('d', '<', '2021-01-01')->where('f_int', 1);

        return [
            'alter delete' => [
                fn (Builder $builder) => $query($builder)->delete(false),
                "ALTER TABLE `examples` DELETE WHERE (`d` < '2021-01-01') AND (`f_int` = 1)",
            ],
            'lightweight delete' => [
                fn (Builder $builder) => $query($builder)->delete(true),
                "DELETE FROM `examples` WHERE (`d` < '2021-01-01') AND (`f_int` = 1)",
            ],
            'update' => [
                fn (Builder $builder) => $query($builder)->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE (`d` < '2021-01-01') AND (`f_int` = 1)",
            ],
            'alter delete on cluster in partition' => [
                fn (Builder $builder) => $query($builder)->onCluster('company_cluster')->delete(false, 202401),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE IN PARTITION 202401"
                . " WHERE (`d` < '2021-01-01') AND (`f_int` = 1)",
            ],
            'or conditions stay grouped' => [
                fn (Builder $builder) => $builder
                    ->preWhere('a', 1)
                    ->orPreWhere('a', 2)
                    ->where('b', 1)
                    ->orWhere('c', 1)
                    ->delete(false),
                'ALTER TABLE `examples` DELETE WHERE (`a` = 1 OR `a` = 2) AND (`b` = 1 OR `c` = 1)',
            ],
            'only a prewhere, delete' => [
                fn (Builder $builder) => $builder->preWhere('a', 1)->orPreWhere('a', 2)->delete(true),
                'DELETE FROM `examples` WHERE `a` = 1 OR `a` = 2',
            ],
            'only a prewhere, update' => [
                fn (Builder $builder) => $builder->preWhere('f_int', 1)->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` = 1",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('preWhereMutationProvider')]
    public function test_prewhere_conditions_are_part_of_the_mutation_where_clause(Closure $mutation, string $expectedSql): void
    {
        $this->expectWrite($expectedSql);

        $mutation($this->examples());
    }

    /**
     * The no-where check runs before use_lightweight_delete is read, so it needs no connection either.
     *
     * @return array<string, array{bool|null}>
     */
    public static function lightweightProvider(): array
    {
        return [
            'alter' => [false],
            'lightweight' => [true],
            'from config' => [null],
        ];
    }

    #[DataProvider('lightweightProvider')]
    public function test_delete_without_where_throws_before_sending_anything(?bool $lightweight): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete without a where condition');

        $this->examples()->delete($lightweight);
    }

    public function test_update_sets_each_column(): void
    {
        $statement = $this->expectWrite(
            "ALTER TABLE `examples` UPDATE `f_int2` = 3, `f_string` = 'b', `created_at` = created_at + INTERVAL 1 YEAR WHERE `f_int` = 1"
        );

        $this->assertSame($statement, $this->examples()->where('f_int', 1)->update([
            'f_int2' => 3,
            'f_string' => 'b',
            'created_at' => new RawColumn('created_at + INTERVAL 1 YEAR'),
        ]));
    }

    public function test_update_writes_an_array_value_as_an_array_literal(): void
    {
        $this->expectWrite(
            "ALTER TABLE `examples` UPDATE `tags` = ['a', 'it\\'s'], `numbers` = [1, 2], `nested` = [['x'], []], `empty` = []"
            . ' WHERE `f_int` = 1'
        );

        $this->examples()->where('f_int', 1)->update([
            'tags' => ['a', "it's"],
            'numbers' => [1, 2],
            'nested' => [['x'], []],
            'empty' => [],
        ]);
    }

    /**
     * @return array<string, array{int|string|Expression, string}>
     */
    public static function updatePartitionProvider(): array
    {
        return [
            'int partition' => [
                202402,
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' IN PARTITION 202402 WHERE `f_int` > 0",
            ],
            'string partition' => [
                '202402',
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' IN PARTITION '202402' WHERE `f_int` > 0",
            ],
            'partition ID expression' => [
                new RawColumn("ID '202402'"),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' IN PARTITION ID '202402' WHERE `f_int` > 0",
            ],
        ];
    }

    #[DataProvider('updatePartitionProvider')]
    public function test_update_in_partition(int|string|Expression $partition, string $expectedSql): void
    {
        $this->expectWrite($expectedSql);

        $this->examples()->where('f_int', '>', 0)->update(['f_string' => 'x'], $partition);
    }

    public function test_update_on_cluster(): void
    {
        $this->expectWrite("ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x' WHERE `f_int` = 1");

        $this->examples()->where('f_int', 1)->onCluster('company_cluster')->update(['f_string' => 'x']);
    }

    public function test_update_on_cluster_in_partition(): void
    {
        $this->expectWrite(
            "ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x' IN PARTITION 202402 WHERE `f_int` = 1"
        );

        $this->examples()->where('f_int', 1)->onCluster('company_cluster')->update(['f_string' => 'x'], 202402);
    }

    public function test_update_without_where_throws_before_sending_anything(): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot update without a where condition');

        $this->examples()->update(['f_string' => 'x']);
    }

    public function test_update_with_empty_values_still_reports_the_empty_values(): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Error updating empty values');

        $this->examples()->update([]);
    }

    /**
     * Each column name is one identifier: a backtick or a backslash in it is escaped,
     * so a name taken from user input cannot add assignments or end the statement.
     *
     * @return array<string, array{string, string}>
     */
    public static function updateColumnNameProvider(): array
    {
        return [
            'backtick' => ['we`ird', '`we``ird`'],
            'backslash' => ['back\\slash', '`back\\\\slash`'],
            'nested name' => ['n.a', '`n.a`'],
            'space' => ['my column', '`my column`'],
            'injection' => [
                "status` = 'x', `role` = 'admin' WHERE 1 -- ",
                "`status`` = 'x', ``role`` = 'admin' WHERE 1 -- `",
            ],
        ];
    }

    #[DataProvider('updateColumnNameProvider')]
    public function test_update_quotes_each_column_name_as_one_identifier(string $column, string $quotedColumn): void
    {
        $this->expectWrite("ALTER TABLE `examples` UPDATE {$quotedColumn} = 'ignored', `f_string` = 'x' WHERE `f_int` = 1");

        $this->examples()->where('f_int', 1)->update([$column => 'ignored', 'f_string' => 'x']);
    }

    /**
     * An empty list compiles to a condition that matches every row (NOT IN) or no
     * row (IN), as in a SELECT, so the mutation is sent with it.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function emptyListMutationProvider(): array
    {
        return [
            'not in, alter delete' => [
                fn (Builder $builder) => $builder->whereNotIn('id', [])->delete(false),
                'ALTER TABLE `examples` DELETE WHERE 1 = 1',
            ],
            'not in, lightweight delete' => [
                fn (Builder $builder) => $builder->whereNotIn('id', [])->delete(true),
                'DELETE FROM `examples` WHERE 1 = 1',
            ],
            'not in, update' => [
                fn (Builder $builder) => $builder->whereNotIn('id', [])->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE 1 = 1",
            ],
            'in, alter delete' => [
                fn (Builder $builder) => $builder->whereIn('id', [])->delete(false),
                'ALTER TABLE `examples` DELETE WHERE 0 = 1',
            ],
            'in, lightweight delete' => [
                fn (Builder $builder) => $builder->whereIn('id', [])->delete(true),
                'DELETE FROM `examples` WHERE 0 = 1',
            ],
            'in, update' => [
                fn (Builder $builder) => $builder->whereIn('id', [])->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE 0 = 1",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('emptyListMutationProvider')]
    public function test_a_mutation_with_an_empty_list_is_sent_with_the_condition_the_list_compiles_to(
        Closure $mutation,
        string $expectedSql
    ): void {
        $this->expectWrite($expectedSql);

        $mutation($this->examples());
    }

    /**
     * Clauses that change which rows the query selects, or what its conditions
     * read, but that a mutation, which only takes the where and prewhere
     * conditions, would ignore. ClickHouse lets the conditions read an alias that
     * an ORDER BY entry defines, so a condition on b would read the alias b of the
     * column a; raw SQL with AS in it for another reason, such as CAST(a AS String),
     * is refused too.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function ignoredClauseProvider(): array
    {
        return [
            'select alias' => [fn (Builder $query): Builder => $query->select('f_int', 'f_string as f_int2'), 'SELECT aliases or expressions'],
            'select alias given as an array key' => [
                fn (Builder $query): Builder => $query->select(['f_string' => 'f_int2']),
                'SELECT aliases or expressions',
            ],
            'select function' => [
                fn (Builder $query): Builder => $query->select(fn (Column $column) => $column->name('f_int')->plus(1)),
                'SELECT aliases or expressions',
            ],
            'distinct column' => [
                fn (Builder $query): Builder => $query->select(fn (Column $column) => $column->name('f_string')->distinct()),
                'SELECT aliases or expressions',
            ],
            'raw select column without an alias' => [
                fn (Builder $query): Builder => $query->select(new RawColumn('f_int')),
                'SELECT aliases or expressions',
            ],
            'raw select column with an alias' => [
                fn (Builder $query): Builder => $query->select('f_int', new RawColumn('f_int + 1', 'next')),
                'SELECT aliases or expressions',
            ],
            'select sub-query' => [
                fn (Builder $query): Builder => $query->select(['n' => $query->newQuery()->from('examples2')]),
                'SELECT aliases or expressions',
            ],
            'where dict' => [
                fn (Builder $query): Builder => $query->whereDict('dict', 'f_string', 'f_int', 'a'),
                'SELECT aliases or expressions',
            ],
            'with alias in the select list' => [
                fn (Builder $query): Builder => $query->withAlias('next', new Expression('f_int + 1'))->select('next'),
                'WITH and SELECT aliases or expressions',
            ],
            'from sub-query' => [
                fn (Builder $query): Builder => $query->from($query->newQuery()->from('examples2')),
                'a FROM sub-query',
            ],
            'from sub-query with a sources table' => [
                fn (Builder $query): Builder => $query->setSourcesTable('examples')->from(fn (From $from) => $from->query()->from('examples2')),
                'a FROM sub-query',
            ],
            'final' => [fn (Builder $query): Builder => $query->final(), 'FINAL'],
            'final given to from()' => [fn (Builder $query): Builder => $query->from('examples', null, true), 'FINAL'],
            'settings' => [fn (Builder $query): Builder => $query->settings(['max_threads' => 1]), 'SETTINGS'],
            'setting that filters rows' => [
                fn (Builder $query): Builder => $query->settings('additional_table_filters', new Expression("{'examples': 'f_int > 1'}")),
                'SETTINGS',
            ],
            'inner join' => [fn (Builder $query): Builder => $query->allInnerJoin('banned', ['user_id']), 'JOIN'],
            'semi join' => [fn (Builder $query): Builder => $query->semiLeftJoin('banned', ['user_id']), 'JOIN'],
            'anti join' => [fn (Builder $query): Builder => $query->antiLeftJoin('banned', ['user_id']), 'JOIN'],
            'array join' => [fn (Builder $query): Builder => $query->arrayJoin('tags'), 'ARRAY JOIN'],
            'left array join' => [fn (Builder $query): Builder => $query->leftArrayJoin('tags'), 'ARRAY JOIN'],
            'limit' => [fn (Builder $query): Builder => $query->orderBy('f_int')->limit(1), 'LIMIT'],
            'limit with an offset' => [fn (Builder $query): Builder => $query->limit(10, 5), 'LIMIT'],
            'limit by' => [fn (Builder $query): Builder => $query->limitBy(1, 'f_string'), 'LIMIT BY'],
            'group by' => [fn (Builder $query): Builder => $query->select('f_string')->groupBy('f_string'), 'GROUP BY'],
            'having' => [fn (Builder $query): Builder => $query->having(new Expression('count()'), '>', 1), 'HAVING'],
            'order by arrayJoin(), which drops the rows whose array is empty' => [
                fn (Builder $query): Builder => $query->orderByRaw('arrayJoin(tags)'),
                'ORDER BY',
            ],
            'order by an expression that calls arrayJoin(), after a plain order' => [
                fn (Builder $query): Builder => $query->orderBy('f_int')->orderBy(new Expression('length(arrayJoin(tags))'), 'desc'),
                'ORDER BY',
            ],
            'order by a nested column, which is neither a name nor raw sql' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name(fn (Column $inner) => $inner->name('f_int'))),
                'ORDER BY',
            ],
            'order by a name with as, whose alias the conditions read' => [fn (Builder $query): Builder => $query->orderBy('a as b'), 'ORDER BY'],
            'order by a name with as in upper case' => [fn (Builder $query): Builder => $query->orderBy('a AS b'), 'ORDER BY'],
            'order by a name with as, after a plain order' => [
                fn (Builder $query): Builder => $query->orderBy('f_int')->orderBy('a as b'),
                'ORDER BY',
            ],
            'order by desc a name with as' => [fn (Builder $query): Builder => $query->orderByDesc('a as b'), 'ORDER BY'],
            'order by a Stringable name with as' => [fn (Builder $query): Builder => $query->orderBy(Str::of('a as b')), 'ORDER BY'],
            'order by a column with an alias' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->as('b')),
                'ORDER BY',
            ],
            'order by a raw column with an alias' => [fn (Builder $query): Builder => $query->orderBy(new RawColumn('a', 'b')), 'ORDER BY'],
            'raw order by with AS' => [fn (Builder $query): Builder => $query->orderByRaw('a AS b'), 'ORDER BY'],
            'raw order by with as in lower case' => [fn (Builder $query): Builder => $query->orderByRaw('toString(id) as v'), 'ORDER BY'],
            'order by raw sql with AS for another reason, which is refused too' => [
                fn (Builder $query): Builder => $query->orderBy(raw('CAST(a AS String)')),
                'ORDER BY',
            ],
            'sample' => [fn (Builder $query): Builder => $query->sample(0.1), 'SAMPLE'],
            'union all' => [fn (Builder $query): Builder => $query->unionAll(fn (Builder $other) => $other->from('examples2')), 'UNION ALL'],
            'except' => [fn (Builder $query): Builder => $query->except(fn (Builder $other) => $other->from('examples2')), 'EXCEPT'],
            'intersect distinct' => [
                fn (Builder $query): Builder => $query->intersectDistinct(fn (Builder $other) => $other->from('examples2')),
                'INTERSECT DISTINCT',
            ],
            'with alias that shadows a column' => [
                fn (Builder $query): Builder => $query->withAlias('f_string', new Expression("if(f_int > 0, 'a', 'b')")),
                'WITH',
            ],
            'with expression' => [
                fn (Builder $query): Builder => $query->withExpression('w', fn (Builder $w) => $w->from('examples2')),
                'WITH',
            ],
            'order by plus() of raw sql with AS, whose alias the conditions read' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->plus(raw('0 AS b'))),
                'ORDER BY',
            ],
            'order by plus() of raw sql that calls arrayJoin()' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->plus(raw('arrayJoin(tags)'))),
                'ORDER BY',
            ],
            'order by multiple() of raw sql that calls arrayJoin()' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->multiple(raw('arrayJoin(tags)'))),
                'ORDER BY',
            ],
            'order by a raw column plus raw sql with AS' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name(raw('a'))->plus(raw('toString(1) AS b'))),
                'ORDER BY',
            ],
            'order by sumIf(), whose condition is raw sql' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->sumIf('a > 1')),
                'ORDER BY',
            ],
            'order by sum()' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->sum('a')), 'ORDER BY'],
            'order by max()' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->max('a')), 'ORDER BY'],
            'order by count()' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->count()), 'ORDER BY'],
            'order by distinct()' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('a')->distinct()), 'ORDER BY'],
            'raw order by with LIMIT BY, which keeps one row of each group' => [
                fn (Builder $query): Builder => $query->orderByRaw('id DESC LIMIT 1 BY grp'),
                'ORDER BY',
            ],
            'raw order by with a LIMIT' => [fn (Builder $query): Builder => $query->orderByRaw('id DESC LIMIT 2'), 'ORDER BY'],
            'raw order by with an OFFSET' => [fn (Builder $query): Builder => $query->orderByRaw('id OFFSET 3 ROWS'), 'ORDER BY'],
            'raw order by with FETCH, after a plain order' => [
                fn (Builder $query): Builder => $query->orderBy('f_int')->orderByRaw('id OFFSET 1 ROW FETCH FIRST 2 ROWS ONLY'),
                'ORDER BY',
            ],
            'raw order by with EXCEPT, which leaves out the rows of another select' => [
                fn (Builder $query): Builder => $query->orderByRaw('id EXCEPT SELECT * FROM examples WHERE f_int = 2'),
                'ORDER BY',
            ],
            'raw order by with INTERSECT in lower case' => [
                fn (Builder $query): Builder => $query->orderByRaw('id intersect select * from examples where f_int = 2'),
                'ORDER BY',
            ],
            'raw order by with UNION ALL, which adds the rows of another select' => [
                fn (Builder $query): Builder => $query->orderByRaw('id UNION ALL SELECT * FROM examples2'),
                'ORDER BY',
            ],
            'from a raw sub-query' => [
                fn (Builder $query): Builder => $query->from(raw('(SELECT * FROM examples WHERE a < 3)')),
                'a FROM table function or raw SQL',
            ],
            'from a raw sub-query, with a sources table' => [
                fn (Builder $query): Builder => $query->setSourcesTable('examples')->from(raw('(SELECT * FROM examples WHERE a < 3)')),
                'a FROM table function or raw SQL',
            ],
            'from a raw table function' => [fn (Builder $query): Builder => $query->from(raw('numbers(3)')), 'a FROM table function or raw SQL'],
            'from merge()' => [
                fn (Builder $query): Builder => $query->setSourcesTable('examples')->from(fn (From $from) => $from->merge('db', '^examples$')),
                'a FROM table function or raw SQL',
            ],
            'from remote()' => [
                fn (Builder $query): Builder => $query->from(fn (From $from) => $from->remote('h:9000', 'db', 'examples')),
                'a FROM table function or raw SQL',
            ],
            'from raw sql with FINAL' => [
                fn (Builder $query): Builder => $query->setSourcesTable('examples')->from(raw('examples FINAL')),
                'a FROM table function or raw SQL',
            ],
            'from raw sql with an alias' => [fn (Builder $query): Builder => $query->from(raw('examples AS x')), 'a FROM table function or raw SQL'],
            'from a raw column with an alias' => [
                fn (Builder $query): Builder => $query->from(new RawColumn('examples', 'x')),
                'a FROM table function or raw SQL',
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
            'delete from config' => [fn (Builder $query) => $query->delete(), 'delete'],
            'update' => [fn (Builder $query) => $query->update(['f_string' => 'x']), 'update'],
        ];
    }

    /**
     * Every ignored clause with every mutation. 'delete from config' needs no
     * connection: the clauses are checked before use_lightweight_delete is read.
     *
     * @return array<string, array{Closure(Builder): Builder, string, Closure(Builder): mixed, string}>
     */
    public static function mutationOfIgnoredClauseProvider(): array
    {
        $dataSets = [];
        foreach (self::ignoredClauseProvider() as $clauseLabel => [$clause, $clauseName]) {
            foreach (self::mutationProvider() as $mutationLabel => [$mutation, $statement]) {
                $dataSets["{$clauseLabel}, {$mutationLabel}"] = [$clause, $clauseName, $mutation, $statement];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $clause
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfIgnoredClauseProvider')]
    public function test_a_mutation_of_a_query_with_a_clause_it_would_ignore_throws_before_sending_anything(
        Closure $clause,
        string $clauseName,
        Closure $mutation,
        string $statement
    ): void {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Cannot {$statement} with a query that uses {$clauseName}: a ClickHouse ");

        $mutation($clause($this->examples()->where('f_int', 1)));
    }

    /**
     * The whole message, for each kind of statement and each sentence that a clause adds.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function refusedMutationMessageProvider(): array
    {
        return [
            'delete with two clauses' => [
                fn (Builder $query) => $query->select('id')->allInnerJoin('banned', ['user_id'])->final()->delete(false),
                'Cannot delete with a query that uses FINAL and JOIN: a ClickHouse DELETE only takes the where and'
                . ' prewhere conditions, so these clauses would be ignored, and the delete could remove rows that the'
                . " query does not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'update with one clause' => [
                fn (Builder $query) => $query->select('id', 'f_string as status')->update(['f_string' => 'x']),
                'Cannot update with a query that uses SELECT aliases or expressions: a ClickHouse UPDATE only takes the'
                . ' where and prewhere conditions, so this clause would be ignored, and the update could change rows'
                . " that the query does not select. Select the keys first, and update by them instead: whereIn('id',"
                . " \$query->pluck('id')).",
            ],
            'limit' => [
                fn (Builder $query) => $query->orderBy('id')->limit(1)->delete(true),
                'Cannot delete with a query that uses LIMIT: a ClickHouse DELETE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the delete could remove rows that the query does'
                . " not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'order by arrayJoin()' => [
                fn (Builder $query) => $query->orderByRaw('arrayJoin(tags)')->update(['f_string' => 'x']),
                'Cannot update with a query that uses ORDER BY: a ClickHouse UPDATE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the update could change rows that the query does'
                . " not select. Select the keys first, and update by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'order by an alias' => [
                fn (Builder $query) => $query->orderBy('a as b')->delete(false),
                'Cannot delete with a query that uses ORDER BY: a ClickHouse DELETE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the delete could remove rows that the query does'
                . " not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'update with a limit, which names no updateOrInsert()' => [
                fn (Builder $query) => $query->limit(1)->update(['f_string' => 'x']),
                'Cannot update with a query that uses LIMIT: a ClickHouse UPDATE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the update could change rows that the query does'
                . " not select. Select the keys first, and update by them instead: whereIn('id', \$query->pluck('id')).",
            ],
            'settings' => [
                fn (Builder $query) => $query->settings(['limit' => 1])->update(['f_string' => 'x']),
                'Cannot update with a query that uses SETTINGS: a ClickHouse UPDATE only takes the where and prewhere'
                . ' conditions, so this clause would be ignored, and the update could change rows that the query does'
                . " not select. Select the keys first, and update by them instead: whereIn('id', \$query->pluck('id'))."
                . ' Settings from settings() only apply to SELECT queries; put mutation settings, such as'
                . " mutations_sync, in the connection's settings.",
            ],
            'limit and settings' => [
                fn (Builder $query) => $query->limit(10, 20)->settings(['max_threads' => 1])->delete(false),
                'Cannot delete with a query that uses LIMIT and SETTINGS: a ClickHouse DELETE only takes the where and'
                . ' prewhere conditions, so these clauses would be ignored, and the delete could remove rows that the'
                . " query does not select. Select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id'))."
                . ' Settings from settings() only apply to SELECT queries; put mutation settings, such as'
                . " mutations_sync, in the connection's settings.",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('refusedMutationMessageProvider')]
    public function test_the_message_names_the_ignored_clauses(Closure $mutation, string $message): void
    {
        $this->expectNoWrite();

        try {
            $mutation($this->examples()->where('id', '>', 0));
            $this->fail('The mutation should throw');
        } catch (QueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function test_the_ignored_clauses_are_named_in_the_order_of_a_select(): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot delete with a query that uses WITH, SELECT aliases or expressions, a FROM sub-query, FINAL, SAMPLE,'
            . ' ARRAY JOIN, JOIN, GROUP BY, HAVING, ORDER BY, LIMIT BY, LIMIT, UNION ALL, EXCEPT and SETTINGS: '
        );

        $other = fn (Builder $query) => $query->from('examples2');
        $query = $this->examples();
        $query
            ->settings(['max_threads' => 1])
            ->unionAll($other)
            ->except($other)
            ->unionAll($other)
            ->limit(1)
            ->limitBy(1, 'f_string')
            ->orderByRaw('arrayJoin(tags)')
            ->having(new Expression('count()'), '>', 1)
            ->groupBy('f_string')
            ->allInnerJoin('banned', ['user_id'])
            ->arrayJoin('tags')
            ->sample(0.5)
            ->from($query->newQuery()->from('examples2'))
            ->final()
            ->select('f_string', new RawColumn('count()', 'c'))
            ->withAlias('a', 1)
            ->where('f_int', 1)
            ->delete(true);
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function mutationWithoutWhereAndWithAnIgnoredClauseProvider(): array
    {
        $query = fn (Builder $builder): Builder => $builder->allInnerJoin('banned', ['user_id'])->limit(1);

        return [
            'delete' => [fn (Builder $builder) => $query($builder)->delete(false), 'Cannot delete without a where condition'],
            'update' => [fn (Builder $builder) => $query($builder)->update(['f_string' => 'x']), 'Cannot update without a where condition'],
            'update without values' => [fn (Builder $builder) => $query($builder)->update([]), 'Error updating empty values'],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationWithoutWhereAndWithAnIgnoredClauseProvider')]
    public function test_the_earlier_checks_come_before_the_check_of_the_clauses(Closure $mutation, string $message): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);

        $mutation($this->examples());
    }

    /**
     * delete() without an argument reads use_lightweight_delete from the connection
     * only after it has checked the clauses, so a refused delete does not resolve the
     * connection. The DB facade gets a container that fails the test when it is asked
     * for the database manager, whatever an earlier test left behind.
     *
     * @param Closure(Builder): Builder $clause
     */
    #[DataProvider('ignoredClauseProvider')]
    public function test_a_refused_delete_does_not_resolve_the_connection(Closure $clause, string $clauseName): void
    {
        $this->expectNoWrite();
        $container = new Container();
        $container->bind('db', fn () => $this->fail('delete() resolved the connection before it checked the clauses'));
        $previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($container);

        try {
            $clause((new Builder($this->client))->from('examples')->where('f_int', 1))->delete();
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith("Cannot delete with a query that uses {$clauseName}: ", $exception->getMessage());
        } finally {
            Facade::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousApplication);
        }
    }

    /**
     * The README's Buffer pattern: a model selects from a Buffer table and mutates
     * its sources table, so a from() table other than the sources table is allowed.
     */
    public function test_a_from_table_other_than_the_sources_table_is_allowed(): void
    {
        $this->expectWrite("ALTER TABLE examples UPDATE `f_string` = 'x' WHERE `f_int` = 1");

        $this->builder()
            ->select(['*'])
            ->from('examples_buffer')
            ->setSourcesTable('examples')
            ->where('f_int', 1)
            ->update(['f_string' => 'x']);
    }

    /**
     * Clauses that do not change which rows match: the mutation is sent without them.
     * WITH FILL only adds rows that the table does not have. An ORDER BY entry that
     * defines no alias only sorts the rows: a name with "as" only inside a word, and
     * raw SQL whose "as" is only part of a word, such as ASC.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function allowedClauseProvider(): array
    {
        return [
            'order by' => [fn (Builder $query): Builder => $query->orderBy('f_int', 'desc')->orderByRaw('f_string')],
            'order by with fill' => [fn (Builder $query): Builder => $query->orderByRaw('f_int WITH FILL FROM 0 TO 10')],
            'raw order by a column whose name starts with arrayJoin' => [
                fn (Builder $query): Builder => $query->orderByRaw('arrayJoined_at DESC'),
            ],
            'raw order by with ASC' => [fn (Builder $query): Builder => $query->orderByRaw('a ASC')],
            'raw order by a column whose name has as inside a word' => [fn (Builder $query): Builder => $query->orderByRaw('has_items DESC')],
            'order by a name that starts with as' => [fn (Builder $query): Builder => $query->orderBy('alias')],
            'order by a name that ends with as' => [fn (Builder $query): Builder => $query->orderBy('gas')],
            'order by a name with as between underscores' => [fn (Builder $query): Builder => $query->orderBy('a_as_b')],
            'plain select list' => [fn (Builder $query): Builder => $query->select('f_int', 'examples.f_string', '*')],
            'final turned off' => [fn (Builder $query): Builder => $query->final()->final(false)],
            'format' => [fn (Builder $query): Builder => $query->format('CSV')],
            'settings removed again' => [fn (Builder $query): Builder => $query->settings(['max_threads' => 1])->settings('max_threads', null)],
            'all of them' => [fn (Builder $query): Builder => $query
                ->select('f_int', 'f_string')
                ->final(false)
                ->orderBy('f_int')
                ->format('JSON')],
            'order by plus() of a number' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_int')->plus(1))],
            'order by multiple() of a number' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_int')->multiple(2)),
            ],
            'order by plus() of a string with as, which is a literal' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_string')->plus('x as y')),
            ],
            'order by plus() of raw sql without AS' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_int')->plus(raw('length(f_string)'))),
            ],
            'order by round()' => [fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_int')->round(1))],
            'order by runningDifference()' => [
                fn (Builder $query): Builder => $query->orderBy(fn (Column $column) => $column->name('f_int')->runningDifference()),
            ],
            'raw order by with a LIMIT inside a sub-query' => [
                fn (Builder $query): Builder => $query->orderByRaw('(SELECT max(id) FROM t LIMIT 1) DESC'),
            ],
            'raw order by with LIMIT in a string literal' => [fn (Builder $query): Builder => $query->orderByRaw("position(f_string, 'LIMIT 1')")],
            'raw order by with EXCEPT inside a sub-query' => [
                fn (Builder $query): Builder => $query->orderByRaw('(SELECT max(id) FROM t EXCEPT SELECT 1) DESC'),
            ],
            'raw order by names that start with set operation words' => [
                fn (Builder $query): Builder => $query->orderByRaw('except_flag DESC, union_id, `intersect`'),
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function sentMutationProvider(): array
    {
        return [
            'alter delete' => [fn (Builder $query) => $query->delete(false), 'ALTER TABLE `examples` DELETE WHERE `f_int` = 1'],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), 'DELETE FROM `examples` WHERE `f_int` = 1'],
            'update' => [
                fn (Builder $query) => $query->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` = 1",
            ],
        ];
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, Closure(Builder): mixed, string}>
     */
    public static function mutationOfAllowedClauseProvider(): array
    {
        $dataSets = [];
        foreach (self::allowedClauseProvider() as $clauseLabel => [$clause]) {
            foreach (self::sentMutationProvider() as $mutationLabel => [$mutation, $expectedSql]) {
                $dataSets["{$clauseLabel}, {$mutationLabel}"] = [$clause, $mutation, $expectedSql];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $clause
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfAllowedClauseProvider')]
    public function test_a_mutation_leaves_out_the_clauses_that_do_not_change_which_rows_match(
        Closure $clause,
        Closure $mutation,
        string $expectedSql
    ): void {
        $this->expectWrite($expectedSql);

        $mutation($clause($this->examples()->where('f_int', 1)));
    }

    /**
     * The where variants that Laravel code uses are plain conditions: a mutation is sent with the condition they
     * compile to, as a SELECT reads it. The order of inRandomOrder(), latest() and oldest() only sorts the rows,
     * so it is left out, like any other ORDER BY entry that defines no alias.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function laravelConditionProvider(): array
    {
        $except = 'SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, b + 100 FROM `t` WHERE `id` = 1';

        return [
            'whereColumn' => [fn (Builder $query) => $query->whereColumn('f_int', '<', 'f_int2'), 'WHERE `f_int` < `f_int2`'],
            'whereColumn with a list' => [
                fn (Builder $query) => $query->whereColumn([['f_int', 'f_int2'], ['a', '<=', 'b']]),
                'WHERE (`f_int` = `f_int2` AND `a` <= `b`)',
            ],
            'orWhereColumn' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereColumn('a', 'b'), 'WHERE `f_int` = 1 OR `a` = `b`'],
            'preWhereColumn and a where' => [
                fn (Builder $query) => $query->preWhereColumn('a', '<=', 'b')->where('f_int', 1),
                'WHERE (`a` <= `b`) AND (`f_int` = 1)',
            ],
            'whereExists' => [
                fn (Builder $query) => $query->where('f_int', 5)->whereExists(fn (Builder $sub) => $sub->from('u')->where('v', 'p')),
                "WHERE `f_int` = 5 AND EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            ],
            'whereNotExists with except' => [
                fn (Builder $query) => $query->whereNotExists(
                    fn (Builder $sub) => $sub->select('a', 'b')->from('t')->where('id', 1)
                        ->except(fn (Builder $other) => $other->select('a', new Expression('b + 100'))->from('t')->where('id', 1))
                ),
                "WHERE NOT EXISTS (SELECT * FROM ({$except}) WHERE NOT ignore(*))",
            ],
            'whereAll' => [fn (Builder $query) => $query->whereAll(['a', 'b'], '>', 1), 'WHERE (`a` > 1 AND `b` > 1)'],
            'whereAny' => [fn (Builder $query) => $query->whereAny(['a', 'b'], 3), 'WHERE (`a` = 3 OR `b` = 3)'],
            'whereNone' => [fn (Builder $query) => $query->whereNone(['a', 'b'], 1), 'WHERE NOT (`a` = 1 OR `b` = 1)'],
            'orWhereNone' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereNone(['a', 'b'], '>', 1), 'WHERE `f_int` = 1 OR NOT (`a` > 1 OR `b` > 1)'],
            'preWhereAny' => [fn (Builder $query) => $query->preWhereAny(['a', 'b'], 5), 'WHERE (`a` = 5 OR `b` = 5)'],
            'whereDate' => [fn (Builder $query) => $query->whereDate('created_at', '2024-01-05'), "WHERE toDate32(`created_at`) = '2024-01-05'"],
            'whereTime' => [fn (Builder $query) => $query->whereTime('created_at', '>=', '10:00'), "WHERE formatDateTime(`created_at`, '%H:%i:%S') >= '10:00:00'"],
            'whereDay' => [fn (Builder $query) => $query->whereDay('created_at', '05'), 'WHERE toDayOfMonth(`created_at`) = 5'],
            'whereMonth' => [fn (Builder $query) => $query->whereMonth('created_at', 2), 'WHERE toMonth(`created_at`) = 2'],
            'whereYear' => [fn (Builder $query) => $query->whereYear('created_at', '<', 2024), 'WHERE toYear(`created_at`) < 2024'],
            'preWhereDate and a where' => [
                fn (Builder $query) => $query->preWhereDate('created_at', '>=', '2024-02-01')->where('f_int', '>', 0),
                "WHERE (toDate32(`created_at`) >= '2024-02-01') AND (`f_int` > 0)",
            ],
            'whereLike' => [fn (Builder $query) => $query->whereLike('f_string', 'X%'), "WHERE `f_string` ILIKE 'X%'"],
            'whereNotLike, case-sensitive' => [fn (Builder $query) => $query->whereNotLike('f_string', 'x%', true), "WHERE `f_string` NOT LIKE 'x%'"],
            'lower-case operators and booleans' => [
                fn (Builder $query) => $query->where('f_int', 'not in', [2])->where('f_string', 'like', '%c', 'or'),
                "WHERE `f_int` NOT IN (2) OR `f_string` LIKE '%c'",
            ],
            'whereBetween with a keyed list' => [
                fn (Builder $query) => $query->whereBetween('f_int', ['from' => 2, 'to' => 3]),
                'WHERE `f_int` BETWEEN 2 AND 3',
            ],
            'a group that adds no condition, next to a where' => [
                fn (Builder $query) => $query->where('f_int', 2)->where(fn (Builder $group) => $group),
                'WHERE `f_int` = 2',
            ],
            'inRandomOrder' => [fn (Builder $query) => $query->where('f_int', 1)->inRandomOrder(), 'WHERE `f_int` = 1'],
            'latest and oldest' => [fn (Builder $query) => $query->where('f_int', 1)->latest()->oldest('f_int'), 'WHERE `f_int` = 1'],
            'when' => [fn (Builder $query) => $query->when(true, fn (Builder $q) => $q->where('f_int', 1)), 'WHERE `f_int` = 1'],
            'unless' => [fn (Builder $query) => $query->unless(false, fn (Builder $q) => $q->where('f_int', 1)), 'WHERE `f_int` = 1'],
            'orWhereColumn with a list' => [
                fn (Builder $query) => $query->where('f_int', 1)->orWhereColumn([['a', 'b'], ['c', '<', 'd']]),
                'WHERE `f_int` = 1 OR (`a` = `b` OR `c` < `d`)',
            ],
            'orPreWhereColumn' => [fn (Builder $query) => $query->preWhere('f_int', 1)->orPreWhereColumn('a', 'b'), 'WHERE `f_int` = 1 OR `a` = `b`'],
            'orWhereExists' => [
                fn (Builder $query) => $query->where('f_int', 5)->orWhereExists(fn (Builder $sub) => $sub->from('u')),
                'WHERE `f_int` = 5 OR EXISTS (SELECT * FROM `u`)',
            ],
            'orWhereNotExists' => [
                fn (Builder $query) => $query->where('f_int', 5)->orWhereNotExists(fn (Builder $sub) => $sub->from('u')),
                'WHERE `f_int` = 5 OR NOT EXISTS (SELECT * FROM `u`)',
            ],
            'orWhereAll' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereAll(['a', 'b'], '>', 1), 'WHERE `f_int` = 1 OR (`a` > 1 AND `b` > 1)'],
            'orWhereAny' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereAny(['a', 'b'], 3), 'WHERE `f_int` = 1 OR (`a` = 3 OR `b` = 3)'],
            'preWhereAll' => [fn (Builder $query) => $query->preWhereAll(['a', 'b'], '>', 1), 'WHERE (`a` > 1 AND `b` > 1)'],
            'preWhereNone and a where' => [
                fn (Builder $query) => $query->preWhereNone(['a', 'b'], 1)->where('f_int', 2),
                'WHERE (NOT (`a` = 1 OR `b` = 1)) AND (`f_int` = 2)',
            ],
            'orWhereDate' => [
                fn (Builder $query) => $query->where('f_int', 1)->orWhereDate('created_at', '2024-01-05'),
                "WHERE `f_int` = 1 OR toDate32(`created_at`) = '2024-01-05'",
            ],
            'orWhereTime' => [
                fn (Builder $query) => $query->where('f_int', 1)->orWhereTime('created_at', '<', '9:30'),
                "WHERE `f_int` = 1 OR formatDateTime(`created_at`, '%H:%i:%S') < '09:30:00'",
            ],
            'orWhereLike' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereLike('f_string', 'x%'), "WHERE `f_int` = 1 OR `f_string` ILIKE 'x%'"],
            'orWhereNotLike' => [fn (Builder $query) => $query->where('f_int', 1)->orWhereNotLike('f_string', 'x%'), "WHERE `f_int` = 1 OR `f_string` NOT ILIKE 'x%'"],
            'whereNotBetween' => [fn (Builder $query) => $query->whereNotBetween('f_int', [1, 2]), 'WHERE NOT ( `f_int` BETWEEN 1 AND 2 )'],
            'whereBetween with a null bound' => [fn (Builder $query) => $query->whereBetween('f_int', [null, 5]), 'WHERE `f_int` BETWEEN NULL AND 5'],
            'whereBetweenColumns' => [
                fn (Builder $query) => $query->whereBetweenColumns('f_int', ['f_int2', new Expression('f_int2 + 1')]),
                'WHERE `f_int` BETWEEN `f_int2` AND f_int2 + 1',
            ],
            'preWhereNotBetweenColumns and a where' => [
                fn (Builder $query) => $query->preWhereNotBetweenColumns('f_int', ['a', 'b'])->where('f_int', 1),
                'WHERE (NOT ( `f_int` BETWEEN `a` AND `b` )) AND (`f_int` = 1)',
            ],
            'where with an array' => [
                fn (Builder $query) => $query->where(['f_int' => 1, 'f_string' => 'x']),
                "WHERE (`f_int` = 1 AND `f_string` = 'x')",
            ],
            'orWhere with an array' => [
                fn (Builder $query) => $query->where('f_int', 1)->orWhere([['f_int', '>', 5], ['f_string', 'x']]),
                "WHERE `f_int` = 1 OR (`f_int` > 5 OR `f_string` = 'x')",
            ],
        ];
    }

    /**
     * Every condition with every mutation, except the lightweight delete of whereNotExists() with EXCEPT, which is
     * refused (see test_a_lightweight_delete_with_a_set_operation_is_refused_before_anything_is_sent()).
     *
     * @return array<string, array{Closure(Builder): Builder, Closure(Builder): mixed, string}>
     */
    public static function mutationOfLaravelConditionProvider(): array
    {
        $mutations = [
            'alter delete' => [fn (Builder $query) => $query->delete(false), 'ALTER TABLE `examples` DELETE '],
            'lightweight delete' => [fn (Builder $query) => $query->delete(true), 'DELETE FROM `examples` '],
            'update' => [fn (Builder $query) => $query->update(['f_string' => 'x']), "ALTER TABLE `examples` UPDATE `f_string` = 'x' "],
        ];
        $dataSets = [];

        foreach (self::laravelConditionProvider() as $conditionLabel => [$condition, $whereClause]) {
            foreach ($mutations as $mutationLabel => [$mutation, $statement]) {
                if ($conditionLabel === 'whereNotExists with except' && $mutationLabel === 'lightweight delete') {
                    continue;
                }
                $dataSets["{$conditionLabel}, {$mutationLabel}"] = [$condition, $mutation, $statement . $whereClause];
            }
        }

        return $dataSets;
    }

    /**
     * @param Closure(Builder): Builder $condition
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfLaravelConditionProvider')]
    public function test_a_mutation_takes_the_laravel_where_variants_as_plain_conditions(
        Closure $condition,
        Closure $mutation,
        string $expectedSql
    ): void {
        $this->expectWrite($expectedSql);

        $mutation($condition($this->examples()));
    }

    /**
     * A group that adds no condition is left out, so a query with nothing else is a query without a where
     * condition, which a mutation refuses.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function emptyGroupMutationProvider(): array
    {
        return [
            'delete' => [fn (Builder $query) => $query->where(fn (Builder $group) => $group)->delete(false), 'Cannot delete without a where condition'],
            'lightweight delete' => [
                fn (Builder $query) => $query->where(fn (Builder $group) => $group)->preWhere(fn (Builder $group) => $group)->delete(true),
                'Cannot delete without a where condition',
            ],
            'update' => [fn (Builder $query) => $query->where(fn (Builder $group) => $group)->update(['f_string' => 'x']), 'Cannot update without a where condition'],
            'delete with an empty array' => [fn (Builder $query) => $query->where([])->delete(false), 'Cannot delete without a where condition'],
            'lightweight delete with an empty array' => [fn (Builder $query) => $query->where([])->orWhere([])->delete(true), 'Cannot delete without a where condition'],
            'update with an empty array' => [fn (Builder $query) => $query->preWhere([])->update(['f_string' => 'x']), 'Cannot update without a where condition'],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('emptyGroupMutationProvider')]
    public function test_a_mutation_with_only_a_group_that_adds_no_condition_throws(Closure $mutation, string $message): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($message);

        $mutation($this->examples());
    }

    public function test_a_mutation_after_in_random_order_with_a_limit_is_refused(): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete with a query that uses LIMIT: ');

        $this->examples()->where('f_int', 1)->inRandomOrder()->limit(1)->delete(false);
    }

    public function test_truncate(): void
    {
        $statement = $this->expectWrite('TRUNCATE TABLE `examples`');

        $this->assertSame($statement, $this->examples()->truncate());
    }

    public function test_truncate_on_cluster(): void
    {
        $this->expectWrite("TRUNCATE TABLE `examples` ON CLUSTER 'company_cluster'");

        $this->examples()->onCluster('company_cluster')->truncate();
    }

    /**
     * The from() table is compiled as in a SELECT, which DatabaseTruncation relies on:
     * it truncates every table through $connection->table('<database>.<table>').
     *
     * @return array<string, array{string|Expression, string}>
     */
    public static function truncatedTableProvider(): array
    {
        return [
            'schema-qualified name' => ['default.examples', 'TRUNCATE TABLE `default`.`examples`'],
            'name that needs quoting' => ['default.my-table', 'TRUNCATE TABLE `default`.`my-table`'],
            'name with a backtick' => ['we`ird', 'TRUNCATE TABLE `we``ird`'],
            'expression' => [new Expression('default.examples'), 'TRUNCATE TABLE default.examples'],
        ];
    }

    #[DataProvider('truncatedTableProvider')]
    public function test_truncate_compiles_the_from_table(string|Expression $table, string $expectedSql): void
    {
        $this->expectWrite($expectedSql);

        $this->builder()->from($table)->truncate();
    }

    public function test_truncate_ignores_where_conditions(): void
    {
        $this->expectWrite('TRUNCATE TABLE `examples`');

        $this->examples()->where('f_int', 1)->truncate();
    }

    public function test_truncate_targets_the_sources_table(): void
    {
        $this->expectWrite('TRUNCATE TABLE examples_sources');

        $this->examples()->setSourcesTable('examples_sources')->truncate();
    }

    public function test_a_query_without_a_table_throws_before_sending_anything(): void
    {
        $this->expectNoWrite();

        $this->expectException(GrammarException::class);

        $this->builder()->truncate();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function insertedTableProvider(): array
    {
        return [
            'name' => [fn (Builder $builder): Builder => $builder->from('examples'), '`examples`'],
            'schema-qualified name that needs quoting' => [
                fn (Builder $builder): Builder => $builder->from('default.my-table'),
                '`default`.`my-table`',
            ],
            'sources table as written' => [
                fn (Builder $builder): Builder => $builder->from('examples')->setSourcesTable('db.examples_sources'),
                'db.examples_sources',
            ],
        ];
    }

    /**
     * A client whose transport adds the SQL that smi2's insert() builds to $sentSql, without sending it.
     *
     * @param string[] $sentSql
     * @return Client
     */
    private function clientRecordingInserts(array &$sentSql): Client
    {
        $transport = $this->createStub(Http::class);
        $transport->method('write')->willReturnCallback(function (string $sql) use (&$sentSql): Statement {
            $sentSql[] = $sql;

            return $this->createStub(Statement::class);
        });
        $client = $this->createPartialMock(Client::class, ['transport']);
        $client->method('transport')->willReturn($transport);

        return $client;
    }

    /**
     * smi2's insert() leaves a table name that contains a backtick or a dot as it is.
     *
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('insertedTableProvider')]
    public function test_insert_targets_the_table_for_writes(Closure $query, string $expectedTable): void
    {
        $sentSql = [];

        $query($this->builder($this->clientRecordingInserts($sentSql)))->insert([['f_int' => 1, 'f_string' => 'a']]);

        $this->assertSame(["INSERT INTO {$expectedTable} (`f_int`,`f_string`)  VALUES  (1,'a')"], $sentSql);
    }

    /**
     * smi2's insert() writes each column name between backticks as given, so each
     * key is escaped first and stays one name. The last data set is a column name
     * with a backtick and a parenthesis that tries to close the column list.
     *
     * @return array<string, array{string, string}>
     */
    public static function insertedColumnNameProvider(): array
    {
        return [
            'plain name' => ['f_string', '`f_string`'],
            'nested name' => ['n.a', '`n.a`'],
            'backtick' => ['tick`name', '`tick``name`'],
            'trailing backslash' => ['ends\\', '`ends\\\\`'],
            'backtick and parenthesis' => ["f_string`) VALUES (2, 'x') -- ", "`f_string``) VALUES (2, 'x') -- `"],
        ];
    }

    #[DataProvider('insertedColumnNameProvider')]
    public function test_insert_escapes_each_column_name(string $column, string $quotedColumn): void
    {
        $sentSql = [];

        $this->builder($this->clientRecordingInserts($sentSql))->from('examples')->insert([
            ['f_int' => 1, $column => 'a'],
            ['f_int' => 2, $column => 'b'],
        ]);

        $this->assertSame(["INSERT INTO `examples` (`f_int`,{$quotedColumn})  VALUES  (1,'a'),  (2,'b')"], $sentSql);
    }

    /**
     * smi2's prepareInsertAssocBulk() still checks that every row has the keys of the first, in the same order.
     */
    public function test_insert_of_rows_with_other_keys_throws_before_sending_anything(): void
    {
        $sentSql = [];

        try {
            $this->builder($this->clientRecordingInserts($sentSql))->from('examples')->insert([
                ['f_int' => 1, 'f_string' => 'a'],
                ['f_string' => 'b', 'f_int' => 2],
            ]);
            $this->fail('The insert should throw');
        } catch (ClientQueryException $exception) {
            $this->assertSame('Fields not match: f_string,f_int and f_int,f_string on element 1', $exception->getMessage());
        }

        $this->assertSame([], $sentSql);
    }

    /**
     * A lightweight delete whose conditions hold UNION, INTERSECT or EXCEPT fails on ClickHouse (24.8 checked) and
     * leaves a mutation behind that blocks every later mutation of the table, so it is refused before anything is
     * sent; ALTER TABLE ... DELETE runs the same condition.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function setOperationConditionProvider(): array
    {
        return [
            'whereIn with union all' => [
                fn (Builder $query): Builder => $query->whereIn(
                    'f_int',
                    fn (Builder $sub) => $sub->select('id')->from('u')->unionAll(fn (Builder $other) => $other->select('id')->from('v'))
                ),
            ],
            'whereIn with union distinct' => [
                fn (Builder $query): Builder => $query->whereIn(
                    'f_int',
                    fn (Builder $sub) => $sub->select('id')->from('u')->unionDistinct(fn (Builder $other) => $other->select('id')->from('v'))
                ),
            ],
            'whereExists with intersect' => [
                fn (Builder $query): Builder => $query->whereExists(
                    fn (Builder $sub) => $sub->from('u')->intersect(fn (Builder $other) => $other->from('v'))
                ),
            ],
            'whereNotExists with except' => [
                fn (Builder $query): Builder => $query->whereNotExists(
                    fn (Builder $sub) => $sub->select('a', 'b')->from('t')->where('id', 1)
                        ->except(fn (Builder $other) => $other->select('a', new Expression('b + 100'))->from('t')->where('id', 1))
                ),
            ],
            'preWhereIn with except distinct' => [
                fn (Builder $query): Builder => $query->preWhereIn(
                    'f_int',
                    fn (Builder $sub) => $sub->select('id')->from('u')->exceptDistinct(fn (Builder $other) => $other->select('id')->from('v'))
                ),
            ],
            'raw sql in lower case' => [fn (Builder $query): Builder => $query->whereRaw('f_int in (select 1 union all select 2)')],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('setOperationConditionProvider')]
    public function test_a_lightweight_delete_with_a_set_operation_is_refused_before_anything_is_sent(Closure $condition): void
    {
        $this->expectNoWrite();
        $this->client->expects($this->never())->method('select');

        try {
            $condition($this->examples())->delete(true);
            $this->fail('The delete should throw');
        } catch (QueryException $exception) {
            $this->assertSame(
                'Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION, INTERSECT or'
                . ' EXCEPT: ClickHouse (24.8 checked) fails such a delete and leaves its mutation behind, which blocks'
                . ' every later mutation of the table until KILL MUTATION. Nothing was sent. Call delete(false), which'
                . ' sends ALTER TABLE ... DELETE with the same condition, or select the keys first, and delete by them'
                . " instead: whereIn('id', \$query->pluck('id')).",
                $exception->getMessage()
            );
        }
    }

    /**
     * use_lightweight_delete makes delete() without an argument a lightweight delete, which is refused as well.
     */
    public function test_a_delete_that_the_connection_makes_lightweight_is_refused_for_a_set_operation(): void
    {
        $this->expectNoWrite();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION');

        $this->builder(null, $this->connection(['use_lightweight_delete' => true]))
            ->from('examples')
            ->whereRaw('f_int IN (SELECT 1 UNION ALL SELECT 2)')
            ->delete();
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('setOperationConditionProvider')]
    public function test_alter_delete_and_update_with_a_set_operation_are_sent(Closure $condition): void
    {
        $sentSql = [];
        $this->client->method('write')->willReturnCallback(function (string $sql) use (&$sentSql): Statement {
            $sentSql[] = $sql;

            return $this->createStub(Statement::class);
        });

        $condition($this->examples())->delete(false);
        $condition($this->examples())->update(['f_string' => 'x']);

        $this->assertCount(2, $sentSql);
        $this->assertStringStartsWith('ALTER TABLE `examples` DELETE WHERE ', $sentSql[0]);
        $this->assertStringStartsWith("ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE ", $sentSql[1]);
    }

    /**
     * A set operation word in a string literal or a quoted name is no set operation.
     */
    public function test_a_lightweight_delete_with_a_set_operation_word_in_a_value_or_a_name_is_sent(): void
    {
        $this->expectWrite("DELETE FROM `examples` WHERE `f_string` = 'a UNION b' AND `except` = 1");

        $this->examples()->where('f_string', 'a UNION b')->where('except', 1)->delete(true);
    }

    /**
     * A correlated sub-query: ClickHouse (24.8 checked) refuses it in a SELECT, but accepts it in a mutation that
     * then never finishes and blocks every later mutation of the table.
     *
     * @return Closure(Builder): Builder
     */
    private static function correlatedCondition(): Closure
    {
        return fn (Builder $query): Builder => $query->whereExists(
            fn (Builder $sub) => $sub->from('u')->whereColumn('u.id', 'examples.f_int')
        );
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function mutationOfACorrelatedSubQueryProvider(): array
    {
        $condition = self::correlatedCondition();

        return [
            'alter delete' => [fn (Builder $query) => $condition($query)->delete(false), 'delete'],
            'lightweight delete' => [fn (Builder $query) => $condition($query)->delete(true), 'delete'],
            'update' => [fn (Builder $query) => $condition($query)->update(['f_string' => 'x']), 'update'],
            'update in partition on a cluster' => [
                fn (Builder $query) => $condition($query)->onCluster('company_cluster')->update(['f_string' => 'x'], 202401),
                'update',
            ],
        ];
    }

    /**
     * A mutation whose conditions hold a sub-query is checked with EXPLAIN PLAN on the builder's client first, and
     * refused when ClickHouse refuses the condition in a SELECT.
     *
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('mutationOfACorrelatedSubQueryProvider')]
    public function test_a_mutation_whose_sub_query_clickhouse_cannot_run_is_refused_before_it_is_sent(
        Closure $mutation,
        string $statement
    ): void {
        $refusal = DatabaseException::fromClickHouse(
            "Resolve identifier 'examples.f_int' from parent scope only supported for constants and CTE.",
            1,
            'UNSUPPORTED_METHOD'
        );
        $explained = $this->createStub(Statement::class);
        $explained->method('rows')->willThrowException($refusal);
        $this->client->expects($this->once())
            ->method('select')
            ->with(
                'EXPLAIN PLAN SELECT 1 FROM `examples` WHERE EXISTS (SELECT * FROM `u` WHERE `u`.`id` = `examples`.`f_int`)'
                . "\nSETTINGS use_index_for_in_with_subqueries = 0"
            )
            ->willReturn($explained);
        $this->expectNoWrite();

        try {
            $mutation($this->examples());
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
     * The EXPLAIN of a sub-query that ClickHouse can run returns a plan, and the mutation is sent after it. The
     * EXPLAIN ends with SETTINGS use_index_for_in_with_subqueries = 0, on a line of its own, so that ClickHouse does
     * not run an IN sub-query on a column of the primary key to build its set for the index.
     */
    public function test_a_mutation_whose_sub_query_clickhouse_can_run_is_sent_after_its_explain(): void
    {
        $calls = [];
        $this->client->method('select')->willReturnCallback(function (string $sql) use (&$calls): Statement {
            $calls[] = "select: {$sql}";

            return $this->createStub(Statement::class);
        });
        $this->client->method('write')->willReturnCallback(function (string $sql) use (&$calls): Statement {
            $calls[] = "write: {$sql}";

            return $this->createStub(Statement::class);
        });

        $this->examples()->where('f_int', 1)->whereIn('f_int2', fn (Builder $sub) => $sub->select('id')->from('u'))->delete(false);

        $this->assertSame(
            [
                'select: EXPLAIN PLAN SELECT 1 FROM `examples` WHERE `f_int` = 1 AND `f_int2` IN (SELECT `id` FROM `u`)'
                    . "\nSETTINGS use_index_for_in_with_subqueries = 0",
                'write: ALTER TABLE `examples` DELETE WHERE `f_int` = 1 AND `f_int2` IN (SELECT `id` FROM `u`)',
            ],
            $calls
        );
    }

    /**
     * Conditions without a sub-query are checked by ClickHouse when it gets the mutation, so no EXPLAIN is sent for
     * them, nor for SELECT in a value or a name.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function conditionWithoutASubQueryProvider(): array
    {
        return [
            'plain conditions' => [fn (Builder $query): Builder => $query->where('f_int', 1)->whereIn('f_int2', [1, 2])],
            'select in a value' => [fn (Builder $query): Builder => $query->where('f_string', 'SELECT 1 FROM t')],
            'select as a name' => [fn (Builder $query): Builder => $query->where('select', 1)],
            'in with a list after spaces' => [fn (Builder $query): Builder => $query->whereRaw('f_int IN  (1, 2)')],
            'not in with a list' => [fn (Builder $query): Builder => $query->whereNotIn('f_int2', [1, 2])],
            'in in a value and as a name' => [fn (Builder $query): Builder => $query->where('f_string', 'x IN y')->where('in', 1)],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('conditionWithoutASubQueryProvider')]
    public function test_a_mutation_without_a_sub_query_sends_no_explain(Closure $condition): void
    {
        $this->client->expects($this->never())->method('select');
        $this->client->expects($this->exactly(3))->method('write')->willReturn($this->createStub(Statement::class));

        $condition($this->examples())->delete(false);
        $condition($this->examples())->delete(true);
        $condition($this->examples())->update(['f_string' => 'x']);
    }

    /**
     * IN followed by a table, rather than a list or a sub-query, names a table that ClickHouse resolves only when the
     * mutation runs, as a sub-query does, so the mutation is checked with EXPLAIN as well.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function inATableConditionProvider(): array
    {
        return [
            'whereIn() of a raw table' => [
                fn (Builder $query): Builder => $query->whereIn('f_int', raw('ids')),
                'WHERE `f_int` IN ids',
            ],
            'whereNotIn() of a raw table' => [
                fn (Builder $query): Builder => $query->whereNotIn('f_int', raw('ids')),
                'WHERE `f_int` NOT IN ids',
            ],
            'raw IN of a quoted table with a database' => [
                fn (Builder $query): Builder => $query->whereRaw('f_int IN `db`.`ids`'),
                'WHERE f_int IN `db`.`ids`',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('inATableConditionProvider')]
    public function test_a_mutation_by_in_a_table_is_refused_when_clickhouse_cannot_run_it(Closure $condition, string $wheres): void
    {
        $refusal = DatabaseException::fromClickHouse("Unknown expression or table expression identifier 'ids'", 47, 'UNKNOWN_IDENTIFIER');
        $explained = $this->createStub(Statement::class);
        $explained->method('rows')->willThrowException($refusal);
        $this->client->expects($this->once())
            ->method('select')
            ->with("EXPLAIN PLAN SELECT 1 FROM `examples` {$wheres}\nSETTINGS use_index_for_in_with_subqueries = 0")
            ->willReturn($explained);
        $this->expectNoWrite();

        try {
            $condition($this->examples())->delete(false);
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
     * use_on_cluster sends delete(), update() and truncate() ON CLUSTER to the cluster_name of the followed connection.
     * onCluster() names another cluster, withoutOnCluster() leaves ON CLUSTER out, and a later onCluster() names a
     * cluster again. A builder that newQuery() makes follows the same connection.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function defaultClusterProvider(): array
    {
        $examples = fn (Builder $query): Builder => $query->from('examples')->where('f_int', 1);

        return [
            'alter delete' => [
                fn (Builder $query) => $examples($query)->delete(false),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1",
            ],
            'alter delete in partition' => [
                fn (Builder $query) => $examples($query)->delete(false, 202401),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE IN PARTITION 202401 WHERE `f_int` = 1",
            ],
            'lightweight delete' => [
                fn (Builder $query) => $examples($query)->delete(true),
                "DELETE FROM `examples` ON CLUSTER 'company_cluster' WHERE `f_int` = 1",
            ],
            'lightweight delete in partition' => [
                fn (Builder $query) => $examples($query)->delete(true, '202401'),
                "DELETE FROM `examples` ON CLUSTER 'company_cluster' IN PARTITION '202401' WHERE `f_int` = 1",
            ],
            'update' => [
                fn (Builder $query) => $examples($query)->update(['f_string' => 'x']),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x' WHERE `f_int` = 1",
            ],
            'update in partition' => [
                fn (Builder $query) => $examples($query)->update(['f_string' => 'x'], 202401),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x' IN PARTITION 202401 WHERE `f_int` = 1",
            ],
            'truncate' => [fn (Builder $query) => $query->from('examples')->truncate(), "TRUNCATE TABLE `examples` ON CLUSTER 'company_cluster'"],
            'a query of newQuery()' => [
                fn (Builder $query) => $examples($query->newQuery())->delete(false),
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1",
            ],
            'onCluster() names another cluster' => [
                fn (Builder $query) => $examples($query)->onCluster('other')->delete(false),
                "ALTER TABLE `examples` ON CLUSTER 'other' DELETE WHERE `f_int` = 1",
            ],
            'withoutOnCluster()' => [
                fn (Builder $query) => $examples($query)->withoutOnCluster()->delete(false),
                'ALTER TABLE `examples` DELETE WHERE `f_int` = 1',
            ],
            'withoutOnCluster() after onCluster()' => [
                fn (Builder $query) => $examples($query)->onCluster('other')->withoutOnCluster()->update(['f_string' => 'x']),
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` = 1",
            ],
            'withoutOnCluster() of a truncate' => [
                fn (Builder $query) => $query->from('examples')->withoutOnCluster()->truncate(),
                'TRUNCATE TABLE `examples`',
            ],
            'onCluster() after withoutOnCluster()' => [
                fn (Builder $query) => $examples($query)->withoutOnCluster()->onCluster('other')->delete(true),
                "DELETE FROM `examples` ON CLUSTER 'other' WHERE `f_int` = 1",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $mutation
     */
    #[DataProvider('defaultClusterProvider')]
    public function test_the_default_cluster_of_the_followed_connection(Closure $mutation, string $expectedSql): void
    {
        $this->expectWrite($expectedSql);

        $mutation($this->builder(null, $this->clusterConnection()));
    }

    public function test_a_cluster_name_with_a_quote_is_written_as_a_string_literal(): void
    {
        $this->expectWrite("TRUNCATE TABLE `examples` ON CLUSTER 'it\\'s'");

        $this->builder(null, $this->connection(['use_on_cluster' => true, 'cluster_name' => "it's"]))->from('examples')->truncate();
    }

    /**
     * The default cluster is read when the statement is sent, so Connection::withoutOnCluster() also covers a builder
     * that was made before its callback runs.
     */
    public function test_the_connection_without_on_cluster_covers_a_builder_made_before(): void
    {
        $connection = $this->clusterConnection();
        $query = $this->builder(null, $connection)->from('examples')->where('f_int', 1);
        $this->expectWrite('ALTER TABLE `examples` DELETE WHERE `f_int` = 1');

        $connection->withoutOnCluster(fn () => $query->delete(false));
    }

    /**
     * A builder made with only a client follows no connection, so it has no default cluster. It still resolves the
     * connection of its name for its pretend mode and the use_lightweight_delete default.
     */
    public function test_a_builder_made_with_only_a_client_sends_no_default_cluster(): void
    {
        $this->expectWrite('ALTER TABLE `examples` DELETE WHERE `f_int` = 1');
        $container = new Container();
        $container->instance('db', new class($this->clusterConnection()) {
            public function __construct(private Connection $connection)
            {
            }

            public function connection(?string $name = null): Connection
            {
                return $this->connection;
            }
        });
        $previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($container);

        try {
            (new Builder($this->client))->from('examples')->where('f_int', 1)->delete(false);
        } finally {
            Facade::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousApplication);
        }
    }

    /**
     * A builder made with only a client whose connection cannot be resolved, as outside a Laravel application, writes
     * as 3.0.0 did: every write goes to the client, delete() without an argument sends ALTER TABLE ... DELETE, and
     * insert() sends Values. Only a JSONEachRow insert, which 3.0.0 did not have, needs the connection.
     */
    public function test_a_builder_whose_connection_cannot_be_resolved_writes_without_one(): void
    {
        $sentSql = [];
        $client = $this->clientRecordingInserts($sentSql);
        $examples = fn (): Builder => (new Builder($client))->from('examples');
        $previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication(null);

        try {
            $examples()->where('f_int', 1)->delete();
            $examples()->where('f_int', 1)->delete(true);
            $examples()->where('f_int', 1)->update(['f_string' => 'x']);
            $examples()->truncate();
            $examples()->insert(['f_int' => 1, 'f_string' => 'a']);

            try {
                $examples()->insert(['f_int' => 1], 'JSONEachRow');
                $this->fail('The JSONEachRow insert should throw');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Cannot insert in JSONEachRow with a builder that follows no connection and whose connection'
                    . ' [clickhouse] cannot be resolved. Build the query from a ClickHouse connection or a model, or'
                    . ' insert in Values.',
                    $exception->getMessage()
                );
            }
        } finally {
            Facade::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousApplication);
        }

        $this->assertSame(
            [
                'ALTER TABLE `examples` DELETE WHERE `f_int` = 1',
                'DELETE FROM `examples` WHERE `f_int` = 1',
                "ALTER TABLE `examples` UPDATE `f_string` = 'x' WHERE `f_int` = 1",
                'TRUNCATE TABLE `examples`',
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (1,'a')",
            ],
            $sentSql
        );
    }

    /**
     * A refused mutation does not read the followed connection either: the clauses are checked first.
     */
    public function test_a_refused_mutation_does_not_read_the_followed_connection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDateTimePrecision')->willReturn('second');
        $connection->expects($this->never())->method('getConfig');
        $connection->expects($this->never())->method('getDefaultCluster');
        $connection->expects($this->never())->method('pretending');
        $this->expectNoWrite();

        foreach ([fn (Builder $query) => $query->delete(), fn (Builder $query) => $query->update(['f_string' => 'x'])] as $mutation) {
            try {
                $mutation($this->builder(null, $connection)->from('examples')->where('f_int', 1)->limit(1));
                $this->fail('The mutation should throw');
            } catch (QueryException $exception) {
                $this->assertStringStartsWith('Cannot ', $exception->getMessage());
            }
        }
    }

    /**
     * While the followed connection pretends, each mutation is logged once and nothing is sent: no EXPLAIN, no
     * session check and no write.
     */
    public function test_mutations_are_logged_and_not_sent_while_the_connection_pretends(): void
    {
        $connection = $this->clusterConnection();
        $this->client->expects($this->never())->method('select');
        $this->expectNoWrite();
        $correlated = self::correlatedCondition();

        $log = $connection->pretend(function () use ($connection, $correlated): void {
            $this->assertFalse($this->builder(null, $connection)->from('examples')->where('f_int', 1)->delete(false)->isError());
            $this->builder(null, $connection)->from('examples')->where('f_int', 1)->withoutOnCluster()->delete(true);
            $correlated($this->builder(null, $connection)->from('examples'))->update(['f_string' => 'x']);
            $this->builder(null, $connection)->from('examples')->truncate();
        });

        $this->assertSame(
            [
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1",
                'DELETE FROM `examples` WHERE `f_int` = 1',
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x'"
                    . ' WHERE EXISTS (SELECT * FROM `u` WHERE `u`.`id` = `examples`.`f_int`)',
                "TRUNCATE TABLE `examples` ON CLUSTER 'company_cluster'",
            ],
            array_column($log, 'query')
        );
    }

    /**
     * Raw SQL in from() that names a table, with or without its database, bare or quoted, is allowed, and written as
     * it is.
     *
     * @return array<string, array{Expression|LaravelExpression, string}>
     */
    public static function rawTableNameProvider(): array
    {
        return [
            'name' => [raw('events'), 'events'],
            'database and name' => [raw('db.events'), 'db.events'],
            'quoted parts' => [raw('`db`.`my-table`'), '`db`.`my-table`'],
            'doubled backtick' => [raw('`a``b`'), '`a``b`'],
            'escaped backtick' => [raw('`a\\`b`'), '`a\\`b`'],
            'double quotes and spaces' => [raw(' "db" . "t" '), ' "db" . "t" '],
            'a Laravel database expression' => [new LaravelExpression('db.events'), 'db.events'],
        ];
    }

    #[DataProvider('rawTableNameProvider')]
    public function test_a_raw_table_name_in_from_is_allowed(Expression|LaravelExpression $table, string $writtenTable): void
    {
        $this->expectWrite("ALTER TABLE {$writtenTable} DELETE WHERE `f_int` = 1");

        $this->builder()->from($table)->where('f_int', 1)->delete(false);
    }

    /**
     * The Buffer pattern with a raw from(): the mutation writes to the sources table.
     */
    public function test_a_raw_table_name_other_than_the_sources_table_is_allowed(): void
    {
        $this->expectWrite('DELETE FROM examples WHERE `f_int` = 1');

        $this->builder()->from(raw('examples_buffer'))->setSourcesTable('examples')->where('f_int', 1)->delete(true);
    }

    /**
     * count() reads the raw SQL of Column functions too: an alias or an arrayJoin() there counts the rows in a
     * sub-query, as get() returns them, while a literal is counted in place.
     *
     * @return array<string, array{Closure(Column): Column, string}>
     */
    public static function countOfAColumnFunctionOrderProvider(): array
    {
        return [
            'plus() of raw sql with AS' => [
                fn (Column $column): Column => $column->name('a')->plus(raw('0 AS b')),
                'SELECT count() AS `count` FROM (SELECT `a` FROM `oa` WHERE `b` < 5 ORDER BY `a` + 0 AS b ASC)',
            ],
            'plus() of raw sql that calls arrayJoin()' => [
                fn (Column $column): Column => $column->name('a')->plus(raw('arrayJoin(arr)')),
                'SELECT count() AS `count` FROM (SELECT `a` FROM `oa` WHERE `b` < 5 ORDER BY `a` + arrayJoin(arr) ASC)',
            ],
            'plus() of a number' => [
                fn (Column $column): Column => $column->name('a')->plus(1),
                'SELECT count() as `count` FROM `oa` WHERE `b` < 5',
            ],
        ];
    }

    /**
     * @param Closure(Column): Column $order
     */
    #[DataProvider('countOfAColumnFunctionOrderProvider')]
    public function test_count_reads_the_raw_sql_of_column_functions(Closure $order, string $expectedSql): void
    {
        $sentSql = [];
        $this->client->method('select')->willReturnCallback(function (string $sql) use (&$sentSql): Statement {
            $sentSql[] = $sql;
            $statement = $this->createStub(Statement::class);
            $statement->method('rows')->willReturn([['count' => '3']]);

            return $statement;
        });
        $builder = new class($this->client) extends Builder {
            public static ?Connection $loggingConnection = null;

            public function resolveConnection(): Connection
            {
                return static::$loggingConnection;
            }
        };
        $builder::$loggingConnection = $this->connection();

        $this->assertSame(3, $builder->from('oa')->select('a')->where('b', '<', 5)->orderBy($order)->count());
        $this->assertSame([$expectedSql], $sentSql);
    }

    /**
     * A set operation in the raw SQL of an order makes the query return other rows than its conditions match, so
     * count() counts the rows in a sub-query, as get() returns them, and the aggregates read them there too.
     */
    public function test_an_order_with_a_set_operation_is_counted_in_a_subquery(): void
    {
        $query = fn (): Builder => $this->builder()->from('g')->where('a', '<', 5)->orderByRaw('a EXCEPT SELECT * FROM g WHERE b = 20');

        $this->assertSame(
            'SELECT count() AS `count` FROM (SELECT * FROM `g` WHERE `a` < 5 ORDER BY a EXCEPT SELECT * FROM g WHERE b = 20)',
            $query()->getQueryForCount()->toSql()
        );
        $this->assertSame(
            'SELECT 1 FROM (SELECT * FROM `g` WHERE `a` < 5 ORDER BY a EXCEPT SELECT * FROM g WHERE b = 20) LIMIT 1',
            $query()->getQueryForExists()->toSql()
        );
    }
}
