<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

/**
 * A ClickHouse table, by Schema::connection(), with the column types and modifiers of the package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('clickhouse')->create('metrics', function (SchemaBlueprint $table) {
            $table->ipv4('ip');
            $table->date32('day');
            $table->array('tags', 'String');
            $table->rawColumn('total', 'UInt64');
            $table->rawColumn('kind', "LowCardinality(Enum8('a' = 1, 'b' = 2))");
            $table->string('raw')->ephemeral();
            $table->unsignedInteger('doubled')->storedAs('length(raw) * 2');
            $table->string('title');
            $table->index('title', null, 'bloom_filter(0.01)')->granularity(4);
            $table->partitionBy('toYYYYMM(day)');
            $table->settings(['index_granularity' => 8192]);
        });
    }

    public function down(): void
    {
        Schema::connection('clickhouse')->dropIfExistsSync('metrics');
    }
};
