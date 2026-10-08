<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\RawColumn;
use Tests\Models\Example;

class UpdateTest extends TestCase
{
    public function testUpdate()
    {
        Example::truncate();
        Example::insertAssoc([['f_int' => 1, 'f_int2' => 2, 'f_string' => 'a']]);
        Example::where('f_int', 1)->update([
            'f_int2' => 3,
            'f_string' => 'b',
            'created_at' => new RawColumn('created_at + INTERVAL 1 YEAR')
        ]);
        $this->waitForMutations((new Example())->getTable());
        $rows = Example::where('f_int', 1)->getRows();
        $this->assertCount(1, $rows);
        $this->assertEquals(3, $rows[0]['f_int2']);
        $this->assertEquals('b', $rows[0]['f_string']);
        $this->assertEquals(date('Y') + 1, substr($rows[0]['created_at'], 0, 4));
    }

    /**
     * Wait until ClickHouse has applied every mutation on the table.
     *
     * ALTER TABLE ... UPDATE only registers the mutation and returns; the rows
     * change once it is done. Mutations on a table run in order, so this also
     * covers the one the test just issued.
     *
     * @param string $table
     * @param float $timeoutSeconds
     * @return void
     */
    private function waitForMutations(string $table, float $timeoutSeconds = 10.0): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $sql = "SELECT mutation_id, command, latest_fail_reason FROM system.mutations"
            . " WHERE database = currentDatabase() AND table = '{$table}' AND is_done = 0";
        $deadline = microtime(true) + $timeoutSeconds;

        while (($pending = $client->select($sql)->rows()) !== []) {
            if (microtime(true) >= $deadline) {
                $this->fail("Mutations on {$table} still pending after {$timeoutSeconds}s: " . json_encode($pending));
            }
            usleep(10_000);
        }
    }
}
