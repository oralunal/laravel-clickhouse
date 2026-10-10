<?php

/*
|--------------------------------------------------------------------------
| ClickHouse connections
|--------------------------------------------------------------------------
|
| Each top-level key defines a ClickHouse connection that the service
| provider merges into config('database.connections.<name>'). Values you
| set in your own config/database.php always win over the defaults here.
|
| Publish this file with:
|   php artisan vendor:publish --tag=clickhouse-config
|
| Add as many connections as you need. For a cluster, replace the `host`
| and `port` pair with a `cluster` array of nodes, and optionally set
| `cluster_name` so that Migration::createMergeTree() and Laravel's schema
| builder emit ON CLUSTER '<name>', and the schema builder creates
| replicated tables (see https://laravel-clickhouse.oralunal.com/advanced/clusters).
|
| Anything you set here overrides the packaged defaults. Entries in your
| config/database.php `connections` array outrank both. An option with a
| value that it does not take throws an InvalidArgumentException when the
| connection is created, before any node is pinged.
|
*/

return [

    'clickhouse' => [
        'driver'          => 'clickhouse',
        'host'            => env('CLICKHOUSE_HOST', '127.0.0.1'),
        'port'            => env('CLICKHOUSE_PORT', '8123'),
        'database'        => env('CLICKHOUSE_DATABASE', 'default'),
        'username'        => env('CLICKHOUSE_USERNAME', 'default'),
        'password'        => env('CLICKHOUSE_PASSWORD', ''),
        // Seconds, and a fraction is allowed: 0.5 is 500 ms. The query
        // timeout is applied in whole seconds, rounded up. Migration commands
        // can need more: an ON CLUSTER statement waits for every host, and on
        // a ClickHouse 26.8 server with little RAM a rollback's delete can
        // take more than 2 seconds (see https://laravel-clickhouse.oralunal.com/advanced/timeouts-and-retries).
        'timeout_connect' => env('CLICKHOUSE_TIMEOUT_CONNECT', 2),
        'timeout_query'   => env('CLICKHOUSE_TIMEOUT_QUERY', 2),
        'https'           => (bool) env('CLICKHOUSE_HTTPS', false),
        'retries'         => env('CLICKHOUSE_RETRIES', 0),
        // Which failed requests the retries send again: 'any' sends every one,
        // 'unsent' only those that never reached the server. With 'any', a
        // write that timed out on the client can run twice
        // (see https://laravel-clickhouse.oralunal.com/advanced/timeouts-and-retries#retries).
        'retry_on'        => env('CLICKHOUSE_RETRY_ON', 'any'),
        // ClickHouse settings sent with every query of the connection, such as
        // 'output_format_json_quote_64bit_integers' => 1, which returns 64-bit
        // integers as strings on 25.8 and later as 24.8 does, or
        // 'async_insert' => 0 (see https://laravel-clickhouse.oralunal.com/reference/clickhouse-versions).
        'settings'        => [],
        // DB::connection('clickhouse')->table() returns the package's query
        // builder when true, and Laravel's own query builder, with ClickHouse
        // SQL, when false (see https://laravel-clickhouse.oralunal.com/query-builder/laravel-query-builder).
        'fix_default_query_builder' => true,
        // delete() without an argument sends a lightweight DELETE FROM when
        // true, an ALTER TABLE ... DELETE mutation when false
        // (see https://laravel-clickhouse.oralunal.com/query-builder/writing-data#deletions).
        'use_lightweight_delete' => (bool) env('CLICKHOUSE_USE_LIGHTWEIGHT_DELETE', false),
        // The engine of the tables that Schema::create() creates without
        // $table->engine(); null means MergeTree().
        'engine' => env('CLICKHOUSE_ENGINE'),
        // When true, Schema::create() gives each integer column its own width
        // and unsigned() a UInt type: tinyInteger() is Int8, id() is UInt64.
        // False keeps the types of 3.0.0 (see https://laravel-clickhouse.oralunal.com/schema/schema-builder#integer-types).
        'exact_integer_types' => (bool) env('CLICKHOUSE_EXACT_INTEGER_TYPES', false),
        // The type of a data-skipping index that Schema::create() or
        // Schema::table() adds without one, such as the index of morphs(),
        // on a table of the MergeTree family: 'minmax' or 'bloom_filter', for
        // example. Null sends nothing for such an index, as 3.0.0 did
        // (see https://laravel-clickhouse.oralunal.com/schema/schema-builder#data-skipping-indexes).
        'default_index_type' => env('CLICKHOUSE_DEFAULT_INDEX_TYPE'),
        // 'microsecond' writes the sub-second part of the dates in conditions,
        // bindings and inserts, which DateTime64 columns keep; 'second' drops
        // it, as 3.0.0 did. Keep it a string for config:cache
        // (see https://laravel-clickhouse.oralunal.com/query-builder/dates).
        'datetime_precision' => env('CLICKHOUSE_DATETIME_PRECISION', 'second'),
        // The input format of the package's inserts: 'Values', as in 3.0.0,
        // or 'JSONEachRow'. A model's $insertFormat wins
        // (see https://laravel-clickhouse.oralunal.com/models/inserting-rows#jsoneachrow-inserts).
        'insert_format' => env('CLICKHOUSE_INSERT_FORMAT', 'Values'),
        // When true, delete(), update(), truncate() and optimize() send
        // ON CLUSTER '<cluster_name>', so that every node runs them. It needs
        // a cluster_name, and every such statement then waits for every host
        // (see https://laravel-clickhouse.oralunal.com/query-builder/writing-data#on-cluster).
        'use_on_cluster' => (bool) env('CLICKHOUSE_USE_ON_CLUSTER', false),
        // For a cluster, replace host and port with the nodes, of which the
        // connection talks to the first that answers, and name the cluster of
        // the server config (remote_servers) for ON CLUSTER. With a
        // cluster_name, the schema builder sends ON CLUSTER, and with the
        // nodes as well, it creates replicated tables (see https://laravel-clickhouse.oralunal.com/advanced/clusters).
        // 'cluster' => [
        //     ['host' => 'clickhouse01', 'port' => '8123'],
        //     ['host' => 'clickhouse02', 'port' => '8123'],
        // ],
        // 'cluster_name' => 'company_cluster',
    ],

    // Additional connections — uncomment or add your own.
    //
    // 'clickhouse2' => [
    //     'driver'          => 'clickhouse',
    //     'host'            => env('CLICKHOUSE2_HOST', '127.0.0.1'),
    //     'port'            => env('CLICKHOUSE2_PORT', '8123'),
    //     'database'        => env('CLICKHOUSE2_DATABASE', 'default'),
    //     'username'        => env('CLICKHOUSE2_USERNAME', 'default'),
    //     'password'        => env('CLICKHOUSE2_PASSWORD', ''),
    //     'timeout_connect' => 2,
    //     'timeout_query'   => 2,
    //     'https'           => false,
    //     'retries'         => 0,
    //     'retry_on'        => 'any',
    //     'fix_default_query_builder' => true,
    //     'use_lightweight_delete' => (bool) env('CLICKHOUSE2_USE_LIGHTWEIGHT_DELETE', false),
    //     'datetime_precision' => env('CLICKHOUSE2_DATETIME_PRECISION', 'second'),
    //     'insert_format' => env('CLICKHOUSE2_INSERT_FORMAT', 'Values'),
    //     // use_on_cluster needs a cluster_name.
    //     'use_on_cluster' => (bool) env('CLICKHOUSE2_USE_ON_CLUSTER', false),
    // ],

];
