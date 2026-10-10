<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * An Eloquent model of the cursor test's table, on the connection with Laravel's query builder.
 */
class ConnectionCursorRow extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $connection = 'clickhouse-cursor-laravel';

    protected $table = 'cursor_rows';

    protected $guarded = [];
}

/**
 * unprepared() and cursor() without PDO: unprepared() sends SQL as it is, and cursor() streams the rows of the query
 * through a temporary stream and yields the rows that select() returns, so Laravel's cursor() and lazy(), of the
 * query builder and of Eloquent, give the rows that get() gives.
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

    public function test_eloquent_cursor_and_lazy_give_the_models_of_get(): void
    {
        $this->laravelBuilderConnection();
        $query = fn () => ConnectionCursorRow::query()->where('id', '<', 700)->orderBy('id');

        $rows = $query()->get()->map(fn (ConnectionCursorRow $row): array => $row->getAttributes())->all();

        $this->assertCount(700, $rows);
        $this->assertSame(['id' => 699, 'v' => '1398'], $rows[699]);
        $this->assertSame($rows, $query()->cursor()->map(fn (ConnectionCursorRow $row): array => $row->getAttributes())->all());
        $this->assertSame($rows, $query()->lazy(250)->map(fn (ConnectionCursorRow $row): array => $row->getAttributes())->all());
        $this->assertTrue($query()->cursor()->first()->exists);
    }

    /**
     * The query runs once, when the first row is asked for, and is logged once with its bindings as given.
     */
    public function test_cursor_runs_and_logs_the_query_once(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $cursor = $connection->cursor('SELECT id FROM ' . self::TABLE . ' WHERE id < ? ORDER BY id', [3]);
        $this->assertSame([], $connection->getQueryLog());

        $this->assertSame([['id' => 0], ['id' => 1], ['id' => 2]], iterator_to_array($cursor));
        $this->assertSame(
            [['query' => 'SELECT id FROM ' . self::TABLE . ' WHERE id < ? ORDER BY id', 'bindings' => [3]]],
            array_map(fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']], $connection->getQueryLog())
        );
    }

    /**
     * A string that is not valid UTF-8, such as a binary MD5 hash, is yielded as select() and get() return it, with
     * U+FFFD in place of its invalid bytes. cursor() used to throw a JsonException before the first such row.
     */
    public function test_cursor_gives_strings_that_are_not_valid_utf8_as_select_gives_them(): void
    {
        $connection = DB::connection('clickhouse');
        $query = "SELECT id, MD5(v) AS h, unhex('C328') AS bad FROM " . self::TABLE . ' WHERE id < ? ORDER BY id';

        $rows = iterator_to_array($connection->cursor($query, [300]));

        $this->assertCount(300, $rows);
        $this->assertSame("\u{FFFD}(", $rows[0]['bad']);
        $this->assertSame($connection->select($query, [300]), $rows);

        $laravelConnection = $this->laravelBuilderConnection();
        $builder = fn () => $laravelConnection->table(self::TABLE)->selectRaw('id, MD5(v) AS h')->where('id', '<', 300)->orderBy('id');
        $this->assertSame($builder()->get()->all(), $builder()->cursor()->all());
    }

    /**
     * Memory stays flat however many rows the result has: the result is downloaded into a temporary stream, which
     * moves to a temporary file above 2 MB, and one line of it is decoded at a time. The result here is about
     * 25 MB of JSON, so a cursor() that held it, or its rows, in memory would use more than the 16 MB allowed.
     */
    public function test_cursor_keeps_its_memory_flat(): void
    {
        $connection = DB::connection('clickhouse');
        $count = 0;

        gc_collect_cycles();
        memory_reset_peak_usage();
        $base = memory_get_usage();
        foreach ($connection->cursor("SELECT number AS id, repeat('x', 100) AS v FROM numbers(200000)") as $row) {
            $count++;
        }
        $used = memory_get_peak_usage() - $base;

        $this->assertSame(200000, $count);
        $this->assertLessThan(16 * 1024 * 1024, $used, sprintf('cursor() used %.2f MB.', $used / 1024 / 1024));
    }

    /**
     * The temporary stream is closed when the cursor is abandoned before its last row: after a break, after
     * LazyCollection's first(), and after an exception in the code that reads the rows.
     */
    public function test_an_abandoned_cursor_closes_its_temporary_stream(): void
    {
        $connection = DB::connection('clickhouse');
        $openStreams = fn (): int => count(array_filter(
            get_resources('stream'),
            fn (mixed $stream): bool => (stream_get_meta_data($stream)['uri'] ?? null) === 'php://temp'
        ));
        $before = $openStreams();

        $cursor = $connection->cursor('SELECT id FROM ' . self::TABLE . ' ORDER BY id');
        foreach ($cursor as $row) {
            break;
        }
        $this->assertSame($before + 1, $openStreams(), 'the stream is open while the cursor can still be read');
        unset($cursor);
        $this->assertSame($before, $openStreams());

        $first = $this->laravelBuilderConnection()->table(self::TABLE)->orderBy('id')->cursor()->first();
        $this->assertSame(['id' => 0, 'v' => '0'], $first);
        $this->assertSame($before, $openStreams());

        try {
            foreach ($connection->cursor('SELECT id FROM ' . self::TABLE . ' ORDER BY id') as $row) {
                throw new RuntimeException('The reader stopped.');
            }
        } catch (RuntimeException $exception) {
            $this->assertSame('The reader stopped.', $exception->getMessage());
        }
        $this->assertSame($before, $openStreams());
    }

    /**
     * A query that ClickHouse refuses, or that fails after its first rows, throws the ClickHouse error with its
     * code and message before any row is yielded, and is not logged. The last query fails after 4 MB of rows,
     * which ClickHouse 24.8 sends with HTTP 200, writing the error as the last line of the result.
     *
     * @return array<string, array{string, int, string}>
     */
    public static function failingQueryProvider(): array
    {
        return [
            'an unknown column' => ['SELECT nosuch FROM ' . self::TABLE, 47, 'nosuch'],
            'an error after a few rows' => [
                'SELECT number, throwIf(number = 5) AS t FROM numbers(10) SETTINGS max_block_size = 1',
                395,
                'throwIf',
            ],
            'an error after 4 MB of rows' => [
                'SELECT number, randomPrintableASCII(200) AS s, throwIf(number = 20000) AS t FROM numbers(21000)'
                    . ' SETTINGS max_block_size = 1000',
                395,
                'throwIf',
            ],
        ];
    }

    #[DataProvider('failingQueryProvider')]
    public function test_an_error_is_thrown_with_the_message_of_clickhouse(string $query, int $code, string $named): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();
        $yielded = 0;

        try {
            foreach ($connection->cursor($query) as $row) {
                $yielded++;
            }
            $this->fail('The query did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame($code, $exception->getCode());
            $this->assertStringContainsString($named, $exception->getMessage());
        }

        $this->assertSame(0, $yielded);
        $this->assertSame([], $connection->getQueryLog());
    }

    public function test_a_query_with_a_format_clause_is_refused_before_it_is_sent(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot read the rows of a query whose FORMAT clause names CSV with cursor(): cursor() asks for the result'
            . ' in JSONEachRow itself and yields its rows one by one. Leave the FORMAT clause out.'
        );

        iterator_to_array(DB::connection('clickhouse')->cursor('SELECT id FROM no_such_table FORMAT CSV'));
    }

    /**
     * While the connection pretends, the query is logged and not sent: the table does not exist, so a query that
     * was sent would throw.
     */
    public function test_a_pretended_cursor_yields_nothing_and_sends_nothing(): void
    {
        $rows = null;

        $log = DB::connection('clickhouse')->pretend(function (Connection $connection) use (&$rows): void {
            $rows = iterator_to_array($connection->cursor('SELECT id FROM no_such_table WHERE id = ?', [1]));
        });

        $this->assertSame([], $rows);
        $this->assertCount(1, $log);
        $this->assertStringStartsWith('SELECT id FROM no_such_table WHERE id = ', $log[0]['query']);
    }

    /**
     * Inside session(), cursor() reads the session's temporary table.
     */
    public function test_cursor_reads_a_temporary_table_inside_a_session(): void
    {
        $rows = DB::connection('clickhouse')->session(function (Connection $connection): array {
            $connection->statement('CREATE TEMPORARY TABLE cursor_temporary_rows (id UInt32)');
            $connection->statement('INSERT INTO cursor_temporary_rows VALUES (1), (2)');

            return iterator_to_array($connection->cursor('SELECT id FROM cursor_temporary_rows ORDER BY id'));
        });

        $this->assertSame([['id' => 1], ['id' => 2]], $rows);
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
