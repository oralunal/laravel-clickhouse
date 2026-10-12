<?php

namespace Tests;

use Carbon\Carbon;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Models\ExampleJson;

/**
 * Inserts in the JSONEachRow format: the INSERT head in the URL and one JSON object per row in the request body.
 */
class JsonEachRowInsertTest extends TestCase
{
    /**
     * The columns of json_examples, in their order.
     */
    private const COLUMNS = [
        'id', 'f_map', 'f_array', 'f_bool', 'f_flag', 'f_float', 'f_nullable', 'f_datetime', 'f_date', 'f_uint64', 'f_string',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        ExampleJson::query()->truncate();
        ExampleJson::clearBuffer();
    }

    protected function tearDown(): void
    {
        ExampleJson::clearBuffer();

        parent::tearDown();
    }

    /**
     * Each insert path in each format, given keyed rows.
     *
     * @return array<string, array{Closure(array<int, array<string, mixed>>, string): mixed}>
     */
    public static function insertPaths(): array
    {
        return [
            'query builder insert()' => [fn (array $rows, string $format) => DB::connection('clickhouse')->table('json_examples')->insert($rows, $format)],
            'model insert()' => [fn (array $rows, string $format) => ExampleJson::query()->insert($rows, $format)],
            'buffer() and flushBuffer() with the connection\'s insert_format' => [function (array $rows, string $format): bool {
                config(['database.connections.clickhouse.insert_format' => $format]);
                DB::purge('clickhouse');
                ExampleJson::buffer($rows);

                return ExampleJson::flushBuffer();
            }],
        ];
    }

    /**
     * The same rows store the same values through JSONEachRow as through Values: floats with every digit, NaN and
     * INF, a Bool, a bool into a UInt8, null into Nullable, a date and time, a UInt64 beyond PHP's int, and the
     * default of a column that the rows leave out.
     *
     * @param Closure(array<int, array<string, mixed>>, string): mixed $insert
     */
    #[DataProvider('insertPaths')]
    public function testJsonEachRowStoresWhatValuesStores(Closure $insert): void
    {
        $row = fn (int $id): array => [
            'id' => $id,
            'f_array' => [1 / 3, 1.5, INF],
            'f_bool' => true,
            'f_flag' => false,
            'f_float' => 0.1 + 0.2,
            'f_nullable' => null,
            'f_datetime' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'),
            'f_date' => '2024-01-02',
            'f_uint64' => '18446744073709551615',
        ];

        $insert([$row(1), $row(2)], 'Values');
        $insert([$row(11), $row(12)], 'JSONEachRow');

        $stored = $this->rows();
        $this->assertSame([1, 2, 11, 12], array_column($stored, 'id'));
        $this->assertSame(
            array_fill(0, 4, [
                'f_map' => '{}',
                'f_array' => '[0.3333333333333333,1.5,inf]',
                'f_bool' => 'true',
                'f_flag' => '0',
                'f_float' => '0.30000000000000004',
                'f_nullable' => null,
                'f_datetime' => '2024-01-02 03:04:05.000000',
                'f_date' => '2024-01-02',
                'f_uint64' => '18446744073709551615',
                'f_string' => 'def',
            ]),
            array_map(fn (array $row): array => array_diff_key($row, ['id' => true]), $stored)
        );
    }

    /**
     * The format is the one that the call names, as a case or in any letter case, or else the connection's
     * insert_format, Values when it is left out.
     */
    public function testTheCallOrTheConnectionDecidesTheFormat(): void
    {
        $sent = fn (Closure $insert): array => array_column(DB::connection('clickhouse')->pretend($insert), 'query');
        $table = fn (): QueryBuilder => DB::connection('clickhouse')->table('json_examples');

        $this->assertSame(['insert into "json_examples" ("id") values (1)'], $sent(fn () => ExampleJson::insert([['id' => 1]])));
        $this->assertSame(['insert into "json_examples" ("id") format JSONEachRow'], $sent(fn () => $table()->insert(['id' => 1], Format::JsonEachRow)));
        $this->assertSame(['insert into "json_examples" ("id") format JSONEachRow'], $sent(fn () => $table()->insert(['id' => 1], 'jsoneachrow')));

        $this->app['config']->set('database.connections.clickhouse.insert_format', 'JSONEachRow');
        DB::purge('clickhouse');

        $this->assertSame(['insert into "json_examples" ("id") format JSONEachRow'], $sent(fn () => ExampleJson::insert([['id' => 1]])));
        $this->assertSame(['insert into "json_examples" ("id") values (1)'], $sent(fn () => $table()->insert(['id' => 1], 'Values')));

        ExampleJson::insert([['id' => 5, 'f_map' => ['a' => 1]]]);
        $this->assertSame([[5, "{'a':1}"]], array_map(fn (array $row): array => [$row['id'], $row['f_map']], $this->rows()));
    }

    /**
     * Values that only JSONEachRow writes: an array with keys and an empty stdClass for a Map, NaN into Float64,
     * and true into a UInt8 column.
     */
    public function testMapsNanAndBoolsForUInt8(): void
    {
        DB::connection('clickhouse')->table('json_examples')->insert([
            ['id' => 1, 'f_map' => ['x' => 1, 'y' => 2], 'f_float' => NAN, 'f_flag' => true, 'f_string' => "it's \"quoted\" \\ \n ü"],
            ['id' => 2, 'f_map' => new stdClass(), 'f_float' => -INF, 'f_flag' => false, 'f_string' => ':0 {0} ON CLUSTER x'],
        ], 'JSONEachRow');

        $this->assertSame(
            [
                [1, "{'x':1,'y':2}", 'nan', 1, "it's \"quoted\" \\ \n ü"],
                [2, '{}', '-inf', 0, ':0 {0} ON CLUSTER x'],
            ],
            array_map(
                fn (array $row): array => [$row['id'], $row['f_map'], $row['f_float'], (int) $row['f_flag'], $row['f_string']],
                $this->rows()
            )
        );
    }

