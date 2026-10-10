<?php

namespace Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oralunal\LaravelClickHouse\Builder;
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

    public function testExpressionValueIsSentAsWritten(): void
    {
        $rows = DB::connection('clickhouse')->table('system.numbers')
            ->select(['number'])
            ->limit(10)
            ->settings('additional_table_filters', new RawColumn("{'system.numbers': 'number < 3'}"))
            ->getRows();

        $this->assertSame(['0', '1', '2'], array_column($rows, 'number'));
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

    public function testLaterCallsAddToTheSettingsAndOverrideSameNames(): void
    {
        $row = $this->selectSettings(['max_threads', 'log_comment'])
            ->settings(['max_threads' => 3, 'log_comment' => 'first'])
            ->settings('max_threads', 1)
            ->getRows()[0];

        $this->assertSame(1, $row['max_threads']);
        $this->assertSame('first', $row['log_comment']);
    }
}
