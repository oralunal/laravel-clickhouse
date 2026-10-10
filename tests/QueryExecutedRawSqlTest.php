<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\RawColumn;

/**
 * Laravel's QueryExecuted::toRawSql() asks the query builder of the connection for its grammar. On a connection with
 * fix_default_query_builder, that builder is the package Builder, whose getGrammar() returns the package Grammar.
 */
class QueryExecutedRawSqlTest extends TestCase
{
    public function test_the_query_builder_of_the_connection_gives_the_package_grammar(): void
    {
        $this->assertInstanceOf(Grammar::class, DB::connection('clickhouse')->query()->getGrammar());
    }

    public function test_a_listener_writes_the_bindings_of_each_query_into_its_sql(): void
    {
        $rawSql = [];
        DB::listen(function (QueryExecuted $event) use (&$rawSql): void {
            $rawSql[] = $event->toRawSql();
        });
        $connection = DB::connection('clickhouse');

        $connection->table('system.one')->select('dummy')->where('dummy', 0)->getRows();
        $connection->table('system.one')->where(new RawColumn('toString(dummy)'), '!=', 'it\'s ?')->count();
        $connection->select('SELECT dummy FROM system.one WHERE dummy = ? AND ? != ?', [0, "it's", 'x']);

        $this->assertSame([
            'SELECT `dummy` FROM `system`.`one` WHERE `dummy` = 0',
            "SELECT count() as `count` FROM `system`.`one` WHERE toString(dummy) != 'it\\'s ?'",
            "SELECT dummy FROM system.one WHERE dummy = 0 AND 'it\\'s' != 'x'",
        ], $rawSql);
    }
}
