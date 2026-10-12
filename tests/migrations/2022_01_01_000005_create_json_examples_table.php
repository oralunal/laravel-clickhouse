<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        DB::connection($this->connection)->statementOnEveryNode(<<<'SQL'
            CREATE TABLE IF NOT EXISTS json_examples (
                id UInt32,
                f_map Map(String, UInt32),
                f_array Array(Float64),
                f_bool Bool,
                f_flag UInt8,
                f_float Float64,
                f_nullable Nullable(String),
                f_datetime DateTime64(6),
                f_date Date,
                f_uint64 UInt64,
                f_string String DEFAULT 'def'
            )
            ENGINE = MergeTree()
            ORDER BY (id)

        SQL);
    }

    public function down(): void
    {
        DB::connection($this->connection)->statementOnEveryNode('DROP TABLE IF EXISTS json_examples');
    }
};
