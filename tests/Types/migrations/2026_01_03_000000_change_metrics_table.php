<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;

/**
 * A change of a ClickHouse table: the renamed and the dropped columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metrics', function (SchemaBlueprint $table) {
            $table->string('title')->nullable()->change()->renameTo('heading');
            $table->dropColumn('day');
        });
    }
};
