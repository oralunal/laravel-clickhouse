<?php

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Transport\CurlerRolling;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\QueryBuilder as Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Models\Example;
use Tests\Models\Example2;

/**
 * Parallel::get() and getRows(), and Connection::selectParallelly(), against the server. The queries of a batch
 * overlap on the server, and each returns the rows that its own run returns.
 */
class ParallelTest extends TestCase
{
    private const TABLE = 'parallel_events';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $base = $config->get('database.connections.clickhouse');
        $config->set('database.connections.clickhouse-parallel-copy', $base);
        $config->set('database.connections.clickhouse-parallel-microsecond', ['datetime_precision' => 'microsecond'] + $base);
        $config->set('database.connections.clickhouse-parallel-retries', ['retries' => 2] + $base);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, kind String, v Float64, d DateTime64(6))'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        $client->write(
            'INSERT INTO ' . self::TABLE . " VALUES (1, 'a', 0.30000000000000004, '2024-01-02 03:04:05.123456'),"
            . " (2, 'a', 0.3, '2024-01-02 03:04:05'), (3, 'b', 10.5, '2024-01-03 00:00:00'),"
            . " (4, 'export as format csv', 1e-7, '2024-01-04 00:00:00')"
        );
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    private static function runAlone(mixed $query): mixed
    {
        return match (true) {
            $query instanceof EloquentBuilder => $query->get(),
            $query instanceof Builder => $query->get()->map(Rows::asArray(...))->all(),
            default => Rows::asArrays(DB::connection($query['connection'] ?? 'clickhouse')->select($query['sql'], $query['bindings'] ?? [])),
        };
    }

    /**
     * The count is selected as a string, since ClickHouse 24.8 quotes a UInt64 in JSON and 25.8 and later do not.
     */
    public function test_the_queries_of_a_batch_overlap_on_the_server(): void
    {
        $prefix = 'parallel_overlap_' . bin2hex(random_bytes(6));
        $sleep = fn (int $number): Builder => DB::connection('clickhouse')->table('system.one')
            ->select(DB::raw('sleep(0.5) as s'))
            ->settings('log_comment', "{$prefix}_{$number}");

        $rows = Parallel::getRows([$sleep(1), $sleep(2), $sleep(3)]);

        $this->assertSame([[['s' => 0]], [['s' => 0]], [['s' => 0]]], Rows::ofEachResult($rows));
        DB::connection('clickhouse')->statement('SYSTEM FLUSH LOGS');
        $this->assertSame(
            [['queries' => '3', 'overlapped' => 1]],
            Rows::asArrays(DB::connection('clickhouse')->select(
                'SELECT toString(count()) AS queries, max(query_start_time_microseconds) < min(event_time_microseconds) AS overlapped'
                . " FROM system.query_log WHERE type = 'QueryFinish' AND log_comment LIKE ?",
                ["{$prefix}%"]
            ))
        );
    }

    /**
     * The test table on a connection.
     *
     * @param string $connection
     * @return Builder
     */
    private static function events(string $connection = 'clickhouse'): Builder
    {
        return DB::connection($connection)->table(self::TABLE);
    }

    /**
     * Floats and dates are written as each query's own run writes them, at second and at microsecond precision.
     *
     * @return array<string, array{callable(): mixed}>
     */
    public static function queries(): array
    {
        $date = new DateTimeImmutable('2024-01-02 03:04:05.123456');
        $float = 0.1 + 0.2;

        return [
            'query builder' => [fn () => self::events()->where('kind', 'a')->where('v', '>', 0.2)->orderBy('id')->select(['id', 'v'])],
            'query builder, float' => [fn () => self::events()->where('v', $float)->select('id')],
            'query builder, date in seconds' => [fn () => self::events()->where('d', '>=', $date)->orderBy('id')->select('id')],
            'query builder, date in microseconds' => [
                fn () => self::events('clickhouse-parallel-microsecond')->where('d', $date)->select('id'),
            ],
            'query builder, value that names a format' => [
                fn () => self::events()->where('kind', 'export as format csv')->select('id'),
            ],
            'query builder, settings' => [
                fn () => self::events()->select(['kind', DB::raw('count() as c')])->groupBy('kind')->orderBy('kind')
                    ->settings('max_threads', 1),
            ],
            'model' => [fn () => Example::select('f_int')->where('f_int', '<', 0)],
            'SQL, ? bindings' => [
                fn () => ['sql' => 'SELECT id FROM ' . self::TABLE . ' WHERE v = ? AND d >= ? ORDER BY id', 'bindings' => [$float, $date]],
            ],
            'SQL, date in microseconds' => [
                fn () => [
                    'sql' => 'SELECT id FROM ' . self::TABLE . ' WHERE d = ?',
                    'bindings' => [$date],
                    'connection' => 'clickhouse-parallel-microsecond',
                ],
            ],
            'SQL, smi2 bindings' => [
                fn () => ['sql' => 'SELECT id FROM ' . self::TABLE . ' WHERE kind = :kind', 'bindings' => ['kind' => 'export as format csv']],
            ],
            'SQL, ORDER BY an alias named format' => [
                fn () => ['sql' => 'SELECT id, kind AS format FROM ' . self::TABLE . ' ORDER BY id DESC, format DESC'],
            ],
            'query builder, raw conditions on an alias named format' => [
                fn () => self::events()->select(['id', 'kind as format'])->whereRaw("format IN ('a', 'b')")
                    ->orderByRaw('format DESC'),
            ],
        ];
    }

    /**
     * @param callable(): mixed $query
     */
    #[DataProvider('queries')]
    public function test_a_query_returns_in_a_batch_what_it_returns_alone(callable $query): void
    {
        $rows = Parallel::getRows(['query' => $query(), 'other' => ['sql' => 'SELECT 1 AS one']]);
        $alone = self::runAlone($query());

        if ($alone instanceof EloquentCollection) {
            $this->assertInstanceOf(EloquentCollection::class, $rows['query']);
            $this->assertSame($alone->toArray(), $rows['query']->toArray());
        } else {
            $this->assertSame($alone, Rows::asArrays($rows['query']));
        }
        $this->assertSame([['one' => 1]], Rows::asArrays($rows['other']));
    }

    public function test_a_batch_runs_queries_of_several_connections(): void
    {
        $rows = Parallel::getRows([
            'first' => self::events()->select('id')->where('id', 1),
            'second' => self::events('clickhouse-parallel-copy')->select('id')->where('id', 2),
            'micro' => self::events('clickhouse-parallel-microsecond')->select('id')->where('id', 3),
        ]);

        $this->assertSame(['first' => [['id' => 1]], 'second' => [['id' => 2]], 'micro' => [['id' => 3]]], Rows::ofEachResult($rows));
    }

    /**
     * The counts are UInt64 values, which ClickHouse 24.8 quotes in JSON and 25.8 and later do not, so they are
     * compared as ints.
     */
    public function test_a_batch_runs_queries_on_two_nodes(): void
    {
        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('Needs the second node of the test cluster; set CLICKHOUSE_CLUSTER_AVAILABLE=1');
        }

        $rows = Parallel::getRows([
            'node1' => Example::query()->toBase()->selectRaw('toString(count()) as count'),
            'node2' => Example2::query()->toBase()->selectRaw('toString(count()) as count'),
        ]);

        $this->assertSame(
            ['node1' => [['count' => (string) Example::query()->count()]], 'node2' => [['count' => (string) Example2::query()->count()]]],
            Rows::ofEachResult($rows)
        );
    }

    public function test_a_failed_query_keeps_the_rows_of_the_others(): void
    {
        try {
            Parallel::getRows([
                'rows' => DB::connection('clickhouse')->table(self::TABLE)->select('id')->where('id', 1),
                'missing' => ['sql' => 'SELECT * FROM parallel_missing_table'],
            ]);
            $this->fail('getRows() did not throw.');
        } catch (ParallelQueryException $exception) {
            $this->assertSame(['rows' => [['id' => 1]]], Rows::ofEachResult($exception->getResults()));
            $error = $exception->getErrors()['missing'];
            $this->assertInstanceOf(DatabaseException::class, $error);
            $this->assertSame('UNKNOWN_TABLE', $error->getClickHouseExceptionName());
            $this->assertStringStartsWith(
                "1 of 2 parallel queries failed:\n[missing] ClickHouseDB\\Exception\\DatabaseException: Unknown table",
                $exception->getMessage()
            );
        }
    }

    /**
     * The count is selected as a string, since ClickHouse 24.8 quotes a UInt64 in JSON and 25.8 and later do not.
     */
    public function test_a_failed_request_is_sent_again_as_often_as_the_retries_say(): void
    {
        $comment = 'parallel_retries_' . bin2hex(random_bytes(6));

        try {
            Parallel::getRows([[
                'sql' => "SELECT * FROM parallel_missing_table SETTINGS log_comment = '{$comment}'",
                'connection' => 'clickhouse-parallel-retries',
            ]]);
            $this->fail('getRows() did not throw.');
        } catch (ParallelQueryException) {
        }

        DB::connection('clickhouse')->statement('SYSTEM FLUSH LOGS');
        $this->assertSame(
            [['attempts' => '3']],
            Rows::asArrays(DB::connection('clickhouse')->select(
                "SELECT toString(count()) AS attempts FROM system.query_log WHERE type = 'ExceptionBeforeStart' AND log_comment = ?",
                [$comment]
            ))
        );
    }

    public function test_a_set_operation_with_settings_returns_its_rows(): void
    {
        $query = fn (): Builder => DB::connection('clickhouse')->table(self::TABLE)->select('id')->where('id', '<', 4)
            ->except(
                DB::connection('clickhouse')->table(self::TABLE)->select('id')->where('id', 2)
                    ->unionAll(DB::connection('clickhouse')->table(self::TABLE)->select('id')->where('id', 3))
            )
            ->settings('max_threads', 2);

        $this->assertSame(['set' => $query()->get()->map(Rows::asArray(...))->all()], Rows::ofEachResult(Parallel::getRows(['set' => $query()])));
        $this->assertSame([['id' => 1]], $query()->get()->map(Rows::asArray(...))->all());
    }

    public function test_a_query_builder_query_is_logged_as_get_logs_it(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        Parallel::getRows([$connection->table('system.one')->select('dummy')->settings('max_threads', 1)]);

        $log = $connection->getQueryLog();
        $this->assertCount(1, $log);
        $this->assertSame('select "dummy" from "system"."one" settings max_threads=1', $log[0]['query']);
        $this->assertGreaterThan(0, $log[0]['time']);
    }

    /**
     * The count is selected as a string, since ClickHouse 24.8 quotes a UInt64 in JSON and 25.8 and later do not.
     */
    public function test_a_count_runs_next_to_a_page(): void
    {
        $query = fn (): Builder => DB::connection('clickhouse')->table(self::TABLE)->where('kind', 'a');

        $rows = Parallel::getRows([
            'total' => $query()->selectRaw('toString(count()) as total'),
            'page' => $query()->select('id')->orderBy('id')->forPage(2, 1),
        ]);

        $this->assertSame(['total' => [['total' => '2']], 'page' => [['id' => 2]]], Rows::ofEachResult($rows));
    }

    public function test_get_returns_the_statements(): void
    {
        $statements = Parallel::get([
            'ids' => DB::connection('clickhouse')->table(self::TABLE)->select('id')->orderBy('id'),
            'rows' => ['sql' => 'SELECT 1 AS one'],
        ]);

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], $statements['ids']->rows());
        $this->assertSame(4, $statements['ids']->count());
        $this->assertSame([['one' => 1]], $statements['rows']->rows());
    }

    /**
     * The batch runs on a curler that throws when it would send the requests. A batch with a session that was sent
     * would never end, since smi2's CurlerRolling runs a request with a session id again and again, so without that
     * curler a batch that is not refused would hang the test instead of failing it.
     */
    public function test_a_client_with_a_session_is_refused(): void
    {
        $parallel = new class extends Parallel {
            protected static function newBatchCurler(int $concurrency): CurlerRolling
            {
                return new class extends CurlerRolling {
                    public function execLoopWait(): bool
                    {
                        throw new RuntimeException('The batch was sent.');
                    }
                };
            }
        };
        DB::connection('clickhouse')->getClient()->useSession();

        try {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('runs on a client that uses a ClickHouse session');

            $parallel::getRows([['sql' => 'SELECT 1']]);
        } finally {
            DB::purge('clickhouse');
        }
    }

    /**
     * The sum is selected as a string, since ClickHouse 24.8 quotes a UInt64 in JSON and 25.8 and later do not.
     */
    public function test_select_parallelly_fills_the_bindings(): void
    {
        $rows = DB::connection('clickhouse')->selectParallelly([
            'total' => ['sql' => 'SELECT toString(sum(number)) AS total FROM numbers(:count)', 'bindings' => ['count' => 4]],
            'question mark' => ['sql' => 'SELECT ? AS value', 'bindings' => ['a']],
            'plain' => 'SELECT 1 AS one',
        ]);

        $this->assertSame(
            ['total' => [['total' => '6']], 'question mark' => [['value' => 'a']], 'plain' => [['one' => 1]]],
            Rows::ofEachResult($rows)
        );
    }
}
