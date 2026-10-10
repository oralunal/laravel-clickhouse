<?php

namespace Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\RawColumn;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;

class SettingsTest extends TestCase
{
    /**
     * Select the given settings as the server sees them while running the query.
     *
     * @param string[] $names
     * @return Builder
     */
    private function selectSettings(array $names): Builder
    {
        return DB::connection('clickhouse')->table('system.one')->select(array_map(
            fn (string $name): RawColumn => new RawColumn("getSetting('{$name}')", $name),
            $names
        ));
    }

    public function testServerAppliesEveryKindOfValue(): void
    {
        $row = $this->selectSettings([
            'max_threads',
            'optimize_move_to_prewhere',
            'use_uncompressed_cache',
            'totals_auto_threshold',
            'log_comment',
        ])->settings([
            'max_threads' => 2,
            'optimize_move_to_prewhere' => false,
            'use_uncompressed_cache' => true,
            'totals_auto_threshold' => 0.25,
            'log_comment' => "it's a \\ test",
        ])->getRows()[0];

        $this->assertSame(2, $row['max_threads']);
        $this->assertFalse($row['optimize_move_to_prewhere']);
        $this->assertTrue($row['use_uncompressed_cache']);
        $this->assertSame(0.25, $row['totals_auto_threshold']);
        $this->assertSame("it's a \\ test", $row['log_comment']);
    }

    /**
     * The UInt64 column number is compared as ints: ClickHouse 24.8 quotes 64-bit integers in JSON, 25.8 and later
     * do not (output_format_json_quote_64bit_integers is 0).
     */
    public function testExpressionValueIsSentAsWritten(): void
    {
        $rows = DB::connection('clickhouse')->table('system.numbers')
            ->select(['number'])
            ->limit(10)
            ->settings('additional_table_filters', new RawColumn("{'system.numbers': 'number < 3'}"))
            ->getRows();

        $this->assertSame([0, 1, 2], array_map('intval', array_column($rows, 'number')));
    }

    public function testServerAppliesStringableAndBackedEnumValues(): void
    {
        $row = $this->selectSettings(['log_comment', 'max_threads'])->settings([
            'log_comment' => Str::of("it's a \\ test"),
            'max_threads' => IntBackedEnumFixture::High,
        ])->getRows()[0];

        $this->assertSame("it's a \\ test", $row['log_comment']);
        $this->assertSame(3, $row['max_threads']);

        $this->assertSame(
            '2026-01-01 00:00:00',
            $this->selectSettings(['log_comment'])
                ->settings('log_comment', Carbon::parse('2026-01-01 00:00:00'))
                ->getRows()[0]['log_comment']
        );
        $this->assertSame(
            'active',
            $this->selectSettings(['log_comment'])
                ->settings('log_comment', StringBackedEnumFixture::Active)
                ->getRows()[0]['log_comment']
        );
    }

    /**
     * The client would add FORMAT JSON after SETTINGS; get() names it before them.
     */
    public function testPlainQueryWithSettingsReturnsItsRowsWithFormatJsonBeforeTheSettings(): void
    {
        $connection = DB::connection('clickhouse');
        $query = fn (): Builder => $connection->table(new RawColumn("values('x UInt8', 1, 2, 3)"))->select('x');
        $connection->enableQueryLog();

        $this->assertSame([1, 2, 3], array_column($query()->settings(['max_threads' => 1])->getRows(), 'x'));
        $this->assertSame([1, 2, 3], array_column($query()->getRows(), 'x'));

        $this->assertSame([
            "SELECT `x` FROM values('x UInt8', 1, 2, 3) FORMAT JSON SETTINGS max_threads=1",
            "SELECT `x` FROM values('x UInt8', 1, 2, 3)",
        ], array_column($connection->getQueryLog(), 'query'));
    }

    /**
     * output_format_json_quote_64bit_integers = 1 in the connection's settings keeps 64-bit integers exact: they come
     * back as strings, as ClickHouse 24.8 returns them by default. 25.8 and later default it to 0 and return JSON
     * numbers, which PHP reads as an int, or as a float above PHP_INT_MAX: 18446744073709551615 becomes
     * 1.8446744073709552E+19.
     *
     * A connection with the setting at 0 reads such numbers on every version, 24.8 included, so the connection's
     * settings are shown to reach the server whatever its default. settings() on one query overrides the
     * connection's value in both directions.
     */
    public function testQuotedSixtyFourBitIntegersStayExactOnEveryPath(): void
    {
        $quoted = $this->connectionWithSettings('clickhouse-quoted-integers', ['output_format_json_quote_64bit_integers' => 1]);
        $unquoted = $this->connectionWithSettings('clickhouse-unquoted-integers', ['output_format_json_quote_64bit_integers' => 0]);
        $sql = "SELECT toUInt64('18446744073709551615') AS big, toInt64('-9223372036854775808') AS small";
        $columns = [
            new RawColumn("toUInt64('18446744073709551615')", 'big'),
            new RawColumn("toInt64('-9223372036854775808')", 'small'),
        ];
        $exact = [['big' => '18446744073709551615', 'small' => '-9223372036854775808']];
        $numbers = [['big' => 1.8446744073709552E+19, 'small' => PHP_INT_MIN]];

        try {
            foreach ([[$quoted, $exact], [$unquoted, $numbers]] as [$connection, $expected]) {
                $this->assertSame($expected, $connection->select($sql), $connection->getName());
                $this->assertSame($expected, $connection->getClient()->select($sql)->rows(), $connection->getName());
                $this->assertSame($expected, $connection->table('system.one')->select($columns)->getRows(), $connection->getName());
            }

            $this->assertSame(
                $exact,
                $unquoted->table('system.one')->select($columns)
                    ->settings('output_format_json_quote_64bit_integers', 1)
                    ->getRows(),
                "The setting of one query overrides the connection's 0."
            );
            $this->assertSame(
                $numbers,
                $quoted->table('system.one')->select($columns)
                    ->settings('output_format_json_quote_64bit_integers', 0)
                    ->getRows(),
                "The setting of one query overrides the connection's 1."
            );
        } finally {
            DB::purge('clickhouse-quoted-integers');
            DB::purge('clickhouse-unquoted-integers');
        }
    }

    public function testLaterCallsAddToTheSettingsAndOverrideSameNames(): void
    {
        $row = $this->selectSettings(['max_threads', 'log_comment'])
            ->settings(['max_threads' => 3, 'log_comment' => 'first'])
            ->settings('max_threads', 1)
            ->getRows()[0];

        $this->assertSame(1, $row['max_threads']);
        $this->assertSame('first', $row['log_comment']);
    }

    /**
     * Configure a copy of the clickhouse connection with the given settings, and connect it.
     *
     * @param string $name
     * @param array<string, int|string> $settings
     * @return Connection
     */
    private function connectionWithSettings(string $name, array $settings): Connection
    {
        $this->app['config']->set("database.connections.{$name}", array_merge(
            $this->app['config']->get('database.connections.clickhouse'),
            ['settings' => $settings]
        ));

        return DB::connection($name);
    }
}
