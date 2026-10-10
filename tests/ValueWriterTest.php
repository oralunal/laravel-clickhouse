<?php

declare(strict_types=1);

namespace Tests;

use Carbon\Carbon;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Type\UInt64;
use Illuminate\Support\Facades\DB;

/**
 * The values that the package query builder writes into its SQL, checked against ClickHouse: floats with every digit,
 * also into Decimal and Int columns, NaN and infinities, the values of the smi2 client, dates at the default second
 * precision, format names in any letter case, and a negative "?" binding after a minus sign.
 */
class ValueWriterTest extends TestCase
{
    private const TABLE = 'value_writer_rows';

    private const DECIMAL_TABLE = 'value_writer_decimals';

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, v Float64, f Float64, d6 DateTime64(6), s String)'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        // The server computes these values, so they are stored exactly.
        $client->write(
            'INSERT INTO ' . self::TABLE
            . " SELECT 1, 1 / 3, nan, toDateTime64('2024-01-02 03:04:05', 6), 'a'"
            . " UNION ALL SELECT 2, 0.1 + 0.2, inf, toDateTime64('2024-01-02 03:04:06', 6), 'b'"
            . " UNION ALL SELECT 3, 2, -inf, toDateTime64('2024-01-02 03:04:07', 6), 'c'"
        );
        $client->write('DROP TABLE IF EXISTS ' . self::DECIMAL_TABLE . ' SYNC');
        $client->write(
            'CREATE TABLE ' . self::DECIMAL_TABLE . ' (id UInt32, price Decimal(10, 2), quantity Int32)'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        $client->write('INSERT INTO ' . self::DECIMAL_TABLE . ' VALUES (1, 435, 29), (2, 435, 29)');
    }

    protected function tearDown(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write('DROP TABLE IF EXISTS ' . self::DECIMAL_TABLE . ' SYNC');

        parent::tearDown();
    }

    public function test_a_float_condition_compares_every_digit(): void
    {
        $query = DB::table(self::TABLE)->where('v', 1 / 3);

        $this->assertSame('SELECT * FROM `value_writer_rows` WHERE `v` = 0.3333333333333333', $query->toSql());
        $this->assertSame(1, $query->count());
        $this->assertSame(2, DB::table(self::TABLE)->whereIn('v', [1 / 3, 0.1 + 0.2])->count());
        $this->assertSame([['id' => 3]], DB::table(self::TABLE)->select('id')->where('v', 2.0)->getRows());
    }

    public function test_an_update_writes_every_digit_of_a_float(): void
    {
        DB::table(self::TABLE)->where('id', 3)->update(['v' => 2 / 3]);
        $this->waitForMutations();

        $this->assertSame([['id' => 3]], DB::table(self::TABLE)->select('id')->where('v', 2 / 3)->getRows());
        $this->assertSame(
            [['exact' => 1]],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT toUInt8(v = 2 / 3) AS exact FROM ' . self::TABLE . ' WHERE id = 3')->rows()
        );
    }

    /**
     * A float is written with every digit, so a computed float just below a whole number or a cent is written as
     * that value: 4.35 * 100 is 434.99999999999994 and 0.29 * 100 is 28.999999999999996. ClickHouse truncates it to
     * the scale of a Decimal column, and an Int column truncates it as PHP's (int) does, while a condition compares
     * it with every digit. 3.0.0 wrote 14 significant digits, 435 and 29, which hid this. Rounding to the scale of
     * the column in PHP, or passing an int or a string, writes the intended value.
     */
    public function test_a_computed_float_is_truncated_by_decimal_and_int_columns_unless_it_is_rounded(): void
    {
        $query = DB::table(self::DECIMAL_TABLE)->where('price', 4.35 * 100);

        $this->assertSame('SELECT * FROM `value_writer_decimals` WHERE `price` = 434.99999999999994', $query->toSql());
        $this->assertSame(0, $query->count());
        $this->assertSame(2, DB::table(self::DECIMAL_TABLE)->where('price', round(4.35 * 100, 2))->count());
        $this->assertSame(0, DB::table(self::DECIMAL_TABLE)->where('quantity', 0.29 * 100)->count());
        $this->assertSame(2, DB::table(self::DECIMAL_TABLE)->where('quantity', (int) round(0.29 * 100))->count());

        DB::table(self::DECIMAL_TABLE)->where('id', 1)->update(['price' => 4.35 * 100, 'quantity' => 0.29 * 100]);
        DB::table(self::DECIMAL_TABLE)->where('id', 2)->update(['price' => round(4.35 * 100, 2), 'quantity' => (int) round(0.29 * 100)]);
        $this->waitForMutations(self::DECIMAL_TABLE);

        $this->assertSame(
            [['id' => 1, 'price' => '434.99', 'quantity' => 28], ['id' => 2, 'price' => '435', 'quantity' => 29]],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT id, toString(price) AS price, quantity FROM ' . self::DECIMAL_TABLE . ' ORDER BY id')
                ->rows()
        );
    }

    /**
     * ClickHouse reads "--" as the start of a comment, so a negative binding written right after a "-" would turn the
     * rest of the line into a comment: "0--1 AND s != 'b'" reads as 0. The literal gets a space before it.
     */
    public function test_a_negative_binding_after_a_minus_sign_is_no_comment(): void
    {
        [$sql, $bindings] = DB::connection('clickhouse')->getQueryGrammar()->prepareQueryForClient(
            'SELECT id FROM ' . self::TABLE . " WHERE id > 0-? AND s != ?\nORDER BY id",
            [-1, 'b']
        );

        $this->assertSame("SELECT id FROM value_writer_rows WHERE id > 0- -1 AND s != 'b'\nORDER BY id", $sql);
        $this->assertSame([['id' => 3]], DB::connection('clickhouse')->getClient()->select($sql, $bindings)->rows());
    }

    /**
     * The value objects and expressions of the smi2 client are written as smi2 writes them: numbers and expressions
     * without quotes.
     */
    public function test_values_of_the_smi2_client_are_written_as_smi2_writes_them(): void
    {
        $query = DB::table(self::TABLE)->select('id')
            ->where('id', '>', UInt64::fromString('1'))
            ->where('d6', '<', new Raw('now()'))
            ->orderBy('id');

        $this->assertSame(
            "SELECT `id` FROM `value_writer_rows` WHERE `id` > 1 AND `d6` < now() ORDER BY `id` ASC",
            $query->toSql()
        );
        $this->assertSame([['id' => 2], ['id' => 3]], $query->getRows());
    }

    public function test_nan_and_infinities_are_written_as_their_literals(): void
    {
        $this->assertSame(
            'SELECT `id` FROM `value_writer_rows` WHERE `f` = nan OR `f` IN (inf, -inf) ORDER BY `id` ASC',
            DB::table(self::TABLE)->select('id')->where('f', NAN)->orWhereIn('f', [INF, -INF])->orderBy('id')->toSql()
        );
        $this->assertSame(0, DB::table(self::TABLE)->where('f', NAN)->count(), 'ClickHouse finds no value equal to NaN');
        $this->assertSame([['id' => 2]], DB::table(self::TABLE)->select('id')->where('f', INF)->getRows());
        $this->assertSame([['id' => 3]], DB::table(self::TABLE)->select('id')->where('f', -INF)->getRows());
    }

    public function test_dates_are_written_with_second_precision_by_default(): void
    {
        $query = DB::table(self::TABLE)->select('id')->where('d6', Carbon::parse('2024-01-02 03:04:05.5', 'UTC'));

        $this->assertSame(
            "SELECT `id` FROM `value_writer_rows` WHERE `d6` = '2024-01-02 03:04:05'",
            $query->toSql()
        );
        $this->assertSame([['id' => 1]], $query->getRows());
    }

    public function test_format_takes_a_name_in_any_letter_case(): void
    {
        foreach (['JSONEachRow', 'jsoneachrow', 'JSONEACHROW'] as $name) {
            $this->assertSame(
                "{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n",
                DB::table(self::TABLE)->select('id')->orderBy('id')->format($name)->get()->rawData(),
                "format('{$name}')"
            );
        }

        $this->assertSame(
            "\"id\"\n1\n2\n3\n",
            DB::table(self::TABLE)->select('id')->orderBy('id')->format('csvwithnames')->get()->rawData()
        );
    }

    /**
     * Wait until ClickHouse has applied every mutation on a table.
     *
     * @param string $table
     * @param float $timeoutSeconds
     * @return void
     */
    private function waitForMutations(string $table = self::TABLE, float $timeoutSeconds = 10.0): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $sql = 'SELECT mutation_id, latest_fail_reason FROM system.mutations'
            . " WHERE database = currentDatabase() AND table = '" . $table . "' AND is_done = 0";
        $deadline = microtime(true) + $timeoutSeconds;

        while (($pending = $client->select($sql)->rows()) !== []) {
            if (microtime(true) >= $deadline) {
                $this->fail("Mutations on {$table} still pending after {$timeoutSeconds}s: " . json_encode($pending));
            }
            usleep(10_000);
        }
    }
}
