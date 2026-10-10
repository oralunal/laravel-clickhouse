<?php

namespace Tests;

use Carbon\Carbon;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\ExampleWithDateCast;

/**
 * A model of date_cast_examples without casts, so its dates reach the insert as objects.
 */
class DateTimePrecisionRow extends BaseModel
{
    protected $table = 'date_cast_examples';
}

/**
 * DateTimePrecisionRow with JSONEachRow inserts.
 */
class DateTimePrecisionJsonRow extends DateTimePrecisionRow
{
    protected $insertFormat = 'JSONEachRow';
}

/**
 * ExampleWithDateCast with a $dateFormat that keeps microseconds whatever the connection's precision.
 */
class ExampleWithDateCastAndDateFormat extends ExampleWithDateCast
{
    protected $dateFormat = 'Y-m-d H:i:s.u';
}

/**
 * The datetime_precision option of the connection on the model's inserts, date casts and queries: at 'microsecond'
 * a date keeps its sub-second part, and at 'second', the default, it is written with seconds, as before.
 */
class DateTimePrecisionTest extends TestCase
{
    private const TABLE = 'date_cast_examples';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, dt DateTime DEFAULT 0, d3 DateTime64(3) DEFAULT 0,'
            . ' d6 DateTime64(6) DEFAULT 0, a6 Array(DateTime64(6)) DEFAULT [], d Date DEFAULT 0)'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        DateTimePrecisionRow::clearBuffer();
        DateTimePrecisionJsonRow::clearBuffer();
    }

    protected function tearDown(): void
    {
        DateTimePrecisionRow::clearBuffer();
        DateTimePrecisionJsonRow::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    /**
     * Each insert path of a model and of the package builder, given one keyed row.
     *
     * @return array<string, array{Closure(array<string, mixed>): mixed}>
     */
    public static function insertPaths(): array
    {
        return [
            'insertAssoc()' => [fn (array $row) => DateTimePrecisionRow::insertAssoc([$row])],
            'insertBulk()' => [fn (array $row) => DateTimePrecisionRow::insertBulk([array_values($row)], array_keys($row))],
            'create()' => [fn (array $row) => DateTimePrecisionRow::create($row)],
            'save()' => [fn (array $row) => DateTimePrecisionRow::make($row)->save()],
            'buffer() and flushBuffer()' => [function (array $row) {
                DateTimePrecisionRow::buffer($row);

                return DateTimePrecisionRow::flushBuffer();
            }],
            'JSONEachRow insertAssoc()' => [fn (array $row) => DateTimePrecisionJsonRow::insertAssoc([$row])],
            'JSONEachRow insertBulk()' => [fn (array $row) => DateTimePrecisionJsonRow::insertBulk([array_values($row)], array_keys($row))],
            'JSONEachRow buffer() and flushBuffer()' => [function (array $row) {
                DateTimePrecisionJsonRow::buffer($row);

                return DateTimePrecisionJsonRow::flushBuffer();
            }],
            'the package builder\'s insert()' => [fn (array $row) => DB::connection('clickhouse')->table(self::TABLE)->insert([$row])],
            'the package builder\'s JSONEachRow insert()' => [
                fn (array $row) => DB::connection('clickhouse')->table(self::TABLE)->insert($row, 'JSONEachRow'),
            ],
        ];
    }

    /**
     * At microsecond precision every insert path keeps the sub-second part, also inside an array; DateTime64(3)
     * keeps what its precision holds, and a whole second is written without a fraction, which a DateTime column
     * takes.
     *
     * @param Closure(array<string, mixed>): mixed $insert
     */
    #[DataProvider('insertPaths')]
    public function testEveryInsertKeepsTheFractionAtMicrosecondPrecision(Closure $insert): void
    {
        $this->usePrecision('microsecond');

        $insert($this->row());

        $this->assertSame(
            [[1, '2024-01-02 03:04:05', '2024-01-02 03:04:05.123', '2024-01-02 03:04:05.123456', "['2024-01-02 03:04:05.500000','2024-01-02 03:04:06.000000']"]],
            $this->rows()
        );
    }

    /**
     * @param Closure(array<string, mixed>): mixed $insert
     */
    #[DataProvider('insertPaths')]
    public function testEveryInsertWritesSecondsByDefault(Closure $insert): void
    {
        $insert($this->row());

        $this->assertSame(
            [[1, '2024-01-02 03:04:05', '2024-01-02 03:04:05.000', '2024-01-02 03:04:05.000000', "['2024-01-02 03:04:05.000000','2024-01-02 03:04:06.000000']"]],
            $this->rows()
        );
    }

    /**
     * A model query follows the connection, so a condition on a date with a sub-second part finds the row that the
     * same date inserted, on DateTime64(6) and on DateTime64(3), which cuts the literal to its precision.
     */
    public function testModelConditionsFindTheRowsTheSameDateInserted(): void
    {
        $this->usePrecision('microsecond');
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');
        DateTimePrecisionRow::insertAssoc([
            ['id' => 1, 'd3' => $date, 'd6' => $date],
            ['id' => 2, 'd3' => $date->copy()->addMicroseconds(1000), 'd6' => $date->copy()->addMicrosecond()],
        ]);

        $this->assertSame(
            "SELECT * FROM `date_cast_examples` WHERE `d6` = '2024-01-02 03:04:05.123456'",
            DateTimePrecisionRow::where('d6', $date)->toSql()
        );
        $this->assertSame([1], $this->ids(DateTimePrecisionRow::where('d6', $date)->getRows()));
        $this->assertSame([1], $this->ids(DateTimePrecisionRow::where('d3', $date)->getRows()));
        $this->assertSame([2], $this->ids(DateTimePrecisionRow::query()->where('d6', '>', $date)->getRows()));
        $this->assertSame([1, 2], $this->ids(DateTimePrecisionRow::select()->whereIn('d6', [$date, $date->copy()->addMicrosecond()])->orderBy('id')->getRows()));
        $this->assertSame([2], $this->ids(DateTimePrecisionRow::select()->whereBetween('d6', [$date->copy()->addMicrosecond(), $date->copy()->addSecond()])->getRows()));

        DateTimePrecisionRow::where('d6', $date)->delete(true);

        $this->assertSame([2], array_column($this->rows(), 0));
    }

    /**
     * At second precision a model condition is written with seconds, as before.
     */
    public function testModelConditionsUseSecondsByDefault(): void
    {
        $this->assertSame(
            "SELECT * FROM `date_cast_examples` WHERE `d6` = '2024-01-02 03:04:05'",
            DateTimePrecisionRow::where('d6', Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'))->toSql()
        );
    }

    /**
     * A DateTime column refuses a date with a sub-second part at microsecond precision, loudly: in an insert
     * (24.8 and 25.8 checked; 26.8 cuts it) and in a condition.
     */
    public function testADateTimeColumnRefusesAFractionAtMicrosecondPrecision(): void
    {
        $this->usePrecision('microsecond');
        $date = Carbon::parse('2024-01-02 03:04:05.5', 'UTC');

        try {
            DateTimePrecisionRow::insertAssoc([['id' => 1, 'dt' => $date]]);
            $this->assertSame('2024-01-02 03:04:05', $this->rows()[0][1], 'A server that cuts the fraction stores the second.');
        } catch (DatabaseException $exception) {
            $this->assertSame('CANNOT_PARSE_TEXT', $exception->getClickHouseExceptionName());
        }

        try {
            DateTimePrecisionRow::where('dt', $date)->getRows();
            $this->fail('The condition did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame('TYPE_MISMATCH', $exception->getClickHouseExceptionName());
        }
    }

    /**
     * An update of a model query writes the sub-second part in SET and WHERE at microsecond precision. The
     * connection waits for the mutation (mutations_sync), so the test can read its result.
     */
    public function testAModelUpdateWritesTheFractionInSetAndWhere(): void
    {
        $this->app['config']->set('database.connections.clickhouse.settings', ['mutations_sync' => 2]);
        $this->usePrecision('microsecond');
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');
        DateTimePrecisionRow::insertAssoc([['id' => 1, 'd6' => $date], ['id' => 2, 'd6' => $date->copy()->addMicrosecond()]]);

        DateTimePrecisionRow::where('d6', $date)->update(['d3' => $date->copy()->addMicroseconds(654321)]);

        $this->assertSame(['2024-01-02 03:04:05.777', '1970-01-01 00:00:00.000'], array_column($this->rows(), 2));
    }

    /**
     * Without $dateFormat, a date and time cast follows the connection's precision, also on create() and fill();
     * the attribute reads back as a Carbon.
     */
    public function testDateCastsFollowTheConnectionsPrecision(): void
    {
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');

        ExampleWithDateCast::create(['id' => 1, 'd6' => $date, 'dt' => $date]);
        $this->usePrecision('microsecond');
        $model = ExampleWithDateCast::create(['id' => 2, 'd6' => $date, 'dt' => $date->copy()->startOfSecond(), 'd' => $date]);
        $filled = (new ExampleWithDateCast())->fill(['id' => 3, 'd6' => '2024-01-02 03:04:05.654321']);
        $filled->save();

        $this->assertInstanceOf(Carbon::class, $model->d6);
        $this->assertSame('2024-01-02 03:04:05.123456', $model->d6->format('Y-m-d H:i:s.u'));
        $this->assertSame(['id' => 2, 'd6' => '2024-01-02 03:04:05.123456', 'dt' => '2024-01-02 03:04:05', 'd' => '2024-01-02 03:04:05.123456'], $model->getAttributes());
        $this->assertSame(
            [
                [1, '2024-01-02 03:04:05', '1970-01-01 00:00:00.000', '2024-01-02 03:04:05.000000', '[]'],
                [2, '2024-01-02 03:04:05', '1970-01-01 00:00:00.000', '2024-01-02 03:04:05.123456', '[]'],
                [3, '1970-01-01 00:00:00', '1970-01-01 00:00:00.000', '2024-01-02 03:04:05.654321', '[]'],
            ],
            $this->rows()
        );
        $this->assertSame('2024-01-02', $this->client()->select('SELECT toString(d) AS d FROM ' . self::TABLE . ' WHERE id = 2')->fetchOne('d'));
    }

    /**
     * $dateFormat decides whatever the precision: 'Y-m-d H:i:s.u' keeps the microseconds at second precision.
     */
    public function testADateFormatKeepsMicrosecondsAtSecondPrecision(): void
    {
        $model = ExampleWithDateCastAndDateFormat::create(['id' => 1, 'd6' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC')]);

        $this->assertSame('2024-01-02 03:04:05.123456', $model->getAttributes()['d6']);
        $this->assertSame('2024-01-02 03:04:05.123456', $this->rows()[0][3]);
        $this->assertInstanceOf(Carbon::class, $model->d6);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'id' => 1,
            'dt' => Carbon::parse('2024-01-02 03:04:05', 'UTC'),
            'd3' => Carbon::parse('2024-01-02 03:04:05.123999', 'UTC'),
            'd6' => Carbon::parse('2024-01-02 03:04:05.123456', 'Europe/Istanbul'),
            'a6' => [Carbon::parse('2024-01-02 03:04:05.5', 'UTC'), Carbon::parse('2024-01-02 03:04:06', 'UTC')],
        ];
    }

    /**
     * Set the datetime_precision of the clickhouse connection and make the connection anew.
     *
     * @param string $precision
     * @return void
     */
    private function usePrecision(string $precision): void
    {
        $this->app['config']->set('database.connections.clickhouse.datetime_precision', $precision);
        DB::purge('clickhouse');
    }

    /**
     * @return list<array{int, string, string, string, string}>
     */
    private function rows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['id'], $row['dt'], $row['d3'], $row['d6'], $row['a6']],
            $this->client()->select(
                'SELECT id, toString(dt) AS dt, toString(d3) AS d3, toString(d6) AS d6, toString(a6) AS a6 FROM '
                . self::TABLE . ' ORDER BY id SETTINGS prefer_column_name_to_alias = 1'
            )->rows()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $rows);
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }
}