    public function testManyRowsAreInsertedAndLoggedOnce(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();
        $rows = array_map(fn (int $id): array => ['id' => $id, 'f_string' => "row {$id}"], range(1, 1000));

        $this->assertTrue(ExampleJson::query()->insert($rows, 'JSONEachRow'));

        $this->assertSame(['insert into "json_examples" ("id", "f_string") format JSONEachRow'], array_column($connection->getQueryLog(), 'query'));
        $this->assertCount(1000, $this->rows());
    }

    /**
     * A value that ClickHouse cannot parse fails the whole insert. The message ends with the INSERT head, not the
     * rows, and the request details leave the rows out; ClickHouse itself quotes only the text around the value.
     */
    public function testAParseErrorNamesTheHeadAndLeavesTheRowsOut(): void
    {
        $rows = [['id' => 1, 'f_uint64' => 'not-a-number', 'f_string' => 'a'], ['id' => 2, 'f_uint64' => 5, 'f_string' => str_repeat('x', 300) . 'secret']];

        try {
            ExampleJson::query()->insert($rows, 'JSONEachRow');
            $this->fail('The insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
            $this->assertStringEndsWith('IN:insert into "json_examples" ("id", "f_uint64", "f_string") format JSONEachRow', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('secret', (string) json_encode($exception->getRequestDetails()));
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * A row whose keys differ from the first row's is refused before anything is sent.
     */
    public function testRowsWithOtherKeysAreRefusedBeforeAnythingIsSent(): void
    {
        try {
            DB::connection('clickhouse')->table('json_examples')->insert([['id' => 1, 'f_string' => 'a'], ['id' => 2]], 'jsoneachrow');
            $this->fail('The insert did not throw.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string].', $exception->getMessage());
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * No rows are no insert, as in Laravel, and a row without values is refused before anything is sent, in both
     * formats.
     */
    public function testNoRowsSendNothingAndAnEmptyRowIsRefused(): void
    {
        foreach (['Values', 'JSONEachRow'] as $format) {
            $log = DB::connection('clickhouse')->pretend(function () use ($format): void {
                $this->assertTrue(ExampleJson::query()->insert([], $format));
            });
            $this->assertSame([], $log, $format);

            foreach ([[[]], [['id' => 1], []]] as $rows) {
                try {
                    ExampleJson::query()->insert($rows, $format);
                    $this->fail("{$format}: the insert did not throw.");
                } catch (ClientQueryException $exception) {
                    $this->assertSame(ClientQueryException::class, $exception::class, $format);
                    $this->assertSame('Inserting empty values array is not supported in ClickHouse', $exception->getMessage(), $format);
                }
            }
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * JSONEachRow refuses a float with a fraction or an exponent for an integer column, which the Values format
     * truncates; a whole float works in both.
     */
    public function testAnIntegerColumnRefusesAFloatWithAFractionInJsonEachRow(): void
    {
        foreach ([2.5, 1e20] as $float) {
            try {
                ExampleJson::query()->insert([['id' => 1, 'f_uint64' => $float]], 'JSONEachRow');
                $this->fail("JSONEachRow took {$float} for a UInt64 column.");
            } catch (DatabaseException $exception) {
                $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
            }
        }

        ExampleJson::query()->insert([['id' => 2, 'f_uint64' => 7.0]], 'JSONEachRow');
        ExampleJson::query()->insert([['id' => 3, 'f_uint64' => 2.5], ['id' => 4, 'f_uint64' => 7.0]], 'Values');

        $this->assertSame(
            [[2, '7'], [3, '2'], [4, '7']],
            array_map(fn (array $row): array => [$row['id'], $row['f_uint64']], $this->rows())
        );
    }

    /**
     * JSONEachRow refuses a date and time for a Date column, which the Values format cuts to its date; a 'Y-m-d'
     * string works in both.
     */
    public function testADateColumnTakesADateStringButNoDateAndTime(): void
    {
        try {
            ExampleJson::query()->insert([['id' => 1, 'f_date' => Carbon::parse('2024-01-02 03:04:05', 'UTC')]], 'JSONEachRow');
            $this->fail('The insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
        }

        ExampleJson::query()->insert([['id' => 2, 'f_date' => '2024-01-02']], 'JSONEachRow');
        ExampleJson::query()->insert([['id' => 3, 'f_date' => Carbon::parse('2024-01-02 03:04:05', 'UTC')]], 'Values');

        $this->assertSame([[2, '2024-01-02'], [3, '2024-01-02']], array_map(fn (array $row): array => [$row['id'], $row['f_date']], $this->rows()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $rows = $this->client()->select(
            'SELECT id, toString(f_map) AS f_map, toString(f_array) AS f_array, toString(f_bool) AS f_bool,'
            . ' toString(f_flag) AS f_flag, toString(f_float) AS f_float, f_nullable, toString(f_datetime) AS f_datetime,'
            . ' toString(f_date) AS f_date, toString(f_uint64) AS f_uint64, f_string FROM json_examples ORDER BY id'
            . ' SETTINGS prefer_column_name_to_alias = 1'
        )->rows();

        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];

            return array_merge(array_flip(self::COLUMNS), $row);
        }, $rows);
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }
}
