<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;

/**
 * Retries and timeouts of a connection's client against the server.
 *
 * An INSERT that times out on the client keeps running on the server and still writes its rows. With
 * retry_on 'any' the client sends it again, so it writes them once per attempt; with 'unsent' it is sent once.
 */
class RetriesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $base = $config->get('database.connections.clickhouse');
        foreach (['any', 'unsent'] as $policy) {
            $config->set(
                "database.connections.clickhouse-retry-{$policy}",
                ['retries' => 2, 'retry_on' => $policy, 'timeout_query' => 1] + $base
            );
        }
        $config->set('database.connections.clickhouse-half-second', ['timeout_query' => 0.5] + $base);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS retries_rows SYNC');
        $client->write('CREATE TABLE retries_rows (x UInt8, marker String) ENGINE = MergeTree ORDER BY x');
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS retries_rows SYNC');
        parent::tearDown();
    }

    public function test_the_connection_gives_its_client_the_retry_policy(): void
    {
        foreach (['any', 'unsent'] as $policy) {
            $curler = DB::connection("clickhouse-retry-{$policy}")->getClient()->transport()->getCurler();

            $this->assertInstanceOf(CurlerRollingWithRetries::class, $curler);
            $this->assertSame(2, $curler->getRetries());
            $this->assertSame($policy, $curler->getRetryOn());
        }
    }

    public function test_any_sends_an_insert_that_timed_out_on_the_client_again(): void
    {
        $this->assertSame(3, $this->rowsOfAnInsertThatTimesOut(DB::connection('clickhouse-retry-any')->getClient()));
    }

    public function test_unsent_does_not_send_an_insert_that_reached_the_server_again(): void
    {
        $this->assertSame(1, $this->rowsOfAnInsertThatTimesOut(DB::connection('clickhouse-retry-unsent')->getClient()));
    }

    public function test_a_fractional_query_timeout_is_rounded_up_and_cuts_a_longer_query(): void
    {
        $client = DB::connection('clickhouse-half-second')->getClient();
        $this->assertSame(1, $client->getTimeout());

        $start = microtime(true);
        try {
            $client->select('SELECT sum(sleepEachRow(0.5)) AS s FROM numbers(5) SETTINGS max_block_size = 1')->rows();
            $this->fail('The query of 2.5 seconds was not cut short.');
        } catch (QueryException $exception) {
            // The HTTP timeout (curl error 28) or the server's max_execution_time (TIMEOUT_EXCEEDED, 159) ends it.
            $this->assertContains($exception->getCode(), [CURLE_OPERATION_TIMEDOUT, 159]);
        }

        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    /**
     * Send an INSERT that the server runs for 1.5 seconds through a client whose timeout_query is 1 second, wait
     * until the server has finished every attempt, and count the rows that the INSERT wrote.
     *
     * @param Client $client
     * @return int
     */
    private function rowsOfAnInsertThatTimesOut(Client $client): int
    {
        $marker = 'retries_' . bin2hex(random_bytes(8));

        try {
            $client->write(
                "INSERT INTO retries_rows SELECT sleep(1.5), '{$marker}' SETTINGS max_execution_time = 0"
            );
            $this->fail('The INSERT did not time out on the client.');
        } catch (QueryException $exception) {
            $this->assertSame(CURLE_OPERATION_TIMEDOUT, $exception->getCode());
        }

        $node = DB::connection('clickhouse')->getClient();
        $deadline = microtime(true) + 15;
        do {
            $running = (int) $node->select(
                "SELECT count() AS running FROM system.processes WHERE query LIKE '%{$marker}%'"
                . " AND query NOT LIKE '%system.processes%'"
            )->fetchOne('running');
            if ($running > 0) {
                usleep(100_000);
            }
        } while ($running > 0 && microtime(true) < $deadline);

        return (int) $node->select("SELECT count() AS c FROM retries_rows WHERE marker = '{$marker}'")->fetchOne('c');
    }
}
