<?php

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\CurlerRollingInSession;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use RuntimeException;

class SessionTemporaryRow extends BaseModel
{
    protected $table = 'session_test_rows';
}

/**
 * Connection::session() against the server. Each test creates its own temporary tables with raw CREATE TEMPORARY
 * TABLE statements; they only exist in their session, so nothing is written to a database table.
 */
class SessionTest extends TestCase
{
    private const CREATE_ROWS = 'CREATE TEMPORARY TABLE session_test_rows (id UInt32, name String)';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app['config'];
        $base = $config->get('database.connections.clickhouse');
        $config->set('database.connections.clickhouse-session-retries', ['retries' => 2] + $base);
        $config->set('database.connections.clickhouse-session-laravel', ['fix_default_query_builder' => false] + $base);
    }

    /**
     * Run a query and return the code of the DatabaseException it throws.
     *
     * @param callable(): mixed $query
     * @return int
     */
    private function codeOf(callable $query): int
    {
        try {
            $query();
        } catch (DatabaseException $exception) {
            return $exception->getCode();
        }

        $this->fail('The query did not throw a DatabaseException.');
    }

    public function test_a_temporary_table_is_used_by_every_query_path_of_the_session(): void
    {
        $connection = DB::connection('clickhouse');

        $rows = $connection->session(function (Connection $connection): array {
            $connection->statement(self::CREATE_ROWS);
            SessionTemporaryRow::insertAssoc([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);
            $connection->table('session_test_rows')->insert([['id' => 3, 'name' => 'c']]);

            return [
                'model' => SessionTemporaryRow::select(['id', 'name'])->orderBy('id')->getRows(),
                'builder' => $connection->table('session_test_rows')->where('id', '>', 1)->orderBy('id')->getRows(),
                'select' => $connection->select('SELECT count() AS rows FROM session_test_rows'),
            ];
        });

        $this->assertSame(
            [
                'model' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']],
                'builder' => [['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']],
                'select' => [['rows' => '3']],
            ],
            $rows
        );
        $this->assertSame(60, $this->codeOf(fn () => $connection->select('SELECT count() FROM session_test_rows')));
    }

    public function test_another_connection_is_outside_the_session(): void
    {
        DB::connection('clickhouse')->session(function (): void {
            DB::connection('clickhouse')->statement(self::CREATE_ROWS);

            $this->assertSame(
                60,
                $this->codeOf(fn () => DB::connection('clickhouse-session-laravel')->table('session_test_rows')->count())
            );
        });
    }

    public function test_laravels_query_builder_joins_the_session(): void
    {
        $connection = DB::connection('clickhouse-session-laravel');

        $count = $connection->session(function (Connection $connection): int {
            $connection->statement(self::CREATE_ROWS);
            $connection->table('session_test_rows')->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

            return $connection->table('session_test_rows')->where('id', '>', 1)->count();
        });

        $this->assertSame(1, $count);
    }

    /**
     * A lazy reader returned by the callback would read after the session, where its temporary table is unknown: it
     * is refused, and the rows read inside the callback are returned.
     */
    public function test_a_lazy_reader_is_read_inside_the_callback(): void
    {
        $connection = DB::connection('clickhouse-session-laravel');
        $create = function (Connection $connection): void {
            $connection->statement(self::CREATE_ROWS);
            $connection->statement("INSERT INTO session_test_rows VALUES (1, 'a'), (2, 'b')");
        };

        try {
            $connection->session(function (Connection $connection) use ($create): LazyCollection {
                $create($connection);

                return $connection->table('session_test_rows')->orderBy('id')->cursor();
            });
            $this->fail('The lazy reader was not refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('returned a lazy reader (Illuminate\\Support\\LazyCollection)', $exception->getMessage());
        }

        $rows = $connection->session(function (Connection $connection) use ($create): array {
            $create($connection);

            return [
                'cursor' => iterator_to_array($connection->cursor('SELECT id FROM session_test_rows ORDER BY id')),
                'lazy' => $connection->table('session_test_rows')->orderBy('id')->cursor()->pluck('name')->all(),
            ];
        });

        $this->assertSame(['cursor' => [['id' => 1], ['id' => 2]], 'lazy' => ['a', 'b']], $rows);
        $this->assertFalse($connection->inSession());
    }

    public function test_it_returns_the_result_of_the_callback_and_passes_the_connection(): void
    {
        $connection = DB::connection('clickhouse');

        $result = $connection->session(function (Connection $passed) use ($connection): string {
            $this->assertSame($connection, $passed);

            return 'result';
        });

        $this->assertSame('result', $result);
    }

    public function test_a_setting_lasts_for_the_session(): void
    {
        $connection = DB::connection('clickhouse');
        $read = fn (): int => (int) $connection->select("SELECT getSetting('max_threads') AS value")[0]['value'];
        $outside = $read();

        $inside = $connection->session(function (Connection $connection) use ($read): int {
            $connection->statement('SET max_threads = 3');

            return $read();
        });

        $this->assertSame(3, $inside);
        $this->assertSame($outside, $read());
    }

    public function test_an_exception_of_the_callback_is_thrown_and_the_client_is_restored(): void
    {
        $connection = DB::connection('clickhouse');
        $client = $connection->getClient();
        $curler = $client->transport()->getCurler();

        try {
            $connection->session(function (): never {
                throw new RuntimeException('in the callback');
            });
            $this->fail('The exception of the callback was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('in the callback', $exception->getMessage());
        }

        foreach (['session_id', 'session_timeout', 'session_check'] as $setting) {
            $this->assertNull($client->settings()->get($setting), $setting);
        }
        $this->assertSame($curler, $client->transport()->getCurler());
        $this->assertFalse($connection->inSession());
    }

    public function test_a_nested_session_is_a_separate_session(): void
    {
        $connection = DB::connection('clickhouse');

        $counts = $connection->session(function (Connection $connection): array {
            $connection->statement(self::CREATE_ROWS);
            $connection->statement("INSERT INTO session_test_rows VALUES (1, 'a')");

            $inner = $connection->session(
                fn (Connection $connection): int => $this->codeOf(fn () => $connection->select('SELECT count() FROM session_test_rows'))
            );

            return [$inner, $connection->select('SELECT count() AS rows FROM session_test_rows')];
        });

        $this->assertSame([60, [['rows' => '1']]], $counts);
    }

    public function test_a_session_that_expired_throws_session_not_found(): void
    {
        $connection = DB::connection('clickhouse');

        try {
            $connection->session(function (Connection $connection): void {
                $connection->statement(self::CREATE_ROWS);
                sleep(4);
                $connection->select('SELECT count() FROM session_test_rows');
            }, 1);
            $this->fail('The expired session did not throw.');
        } catch (QueryException $exception) {
            $this->assertSame(372, $exception->getCode());
            $this->assertStringContainsString('(SESSION_NOT_FOUND)', $exception->getMessage());
            $this->assertStringContainsString('It closes a session 1 second after', $exception->getMessage());
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
            $this->assertSame(372, $exception->getPrevious()->getCode());
        }
    }

    public function test_a_timeout_below_one_second_throws_before_anything_is_sent(): void
    {
        $connection = DB::connection('clickhouse');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The timeout of a ClickHouse session must be at least 1 second, 0 given');

        try {
            $connection->session(fn () => $this->fail('The callback ran.'), 0);
        } finally {
            $this->assertFalse($connection->inSession());
        }
    }

    public function test_a_timeout_above_the_servers_maximum_fails_before_the_callback_runs(): void
    {
        $connection = DB::connection('clickhouse');
        $ran = false;

        try {
            $connection->session(function () use (&$ran): void {
                $ran = true;
            }, 3601);
            $this->fail('The timeout above max_session_timeout was accepted.');
        } catch (DatabaseException $exception) {
            $this->assertSame(374, $exception->getCode());
            $this->assertSame('INVALID_SESSION_TIMEOUT', $exception->getClickHouseExceptionName());
        }

        $this->assertFalse($ran);
        $this->assertFalse($connection->inSession());
    }

    public function test_in_session_is_true_only_inside_the_callback(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertFalse($connection->inSession());
        $this->assertTrue($connection->session(fn (Connection $connection): bool => $connection->inSession()));
        $this->assertFalse($connection->inSession());
    }

    public function test_smi2s_asynchronous_select_is_refused_inside_the_session(): void
    {
        $connection = DB::connection('clickhouse');

        $connection->session(function (Connection $connection): void {
            $client = $connection->getClient();

            try {
                $client->selectAsync('SELECT 1');
                $this->fail('selectAsync() was not refused.');
            } catch (QueryException $exception) {
                $this->assertSame(QueryException::cannotRunAsynchronouslyInSession()->getMessage(), $exception->getMessage());
            }

            $this->assertSame(0, $client->getCountPendingQueue());
        });
    }

    public function test_the_session_curler_keeps_the_retries_of_the_connection(): void
    {
        $connection = DB::connection('clickhouse-session-retries');
        $original = $connection->getClient()->transport()->getCurler();
        $this->assertInstanceOf(CurlerRollingWithRetries::class, $original);

        $connection->session(function (Connection $connection): void {
            $curler = $connection->getClient()->transport()->getCurler();

            $this->assertInstanceOf(CurlerRollingInSession::class, $curler);
            $this->assertSame(2, $curler->getRetries());
            $this->assertSame(CurlerRollingWithRetries::RETRY_ON_UNSENT, $curler->getRetryOn());
        });

        $this->assertSame($original, $connection->getClient()->transport()->getCurler());
    }

    public function test_a_session_on_a_cluster_connection_stays_on_its_node(): void
    {
        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            $this->markTestSkipped('Needs the second node of the test cluster; set CLICKHOUSE_CLUSTER_AVAILABLE=1');
        }

        $connection = DB::connection('clickhouse-cluster');
        $node = $connection->getClient();

        $counts = $connection->session(function (Connection $connection) use ($node): array {
            $connection->statement(self::CREATE_ROWS);
            $connection->statement("INSERT INTO session_test_rows VALUES (1, 'a'), (2, 'b')");

            try {
                $connection->getCluster()->slideNode();
                $this->fail('slideNode() did not throw inside the session.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('is pinned, as it is while session() runs', $exception->getMessage());
            }

            $this->assertSame($node, $connection->getClient());
            $counts = [];
            for ($query = 0; $query < 5; $query++) {
                $counts[] = $connection->select('SELECT count() AS rows FROM session_test_rows')[0]['rows'];
            }

            return $counts;
        });

        $this->assertSame(['2', '2', '2', '2', '2'], $counts);
        $this->assertFalse($connection->getCluster()->isActiveNodePinned());
    }
}
