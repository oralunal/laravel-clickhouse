<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        DB::connection($this->connection)->statementOnEveryNode(<<<'SQL'
            CREATE TABLE IF NOT EXISTS examples5 (
                created_at DateTime64(3) DEFAULT now64(),
                f_int Int64,
                f_string String,
                f_bool Bool
            )
            ENGINE = MergeTree()
            ORDER BY (f_int)
        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statementOnEveryNode('DROP TABLE IF EXISTS examples5');
    }
};
