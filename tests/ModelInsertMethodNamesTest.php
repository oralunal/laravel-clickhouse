<?php

namespace Tests;

use Carbon\Carbon;
use ClickHouseDB\Client;
use Illuminate\Support\Facades\DB;
use LogicException;
use Oralunal\LaravelClickHouse\BaseModel;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Models\ExampleWithOwnInsertMethods;

/**
 * BaseModel's insert helpers, insertRows() and insertAssocRows(), and the helpers of
 * its traits that the inserts and date casts use, are private, so a model may
 * declare methods of the same names with other signatures.
 */
class ModelInsertMethodNamesTest extends TestCase
{
    private const TABLE = 'own_insert_method_rows';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write('CREATE TABLE ' . self::TABLE . ' (id UInt32, v String) ENGINE = MergeTree ORDER BY id');
    }

    protected function tearDown(): void
    {
        ExampleWithOwnInsertMethods::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        parent::tearDown();
    }

    /**
     * The model is loaded by this test only, in a process of its own: if the helpers
     * were protected or public, PHP would end that process with a fatal error for the
     * incompatible declarations, which fails this test and not the whole run. Every
     * insert path reaches the table through BaseModel's helpers, not the model's
     * methods, which throw.
     */
    #[RunInSeparateProcess]
    public function testAModelWithItsOwnInsertRowsAndInsertAssocRowsInsertsThroughBaseModel(): void
    {
        ExampleWithOwnInsertMethods::insertAssoc([['id' => 1, 'v' => 'a']]);
        ExampleWithOwnInsertMethods::insertBulk([[2, 'b']], ['id', 'v']);
        ExampleWithOwnInsertMethods::prepareAndInsertAssoc([['id' => 3, 'v' => 'c']]);
        ExampleWithOwnInsertMethods::prepareAndInsertBulk([[4, 'd']], ['id', 'v']);
        ExampleWithOwnInsertMethods::prepareAndInsert([[5, 'e']], ['id', 'v']);
        ExampleWithOwnInsertMethods::create(['id' => 6, 'v' => 'f']);
        ExampleWithOwnInsertMethods::buffer([['id' => 7, 'v' => 'g'], ['id' => 8, 'v' => 'h']]);
        ExampleWithOwnInsertMethods::flushBuffer();

        $this->assertSame(
            [[1, 'a'], [2, 'b'], [3, 'c'], [4, 'd'], [5, 'e'], [6, 'f'], [7, 'g'], [8, 'h']],
            array_map(
                fn (array $row): array => [(int) $row['id'], $row['v']],
                $this->client()->select('SELECT id, v FROM ' . self::TABLE . ' ORDER BY id')->rows()
            )
        );
    }

    /**
     * The model is declared by this test only, in a process of its own, as above. Its methods are named like the
     * private helpers of HasBufferedInserts and HasAttributes, with other signatures, and throw if called.
     */
    #[RunInSeparateProcess]
    public function testAModelWithMethodsNamedLikeTheTraitHelpersInsertsThroughBaseModel(): void
    {
        $model = get_class(new class extends BaseModel {
            protected $table = 'own_insert_method_rows';

            protected $casts = ['v' => 'datetime'];

            public static function insertKeyedRows(string $csv): never
            {
                throw new LogicException("BaseModel called the model's own insertKeyedRows()");
            }

            public static function insertPositionalRows(string $csv): never
            {
                throw new LogicException("BaseModel called the model's own insertPositionalRows()");
            }

            public function insertValuesRows(): never
            {
                throw new LogicException("BaseModel called the model's own insertValuesRows()");
            }

            public static function compileValuesInsert(int $rows): never
            {
                throw new LogicException("BaseModel called the model's own compileValuesInsert()");
            }

            public function madeConnectionPretends(string $name): never
            {
                throw new LogicException("BaseModel called the model's own madeConnectionPretends()");
            }

            public function verifyRowsToBuffer(): never
            {
                throw new LogicException("BaseModel called the model's own verifyRowsToBuffer()");
            }

            public static function verifyRowsHaveValues(string $rows): never
            {
                throw new LogicException("BaseModel called the model's own verifyRowsHaveValues()");
            }

            public function prepareValuesForValuesInsert(int $values): never
            {
                throw new LogicException("BaseModel called the model's own prepareValuesForValuesInsert()");
            }

            public static function prepareObjectForValuesInsert(): never
            {
                throw new LogicException("BaseModel called the model's own prepareObjectForValuesInsert()");
            }

            public static function formatDateForStorage(): never
            {
                throw new LogicException("BaseModel called the model's own formatDateForStorage()");
            }

            public function getConnectionDateTimePrecision(int $precision): never
            {
                throw new LogicException("BaseModel called the model's own getConnectionDateTimePrecision()");
            }
        });

        $model::insertAssoc([['id' => 1, 'v' => Carbon::parse('2024-01-02 03:04:05', 'UTC')]]);
        $model::insertBulk([[2, 'b']], ['id', 'v']);
        $model::create(['id' => 3, 'v' => Carbon::parse('2024-01-02 03:04:06', 'UTC')]);
        $model::buffer([['id' => 4, 'v' => 'd'], ['id' => 5, 'v' => 'e']]);
        $model::flushBuffer();
        $model::optimize(true);

        $this->assertSame(
            [[1, '2024-01-02 03:04:05'], [2, 'b'], [3, '2024-01-02 03:04:06'], [4, 'd'], [5, 'e']],
            array_map(
                fn (array $row): array => [(int) $row['id'], $row['v']],
                $this->client()->select('SELECT id, v FROM ' . self::TABLE . ' ORDER BY id')->rows()
            )
        );

        $model::truncate();
        $this->assertSame([], $this->client()->select('SELECT id FROM ' . self::TABLE)->rows());
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }
}
