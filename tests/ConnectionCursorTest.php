<?php

declare(strict_types=1);

namespace Tests;

use Generator;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;

/**
 * unprepared() and cursor() without PDO: unprepared() sends SQL as it is, and cursor() yields the rows that select()
 * returns, so Laravel's cursor() and lazy() give the rows that get() gives.
 */
class ConnectionCursorTest extends TestCase
{
    private const TABLE = 'cursor_rows';

    private const LARAVEL_CONNECTION = 'clickhouse-cursor-laravel';

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write('CREATE TABLE ' . self::TABLE . ' (id UInt32, v String) ENGINE = MergeTree ORDER BY id');
        $client->write('INSERT INTO ' . self::TABLE . ' SELECT number, toString(number * 2) FROM numbers(2500)');
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    public function test_unprepared_runs_ddl_as_it_is(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->assertTrue($connection->unprepared('CREATE TABLE ' . self::TABLE . '_ddl (id UInt8) ENGINE = Memory'));
        $this->assertSame('1', (string) $connection->getClient()->select('EXISTS TABLE ' . self::TABLE . '_ddl')->fetchOne('result'));
        $this->assertTrue($connection->unprepared('DROP TABLE ' . self::TABLE . '_ddl'));
        $this->assertSame('0', (string) $connection->getClient()->select('EXISTS TABLE ' . self::TABLE . '_ddl')->fetchOne('result'));

        $this->assertSame(
            [
                ['query' => 'CREATE TABLE ' . self::TABLE . '_ddl (id UInt8) ENGINE = Memory', 'bindings' => []],
                ['query' => 'DROP TABLE ' . self::TABLE . '_ddl', 'bindings' => []],
            ],
            array_map(fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']], $connection->getQueryLog())
        );
    }

    public function test_a_pretended_unprepared_statement_is_not_sent(): void
    {
        $connection = DB::connection('clickhouse');

        $log = $connection->pretend(fn (Connection $connection) => $this->assertTrue($connection->unprepared('TRUNCATE TABLE ' . self::TABLE)));

        $this->assertSame(['TRUNCATE TABLE ' . self::TABLE], array_column($log, 'query'));
        $this->assertSame(2500, $this->countRows());
    }

    public function test_cursor_yields_the_rows_of_select_with_bindings(): void
    {
        $connection = DB::connection('clickhouse');

        $cursor = $connection->cursor('SELECT id, v FROM ' . self::TABLE . ' WHERE id >= ? AND v != ? ORDER BY id', [10, '22']);

        $this->assertInstanceOf(Generator::class, $cursor);
        $rows = iterator_to_array($cursor);
        $this->assertCount(2489, $rows);
        $this->assertSame(['id' => 10, 'v' => '20'], $rows[0]);
        $this->assertSame(['id' => 12, 'v' => '24'], $rows[1]);
        $this->assertSame(
            $connection->select('SELECT id, v FROM ' . self::TABLE . ' WHERE id >= ? AND v != ? ORDER BY id', [10, '22']),
            $rows
        );
    }

    public function test_cursor_and_lazy_of_laravels_query_builder_give_the_rows_of_get(): void
    {
        $connection = $this->laravelBuilderConnection();
        $query = fn () => $connection->table(self::TABLE)->where('id', '<', 1200)->orderBy('id');

        $rows = $query()->get()->all();

        $this->assertCount(1200, $rows);
        $this->assertSame($rows, $query()->cursor()->all());
        $this->assertSame($rows, $query()->lazy(500)->all());
        $this->assertSame(
            array_column($rows, 'id'),
            $connection->table(self::TABLE)->where('id', '<', 1200)->lazyById(500)->pluck('id')->all()
        );
    }

    private function laravelBuilderConnection(): Connection
    {
        config(['database.connections.' . self::LARAVEL_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false]
        )]);
        DB::purge(self::LARAVEL_CONNECTION);

        return DB::connection(self::LARAVEL_CONNECTION);
    }

    private function countRows(): int
    {
        return (int) DB::connection('clickhouse')->getClient()
            ->select('SELECT count() AS c FROM ' . self::TABLE)
            ->fetchOne('c');
    }
}
