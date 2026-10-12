<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse-cluster';

    public function up(): void
    {
        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            // The clickhouse-cluster connection and the {replica} and {shard} macros are only configured when
            // docker-compose.test.yaml mounts tests/docker/clickhouse0*/config.xml.
            return;
        }

        DB::connection($this->connection)->statementOnEveryNode(<<<'SQL'
            CREATE TABLE IF NOT EXISTS examples4 (
                created_at DateTime64 DEFAULT now64(),
                f_int Int64,
                f_string String
            )
            ENGINE = ReplicatedMergeTree('/clickhouse/tables/default.examples4', '{replica}')
            ORDER BY (f_int)

        SQL);
    }

    public function down(): void
    {
        if (! env('CLICKHOUSE_CLUSTER_AVAILABLE')) {
            // The clickhouse-cluster connection and the {replica} and {shard} macros are only configured when
            // docker-compose.test.yaml mounts tests/docker/clickhouse0*/config.xml.
            return;
        }

        DB::connection($this->connection)->statementOnEveryNode('DROP TABLE IF EXISTS examples4');
    }
};
