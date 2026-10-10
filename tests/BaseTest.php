<?php

namespace Tests;

use ClickHouseDB\Query\Expression\Raw;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Tests\Models\Example;

/**
 * A model of a table with float, Decimal and Int columns, which BaseTest creates.
 */
class FloatRow extends BaseModel
{
    protected $table = 'float_rows';
}

/**
 * A model of a table with a String and an Array(UInt32) column, which BaseTest creates.
 */
class RawRow extends BaseModel
{
    protected $table = 'raw_rows';
}

/**
 * A value object whose string is its private $value.
 */
final class PrivateValue implements \Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

class BaseTest extends TestCase
{
    public function testWorkWithClient()
    {
        /** @var \ClickHouseDB\Client $db */
        $db = DB::connection('clickhouse')->getClient();
        $db->write("TRUNCATE TABLE examples");
        $db->insert('examples', [[100, 'string']], ['f_int', 'f_string']);
        $db->write("ALTER TABLE examples UPDATE f_string='updated string' WHERE f_int=100 SETTINGS mutations_sync=2");
        $rows = $db->select("SELECT * FROM examples LIMIT 1")->rows();
        $this->assertEquals(100, $rows[0]['f_int']);
        $this->assertEquals('updated string', $rows[0]['f_string']);
    }

    public function testSimpleModelInsertAndSelect()
    {
        Example::truncate();
        Example::insertAssoc([['f_int' => 1, 'f_string' => 'zz']]);
        $rows = Example::select()->getRows();
        $this->assertEquals(1, $rows[0]['f_int']);
        $this->assertEquals('zz', $rows[0]['f_string']);
    }

    public function testSimpleModelInsertAndPaginate()
    {
        Example::truncate();
        Example::insertAssoc([
            ['f_int' => 1, 'f_string' => 'zz'],
            ['f_int' => 2, 'f_string' => 'aa'],
            ['f_int' => 3, 'f_string' => 'bb'],
        ]);
        $result = Example::select()->paginate(2);
        $this->assertTrue($result instanceof LengthAwarePaginator);
        $this->assertEquals(3, $result->total());
        $this->assertCount(2, $result->items());
        $this->assertEquals(1, $result->items()[0]['f_int']);
        $this->assertEquals('zz', $result->items()[0]['f_string']);
    }

    public function testSimpleModelInsertAndSimplePaginate()
    {
        Example::truncate();
        Example::insertAssoc([
            ['f_int' => 1, 'f_string' => 'zz'],
            ['f_int' => 2, 'f_string' => 'aa'],
            ['f_int' => 3, 'f_string' => 'bb'],
        ]);
        $result = Example::select()->simplePaginate(2);
        $this->assertTrue($result instanceof Paginator);
        $this->assertCount(2, $result->items());
        $this->assertEquals(2, $result->perPage());
        $this->assertEquals(1, $result->items()[0]['f_int']);
        $this->assertEquals('zz', $result->items()[0]['f_string']);
    }

    public function testMultipleWheres()
    {
        Example::truncate();
        Example::insertAssoc([['f_int' => 1, 'f_string' => 'zz']]);
        $query = Example::select()
            ->whereIn('f_string', ['zz'])
            ->whereBetween('f_int', [1, 2]);
        $this->assertEquals("SELECT * FROM `examples` WHERE `f_string` IN ('zz') AND `f_int` BETWEEN 1 AND 2", $query->toSql());
        $rows = $query->getRows();
        $this->assertNotEmpty($rows);
    }

