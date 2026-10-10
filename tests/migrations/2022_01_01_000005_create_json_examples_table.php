<?php

return new class extends \Oralunal\LaravelClickHouse\Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        static::write(
            "
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
        "
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        static::write('DROP TABLE json_examples');
    }
};
