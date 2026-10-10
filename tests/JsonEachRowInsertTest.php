<?php

namespace Tests;

use Carbon\Carbon;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Models\ExampleJson;

/**
 * A model of json_examples that sends its inserts in the connection's insert format, Values by default.
 */
class JsonExampleValuesRow extends BaseModel
{
    protected $table = 'json_examples';

    protected $casts = ['f_flag' => 'boolean'];
}

/**
 * The model inserts in the JSONEachRow format: keyed rows as JSONEachRow and positional rows as
 * JSONCompactEachRow, with the INSERT head in the URL and the rows in the request body.
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

        ExampleJson::truncate();
        ExampleJson::clearBuffer();
        JsonExampleValuesRow::clearBuffer();
    }

    protected function tearDown(): void
    {
        ExampleJson::clearBuffer();
        JsonExampleValuesRow::clearBuffer();

        parent::tearDown();
    }

    /**
     * Each insert path of a model, given keyed rows.
     *
     * @return array<string, array{Closure(class-string<BaseModel>, array<int, array<string, mixed>>): mixed}>
     */
    public static function insertPaths(): array
    {
        return [
            'insertAssoc()' => [fn (string $model, array $rows) => $model::insertAssoc($rows)],
            'insertBulk() with columns' => [fn (string $model, array $rows) => $model::insertBulk(array_map('array_values', $rows), array_keys($rows[0]))],
            'prepareAndInsertBulk()' => [fn (string $model, array $rows) => $model::prepareAndInsertBulk(array_map('array_values', $rows), array_keys($rows[0]))],
            'create()' => [fn (string $model, array $rows) => array_map(fn (array $row) => $model::create($row), $rows)],
            'save()' => [fn (string $model, array $rows) => array_map(fn (array $row) => $model::make($row)->save(), $rows)],
            'buffer() and flushBuffer()' => [function (string $model, array $rows) {
                foreach ($rows as $row) {
                    $model::buffer($row);
                }

                return $model::flushBuffer();
            }],
        ];
    }

    /**
     * The same rows store the same values through JSONEachRow as through Values: floats with every digit, NaN and
     * INF, a Bool, a cast UInt8, null into Nullable, a date and time, a UInt64 beyond PHP's int, and the default
     * of a column that the rows leave out.
     *
     * @param Closure(class-string<BaseModel>, array<int, array<string, mixed>>): mixed $insert
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

        $insert(JsonExampleValuesRow::class, [$row(1), $row(2)]);
        $insert(ExampleJson::class, [$row(11), $row(12)]);

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
     * The package builder's insert() stores the same rows in both formats: as Values, as the connection's
     * insert_format gives by default, and as JSONEachRow, when the call or the connection names it. Both write
     * floats with every digit. A PHP bool goes to the Bool column only, since Values writes it as the string 'true'.
     */
    public function testTheBuilderInsertStoresTheSameRowsInBothFormats(): void
    {
        $row = fn (int $id): array => [
            'id' => $id,
            'f_array' => [1 / 3, 1.5, INF],
            'f_bool' => true,
            'f_flag' => 0,
            'f_float' => 0.1 + 0.2,
            'f_nullable' => null,
            'f_datetime' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'),
            'f_date' => '2024-01-02',
            'f_uint64' => '18446744073709551615',
        ];
        $table = fn () => DB::connection('clickhouse')->table('json_examples');

        $values = $table()->insert([$row(1), $row(2)]);
        $json = $table()->insert([$row(11), $row(12)], Format::JSON_EACH_ROW);
        $this->app['config']->set('database.connections.clickhouse.insert_format', 'JSONEachRow');
        DB::purge('clickhouse');
        $table()->insert($row(21));

        $this->assertSame(
            'INSERT INTO `json_examples` (`id`, `f_array`, `f_bool`, `f_flag`, `f_float`, `f_nullable`, `f_datetime`,'
            . ' `f_date`, `f_uint64`) FORMAT JSONEachRow',
            $json->sql()
        );
        $this->assertSame('2', $json->summary('written_rows'));
        $this->assertFalse($values->isError());
        $stored = $this->rows();
        $this->assertSame([1, 2, 11, 12, 21], array_column($stored, 'id'));
        $this->assertSame(
            array_fill(0, 5, [
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
     * The builder's JSONEachRow insert checks the keys of every row before anything is sent, as the model's does.
     */
    public function testTheBuilderRefusesJsonEachRowRowsWithOtherKeysBeforeAnythingIsSent(): void
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
     * Values that only JSONEachRow writes: an array with keys and an empty stdClass for a Map, NaN into Float64,
     * and true into a UInt8 column without a cast.
     */
    public function testMapsNanAndBoolsForUInt8(): void
    {
        ExampleJson::insertAssoc([
            ['id' => 1, 'f_map' => ['x' => 1, 'y' => 2], 'f_float' => NAN, 'f_flag' => true, 'f_string' => "it's \"quoted\" \\ \n ü"],
            ['id' => 2, 'f_map' => new stdClass(), 'f_float' => -INF, 'f_flag' => false, 'f_string' => ':0 {0} ON CLUSTER x'],
        ]);
        ExampleJson::insertBulk([[3, ['z' => 3]]], ['id', 'f_map']);

        $this->assertSame(
            [
                [1, "{'x':1,'y':2}", 'nan', 1, "it's \"quoted\" \\ \n ü"],
                [2, '{}', '-inf', 0, ':0 {0} ON CLUSTER x'],
                [3, "{'z':3}", '0', 0, 'def'],
            ],
            array_map(
                fn (array $row): array => [$row['id'], $row['f_map'], $row['f_float'], (int) $row['f_flag'], $row['f_string']],
                $this->rows()
            )
        );
    }

    /**
     * Without columns, insertBulk() sends JSONCompactEachRow without a column list, so the values go to the table's
     * columns in their order, as in a Values insert.
     */
    public function testInsertBulkWithoutColumnsFillsTheColumnsInTheirOrder(): void
    {
        ExampleJson::insertBulk([[1, ['k' => 5], [2.5], false, true, 1.25, 'n', '2024-01-02 03:04:05', '2024-01-02', 7, 's']]);

        $this->assertSame(
            [[
                'id' => 1, 'f_map' => "{'k':5}", 'f_array' => '[2.5]', 'f_bool' => 'false', 'f_flag' => '1', 'f_float' => '1.25',
                'f_nullable' => 'n', 'f_datetime' => '2024-01-02 03:04:05.000000', 'f_date' => '2024-01-02', 'f_uint64' => '7', 'f_string' => 's',
            ]],
            $this->rows()
        );
    }

    public function testTheStatementNamesTheWrittenRows(): void
    {
        $rows = array_map(fn (int $id): array => ['id' => $id, 'f_string' => "row {$id}"], range(1, 1000));

        $statement = ExampleJson::insertAssoc($rows);

        $this->assertInstanceOf(Statement::class, $statement);
        $this->assertSame('1000', $statement->summary('written_rows'));
        $this->assertSame('INSERT INTO `json_examples` (`id`, `f_string`) FORMAT JSONEachRow', $statement->sql());
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
            ExampleJson::insertAssoc($rows);
            $this->fail('The insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
            $this->assertStringEndsWith('IN:INSERT INTO `json_examples` (`id`, `f_uint64`, `f_string`) FORMAT JSONEachRow', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
            $this->assertStringNotContainsString('secret', (string) json_encode($exception->getRequestDetails()));
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * A row whose keys differ from the first row's is refused before anything is sent, and buffer() refuses a row
     * whose keys differ from those of the buffered rows before buffering it, so the buffered rows are still flushed.
     */
    public function testRowsWithOtherKeysAreRefusedBeforeAnythingIsSent(): void
    {
        try {
            ExampleJson::insertAssoc([['id' => 1, 'f_string' => 'a'], ['id' => 2]]);
            $this->fail('The insert did not throw.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string].', $exception->getMessage());
        }

        ExampleJson::buffer(['id' => 3, 'f_string' => 'a']);
        try {
            ExampleJson::buffer(['id' => 4, 'f_flag' => true]);
            $this->fail('buffer() did not throw.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                'Cannot buffer the rows of the model [' . ExampleJson::class . ']: the row at index 0 has the keys [id, f_flag],'
                . ' and the rows already buffered [id, f_string].',
                $exception->getMessage()
            );
        }

        $this->assertSame(1, ExampleJson::bufferCount());
        $this->assertSame([], $this->rows());

        ExampleJson::flushBuffer();

        $this->assertSame([[3, 'a']], array_map(fn (array $row): array => [$row['id'], $row['f_string']], $this->rows()));
    }

    /**
     * Each way of inserting no rows, or a row without values, into json_examples.
     *
     * @return array<string, array{Closure(class-string<BaseModel>): mixed}>
     */
    public static function insertsWithoutValues(): array
    {
        return [
            'insertAssoc() of no rows' => [fn (string $model) => $model::insertAssoc([])],
            'insertBulk() of no rows' => [fn (string $model) => $model::insertBulk([], ['id'])],
            'insertAssoc() of an empty row' => [fn (string $model) => $model::insertAssoc([[]])],
            'create() without attributes' => [fn (string $model) => $model::create([])],
            'insertBulk() with a later empty row' => [fn (string $model) => $model::insertBulk([[1], []], ['id'])],
            'buffer() of an empty row' => [fn (string $model) => $model::buffer([[]])],
        ];
    }

    /**
     * No rows, or a row without values, are refused in both formats before anything is sent, with the exception
     * that smi2 throws for an empty row list. Values used to send VALUES (), which ClickHouse refuses, or stores as
     * 0 for one UInt32 column; JSONEachRow used to store a row of column defaults for {}.
     *
     * @param Closure(class-string<BaseModel>): mixed $insert
     */
    #[DataProvider('insertsWithoutValues')]
    public function testNoRowsAndRowsWithoutValuesAreRefusedInBothFormats(Closure $insert): void
    {
        foreach ([JsonExampleValuesRow::class, ExampleJson::class] as $model) {
            try {
                $insert($model);
                $this->fail("{$model}: the insert did not throw.");
            } catch (ClientQueryException $exception) {
                $this->assertSame(ClientQueryException::class, $exception::class, $model);
                $this->assertSame('Inserting empty values array is not supported in ClickHouse', $exception->getMessage(), $model);
            }

            $this->assertSame(0, $model::bufferCount(), $model);
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
                ExampleJson::insertAssoc([['id' => 1, 'f_uint64' => $float]]);
                $this->fail("JSONEachRow took {$float} for a UInt64 column.");
            } catch (DatabaseException $exception) {
                $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
            }
        }

        ExampleJson::insertAssoc([['id' => 2, 'f_uint64' => 7.0]]);
        JsonExampleValuesRow::insertAssoc([['id' => 3, 'f_uint64' => 2.5], ['id' => 4, 'f_uint64' => 7.0]]);

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
            ExampleJson::insertAssoc([['id' => 1, 'f_date' => Carbon::parse('2024-01-02 03:04:05', 'UTC')]]);
            $this->fail('The insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('CANNOT_PARSE', $exception->getClickHouseExceptionName());
        }

        ExampleJson::insertAssoc([['id' => 2, 'f_date' => '2024-01-02']]);
        JsonExampleValuesRow::insertAssoc([['id' => 3, 'f_date' => Carbon::parse('2024-01-02 03:04:05', 'UTC')]]);

        $this->assertSame([[2, '2024-01-02'], [3, '2024-01-02']], array_map(fn (array $row): array => [$row['id'], $row['f_date']], $this->rows()));
    }

    /**
     * Without $insertFormat, the connection's insert_format decides, and it is Values unless it is set.
     */
    public function testTheConnectionDecidesForAModelWithoutAnInsertFormat(): void
    {
        $sent = fn (): array => array_column(DB::connection('clickhouse')->pretend(function (): void {
            JsonExampleValuesRow::insertAssoc([['id' => 1]]);
            ExampleJson::insertAssoc([['id' => 2]]);
        }), 'query');

        $this->assertSame(
            ['INSERT INTO `json_examples` (`id`)  VALUES  (1)', 'INSERT INTO `json_examples` (`id`) FORMAT JSONEachRow'],
            $sent()
        );

        $this->app['config']->set('database.connections.clickhouse.insert_format', 'JSONEachRow');
        DB::purge('clickhouse');

        $this->assertSame(
            ['INSERT INTO `json_examples` (`id`) FORMAT JSONEachRow', 'INSERT INTO `json_examples` (`id`) FORMAT JSONEachRow'],
            $sent()
        );

        JsonExampleValuesRow::insertAssoc([['id' => 5, 'f_map' => ['a' => 1]]]);
        $this->assertSame([[5, "{'a':1}"]], array_map(fn (array $row): array => [$row['id'], $row['f_map']], $this->rows()));
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