    protected function tearDown(): void
    {
        foreach (glob(__DIR__ . '/migrations/example_*.php') as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function testPretendMigration()
    {
        /** @var \ClickHouseDB\Client $db */
        $db = DB::connection('clickhouse')->getClient();
        $migrationsDir = __DIR__ . '/migrations';
        $migration     = file_get_contents($migrationsDir . '/2022_01_01_000000_create_examples_table.php');

        // Default mode
        $tableName = 'examples_' . rand(0, 9999999999999999);
        $migrationTmp = str_replace('examples', $tableName, $migration);
        file_put_contents($migrationsDir . '/example_' . rand(0, 9999999999999999) . '.php', $migrationTmp);
        Artisan::call('migrate');
        $exist = $db->select("EXISTS $tableName")->fetchOne('result');
        $this->assertEquals(1, $exist);

        // Pretend mode
        $tableName = 'examples_' . rand(0, 9999999999999999);
        $migrationTmp = str_replace('examples', $tableName, $migration);
        file_put_contents($migrationsDir . '/example_' . rand(0, 9999999999999999) . '.php', $migrationTmp);
        Artisan::call('migrate', ['--pretend' => true]);
        // Migration::write() logs the statement on the pretending connection, so the migrator lists it.
        $this->assertStringContainsString("CREATE TABLE IF NOT EXISTS $tableName", Artisan::output());
        $exist = $db->select("EXISTS $tableName")->fetchOne('result');
        $this->assertEquals(0, $exist);
    }

    /**
     * Model inserts write floats with every digit, NaN and INF as nan and inf, in every array too, through
     * insertAssoc(), insertBulk() and buffer() with flushBuffer(), so where() finds a float that the model inserted.
     * 3.0.0 wrote 14 significant digits, so 1/3 was stored as 0.33333333333333 and a computed 4.35 * 100
     * (434.99999999999994) as 435 in a Decimal(10, 2) column; the column now truncates it to 434.99, as a Values
     * insert of that literal does, and round() gives 435.
     */
    public function testModelInsertsWriteFloatsWithEveryDigit(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS float_rows SYNC');
        $client->write(
            'CREATE TABLE float_rows (id UInt32, f Float64, dec Decimal(10, 2), arr Array(Float64)) ENGINE = MergeTree ORDER BY id'
        );

        try {
            FloatRow::insertAssoc([
                ['id' => 1, 'f' => 1 / 3, 'dec' => 4.35 * 100, 'arr' => [0.1 + 0.2, NAN, -INF]],
                ['id' => 2, 'f' => INF, 'dec' => round(4.35 * 100, 2), 'arr' => []],
            ]);
            FloatRow::insertBulk(
                [[3, 0.1 + 0.2, '19.99', [1.5]], [4, NAN, '2.5', [INF, -INF, NAN, 2.0]]],
                ['id', 'f', 'dec', 'arr']
            );
            FloatRow::buffer([
                ['id' => 5, 'f' => -INF, 'dec' => 1e3, 'arr' => [NAN, 1 / 3]],
                ['id' => 6, 'f' => 2.0, 'dec' => 0.5, 'arr' => [1e20]],
            ]);
            FloatRow::buffer(['arr' => [-0.0, INF], 'dec' => 7, 'f' => PHP_FLOAT_MAX, 'id' => 7]);
            FloatRow::flushBuffer();

            $this->assertSame(
                [
                    ['1', '0.3333333333333333', '434.99', '[0.30000000000000004,nan,-inf]'],
                    ['2', 'inf', '435', '[]'],
                    ['3', '0.30000000000000004', '19.99', '[1.5]'],
                    ['4', 'nan', '2.5', '[inf,-inf,nan,2]'],
                    ['5', '-inf', '1000', '[nan,0.3333333333333333]'],
                    ['6', '2', '0.5', '[100000000000000000000]'],
                    ['7', '1.7976931348623157e308', '7', '[-0,inf]'],
                ],
                array_map('array_values', $client->select(
                    'SELECT toString(id) AS i, toString(f) AS sf, toString(dec) AS sd, toString(arr) AS sa FROM float_rows ORDER BY id'
                )->rows())
            );
            $this->assertCount(1, FloatRow::where('f', 1 / 3)->getRows());
            $this->assertCount(1, FloatRow::where('f', 0.1 + 0.2)->getRows());
        } finally {
            FloatRow::clearBuffer();
            $client->write('DROP TABLE IF EXISTS float_rows SYNC');
        }
    }

    /**
     * Model inserts in the Values format write raw SQL, this package's Expression or DB::raw(), as the SQL it holds,
     * as they write smi2's Raw, and a value object with a private $value as its string. 3.0.0 failed with 'Cannot
     * access protected property' for both expressions and 'Cannot access private property' for the value object.
     */
    public function testModelValuesInsertsWriteRawSqlAndValueObjects(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS raw_rows SYNC');
        $client->write('CREATE TABLE raw_rows (id UInt32, s String, a Array(UInt32)) ENGINE = MergeTree ORDER BY id');

        try {
            RawRow::insertAssoc([
                ['id' => 1, 's' => new Expression("concat('a', 'b')"), 'a' => [new Expression('1 + 1'), 3]],
                ['id' => 2, 's' => DB::raw("upper('x')"), 'a' => []],
                ['id' => 3, 's' => new Raw("lower('Y')"), 'a' => []],
                ['id' => 4, 's' => new PrivateValue("it's"), 'a' => []],
            ]);

            $this->assertSame(
                [['1', 'ab', '[2,3]'], ['2', 'X', '[]'], ['3', 'y', '[]'], ['4', "it's", '[]']],
                array_map('array_values', $client->select(
                    'SELECT toString(id) AS i, s, toString(a) AS sa FROM raw_rows ORDER BY id'
                )->rows())
            );
        } finally {
            $client->write('DROP TABLE IF EXISTS raw_rows SYNC');
        }
    }

    public function testOrWhere()
    {
        $query = Example::select()->where(function (Builder $q) {
            $q->where('f_int', 1)->orWhere('f_int', 2);
        });
        $this->assertEquals("SELECT * FROM `examples` WHERE (`f_int` = 1 OR `f_int` = 2)", $query->toSql());
    }
}