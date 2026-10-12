<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\QueryGrammar;

/**
 * Laravel's QueryExecuted::toRawSql() asks the query builder of the connection for its grammar, the package's
 * QueryGrammar, which writes the bindings as ClickHouse literals.
 */
class QueryExecutedRawSqlTest extends TestCase
{
    public function test_the_query_builder_of_the_connection_gives_the_package_grammar(): void
    {
        $this->assertInstanceOf(QueryGrammar::class, DB::connection('clickhouse')->query()->getGrammar());
    }

    public function test_a_listener_writes_the_bindings_of_each_query_into_its_sql(): void
    {
        $rawSql = [];
        DB::listen(function (QueryExecuted $event) use (&$rawSql): void {
            $rawSql[] = $event->toRawSql();
        });
        $connection = DB::connection('clickhouse');

        $connection->table('system.one')->select('dummy')->where('dummy', 0)->get();
        $connection->table('system.one')->where(DB::raw('toString(dummy)'), '!=', 'it\'s ?')->count();
        $connection->select('SELECT dummy FROM system.one WHERE dummy = ? AND ? != ?', [0, "it's", 'x']);

        $this->assertSame([
            'select "dummy" from "system"."one" where "dummy" = 0',
            "select count(*) as \"aggregate\" from \"system\".\"one\" where toString(dummy) != 'it\\'s ?'",
            "SELECT dummy FROM system.one WHERE dummy = 0 AND 'it\\'s' != 'x'",
        ], $rawSql);
    }
}
