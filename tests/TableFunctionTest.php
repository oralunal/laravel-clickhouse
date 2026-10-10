<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;

/**
 * The table functions of From against the server: remote() with a user and a password, and merge() with a regular
 * expression that has a backslash.
 */
class TableFunctionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        foreach (['table_fn_1', 'table_fn_2', 'table_fn_x'] as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
            $client->write("CREATE TABLE {$table} (id UInt32) ENGINE = MergeTree ORDER BY id");
        }
        $client->write('INSERT INTO table_fn_1 VALUES (1), (2)');
        $client->write('INSERT INTO table_fn_2 VALUES (3)');
        $client->write('INSERT INTO table_fn_x VALUES (4)');
    }

    protected function tearDown(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        foreach (['table_fn_1', 'table_fn_2', 'table_fn_x'] as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
        }

        parent::tearDown();
    }

    /**
     * ClickHouse refuses a user or a password that is not a string literal with BAD_ARGUMENTS.
     */
    public function testRemoteReadsATableWithAUserAndAPassword(): void
    {
        $connection = DB::connection('clickhouse');

        $rows = $connection->table(fn (From $from) => $from->remote(
            '127.0.0.1:9000',
            $connection->getDatabaseName(),
            'table_fn_1',
            (string) $connection->getConfig('username'),
            (string) $connection->getConfig('password')
        ))->orderBy('id')->getRows();

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
    }

    /**
     * \d in the regular expression reaches ClickHouse as \d, so table_fn_x is left out.
     */
    public function testMergeReadsTheTablesThatTheRegularExpressionMatches(): void
    {
        $connection = DB::connection('clickhouse');

        $ids = $connection->table(fn (From $from) => $from->merge($connection->getDatabaseName(), '^table_fn_\d+$'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([1, 2, 3], $ids);
    }
}
