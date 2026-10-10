<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\CurlerRollingInSession;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The curler of a client inside Connection::session(), and the exceptions of sessions and temporary tables.
 * Nothing reaches a server: requests go to 127.0.0.1:1, which refuses them, or get canned responses.
 */
class CurlerRollingInSessionTest extends TestCase
{
    public function test_it_retries_only_unsent_requests_as_often_as_the_connection_says(): void
    {
        $curler = new CurlerRollingInSession(2);

        $this->assertInstanceOf(CurlerRollingWithRetries::class, $curler);
        $this->assertSame(2, $curler->getRetries());
        $this->assertSame(CurlerRollingWithRetries::RETRY_ON_UNSENT, $curler->getRetryOn());
        $this->assertSame(0, (new CurlerRollingInSession())->getRetries());
    }

    public function test_the_policy_cannot_be_changed_to_any(): void
    {
        $curler = new CurlerRollingInSession(1);
        $curler->setRetryOn(CurlerRollingWithRetries::RETRY_ON_UNSENT);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Inside a ClickHouse session the retry policy is always 'unsent', [any] given");

        $curler->setRetryOn(CurlerRollingWithRetries::RETRY_ON_ANY);
    }

    public function test_add_que_loop_throws_and_queues_nothing(): void
    {
        $curler = new CurlerRollingInSession();

        try {
            $curler->addQueLoop(new CurlerRequest());
            $this->fail('addQueLoop() did not throw.');
        } catch (QueryException $exception) {
            $this->assertSame(QueryException::cannotRunAsynchronouslyInSession()->getMessage(), $exception->getMessage());
        }

        $this->assertSame(0, $curler->countPending());
    }

    public function test_smi2_select_async_is_refused_before_anything_is_sent(): void
    {
        $client = $this->clientWithSessionCurler();

        try {
            $client->selectAsync('SELECT 1');
            $this->fail('selectAsync() did not throw.');
        } catch (QueryException) {
        }

        $this->assertSame(0, $client->getCountPendingQueue());
        $this->assertTrue($client->executeAsync(), 'An empty queue finishes at once.');
    }

    public function test_smi2_insert_batch_files_is_refused_before_anything_is_sent(): void
    {
        $client = $this->clientWithSessionCurler();
        $file = tempnam(sys_get_temp_dir(), 'session-batch-');
        file_put_contents($file, "1\n2\n");

        try {
            $this->expectException(QueryException::class);
            $client->insertBatchFiles('examples', [$file], ['f_int']);
        } finally {
            unlink($file);
        }
    }

    public function test_a_refused_connection_is_sent_again(): void
    {
        $curler = new class(2) extends CurlerRollingInSession {
            public int $attempts = 0;

            protected function attempt(CurlerRequest $request, bool $auto_close): int
            {
                $this->attempts++;

                return parent::attempt($request, $auto_close);
            }
        };
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->setConnectTimeOut(1.0);

        $this->assertSame(0, $curler->execOne($client->transport()->writeStreamData('SELECT 1')));
        $this->assertSame(3, $curler->attempts);
    }

    /**
     * @return array<string, array{array<string, int|float>}>
     */
    public static function attemptsThatReachedTheServer(): array
    {
        return [
            'connected' => [['http_code' => 0, 'connect_time' => 0.001, 'size_upload' => 0]],
            'sent part of the body' => [['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 12]],
            'got an answer' => [['http_code' => 500, 'connect_time' => 0.001, 'size_upload' => 12]],
        ];
    }

    /**
     * @param array<string, int|float> $info
     */
    #[DataProvider('attemptsThatReachedTheServer')]
    public function test_a_request_that_reached_the_server_is_not_sent_again(array $info): void
    {
        $curler = new class($info) extends CurlerRollingInSession {
            public int $attempts = 0;

            /**
             * @param array<string, int|float> $info
             */
            public function __construct(private array $info)
            {
                parent::__construct(2);
            }

            protected function attempt(CurlerRequest $request, bool $auto_close): int
            {
                $this->attempts++;
                $response = new CurlerResponse();
                $response->_info = $this->info;
                $request->setResponse($response);

                return (int) $this->info['http_code'];
            }
        };

        $curler->execOne(new CurlerRequest());

        $this->assertSame(1, $curler->attempts);
    }

    public function test_session_not_found_names_the_session_and_its_timeout(): void
    {
        $previous = DatabaseException::fromClickHouse('Session abc not found.', 372, 'SESSION_NOT_FOUND');

        $exception = QueryException::sessionNotFound('abc', 120, $previous);

        $this->assertSame(372, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertStringContainsString('ClickHouse no longer knows the session abc (SESSION_NOT_FOUND)', $exception->getMessage());
        $this->assertStringContainsString('120 seconds after', $exception->getMessage());
        $this->assertStringContainsString('max_session_timeout (3600 by default)', $exception->getMessage());
    }

    public function test_session_is_locked_names_the_session(): void
    {
        $previous = DatabaseException::fromClickHouse('Session abc is locked by a concurrent client.', 373, 'SESSION_IS_LOCKED');

        $exception = QueryException::sessionIsLocked('abc', $previous);

        $this->assertSame(373, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertStringStartsWith(
            'The ClickHouse session abc is still running another query (SESSION_IS_LOCKED).',
            $exception->getMessage()
        );
    }

    public function test_cannot_create_a_temporary_table_outside_a_session(): void
    {
        $this->assertSame(
            'Cannot create the temporary table tmp_ids outside a session: ClickHouse drops a temporary table at the end'
            . ' of the query that creates it, unless the query runs in a session. Create and use the table inside'
            . " the connection's session() callback.",
            QueryException::cannotCreateTemporaryTableOutsideSession('tmp_ids')->getMessage()
        );
    }

    public function test_cannot_reach_a_temporary_table_with_a_lightweight_delete(): void
    {
        $this->assertSame(
            'Cannot send DELETE FROM for shadow: the session has a temporary table named shadow, and ClickHouse'
            . ' (24.8 checked) runs a lightweight DELETE on the table shadow of the database instead. Call'
            . ' delete(false), which sends ALTER TABLE ... DELETE to the temporary table.',
            QueryException::cannotReachTemporaryTable(
                'DELETE FROM',
                'shadow',
                'Call delete(false), which sends ALTER TABLE ... DELETE to the temporary table.'
            )->getMessage()
        );
    }

    public function test_cannot_reach_a_temporary_table_on_cluster(): void
    {
        $this->assertSame(
            'Cannot send TRUNCATE ... ON CLUSTER for shadow: the session has a temporary table named shadow, and'
            . ' ClickHouse (24.8 checked) runs an ON CLUSTER statement on every node outside the session, on the'
            . ' table shadow of the database. Leave out onCluster() to reach the temporary table.',
            QueryException::cannotReachTemporaryTable(
                'TRUNCATE ... ON CLUSTER',
                'shadow',
                'Leave out onCluster() to reach the temporary table.'
            )->getMessage()
        );
    }

    /**
     * A client for a port that nothing listens on, with the session curler installed.
     *
     * @return Client
     */
    private function clientWithSessionCurler(): Client
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->settings()->set('session_id', 'abc');
        $client->transport()->setDirtyCurler(new CurlerRollingInSession());

        return $client;
    }
}
