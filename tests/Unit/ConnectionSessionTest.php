<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Exception\ClickHouseUnavailableException;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use Closure;
use Generator;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Cluster;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\CurlerRollingInSession;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Parallel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Connection::session() without a server: the connection's cluster has one node, a CannedAsyncClient, whose
 * requests get canned responses. The tests read the session settings from the URL of each request.
 */
class ConnectionSessionTest extends TestCase
{
    private CannedAsyncClient $client;

    private Cluster $cluster;

    /**
     * Make a connection whose cluster has the canned client as its one node.
     *
     * @param CannedAsyncClient|null $client
     * @return Connection
     */
    private function connection(?CannedAsyncClient $client = null): Connection
    {
        $this->client = $client ?? new CannedAsyncClient();
        $this->cluster = (new ReflectionClass(Cluster::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Cluster::class, 'nodeConfigs'))->setValue($this->cluster, [[]]);
        (new ReflectionProperty(Cluster::class, 'nodes'))->setValue($this->cluster, [$this->client]);
        (new ReflectionProperty(Cluster::class, 'activeNodeIndex'))->setValue($this->cluster, 0);

        $connection = new Connection(null, 'db', '', ['name' => 'clickhouse']);
        (new ReflectionProperty(Connection::class, 'cluster'))->setValue($connection, $this->cluster);

        return $connection;
    }

    /**
     * The session parameters in the URL of a request: session_id, session_timeout and session_check, null when the
     * URL has none.
     *
     * @param CurlerRequest $request
     * @return array{session_id: string|null, session_timeout: string|null, session_check: string|null}
     */
    private static function sessionOf(CurlerRequest $request): array
    {
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $parameters);

