<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse2';

    public function up(): void
    {
        DB::connection($this->connection)->statementOnEveryNode(<<<'SQL'
            CREATE TABLE IF NOT EXISTS examples2 (
                created_at DateTime64 DEFAULT now64(),
                f_int Int64,
                f_int2 Int64,
                f_string String
            )
            ENGINE = MergeTree()
            ORDER BY (f_int)

        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statementOnEveryNode('DROP TABLE IF EXISTS examples2');
    }
};
