<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;

/**
 * affectingStatement(), and with it Laravel's insertUsing(), update() and delete(): an INSERT returns the rows that
 * ClickHouse reports it wrote (X-ClickHouse-Summary written_rows), 0 included. The count of a synchronous insert
 * includes the rows that the table's materialized views wrote; the count of an asynchronous insert depends on the
 * server version (see test_the_count_of_an_asynchronous_insert_follows_the_server()). ClickHouse reports no count for a
 * mutation or DDL, so those return 1, as in 3.0.0.
 */
class AffectingStatementTest extends TestCase
{
    private const TABLE = 'affecting_rows';

    private const LARAVEL_CONNECTION = 'clickhouse-affecting-laravel';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        DB::connection('clickhouse')->getClient()->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, v String) ENGINE = MergeTree ORDER BY id'
        );
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_an_insert_returns_the_rows_it_wrote(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertSame(3, $connection->affectingStatement(
            'INSERT INTO ' . self::TABLE . ' (id, v) VALUES (?, ?), (?, ?), (?, ?)',
            [1, 'a', 2, 'b', 3, "it's"]
        ));
        $this->assertSame(2, $connection->affectingStatement(
            "/* two rows */ insert into " . self::TABLE . " values (4, 'd'), (5, 'e')"
        ));
        $this->assertSame(25, $connection->affectingStatement(
            "-- many rows\nINSERT INTO " . self::TABLE . ' SELECT number + 100, toString(number) FROM numbers(25)'
        ));
        $this->assertSame(0, $connection->affectingStatement(
            'INSERT INTO ' . self::TABLE . ' SELECT number, toString(number) FROM numbers(?) WHERE number > ?',
            [10, 100]
        ));
        $this->assertSame(30, $this->countRows());
    }

    public function test_insert_using_returns_the_rows_it_wrote(): void
    {
        $connection = $this->laravelBuilderConnection();
        $connection->table(self::TABLE)->insert([['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b'], ['id' => 3, 'v' => 'c']]);

        $this->assertSame(2, $connection->table(self::TABLE)->insertUsing(
            ['id', 'v'],
            $connection->table(self::TABLE)->selectRaw('id + 10, v')->where('id', '>', 1)
        ));
        $this->assertSame(0, $connection->table(self::TABLE)->insertUsing(
            ['id', 'v'],
            $connection->table(self::TABLE)->selectRaw('id + 20, v')->where('id', '>', 100)
        ));
        $this->assertSame(5, $this->countRows());
    }

    public function test_mutations_return_one(): void
    {
        $connection = $this->laravelBuilderConnection();
        $connection->table(self::TABLE)->insert([['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b']]);

        $this->assertSame(1, $connection->table(self::TABLE)->where('id', 1)->update(['v' => 'z']));
        $this->assertSame(1, $connection->table(self::TABLE)->where('id', 999)->delete());
        $this->assertSame(1, $connection->affectingStatement(
            'ALTER TABLE ' . self::TABLE . ' DELETE WHERE id = ? SETTINGS mutations_sync = 2',
            [2]
        ));
        $this->assertSame(1, $this->lightweightDeleteConnection()->table(self::TABLE)->where('id', 1)->delete());
        $this->assertSame(1, DB::connection('clickhouse')->update(
            'ALTER TABLE ' . self::TABLE . ' UPDATE v = ? WHERE id = ? SETTINGS mutations_sync = 2',
            ['y', 1]
        ));
    }

    /**
     * ClickHouse counts the rows that a materialized view writes into its target table in written_rows of a
     * synchronous insert, so an INSERT of 3 rows into a table with one such view returns 6. The INSERT ... VALUES
     * turns async_insert off: ClickHouse 26.3 and 26.8 insert asynchronously by default (async_insert = 1), and the
     * count of an asynchronous insert leaves the rows of the views out (3). An INSERT ... SELECT is never
     * asynchronous. A change of these counts in a later ClickHouse version shows up here.
     */
    public function test_the_count_of_an_insert_includes_the_rows_of_materialized_views(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('CREATE TABLE ' . self::TABLE . '_target (id UInt32) ENGINE = MergeTree ORDER BY id');
        $client->write(
            'CREATE MATERIALIZED VIEW ' . self::TABLE . '_view TO ' . self::TABLE . '_target AS SELECT id FROM '
            . self::TABLE
        );
        $connection = DB::connection('clickhouse');

        $this->assertSame(6, $connection->affectingStatement(
            'INSERT INTO ' . self::TABLE . ' (id, v) SETTINGS async_insert = 0 VALUES (?, ?), (?, ?), (?, ?)',
            [1, 'a', 2, 'b', 3, 'c']
        ));
        $this->assertSame(4, $this->laravelBuilderConnection()->table(self::TABLE)->insertUsing(
            ['id', 'v'],
            'SELECT number + 10, toString(number) FROM numbers(2)'
        ));
        $this->assertSame(5, $this->countRows());
    }

    /**
     * An asynchronous insert writes its rows in a batch. When the query waits for the batch, ClickHouse 24.8 does not
     * count its rows for the query (written_rows is 0), while 26.3 and 26.8 do (3). The count is the one that the
     * server logged for the query in system.query_log, on every version. Either way the rows are written, and
     * hasModifiedRecords() follows the count.
     */
    public function test_the_count_of_an_asynchronous_insert_follows_the_server(): void
    {
        $connection = DB::connection('clickhouse');
        $comment = 'affecting_rows_' . bin2hex(random_bytes(8));

        $count = $connection->affectingStatement(
            'INSERT INTO ' . self::TABLE . ' (id, v) SETTINGS async_insert = 1, wait_for_async_insert = 1,'
            . " log_comment = '{$comment}' VALUES (?, ?), (?, ?), (?, ?)",
            [1, 'a', 2, 'b', 3, 'c']
        );

        $this->assertSame($this->loggedWrittenRowsOfTheInsert($comment), $count);
        $this->assertContains($count, [0, 3]);
        $this->assertSame($count > 0, $connection->hasModifiedRecords());
        $this->assertSame(3, $this->countRows());
    }

    public function test_ddl_returns_one(): void
    {
        $this->assertSame(1, DB::connection('clickhouse')->affectingStatement(
            'CREATE TABLE ' . self::TABLE . '_created (id UInt8) ENGINE = Memory'
        ));
        $this->assertSame(1, DB::connection('clickhouse')->affectingStatement('TRUNCATE TABLE ' . self::TABLE));
    }

    private function laravelBuilderConnection(array $config = []): Connection
    {
        config(['database.connections.' . self::LARAVEL_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false],
            $config
        )]);
        DB::purge(self::LARAVEL_CONNECTION);

        return DB::connection(self::LARAVEL_CONNECTION);
    }

    private function lightweightDeleteConnection(): Connection
    {
        return $this->laravelBuilderConnection(['use_lightweight_delete' => true]);
    }

    private function dropTables(): void
    {
        $client = DB::connection('clickhouse')->getClient();

        foreach (['_view', '_target', '', '_created'] as $suffix) {
            $client->write('DROP TABLE IF EXISTS ' . self::TABLE . $suffix . ' SYNC');
        }
    }

    /**
     * Read the written_rows that the server logged in system.query_log for the one INSERT query with the given
     * log_comment. The flush of an asynchronous insert is logged with the same comment, as a query of the kind
     * AsyncInsertFlush, and is left out.
     */
    private function loggedWrittenRowsOfTheInsert(string $comment): int
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('SYSTEM FLUSH LOGS');
        $rows = $client->select(
            'SELECT toString(written_rows) AS written_rows FROM system.query_log'
            . " WHERE type = 'QueryFinish' AND query_kind = 'Insert' AND log_comment = '{$comment}'"
        )->rows();

        $this->assertCount(1, $rows, 'The server logged the insert once.');

        return (int) $rows[0]['written_rows'];
    }

    private function countRows(): int
    {
        return (int) DB::connection('clickhouse')->getClient()
            ->select('SELECT count() AS c FROM ' . self::TABLE)
            ->fetchOne('c');
    }
}
