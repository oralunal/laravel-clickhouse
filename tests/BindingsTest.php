<?php

namespace Tests;

use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\Connection;
use Tests\Models\Example;

class BindingsTest extends TestCase
{
    private const TABLE = 'bindings_rows';

    private const LARAVEL_CONNECTION = 'clickhouse-bindings-laravel';

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, price Float64, created DateTime, precise DateTime64(6),'
            . ' flag UInt8, note String) ENGINE = MergeTree ORDER BY id'
        );
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    public function testBindingsByModel()
    {
        $query = Example::select()
            ->where(function (Builder $q) {
                $q->where('f_int', 1)
                    ->orWhere('f_int', 2)
                    ->orWhere('f_int', 3);
            })
            ->whereIn('f_int2', [10, 11]);
        $this->assertEquals("SELECT * FROM `examples` WHERE (`f_int` = 1 OR `f_int` = 2 OR `f_int` = 3) AND `f_int2` IN (10, 11)", $query->toSql());
    }

    public function testBindingsByTableMethod()
    {
        $query = DB::table('examples')
            ->where(function ($q) {
                $q->where('f_int', 1)
                    ->orWhere('f_int', 2)
                    ->orWhere('f_int', 3);
            })
            ->whereIn('f_int2', [10, 11]);
        $this->assertEquals("SELECT * FROM `examples` WHERE (`f_int` = 1 OR `f_int` = 2 OR `f_int` = 3) AND `f_int2` IN (10, 11)", $query->toSql());
    }

    /**
     * The "?" bindings of a raw statement are written into the SQL. 3.0.0 sent the "?" as it was, which ClickHouse
     * rejected with a syntax error.
     */
    public function test_a_raw_insert_stores_its_question_mark_bindings(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertTrue($connection->insert(
            'INSERT INTO ' . self::TABLE . ' (id, price, created, precise, flag, note) VALUES (?, ?, ?, ?, ?, ?)',
            [1, 19.99, new DateTime('2024-01-02 03:04:05'), new DateTime('2024-01-02 03:04:05.123456'), false, "it's a \\ test"]
        ));

        $this->assertSame(
            [[
                'id' => 1,
                'price' => 19.99,
                'created' => '2024-01-02 03:04:05',
                'precise' => '2024-01-02 03:04:05.000000',
                'flag' => 0,
                'note' => "it's a \\ test",
            ]],
            $this->rows()
        );
        $this->assertSame(
            [['id' => 1]],
            $connection->select('SELECT id FROM ' . self::TABLE . ' WHERE price = ? AND created = ? AND flag = ?', [19.99, new DateTime('2024-01-02 03:04:05'), false])
        );
    }

    /**
     * The rows after the FORMAT clause of an INSERT without bindings are data, not SQL, and are sent as they are: a
     * "??" in them is stored as "??". 3.0.0 also stored "??".
     */
    public function test_inline_insert_data_keeps_its_double_question_marks(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertTrue($connection->insert('INSERT INTO ' . self::TABLE . " (id, note) FORMAT TSV\n1\twhat??\n"));
        $this->assertTrue($connection->statement('INSERT INTO ' . self::TABLE . " (id, note) FORMAT CSV\n2,why??\n"));
        $this->assertSame(3, $connection->affectingStatement(
            'INSERT INTO ' . self::TABLE . " (id, note) FORMAT TSV\n3\t??\n4\ta ?? b\n5\tit's??\n"
        ));
        $this->assertTrue($connection->insert('INSERT INTO ' . self::TABLE . " (id, note) VALUES (6, 'ok??')"));

        $this->assertSame(
            [
                ['id' => 1, 'note' => 'what??'],
                ['id' => 2, 'note' => 'why??'],
                ['id' => 3, 'note' => '??'],
                ['id' => 4, 'note' => 'a ?? b'],
                ['id' => 5, 'note' => "it's??"],
                ['id' => 6, 'note' => 'ok??'],
            ],
            $connection->select('SELECT id, note FROM ' . self::TABLE . ' ORDER BY id')
        );
    }

    public function test_a_float_binding_is_written_with_every_digit(): void
    {
        $connection = DB::connection('clickhouse');

        $connection->insert('INSERT INTO ' . self::TABLE . ' (id, price) VALUES (?, ?)', [1, 0.1 + 0.2]);

        $this->assertSame([['exact' => 1]], $connection->select('SELECT toUInt8(price = 0.1 + 0.2) AS exact FROM ' . self::TABLE));
        $this->assertSame([['id' => 1]], $connection->select('SELECT id FROM ' . self::TABLE . ' WHERE price = ?', [0.1 + 0.2]));
    }

    /**
     * Named bindings are left to smi2, as in 3.0.0, with dates formatted by the connection and bools as 1 or 0.
     */
    public function test_named_bindings_are_substituted_by_smi2(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertTrue($connection->insert(
            'INSERT INTO ' . self::TABLE . ' (id, created, flag, note) VALUES (:id, :created, :flag, :note)',
            ['id' => 2, 'created' => new DateTime('2024-01-02 03:04:05'), 'flag' => true, 'note' => 'named']
        ));

        $this->assertSame(
            [['id' => 2, 'created' => '2024-01-02 03:04:05', 'flag' => 1, 'note' => 'named']],
            $connection->select('SELECT id, created, flag, note FROM ' . self::TABLE . ' WHERE note = :note', ['note' => 'named'])
        );
    }

    /**
     * Laravel's query builder sent "= " and nothing after it for false, a syntax error.
     */
    public function test_false_works_on_laravels_query_builder(): void
    {
        $connection = $this->laravelBuilderConnection();

        $this->assertTrue($connection->table(self::TABLE)->insert([
            ['id' => 1, 'flag' => false, 'note' => 'a'],
            ['id' => 2, 'flag' => true, 'note' => 'b'],
        ]));

        $this->assertSame([1], $connection->table(self::TABLE)->where('flag', false)->pluck('id')->all());
        $this->assertSame([1], $connection->table(self::TABLE)->whereIn('flag', [false])->pluck('id')->all());
        $this->assertSame(1, $connection->table(self::TABLE)->where('id', 2)->update(['flag' => false]));
        $this->waitForMutations();
        $this->assertSame([1, 2], $connection->table(self::TABLE)->where('flag', false)->orderBy('id')->pluck('id')->all());
    }

    public function test_dates_follow_the_microsecond_precision_of_the_connection(): void
    {
        $connection = $this->laravelBuilderConnection(['datetime_precision' => 'microsecond']);
        $date = Carbon::parse('2024-01-02 03:04:05.123456');

        $this->assertTrue($connection->insert(
            'INSERT INTO ' . self::TABLE . ' (id, precise) VALUES (?, ?)',
            [1, $date]
        ));
        $this->assertTrue($connection->insert(
            'INSERT INTO ' . self::TABLE . ' (id, precise) VALUES (:id, :precise)',
            ['id' => 2, 'precise' => $date->copy()->addMicroseconds(1)]
        ));
        $connection->table(self::TABLE)->insert(['id' => 3, 'precise' => $date->copy()->addMicroseconds(2)]);

        $this->assertSame(
            [
                ['id' => 1, 'precise' => '2024-01-02 03:04:05.123456'],
                ['id' => 2, 'precise' => '2024-01-02 03:04:05.123457'],
                ['id' => 3, 'precise' => '2024-01-02 03:04:05.123458'],
            ],
            $connection->select('SELECT id, precise FROM ' . self::TABLE . ' ORDER BY id')
        );
        $this->assertSame([1], $connection->table(self::TABLE)->where('precise', $date)->pluck('id')->all());
        $this->assertSame(
            [['id' => 2]],
            $connection->select('SELECT id FROM ' . self::TABLE . ' WHERE precise = :precise', ['precise' => $date->copy()->addMicroseconds(1)])
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function laravelBuilderConnection(array $config = []): Connection
    {
        config(['database.connections.' . self::LARAVEL_CONNECTION => array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false],
            $config
        )]);
        DB::purge(self::LARAVEL_CONNECTION);

        return DB::connection(self::LARAVEL_CONNECTION);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return DB::connection('clickhouse')->getClient()
            ->select('SELECT id, price, created, precise, flag, note FROM ' . self::TABLE . ' ORDER BY id')
            ->rows();
    }

    /**
     * Wait until the mutations of the table are done.
     *
     * @param float $timeoutSeconds
     * @return void
     */
    private function waitForMutations(float $timeoutSeconds = 10.0): void
    {
        $sql = 'SELECT mutation_id FROM system.mutations'
            . " WHERE database = currentDatabase() AND table = '" . self::TABLE . "' AND is_done = 0";
        $deadline = microtime(true) + $timeoutSeconds;

        while (DB::connection('clickhouse')->getClient()->select($sql)->rows() !== []) {
            if (microtime(true) >= $deadline) {
                $this->fail('Mutations still pending after ' . $timeoutSeconds . 's.');
            }
            usleep(10_000);
        }
    }
}