        return [
            'session_id' => $parameters['session_id'] ?? null,
            'session_timeout' => $parameters['session_timeout'] ?? null,
            'session_check' => $parameters['session_check'] ?? null,
        ];
    }

    /**
     * @return array{session_id: mixed, session_timeout: mixed, session_check: mixed}
     */
    private function clientSessionSettings(): array
    {
        $settings = $this->client->settings();

        return [
            'session_id' => $settings->get('session_id'),
            'session_timeout' => $settings->get('session_timeout'),
            'session_check' => $settings->get('session_check'),
        ];
    }

    /**
     * A ClickHouse error for the session that a request names.
     *
     * @param CurlerRequest $request
     * @param int $code
     * @return CurlerResponse
     */
    private static function sessionError(CurlerRequest $request, int $code): CurlerResponse
    {
        $sessionId = self::sessionOf($request)['session_id'];

        return $code === 372
            ? CannedAsyncClient::error(500, 372, "Session {$sessionId} not found", 'SESSION_NOT_FOUND')
            : CannedAsyncClient::error(500, 373, "Session {$sessionId} is locked by a concurrent client", 'SESSION_IS_LOCKED');
    }

    public function test_it_opens_the_session_and_sends_every_query_of_the_callback_in_it(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        $result = $connection->session(function (Connection $passed) use ($connection): string {
            $this->assertSame($connection, $passed);
            $passed->select('SELECT 2');
            $passed->statement('CREATE TEMPORARY TABLE tmp (id UInt8)');

            return 'result';
        }, 120);
        $connection->select('SELECT 3');

        $this->assertSame('result', $result);
        $this->assertSame(['SELECT 1 FORMAT JSON', 'SELECT 2 FORMAT JSON', 'CREATE TEMPORARY TABLE tmp (id UInt8)', 'SELECT 3 FORMAT JSON'], $this->client->sentSql());
        [$open, $select, $statement, $after] = array_map(self::sessionOf(...), $this->client->requests);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', (string) $open['session_id']);
        $this->assertSame(['session_id' => $open['session_id'], 'session_timeout' => '120', 'session_check' => null], $open);
        $this->assertSame(['session_id' => $open['session_id'], 'session_timeout' => '120', 'session_check' => '1'], $select);
        $this->assertSame($select, $statement);
        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $after);
        $this->assertSame(
            ['SELECT 2', 'CREATE TEMPORARY TABLE tmp (id UInt8)', 'SELECT 3'],
            array_column($connection->getQueryLog(), 'query'),
            'The query that opens the session is not logged.'
        );
    }

    public function test_the_session_has_its_own_curler_and_pins_the_node_while_the_callback_runs(): void
    {
        $connection = $this->connection();
        $original = new CurlerRollingWithRetries();
        $original->setRetries(2);
        $this->client->transport()->setDirtyCurler($original);

        $connection->session(function (Connection $connection) use ($original): void {
            $curler = $this->client->transport()->getCurler();
            $this->assertInstanceOf(CurlerRollingInSession::class, $curler);
            $this->assertNotSame($original, $curler);
            $this->assertSame(2, $curler->getRetries());
            $this->assertSame(CurlerRollingWithRetries::RETRY_ON_UNSENT, $curler->getRetryOn());
            $this->assertTrue($connection->inSession());
            $this->assertTrue($this->cluster->isActiveNodePinned());

            $this->assertSlideNodeThrows();
        });

        $this->assertSame($original, $this->client->transport()->getCurler());
        $this->assertFalse($connection->inSession());
        $this->assertFalse($this->cluster->isActiveNodePinned());
        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $this->clientSessionSettings());
    }

    private function assertSlideNodeThrows(): void
    {
        try {
            $this->cluster->slideNode();
            $this->fail('slideNode() did not throw inside the session.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('as it is while session() runs', $exception->getMessage());
        }
    }

    /**
     * A test double that overrides getClient() has no cluster to pin.
     */
    public function test_a_connection_without_a_cluster_runs_the_session_on_its_client(): void
    {
        $client = new CannedAsyncClient();
        $connection = new class (null, 'db', '', ['name' => 'clickhouse']) extends Connection {
            public CannedAsyncClient $cannedClient;

            public function getClient(): CannedAsyncClient
            {
                return $this->cannedClient;
            }
        };
        $connection->cannedClient = $client;

        $this->assertTrue($connection->session(fn (Connection $connection): bool => $connection->inSession()));
        $this->assertFalse($connection->inSession());
        $this->assertSame(['SELECT 1 FORMAT JSON'], $client->sentSql());
    }

    public function test_a_client_without_retries_gets_a_session_curler_without_retries(): void
    {
        $connection = $this->connection();

        $retries = $connection->session(fn (): int => $this->client->transport()->getCurler()->getRetries());

        $this->assertSame(0, $retries);
    }

    public function test_an_exception_of_the_callback_is_thrown_as_it_is_and_everything_is_restored(): void
    {
        $connection = $this->connection();
        $original = $this->client->transport()->getCurler();
        $thrown = new RuntimeException('in the callback');

        try {
            $connection->session(function () use ($thrown): never {
                throw $thrown;
            });
            $this->fail('The exception of the callback was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($thrown, $exception);
        }

        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $this->clientSessionSettings());
        $this->assertSame($original, $this->client->transport()->getCurler());
        $this->assertFalse($this->cluster->isActiveNodePinned());
    }

    /**
     * A request that never reached the server is sent again under the session curler's 'unsent' policy, up to the
     * connection's retries, and the error is thrown as it is; the callback does not run.
     */
    public function test_a_transport_failure_of_the_open_restores_everything(): void
    {
        $connection = $this->connection(new CannedAsyncClient(fn (): CurlerResponse => CannedAsyncClient::unreachable()));
        $original = new CurlerRollingWithRetries();
        $original->setRetries(2);
        $this->client->transport()->setDirtyCurler($original);

        try {
            $connection->session(fn () => $this->fail('The callback ran.'));
            $this->fail('The transport failure was not thrown.');
        } catch (ClickHouseUnavailableException $exception) {
            $this->assertSame(7, $exception->getCode());
        }

        $this->assertSame(['SELECT 1 FORMAT JSON'], $this->client->sentSql());
        $this->assertSame(3, CannedAsyncClient::attemptsOf($this->client->requests[0]));
        $this->assertSame($original, $this->client->transport()->getCurler());
        $this->assertFalse($this->cluster->isActiveNodePinned());
        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $this->clientSessionSettings());
    }

    /**
     * Session settings that the client had before, such as from the connection's settings option, come back.
     */
    public function test_session_settings_that_the_client_had_before_are_restored(): void
    {
        $connection = $this->connection();
        $this->client->settings()->set('session_timeout', 300);
        $this->client->settings()->set('session_check', 0);

        $connection->session(fn (Connection $connection) => $connection->select('SELECT 2'), 30);
        $connection->select('SELECT 3');

        [, $inside, $after] = array_map(self::sessionOf(...), $this->client->requests);
        $this->assertSame('30', $inside['session_timeout']);
        $this->assertSame('1', $inside['session_check']);
        $this->assertSame(['session_id' => null, 'session_timeout' => '300', 'session_check' => '0'], $after);
        $this->assertSame(['session_id' => null, 'session_timeout' => 300, 'session_check' => 0], $this->clientSessionSettings());
    }

    /**
     * When an inner session throws and the outer callback catches the error, the outer callback goes on in its own
     * session, with its own curler, and the node stays pinned.
     */
    public function test_an_outer_session_goes_on_after_an_inner_session_throws(): void
    {
        $connection = $this->connection();

        $connection->session(function (Connection $connection): void {
            $outerCurler = $this->client->transport()->getCurler();
            $connection->select('SELECT outer_before');

            try {
                $connection->session(function (Connection $connection): never {
                    $connection->select('SELECT inner');

                    throw new RuntimeException('in the inner callback');
                });
                $this->fail('The exception of the inner callback was not thrown.');
            } catch (RuntimeException $exception) {
                $this->assertSame('in the inner callback', $exception->getMessage());
            }

            $connection->select('SELECT outer_after');
            $this->assertSame($outerCurler, $this->client->transport()->getCurler());
            $this->assertTrue($this->cluster->isActiveNodePinned(), 'The outer session still pins the node.');
            $this->assertTrue($connection->inSession());
        });

        $sessions = array_combine($this->client->sentSql(), array_map(self::sessionOf(...), $this->client->requests));
        $outer = $sessions['SELECT outer_before FORMAT JSON'];
        $this->assertNotSame($outer['session_id'], $sessions['SELECT inner FORMAT JSON']['session_id']);
        $this->assertSame($outer, $sessions['SELECT outer_after FORMAT JSON']);
        $this->assertSame('1', $outer['session_check']);
        $this->assertFalse($this->cluster->isActiveNodePinned());
        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $this->clientSessionSettings());
    }

    /**
     * @return array<string, array{Closure(Connection): mixed, string}>
     */
    public static function lazyReaders(): array
    {
        return [
            'the generator of cursor()' => [
                fn (Connection $connection): Generator => $connection->cursor('SELECT 2'),
                'Generator',
            ],
            'a LazyCollection' => [
                fn (Connection $connection): LazyCollection => LazyCollection::make(
                    fn (): Generator => yield from $connection->select('SELECT 2')
                ),
                LazyCollection::class,
            ],
        ];
    }

    /**
     * A lazy reader would run its query after the session has ended, outside it, where a temporary table of the
     * session is unknown or a table of the same name is read instead: it is refused, and its query never runs.
     *
     * @param Closure(Connection): mixed $callback
     */
    #[DataProvider('lazyReaders')]
    public function test_a_lazy_reader_returned_by_the_callback_is_refused(Closure $callback, string $type): void
    {
        $connection = $this->connection();
        $original = $this->client->transport()->getCurler();

        try {
            $connection->session($callback);
            $this->fail('The lazy reader was not refused.');
        } catch (LogicException $exception) {
            $this->assertSame(
                "The session() callback returned a lazy reader ({$type}), which would run its query after the session"
                . ' has ended, outside it. Read the rows inside the callback and return them, for example with'
                . ' iterator_to_array() or ->all().',
                $exception->getMessage()
            );
        }

        $this->assertSame(['SELECT 1 FORMAT JSON'], $this->client->sentSql());
        $this->assertSame($original, $this->client->transport()->getCurler());
        $this->assertFalse($this->cluster->isActiveNodePinned());
        $this->assertFalse($connection->inSession());
    }

    public function test_rows_that_a_lazy_reader_read_inside_the_callback_are_returned(): void
    {
        $connection = $this->connection();

        $rows = $connection->session(
            fn (Connection $connection): array => iterator_to_array($connection->cursor('SELECT 2'))
        );

        $this->assertSame([['x' => '1']], $rows);
        $this->assertSame('1', self::sessionOf($this->client->requests[1])['session_check']);
    }

    public function test_a_nested_session_is_a_separate_session(): void
    {
        $connection = $this->connection();

        $connection->session(function (Connection $connection): void {
            $outerCurler = $this->client->transport()->getCurler();
            $connection->select('SELECT outer_before');
            $connection->session(function (Connection $connection): void {
                $connection->select('SELECT inner');
                $this->assertTrue($this->cluster->isActiveNodePinned());
            }, 30);
            $connection->select('SELECT outer_after');

            $this->assertSame($outerCurler, $this->client->transport()->getCurler());
            $this->assertTrue($this->cluster->isActiveNodePinned(), 'The outer session still pins the node.');
        });

        $sessions = array_combine($this->client->sentSql(), array_map(self::sessionOf(...), $this->client->requests));
        $outer = $sessions['SELECT outer_before FORMAT JSON'];
        $inner = $sessions['SELECT inner FORMAT JSON'];
        $this->assertNotSame($outer['session_id'], $inner['session_id']);
        $this->assertSame('30', $inner['session_timeout']);
        $this->assertSame('1', $inner['session_check']);
        $this->assertSame($outer, $sessions['SELECT outer_after FORMAT JSON']);
        $this->assertFalse($this->cluster->isActiveNodePinned());
    }

    public function test_the_session_of_smi2s_use_session_is_restored(): void
    {
        $connection = $this->connection();
        $this->client->useSession('own');

        $connection->session(fn (Connection $connection) => $connection->select('SELECT 2'));
        $connection->select('SELECT 3');

        $sessions = array_map(self::sessionOf(...), $this->client->requests);
        $this->assertNotSame('own', $sessions[1]['session_id']);
        $this->assertSame(['session_id' => 'own', 'session_timeout' => null, 'session_check' => null], $sessions[2]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidTimeouts(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    #[DataProvider('invalidTimeouts')]
    public function test_a_timeout_below_one_second_is_refused_before_anything_is_sent(int $timeout): void
    {
        $connection = $this->connection();

        try {
            $connection->session(fn () => $this->fail('The callback ran.'), $timeout);
            $this->fail('The timeout was not refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                "The timeout of a ClickHouse session must be at least 1 second, {$timeout} given: ClickHouse closes a"
                . ' session with a timeout of 0 at the end of the query that opens it.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->client->requests);
        $this->assertFalse($this->cluster->isActiveNodePinned());
    }

    public function test_session_not_found_is_thrown_as_a_query_exception_that_names_the_timeout(): void
    {
        $connection = $this->connection(new CannedAsyncClient(
            fn (string $sql, CurlerRequest $request): CurlerResponse => str_contains($sql, 'expired')
                ? self::sessionError($request, 372)
                : CannedAsyncClient::json([['x' => '1']])
        ));

        try {
            $connection->session(fn (Connection $connection) => $connection->select('SELECT expired'), 5);
            $this->fail('SESSION_NOT_FOUND was not thrown.');
        } catch (QueryException $exception) {
            $sessionId = self::sessionOf($this->client->requests[0])['session_id'];
            $this->assertSame(372, $exception->getCode());
            $this->assertStringContainsString("ClickHouse no longer knows the session {$sessionId} (SESSION_NOT_FOUND)", $exception->getMessage());
            $this->assertStringContainsString('It closes a session 5 seconds after', $exception->getMessage());
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
            $this->assertSame(372, $exception->getPrevious()->getCode());
        }

        $this->assertFalse($connection->inSession());
    }

    public function test_session_is_locked_is_thrown_as_a_query_exception(): void
    {
        $connection = $this->connection(new CannedAsyncClient(
            fn (string $sql, CurlerRequest $request): CurlerResponse => str_contains($sql, 'busy')
                ? self::sessionError($request, 373)
                : CannedAsyncClient::json([['x' => '1']])
        ));

        try {
            $connection->session(fn (Connection $connection) => $connection->statement('INSERT INTO t SELECT busy'));
            $this->fail('SESSION_IS_LOCKED was not thrown.');
        } catch (QueryException $exception) {
            $this->assertSame(373, $exception->getCode());
            $this->assertStringContainsString('is still running another query (SESSION_IS_LOCKED)', $exception->getMessage());
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
        }
    }

    /**
     * Only an error of this session is described: a SESSION_NOT_FOUND of another session id, as one opened with
     * smi2's useSession() inside the callback, and any other error, such as an invalid timeout of the query that
     * opens the session, are thrown as they are.
     */
    public function test_other_errors_are_thrown_as_they_are(): void
    {
        $connection = $this->connection(new CannedAsyncClient(
            fn (string $sql): CurlerResponse => match (true) {
                str_contains($sql, 'other') => CannedAsyncClient::error(500, 372, 'Session 0123 not found', 'SESSION_NOT_FOUND'),
                str_contains($sql, 'missing') => CannedAsyncClient::error(404, 60, 'Unknown table expression identifier', 'UNKNOWN_TABLE'),
                default => CannedAsyncClient::json([['x' => '1']]),
            }
        ));

        foreach (['SELECT other' => 372, 'SELECT * FROM missing' => 60] as $sql => $code) {
            try {
                $connection->session(fn (Connection $connection) => $connection->select($sql));
                $this->fail("{$sql} did not throw.");
            } catch (DatabaseException $exception) {
                $this->assertSame($code, $exception->getCode());
            }
        }
    }

    public function test_an_invalid_timeout_of_the_server_fails_before_the_callback_runs(): void
    {
        $connection = $this->connection(new CannedAsyncClient(
            fn (): CurlerResponse => CannedAsyncClient::error(
                500,
                374,
                'Session timeout 3601 is larger than max_session_timeout: 3600',
                'INVALID_SESSION_TIMEOUT'
            )
        ));

        try {
            $connection->session(fn () => $this->fail('The callback ran.'), 3601);
            $this->fail('INVALID_SESSION_TIMEOUT was not thrown.');
        } catch (DatabaseException $exception) {
            $this->assertSame(374, $exception->getCode());
        }

        $this->assertSame(['SELECT 1 FORMAT JSON'], $this->client->sentSql());
        $this->assertSame(['session_id' => null, 'session_timeout' => null, 'session_check' => null], $this->clientSessionSettings());
        $this->assertFalse($this->cluster->isActiveNodePinned());
    }

    /**
     * A parallel batch in the session that was sent would never end, since smi2's CurlerRolling runs a request with
     * a session id again and again; the canned client throws after CannedAsyncClient::MAX_ATTEMPTS runs, so the test
     * then fails at once.
     */
    public function test_asynchronous_and_parallel_queries_are_refused_inside_the_session(): void
    {
        $connection = $this->connection();

        $connection->session(function (Connection $connection): void {
            try {
                $this->client->selectAsync('SELECT async');
                $this->fail('selectAsync() was not refused.');
            } catch (QueryException $exception) {
                $this->assertSame(QueryException::cannotRunAsynchronouslyInSession()->getMessage(), $exception->getMessage());
            }

            try {
                Parallel::get(['q' => ['sql' => 'SELECT parallel', 'connection' => $connection]]);
                $this->fail('Parallel::get() was not refused.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('runs on a client that uses a ClickHouse session', $exception->getMessage());
            }
        });

        $this->assertSame(['SELECT 1 FORMAT JSON', 'SELECT async FORMAT JSON', 'SELECT parallel FORMAT JSON'], $this->client->sentSql());
        $this->assertSame([1, 0, 0], array_map(CannedAsyncClient::attemptsOf(...), $this->client->requests));
    }
}
