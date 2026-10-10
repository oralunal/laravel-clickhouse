<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\Example3;

/**
 * A model with a boolean cast on a column whose default is 1, so a value that the insert makes up shows as 0.
 */
class CastDefaultsRow extends BaseModel
{
    protected $table = 'cast_defaults';

    protected $casts = ['f_bool' => 'boolean'];
}

/**
 * CastDefaultsRow with JSONEachRow inserts.
 */
class CastDefaultsJsonRow extends CastDefaultsRow
{
    protected $insertFormat = 'JSONEachRow';
}

class CastsTest extends TestCase
{
    private const TABLE = 'cast_defaults';

    protected function setUp(): void
    {
        parent::setUp();
        Example3::truncate();
        CastDefaultsRow::clearBuffer();
        CastDefaultsJsonRow::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write(
            'CREATE TABLE ' . self::TABLE . " (f_int Int32, f_bool UInt8 DEFAULT 1, f_s String DEFAULT 'def')"
            . ' ENGINE = MergeTree ORDER BY f_int'
        );
    }

    protected function tearDown(): void
    {
        CastDefaultsRow::clearBuffer();
        CastDefaultsJsonRow::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        parent::tearDown();
    }

    public function testCasts()
    {
        Example3::insertAssoc([['f_int' => 1, 'f_bool' => false]]);
        $rows = Example3::where('f_int', 1)->getRows();
        $this->assertEquals(false, $rows[0]['f_bool']);

        Example3::truncate();
        Example3::insertBulk([[2, false]], ['f_int', 'f_bool']);
        $rows = Example3::where('f_int', 2)->getRows();
        $this->assertEquals(false, $rows[0]['f_bool']);

        Example3::truncate();
        $one = new Example3();
        $one->f_int = 3;
        $one->f_bool = false;
        $one->save();
        $rows = Example3::where('f_int', 3)->getRows();
        $this->assertEquals(false, $rows[0]['f_bool']);
    }

    /**
     * Each insert path of a model, given one keyed row.
     *
     * @return array<string, array{Closure(class-string<CastDefaultsRow>, array<string, mixed>): mixed}>
     */
    public static function insertPaths(): array
    {
        return [
            'insertAssoc()' => [fn (string $model, array $row) => $model::insertAssoc([$row])],
            'buffer() and flushBuffer()' => [function (string $model, array $row) {
                $model::buffer($row);

                return $model::flushBuffer();
            }],
            'create()' => [fn (string $model, array $row) => $model::create($row)],
            'save()' => [fn (string $model, array $row) => $model::make($row)->save()],
        ];
    }

    /**
     * A cast column that the row does not have is left out of the insert, so ClickHouse gives it the column's
     * default; it used to be stored as 0, or to throw ErrorException 'Undefined array key' in a Laravel app.
     *
     * @param Closure(class-string<CastDefaultsRow>, array<string, mixed>): mixed $insert
     */
    #[DataProvider('insertPaths')]
    public function testACastColumnThatTheRowLacksGetsTheColumnDefault(Closure $insert): void
    {
        $insert(CastDefaultsRow::class, ['f_int' => 1]);
        $insert(CastDefaultsJsonRow::class, ['f_int' => 2]);
        $insert(CastDefaultsRow::class, ['f_int' => 3, 'f_bool' => false]);

        $this->assertSame([[1, 1, 'def'], [2, 1, 'def'], [3, 0, 'def']], $this->rows());
    }

    /**
     * A row of insertBulk() without a value at the position of a cast column fails in ClickHouse, and nothing is
     * inserted; it used to be filled with 0.
     */
    public function testAShortInsertBulkRowFailsAndInsertsNothing(): void
    {
        try {
            CastDefaultsRow::insertBulk([[7]], ['f_int', 'f_bool']);
            $this->fail('The insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame('SYNTAX_ERROR', $exception->getClickHouseExceptionName());
            $this->assertStringEndsWith('IN:INSERT INTO `cast_defaults` (`f_int`,`f_bool`)  VALUES  (7)', $exception->getMessage());
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * insertAssoc() refuses a later row that lacks a key of the first row, and inserts nothing; it used to give
     * the key its position in the first row as its value, so f_s was stored as '1' and f_bool as 0.
     */
    public function testARowThatLacksAKeyOfTheFirstRowIsRefused(): void
    {
        foreach ([[['f_int' => 30, 'f_s' => 'a'], ['f_int' => 31]], [['f_bool' => true, 'f_int' => 20], ['f_int' => 21]]] as $rows) {
            try {
                CastDefaultsRow::insertAssoc($rows);
                $this->fail('The insert did not throw.');
            } catch (ClientQueryException $exception) {
                $this->assertStringStartsWith('Fields not match: f_int and ', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * Rows with the same keys in another order are reordered and inserted, with their casts.
     */
    public function testRowsWithTheSameKeysInAnotherOrderAreInserted(): void
    {
        CastDefaultsRow::insertAssoc([['f_int' => 34, 'f_s' => 'a', 'f_bool' => false], ['f_bool' => true, 'f_s' => 'b', 'f_int' => 35]]);
        CastDefaultsJsonRow::insertAssoc([['f_int' => 36, 'f_s' => 'c'], ['f_s' => 'd', 'f_int' => 37]]);

        $this->assertSame([[34, 0, 'a'], [35, 1, 'b'], [36, 1, 'c'], [37, 1, 'd']], $this->rows());
    }

    /**
     * An explicit null is still cast: a boolean cast writes 0.
     */
    public function testAnExplicitNullIsStillCast(): void
    {
        CastDefaultsRow::insertAssoc([['f_int' => 1, 'f_bool' => null]]);

        $this->assertSame([[1, 0, 'def']], $this->rows());
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function rows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['f_int'], (int) $row['f_bool'], $row['f_s']],
            $this->client()->select('SELECT f_int, f_bool, f_s FROM ' . self::TABLE . ' ORDER BY f_int')->rows()
        );
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }
}
