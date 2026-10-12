<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\QueryBuilder;
use stdClass;

/**
 * Each row that a ClickHouse connection returns is a stdClass object, as on Laravel's other connections, through every
 * method that reads rows: the connection's select(), selectOne() and cursor(), the reads of the query builder and of
 * the base query of a model, and the parallel queries. A value of a column, such as an Array or a Map, stays a PHP
 * array. RowTypesTest checks that PHPStan reads the same types.
 */
class RowObjectsTest extends TestCase
{
    public const TABLE = 'row_objects';

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, name String, tags Array(String), attributes Map(String, UInt8))'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        $client->write('INSERT INTO ' . self::TABLE . " VALUES (1, 'a', ['x'], {'k': 1}), (2, 'b', [], {}), (3, 'c', [], {})");
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    public function test_the_connection_returns_each_row_as_an_object(): void
    {
        $connection = DB::connection('clickhouse');
        $sql = 'SELECT id, name, tags, attributes FROM ' . self::TABLE . ' ORDER BY id';

        $rows = $connection->select($sql);
        $this->assertSame(
            [['id' => 1, 'name' => 'a', 'tags' => ['x'], 'attributes' => ['k' => 1]]],
            Rows::asArrays(array_slice($rows, 0, 1))
        );
        $this->assertSame(1, $rows[0]->id);
        $this->assertSame(['k' => 1], $rows[0]->attributes);

        $this->assertSame(['id' => 1, 'name' => 'a'], Rows::asArray($connection->selectOne('SELECT id, name FROM ' . self::TABLE . ' ORDER BY id')));
        $this->assertSame(3, (int) $connection->scalar('SELECT count() FROM ' . self::TABLE));
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], Rows::asArrays($connection->cursor('SELECT id FROM ' . self::TABLE . ' ORDER BY id')));
    }

    public function test_every_read_of_the_query_builder_returns_objects(): void
    {
        $query = fn (): QueryBuilder => DB::connection('clickhouse')->table(self::TABLE)->select('id', 'name')->orderBy('id');

        $first = ['id' => 1, 'name' => 'a'];
        $all = [$first, ['id' => 2, 'name' => 'b'], ['id' => 3, 'name' => 'c']];

        $this->assertSame($all, Rows::asArrays($query()->get()));
        $this->assertSame($first, Rows::asArray($query()->first()));
        $this->assertSame($first, Rows::asArray($query()->firstOrFail()));
        $this->assertSame($first, Rows::asArray($query()->where('id', 1)->sole()));
        $this->assertSame($first, Rows::asArray($query()->find(1)));
        $this->assertSame($first, Rows::asArray($query()->findOr(1, fn () => null)));
        $this->assertSame($all, Rows::asArrays($query()->cursor()));
        $this->assertSame($all, Rows::asArrays($query()->lazy(2)));
        $this->assertSame($all, Rows::asArrays($query()->lazyById(2)));
        $this->assertSame(array_reverse($all), Rows::asArrays($query()->lazyByIdDesc(2)));
        $this->assertSame($all, Rows::asArrays($query()->paginate(10)->items()));
        $this->assertSame($all, Rows::asArrays($query()->simplePaginate(10)->items()));
        $this->assertSame($all, Rows::asArrays($query()->cursorPaginate(10)->items()));
        $this->assertSame(['a', 'b', 'c'], $query()->chunkMap(fn (stdClass $row): string => $row->name, 2)->all());

        $chunks = [];
        $query()->chunk(2, function (Collection $rows) use (&$chunks): void {
            $chunks[] = Rows::asArrays($rows);
        });
        $query()->chunkById(2, function (Collection $rows) use (&$chunks): void {
            $chunks[] = Rows::asArrays($rows);
        });
        $query()->chunkByIdDesc(2, function (Collection $rows) use (&$chunks): void {
            $chunks[] = Rows::asArrays($rows);
        });
        $this->assertSame([[$all[0], $all[1]], [$all[2]], [$all[0], $all[1]], [$all[2]], [$all[2], $all[1]], [$all[0]]], $chunks);

        $each = [];
        $query()->each(function (stdClass $row) use (&$each): void {
            $each[] = $row->id;
        }, 2);
        $query()->eachById(function (stdClass $row) use (&$each): void {
            $each[] = $row->id;
        }, 2);
        $this->assertSame([1, 2, 3, 1, 2, 3], $each);
    }

    public function test_the_base_query_of_a_model_returns_objects_and_the_model_query_returns_models(): void
    {
        $model = new class extends Model {
            protected $table = RowObjectsTest::TABLE;

            public $timestamps = false;
        };

        $this->assertInstanceOf(QueryBuilder::class, $model::query()->toBase());
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], Rows::asArrays($model::select(['id'])->orderBy('id')->toBase()->get()));
        $this->assertSame(['id' => 2, 'name' => 'b'], $model::select(['id', 'name'])->where('id', 2)->first()->getAttributes());
    }

    /**
     * Each value has the PHP type of its ClickHouse type, the same through select(), the query builder, cursor()
     * and the parallel queries, and on every ClickHouse version: 24.8 quotes the 64-bit integers, and 25.8 and later
     * write them as numbers.
     */
    public function test_each_value_has_the_php_type_of_its_column_on_every_path(): void
    {
        $sql = "SELECT toUInt64(5) AS u64, toUInt64('18446744073709551615') AS big, toInt64(-5) AS i64,"
            . " toFloat64(2) AS whole, toFloat32(2.5) AS f32, nan AS not_a_number, inf AS infinite,"
            . " toDecimal64(1.5, 2) AS price, toDecimal32(3, 1) AS whole_price, toNullable(toDecimal64(1, 2)) AS maybe,"
            . " CAST(NULL, 'Nullable(UInt64)') AS nothing, [1.0, 2.5] AS floats, map('a', toUInt64(1)) AS counts,"
            . " tuple(toUInt64(7), toFloat64(1)) AS pair, true AS flag, 'x' AS name";
        $expected = [
            'u64' => 5,
            'big' => '18446744073709551615',
            'i64' => -5,
            'whole' => 2.0,
            'f32' => 2.5,
            'infinite' => INF,
            'price' => '1.50',
            'whole_price' => '3.0',
            'maybe' => '1.00',
            'nothing' => null,
            'floats' => [1.0, 2.5],
            'counts' => ['a' => 1],
            'pair' => [7, 1.0],
            'flag' => true,
            'name' => 'x',
        ];
        $connection = DB::connection('clickhouse');

        $rows = [
            'select()' => $connection->select($sql)[0],
            'get()' => $connection->query()->fromSub(fn ($query) => $query->selectRaw(substr($sql, 7)), 's')->get()[0],
            'cursor()' => iterator_to_array($connection->cursor($sql))[0],
            'Parallel::getRows()' => Parallel::getRows(['q' => ['sql' => $sql, 'connection' => 'clickhouse']])['q'][0],
        ];

        foreach ($rows as $path => $row) {
            $values = Rows::asArray($row);
            $this->assertNan($values['not_a_number'], $path);
            unset($values['not_a_number']);
            $this->assertSame($expected, $values, $path);
        }
    }

    public function test_the_parallel_queries_return_objects(): void
    {
        $connection = DB::connection('clickhouse');

        $results = Parallel::getRows([
            'builder' => $connection->table(self::TABLE)->select('id')->where('id', 1),
            'sql' => ['sql' => 'SELECT id FROM ' . self::TABLE . ' WHERE id = ?', 'bindings' => [2], 'connection' => 'clickhouse'],
        ]);
        $this->assertSame(['builder' => [['id' => 1]], 'sql' => [['id' => 2]]], Rows::ofEachResult($results));

        $this->assertSame(
            ['three' => [['id' => 3]]],
            Rows::ofEachResult($connection->selectParallelly(['three' => 'SELECT id FROM ' . self::TABLE . ' WHERE id = 3']))
        );
    }
}
