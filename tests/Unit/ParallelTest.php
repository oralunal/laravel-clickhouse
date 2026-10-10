<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parallel::get() and getRows(), and Connection::selectParallelly(), without a server: every client is a
 * CannedAsyncClient, whose requests get canned responses when the batch curler runs them. The DB facade resolves
 * the connections of the test by name, for the package builder's query log and for SQL entries that name their
 * connection.
 */
class ParallelTest extends TestCase
{
    private ?Container $previousApplication = null;

    /**
     * The connections that the DB facade resolves, by name.
     *
     * @var array<string, LaravelConnection>
     */
    private array $connections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApplication = Facade::getFacadeApplication();
        $container = new Container();
        $connections = &$this->connections;
        $container->instance('db', new class ($connections) {
            /**
             * @param array<string, LaravelConnection> $connections
             */
            public function __construct(private array &$connections)
            {
            }

            public function connection(?string $name = null): LaravelConnection
            {
                return $this->connections[$name ?? Connection::DEFAULT_NAME]
                    ?? throw new InvalidArgumentException("Database connection [{$name}] not configured.");
            }
        });
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($this->previousApplication);
        $this->connections = [];

        parent::tearDown();
    }

    /**
     * A connection whose active node is a canned client, registered with the DB facade under its name.
     *
     * @param CannedAsyncClient $client
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(CannedAsyncClient $client, array $config = []): Connection
    {
        $connection = new class (null, 'db', '', $config + ['name' => Connection::DEFAULT_NAME]) extends Connection {
            public Client $cannedClient;

            public function getClient(): Client
            {
                return $this->cannedClient;
            }
        };
        $connection->cannedClient = $client;
        $this->connections[$connection->getName()] = $connection;

        return $connection;
    }

    /**
     * A canned client whose answer to a query that reads the table `bad` is UNKNOWN_TABLE, and to any other query a
     * JSON row ['v' => <the SQL>], each with a total time of 0.012 seconds.
     *
     * @return CannedAsyncClient
     */
    private static function client(): CannedAsyncClient
    {
        return new CannedAsyncClient(fn (string $sql): CurlerResponse => str_contains($sql, 'bad')
            ? CannedAsyncClient::error(404, 60, "Unknown table expression identifier 'bad' in scope SELECT * FROM bad", 'UNKNOWN_TABLE', 0.012)
            : CannedAsyncClient::json([['v' => $sql]], 0.012));
    }

    /**
     * @param Connection $connection
     * @return QueryBuilder
     */
    private static function laravelQuery(Connection $connection): QueryBuilder
    {
        return new QueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    // What is sent

    public function test_a_package_builder_is_sent_as_get_sends_it(): void
    {
        $client = self::client();
        $this->connection($client);

        Parallel::get([
            'settings' => (new Builder($client))->from('t')->where('a', 1)->settings('max_threads', 2),
            'plain' => (new Builder($client))->from('t'),
            'csv' => (new Builder($client))->from('t')->format('CSV'),
        ]);

        $this->assertSame(
            [
                'SELECT * FROM `t` WHERE `a` = 1 FORMAT JSON SETTINGS max_threads=2',
                'SELECT * FROM `t` FORMAT JSON',
                'SELECT * FROM `t` FORMAT CSV',
            ],
            $client->sentSql()
        );
        $this->assertStringContainsString('readonly=2', $client->requests[0]->getUrl());
        $this->assertStringNotContainsString('default_format', $client->requests[2]->getUrl());
    }

    /**
     * smi2's selectAsync() would search the whole SQL for FORMAT and a format name, and read the JSON result of
     * such a query as CSV. The request of ClientRequests sends the SQL as it is, with default_format=JSON.
     */
    public function test_a_value_that_names_a_format_is_read_as_json(): void
    {
        $client = new CannedAsyncClient(fn (): CurlerResponse => CannedAsyncClient::json([['note' => 'export as format csv']]));
        $this->connection($client);

        $statements = Parallel::get([
            'builder' => (new Builder($client))->from('t')->where('note', 'export as format csv'),
            'sql' => ['sql' => "SELECT note FROM t WHERE note = 'format csv'"],
        ]);

        $this->assertSame(
            ["SELECT * FROM `t` WHERE `note` = 'export as format csv'", "SELECT note FROM t WHERE note = 'format csv'"],
            $client->sentSql()
        );
        foreach ($client->requests as $request) {
            $this->assertStringContainsString('default_format=JSON', $request->getUrl());
        }
        $this->assertSame([['note' => 'export as format csv']], $statements['builder']->rows());
        $this->assertSame([['note' => 'export as format csv']], $statements['sql']->rows());
    }

    public function test_a_laravel_builder_and_sql_are_sent_as_select_sends_them(): void
    {
        $client = self::client();
        $connection = $this->connection($client, ['datetime_precision' => 'microsecond']);

        Parallel::get([
            'laravel' => self::laravelQuery($connection)->from('t')->where('a', "it's")->where('b', '>', 2),
            'question marks' => [
                'sql' => 'SELECT ? AS d, ? AS f, ? AS b',
                'bindings' => [new DateTimeImmutable('2024-01-02 03:04:05.123456'), 0.1 + 0.2, false],
                'connection' => $connection,
            ],
            'smi2 placeholders' => ['sql' => 'SELECT :v AS v', 'bindings' => ['v' => 1]],
        ]);

        $this->assertSame(
            [
                "select * from \"t\" where \"a\" = 'it\\'s' and \"b\" > 2 FORMAT JSON",
                "SELECT '2024-01-02 03:04:05.123456' AS d, 0.30000000000000004 AS f, 0 AS b FORMAT JSON",
                'SELECT 1 AS v FORMAT JSON',
            ],
            $client->sentSql()
        );
    }

    /**
     * A column or table named format is no FORMAT clause, so these entries are sent and their rows read.
     */
    public function test_queries_with_a_column_named_format_are_sent(): void
    {
        $client = self::client();
        $connection = $this->connection($client);

        $rows = Parallel::getRows([
            'desc' => ['sql' => 'SELECT id FROM files ORDER BY format DESC'],
            'like' => ['sql' => "SELECT id FROM files WHERE format LIKE 'c%'"],
            'alias' => ['sql' => 'SELECT f.id FROM format f'],
            'laravel' => self::laravelQuery($connection)->from('files')->whereRaw("format IN ('csv')")->orderByRaw('format ASC'),
        ]);

        $this->assertSame(
            [
                'SELECT id FROM files ORDER BY format DESC FORMAT JSON',
                "SELECT id FROM files WHERE format LIKE 'c%' FORMAT JSON",
                'SELECT f.id FROM format f FORMAT JSON',
                "select * from \"files\" where format IN ('csv') order by format ASC FORMAT JSON",
            ],
            $client->sentSql()
        );
        $this->assertSame([['v' => 'SELECT id FROM files ORDER BY format DESC FORMAT JSON']], $rows['desc']);
        $this->assertSame(['desc', 'like', 'alias', 'laravel'], array_keys($rows));
    }

    public function test_queries_on_several_connections_share_one_batch(): void
    {
        $first = self::client();
        $second = self::client();
        $this->connection($first);
        $other = $this->connection($second, ['name' => 'other']);

        $rows = Parallel::getRows([
            'first' => (new Builder($first))->from('a'),
            'second' => (new Builder($second, 'other'))->from('b'),
            'sql' => ['sql' => 'SELECT 3', 'connection' => 'other'],
            'laravel' => self::laravelQuery($other)->from('c'),
        ]);

        $this->assertSame(['SELECT * FROM `a` FORMAT JSON'], $first->sentSql());
        $this->assertSame(
            ['SELECT * FROM `b` FORMAT JSON', 'SELECT 3 FORMAT JSON', 'select * from "c" FORMAT JSON'],
            $second->sentSql()
        );
        $this->assertSame(['first', 'second', 'sql', 'laravel'], array_keys($rows));
    }

    public function test_keys_and_order_are_kept(): void
    {
        $client = self::client();
        $this->connection($client);

        $statements = Parallel::get([
            'b' => ['sql' => 'SELECT 2'],
            0 => ['sql' => 'SELECT 0'],
            'a' => ['sql' => 'SELECT 1'],
        ]);

        $this->assertSame(['b', 0, 'a'], array_keys($statements));
        $this->assertSame([['v' => 'SELECT 2 FORMAT JSON']], $statements['b']->rows());
        $this->assertSame([['v' => 'SELECT 0 FORMAT JSON']], $statements[0]->rows());
        $this->assertSame([['v' => 'SELECT 1 FORMAT JSON']], $statements['a']->rows());
    }

    /**
     * The deprecation notes of asyncWithQuery() and getAsyncQueries() name Parallel::get() and getRows().
     */
    public function test_the_queries_of_the_deprecated_async_with_query_run_in_a_batch(): void
    {
        $client = self::client();
        $this->connection($client);
        $query = (new Builder($client))->from('t')->where('id', 1);
        $query->asyncWithQuery(fn (Builder $other): Builder => $other->from('t')->where('id', 2));

        $rows = Parallel::getRows($query->getAsyncQueries());

        $this->assertSame(
            [
                [['v' => 'SELECT * FROM `t` WHERE `id` = 1 FORMAT JSON']],
                [['v' => 'SELECT * FROM `t` WHERE `id` = 2 FORMAT JSON']],
            ],
            $rows
        );
    }

    public function test_an_empty_list_sends_nothing(): void
    {
        $this->assertSame([], Parallel::get([]));
        $this->assertSame([], Parallel::getRows([]));
    }

    // Results and errors

    public function test_get_returns_the_statements_and_a_failed_one_throws_when_its_rows_are_read(): void
    {
        $client = self::client();
        $this->connection($client);

        $statements = Parallel::get(['ok' => ['sql' => 'SELECT 1'], 'bad' => ['sql' => 'SELECT * FROM bad']]);

        $this->assertContainsOnlyInstancesOf(Statement::class, $statements);
        $this->assertFalse($statements['ok']->isError());
        $this->assertTrue($statements['bad']->isError());
        $this->expectException(DatabaseException::class);
        $this->expectExceptionCode(60);

        $statements['bad']->rows();
    }

    public function test_get_rows_throws_after_every_query_with_the_results_and_the_errors(): void
    {
        $client = self::client();
        $this->connection($client);

        try {
            Parallel::getRows([
                'first' => ['sql' => 'SELECT 1'],
                'bad' => ['sql' => 'SELECT * FROM bad'],
                'last' => (new Builder($client))->from('t'),
            ]);
            $this->fail('getRows() did not throw.');
        } catch (ParallelQueryException $exception) {
            $this->assertInstanceOf(ClientQueryException::class, $exception);
            $this->assertInstanceOf(QueryException::class, $exception);
            $this->assertSame(
                ['first' => [['v' => 'SELECT 1 FORMAT JSON']], 'last' => [['v' => 'SELECT * FROM `t` FORMAT JSON']]],
                $exception->getResults()
            );
            $this->assertSame(['bad'], array_keys($exception->getErrors()));
            $this->assertInstanceOf(DatabaseException::class, $exception->getErrors()['bad']);
            $this->assertSame(60, $exception->getErrors()['bad']->getCode());
            $this->assertSame(['first', 'bad', 'last'], array_keys($exception->getStatements()));
            $this->assertSame($exception->getErrors()['bad'], $exception->getPrevious());
            $this->assertSame(0, $exception->getCode());
            $this->assertStringStartsWith(
                "1 of 3 parallel queries failed:\n[bad] ClickHouseDB\\Exception\\DatabaseException: Unknown table",
                $exception->getMessage()
            );
        }

        $this->assertCount(3, $client->requests, 'Every query was sent.');
    }

    public function test_get_rows_applies_the_after_query_callbacks_of_a_laravel_builder(): void
    {
        $client = new CannedAsyncClient(fn (): CurlerResponse => CannedAsyncClient::json([['id' => '1'], ['id' => '2']]));
        $connection = $this->connection($client);

        $rows = Parallel::getRows([
            'mapped' => self::laravelQuery($connection)->from('t')
                ->afterQuery(fn (Collection $rows): Collection => $rows->map(fn (array $row): int => (int) $row['id'] * 10)),
            'plain' => self::laravelQuery($connection)->from('t'),
            'sql' => ['sql' => 'SELECT id FROM t'],
        ]);

        $this->assertSame(
            ['mapped' => [10, 20], 'plain' => [['id' => '1'], ['id' => '2']], 'sql' => [['id' => '1'], ['id' => '2']]],
            $rows
        );
    }

    // Logging and callbacks

    public function test_each_query_is_logged_as_its_own_run_logs_it(): void
    {
        $client = self::client();
        $connection = $this->connection($client);
        $connection->enableQueryLog();
        $callbacks = [];
        $connection->beforeExecuting(function (string $query, array $bindings) use (&$callbacks): void {
            $callbacks[] = [$query, $bindings];
        });

        Parallel::get([
            'builder' => (new Builder($client))->from('t'),
            'failed builder' => (new Builder($client))->from('bad'),
            'sql' => ['sql' => 'SELECT ?', 'bindings' => [1]],
            'failed sql' => ['sql' => 'SELECT * FROM bad'],
            'laravel' => self::laravelQuery($connection)->from('t')->where('a', 2),
        ]);

        $this->assertSame(
            [
                ['SELECT * FROM `t`', [], 12.0],
                ['SELECT * FROM `bad`', [], 12.0],
                ['SELECT ?', [1], 12.0],
                ['select * from "t" where "a" = ?', [2], 12.0],
            ],
            array_map(fn (array $entry): array => [$entry['query'], $entry['bindings'], $entry['time']], $connection->getQueryLog())
        );
        $this->assertSame(
            [['SELECT ?', [1]], ['SELECT * FROM bad', []], ['select * from "t" where "a" = ?', [2]]],
            $callbacks
        );
    }

    public function test_a_query_of_a_connection_that_pretends_is_logged_and_not_sent(): void
    {
        $client = self::client();
        $connection = $this->connection($client);
        $callbacks = 0;
        $connection->beforeExecuting(function () use (&$callbacks): void {
            $callbacks++;
        });

        $rows = null;
        $log = $connection->pretend(function (Connection $connection) use (&$rows, $client): void {
            $rows = Parallel::getRows([
                'sql' => ['sql' => 'SELECT ?', 'bindings' => [1]],
                'laravel' => self::laravelQuery($connection)->from('t'),
                'builder' => (new Builder($client))->from('t'),
            ]);
        });

        $this->assertSame(['sql' => [], 'laravel' => [], 'builder' => [['v' => 'SELECT * FROM `t` FORMAT JSON']]], $rows);
        $this->assertSame(['SELECT * FROM `t` FORMAT JSON'], $client->sentSql(), 'Only the package builder is sent, as get() does.');
        $this->assertSame(['SELECT 1', 'select * from "t"', 'SELECT * FROM `t`'], array_column($log, 'query'));
        $this->assertSame(2, $callbacks);
    }

    // Retries

    /**
     * @return array<string, array{string, list<CurlerResponse>, int, int}>
     */
    public static function retries(): array
    {
        $error = CannedAsyncClient::error(500, 159, 'Timeout exceeded', 'TIMEOUT_EXCEEDED', 0.5);
        $ok = CannedAsyncClient::json([['x' => '1']], 0.25);
        $unreachable = CannedAsyncClient::unreachable();

        return [
            "any sends a failed request again" => ['any', [$error, $error, $ok], 3, 200],
            'any stops after the retries' => ['any', [$error, $error, $error, $ok], 3, 500],
            "unsent does not send a request that reached the server again" => ['unsent', [$error, $ok], 1, 500],
            'unsent sends a request that never reached the server again' => ['unsent', [$unreachable, $unreachable, $ok], 3, 200],
        ];
    }

    /**
     * @param list<CurlerResponse> $responses
     */
    #[DataProvider('retries')]
    public function test_a_failed_request_is_sent_again_as_the_retries_and_retry_on_of_its_client_say(
        string $retryOn,
        array $responses,
        int $attempts,
        int $httpCode
    ): void {
        $client = new CannedAsyncClient(fn (): array => $responses);
        $curler = new CurlerRollingWithRetries();
        $curler->setRetries(2);
        $curler->setRetryOn($retryOn);
        $client->transport()->setDirtyCurler($curler);
        $connection = $this->connection($client);
        $connection->enableQueryLog();

        $statement = Parallel::get(['q' => ['sql' => 'SELECT 1']])['q'];

        $this->assertSame($attempts, CannedAsyncClient::attemptsOf($client->requests[0]));
        $this->assertSame($httpCode, $statement->getRequest()->response()->http_code());
        $this->assertSame($curler, $client->transport()->getCurler());
    }

    public function test_the_time_of_a_query_adds_up_its_attempts(): void
    {
        $error = CannedAsyncClient::error(500, 159, 'Timeout exceeded', 'TIMEOUT_EXCEEDED', 0.5);
        $client = new CannedAsyncClient(fn (): array => [$error, CannedAsyncClient::json([['x' => '1']], 0.25)]);
        $curler = new CurlerRollingWithRetries();
        $curler->setRetries(1);
        $client->transport()->setDirtyCurler($curler);
        $connection = $this->connection($client);
        $connection->enableQueryLog();

        Parallel::getRows(['q' => ['sql' => 'SELECT 1']]);

        $this->assertSame(750.0, $connection->getQueryLog()[0]['time']);
    }

    public function test_a_client_without_retries_sends_a_failed_request_once(): void
    {
        $error = CannedAsyncClient::error(500, 159, 'Timeout exceeded', 'TIMEOUT_EXCEEDED');
        $client = new CannedAsyncClient(fn (): array => [$error, CannedAsyncClient::json([['x' => '1']])]);
        $this->connection($client);

        $statement = Parallel::get(['q' => ['sql' => 'SELECT 1']])['q'];

        $this->assertSame(1, CannedAsyncClient::attemptsOf($client->requests[0]));
        $this->assertTrue($statement->isError());
    }

    // Refusals before anything is sent

    /**
     * A batch with a session that was sent would never end: smi2's CurlerRolling runs a request with a session id
     * again and again. The canned client throws when a request is run more than CannedAsyncClient::MAX_ATTEMPTS
     * times, so a batch that is not refused fails the test at once.
     */
    public function test_a_client_with_a_session_is_refused_before_anything_is_sent(): void
    {
        $client = self::client();
        $connection = $this->connection($client);
        $client->useSession('s1');

        try {
            Parallel::get(['first' => ['sql' => 'SELECT 1'], 'second' => self::laravelQuery($connection)->from('t')]);
            $this->fail('A session was not refused.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Parallel query [first] runs on a client that uses a ClickHouse session. ClickHouse runs one query of'
                . ' a session at a time and rejects the others with SESSION_IS_LOCKED. Run the queries outside the'
                . ' session.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, CannedAsyncClient::attemptsOf($client->requests[0]));
    }

    /**
     * @return array<string, array{callable(Connection, Client): array<int|string, mixed>, class-string, string}>
     */
    public static function refusedBatches(): array
    {
        return [
            'a setting name that cannot be written' => [
                fn (Connection $connection, Client $client): array => [
                    ['sql' => 'SELECT 1'],
                    (new Builder($client))->from('t'),
                    (new Builder($client))->from('t')->settings('bad name', 1),
                ],
                InvalidArgumentException::class,
                'bad name',
            ],
            'a placeholder count mismatch' => [
                fn (): array => [['sql' => 'SELECT 1'], ['sql' => 'SELECT ?', 'bindings' => [1, 2]]],
                InvalidArgumentException::class,
                'The query has 1 "?" placeholder, but 2 bindings were given.',
            ],
            'a FORMAT clause that select() cannot read' => [
                fn (): array => [['sql' => 'SELECT 1'], 'csv' => ['sql' => 'SELECT 2 FORMAT CSV']],
                QueryException::class,
                'Cannot read rows from a query whose FORMAT clause names CSV',
            ],
            'an entry of another type' => [
                fn (): array => [['sql' => 'SELECT 1'], 'number' => 42],
                InvalidArgumentException::class,
                'Parallel query [number] must be a query builder of this package, a Laravel or Eloquent query builder'
                . ' or an array with an sql string, int given.',
            ],
            'an array without sql' => [
                fn (): array => ['query' => ['query' => 'SELECT 1']],
                InvalidArgumentException::class,
                'Parallel query [query] must be a query builder of this package, a Laravel or Eloquent query builder'
                . ' or an array with an sql string, array given.',
            ],
            'empty SQL' => [
                fn (): array => ['blank' => ['sql' => ' ']],
                InvalidArgumentException::class,
                'Parallel query [blank] is empty.',
            ],
            'bindings that are no array' => [
                fn (): array => ['one' => ['sql' => 'SELECT ?', 'bindings' => 1]],
                InvalidArgumentException::class,
                'Parallel query [one] must give its bindings as an array, int given.',
            ],
            'a connection that is no ClickHouse connection' => [
                fn (): array => ['other' => new LaravelBuilder(new LaravelConnection(null, 'db', '', ['name' => 'mysql']))],
                InvalidArgumentException::class,
                'Parallel query [other] must run on a ClickHouse connection, the connection [mysql] given.',
            ],
            'a connection of another type' => [
                fn (): array => ['other' => ['sql' => 'SELECT 1', 'connection' => 1]],
                InvalidArgumentException::class,
                'Parallel query [other] must name its connection or give a connection, int given.',
            ],
            'a connection name that is not configured' => [
                fn (): array => ['other' => ['sql' => 'SELECT 1', 'connection' => 'missing']],
                InvalidArgumentException::class,
                'Database connection [missing] not configured.',
            ],
        ];
    }

    /**
     * @param callable(Connection, Client): array<int|string, mixed> $queries
     * @param class-string $exception
     */
    #[DataProvider('refusedBatches')]
    public function test_a_batch_that_cannot_be_prepared_sends_nothing(callable $queries, string $exception, string $message): void
    {
        $client = self::client();
        $connection = $this->connection($client);
        $callbacks = 0;
        $connection->beforeExecuting(function () use (&$callbacks): void {
            $callbacks++;
        });

        try {
            Parallel::get($queries($connection, $client));
            $this->fail('The batch was not refused.');
        } catch (InvalidArgumentException|QueryException $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringContainsString($message, $thrown->getMessage());
        }

        foreach ($client->requests as $request) {
            $this->assertSame(0, CannedAsyncClient::attemptsOf($request), 'A request was sent.');
        }
        $this->assertSame(0, $callbacks, 'A beforeExecuting() callback ran.');
    }

    public function test_a_concurrency_below_two_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parallel queries need a concurrency of at least 2, 1 given.');

        Parallel::get([['sql' => 'SELECT 1']], 1);
    }

    public function test_more_queries_than_the_concurrency_all_run(): void
    {
        $client = self::client();
        $this->connection($client);
        $queries = [];
        for ($i = 0; $i < 7; $i++) {
            $queries["q{$i}"] = ['sql' => "SELECT {$i}"];
        }

        $rows = Parallel::getRows($queries, 2);

        $this->assertSame(array_keys($queries), array_keys($rows));
        $this->assertSame([['v' => 'SELECT 6 FORMAT JSON']], $rows['q6']);
    }

    // The clients' own curlers

    public function test_the_clients_own_curler_and_its_queue_are_left_alone(): void
    {
        $client = self::client();
        $this->connection($client);
        $curler = $client->transport()->getCurler();
        $queued = $client->selectAsync('SELECT queued');

        Parallel::get(['ok' => ['sql' => 'SELECT 1']]);
        try {
            Parallel::get(['ok' => ['sql' => 'SELECT 1'], 'refused' => ['sql' => 'SELECT ?', 'bindings' => []]]);
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($curler, $client->transport()->getCurler());
        $this->assertSame(1, $client->getCountPendingQueue(), 'The request queued with selectAsync() is still queued.');
        $this->assertFalse($queued->getRequest()->isResponseExists());
    }

    public function test_the_batch_curler_runs_as_many_requests_at_once_as_the_concurrency(): void
    {
        $curler = (new class extends Parallel {
            public static function batchCurler(int $concurrency): CurlerRolling
            {
                return static::newBatchCurler($concurrency);
            }
        })::batchCurler(3);

        $this->assertSame(3, $curler->getSimultaneousLimit());
    }

    // Connection::selectParallelly()

    public function test_select_parallelly_runs_sql_on_its_own_connection(): void
    {
        $client = self::client();
        $connection = $this->connection($client, ['name' => 'own']);
        $other = self::client();
        $this->connection($other, ['name' => 'other']);
        $connection->enableQueryLog();

        $rows = $connection->selectParallelly([
            'total' => 'SELECT count() FROM t',
            'recent' => ['sql' => 'SELECT id FROM t WHERE v >= ?', 'bindings' => [30], 'connection' => 'other'],
        ]);

        $this->assertSame(
            ['total' => [['v' => 'SELECT count() FROM t FORMAT JSON']], 'recent' => [['v' => 'SELECT id FROM t WHERE v >= 30 FORMAT JSON']]],
            $rows
        );
        $this->assertSame([], $other->requests, "The 'connection' key is ignored.");
        $this->assertSame(['SELECT count() FROM t', 'SELECT id FROM t WHERE v >= ?'], array_column($connection->getQueryLog(), 'query'));
    }

    public function test_select_parallelly_refuses_an_entry_that_is_no_sql(): void
    {
        $client = self::client();
        $connection = $this->connection($client);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parallel query [0] must be SQL or an array with an sql string, int given.');

        $connection->selectParallelly([42]);
    }

    public function test_select_parallelly_throws_a_parallel_query_exception(): void
    {
        $client = self::client();
        $connection = $this->connection($client);

        $this->expectException(ParallelQueryException::class);
        $this->expectExceptionMessage('1 of 2 parallel queries failed:');

        $connection->selectParallelly(['ok' => 'SELECT 1', 'bad' => 'SELECT * FROM bad']);
    }

    // ParallelQueryException

    public function test_the_exception_lists_each_error_in_the_order_given(): void
    {
        $first = DatabaseException::fromClickHouse('Unknown table', 60, 'UNKNOWN_TABLE');
        $second = new ClientQueryException('Operation timed out', 28);

        $exception = new ParallelQueryException(['ok' => [['x' => 1]]], ['a' => $first, 7 => $second]);

        $this->assertSame(
            "2 of 3 parallel queries failed:\n[a] ClickHouseDB\\Exception\\DatabaseException: Unknown table\n"
            . '[7] ClickHouseDB\\Exception\\QueryException: Operation timed out',
            $exception->getMessage()
        );
        $this->assertSame($first, $exception->getPrevious());
        $this->assertSame([], $exception->getStatements());
        $this->assertInstanceOf(LogicException::class, $exception);
    }

    /**
     * The request that a statement holds is the one that ran: the statement reads the format that ClickHouse
     * names in its response.
     */
    public function test_a_statement_reads_the_format_that_the_response_names(): void
    {
        $client = new CannedAsyncClient(fn (): CurlerResponse => CannedAsyncClient::response(200, "1\n2\n", ['X-ClickHouse-Format' => 'TabSeparated']));
        $this->connection($client);

        $statement = Parallel::get(['tsv' => (new Builder($client))->from('t')->format('TSV')])['tsv'];

        $this->assertSame('TabSeparated', $statement->getRequest()->getRequestExtendedInfo('format'));
        $this->assertSame("1\n2\n", $statement->rawData());
        $this->assertInstanceOf(CurlerRequest::class, $statement->getRequest());
    }
}
