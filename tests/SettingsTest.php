<?php

namespace Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Tests\Fixtures\IntBackedEnumFixture;
use Tests\Fixtures\StringBackedEnumFixture;

class SettingsTest extends TestCase
{
    /**
     * Select the given settings as the server sees them while running the query.
     *
     * @param string[] $names
     * @return QueryBuilder
     */
    private function selectSettings(array $names): QueryBuilder
    {
        return DB::connection('clickhouse')->table('system.one')->select(array_map(
            fn (string $name) => DB::raw("getSetting('{$name}') AS {$name}"),
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
        ])->get()->first();

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
            ->settings('additional_table_filters', DB::raw("{'system.numbers': 'number < 3'}"))
            ->get()->all();

        $this->assertSame([0, 1, 2], array_map('intval', array_column($rows, 'number')));
    }

    public function testServerAppliesStringableAndBackedEnumValues(): void
    {
        $row = $this->selectSettings(['log_comment', 'max_threads'])->settings([
            'log_comment' => Str::of("it's a \\ test"),
            'max_threads' => IntBackedEnumFixture::High,
        ])->get()->first();

        $this->assertSame("it's a \\ test", $row['log_comment']);
        $this->assertSame(3, $row['max_threads']);

        $this->assertSame(
            '2026-01-01 00:00:00',
            $this->selectSettings(['log_comment'])
                ->settings('log_comment', Carbon::parse('2026-01-01 00:00:00'))
                ->get()->first()['log_comment']
        );
        $this->assertSame(
            'active',
            $this->selectSettings(['log_comment'])
                ->settings('log_comment', StringBackedEnumFixture::Active)
                ->get()->first()['log_comment']
        );
    }

    /**
     * The client adds FORMAT JSON after the SETTINGS clause of a plain query, where ClickHouse reads it.
     */
    public function testPlainQueryWithSettingsReturnsItsRows(): void
    {
        $connection = DB::connection('clickhouse');
        $query = fn (): QueryBuilder => $connection->query()->fromRaw("values('x UInt8', 1, 2, 3)")->select('x');
        $connection->enableQueryLog();

        $this->assertSame([1, 2, 3], array_column($query()->settings(['max_threads' => 1])->get()->all(), 'x'));
        $this->assertSame([1, 2, 3], array_column($query()->get()->all(), 'x'));

        $this->assertSame([
            "select \"x\" from values('x UInt8', 1, 2, 3) settings max_threads=1",
            "select \"x\" from values('x UInt8', 1, 2, 3)",
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
            DB::raw("toUInt64('18446744073709551615') AS big"),
            DB::raw("toInt64('-9223372036854775808') AS small"),
        ];
        $exact = [['big' => '18446744073709551615', 'small' => '-9223372036854775808']];
        $numbers = [['big' => 1.8446744073709552E+19, 'small' => PHP_INT_MIN]];

        try {
            foreach ([[$quoted, $exact], [$unquoted, $numbers]] as [$connection, $expected]) {
                $this->assertSame($expected, $connection->select($sql), $connection->getName());
                $this->assertSame($expected, $connection->getClient()->select($sql)->rows(), $connection->getName());
                $this->assertSame($expected, $connection->table('system.one')->select($columns)->get()->all(), $connection->getName());
            }

            $this->assertSame(
                $exact,
                $unquoted->table('system.one')->select($columns)
                    ->settings('output_format_json_quote_64bit_integers', 1)
                    ->get()->all(),
                "The setting of one query overrides the connection's 0."
            );
            $this->assertSame(
                $numbers,
                $quoted->table('system.one')->select($columns)
                    ->settings('output_format_json_quote_64bit_integers', 0)
                    ->get()->all(),
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
            ->get()->first();

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
