<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Oralunal\LaravelClickHouse\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Transactions on a ClickHouse connection: beginTransaction(), transaction() and commit() throw a LogicException that
 * says what to do instead, without reconnecting first, and rollBack() does nothing, so that the teardown of a test
 * stays harmless.
 */
class TransactionTest extends TestCase
{
    private const MESSAGE = 'ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for'
        . ' ClickHouse connections and leave them out of $connectionsToTransact.';

    /**
     * @return array<string, array{callable(Connection): mixed}>
     */
    public static function transactionMethods(): array
    {
        return [
            'beginTransaction' => [fn (Connection $connection) => $connection->beginTransaction()],
            'transaction' => [fn (Connection $connection) => $connection->transaction(fn () => null)],
            'transaction with attempts' => [fn (Connection $connection) => $connection->transaction(fn () => null, 3)],
            'commit' => [fn (Connection $connection) => $connection->commit()],
        ];
    }

    /**
     * Laravel's beginTransaction() and transaction() would reconnect first, which builds a whole new connection
     * and fires ConnectionEstablished, only to fail on a missing PDO.
     *
     * @param callable(Connection): mixed $call
     */
    #[DataProvider('transactionMethods')]
    public function test_a_transaction_method_throws_without_reconnecting(callable $call): void
    {
        $connection = DB::connection('clickhouse');
        $established = 0;
        Event::listen(ConnectionEstablished::class, function () use (&$established): void {
            $established++;
        });

        try {
            $call($connection);
            $this->fail('The transaction method did not throw.');
        } catch (LogicException $exception) {
            $this->assertSame(self::MESSAGE, $exception->getMessage());
        }

        $this->assertSame(0, $established);
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame($connection, DB::connection('clickhouse'));
    }

    public function test_transaction_does_not_run_its_callback(): void
    {
        $ran = false;

        try {
            DB::connection('clickhouse')->transaction(function () use (&$ran): void {
                $ran = true;
            });
        } catch (LogicException) {
        }

        $this->assertFalse($ran);
    }

    public function test_roll_back_does_nothing(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertNull($connection->rollBack());
        $this->assertNull($connection->rollBack(0));
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame([['one' => 1]], $connection->select('SELECT 1 AS one'));
    }

    /**
     * DatabaseTransactions and RefreshDatabase begin a transaction on each connection in $connectionsToTransact.
     * Leaving the ClickHouse connections out lets them run; the default list, which names the default
     * connection, gets the LogicException.
     */
    public function test_database_transactions_run_when_clickhouse_is_left_out_of_the_connections_to_transact(): void
    {
        $leftOut = $this->databaseTransactions([]);
        $leftOut->beginDatabaseTransaction();
        $leftOut->rollBackDatabaseTransactions();

        $this->assertSame(0, DB::connection('clickhouse')->transactionLevel());

        $default = $this->databaseTransactions([null]);
        $dispatcher = DB::connection('clickhouse')->getEventDispatcher();

        try {
            $default->beginDatabaseTransaction();
            $this->fail('beginDatabaseTransaction() did not throw for the default connection.');
        } catch (LogicException $exception) {
            $this->assertSame(self::MESSAGE, $exception->getMessage());
        } finally {
            DB::connection('clickhouse')->setEventDispatcher($dispatcher);
        }

        $default->rollBackDatabaseTransactions();
    }

    /**
     * An object that uses Laravel's DatabaseTransactions trait the way a test case does.
     *
     * @param array<int, string|null> $connections Its $connectionsToTransact; [null], the trait's default, names the
     *                                             default connection
     * @return object
     */
    private function databaseTransactions(array $connections): object
    {
        $object = new class () {
            use DatabaseTransactions;

            public $app;

            /** @var array<int, string|null> */
            public array $connectionsToTransact = [];

            /** @var array<int, callable> */
            public array $teardown = [];

            public function rollBackDatabaseTransactions(): void
            {
                foreach ($this->teardown as $callback) {
                    $callback();
                }
            }

            protected function beforeApplicationDestroyed(callable $callback): void
            {
                $this->teardown[] = $callback;
            }
        };
        $object->app = $this->app;
        $object->connectionsToTransact = $connections;

        return $object;
    }
}
