<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Types\Models\TypedUser;

/**
 * A ClickHouse table, by the connection of the migration: Larastan reads its columns with the types of the package.
 */
return new class extends Migration
{
    protected $connection = 'clickhouse';

    public function up(): void
    {
        Schema::create('hits', function (Blueprint $table) {
            $table->id();
            $table->decimal('price', 10, 2);
            $table->unsignedBigInteger('big');
            $table->bigInteger('signed_big');
            $table->integer('counter', unsigned: true);
            $table->year('year');
            $table->float('ratio');
            $table->enum('level', ['low' => 1, 'high' => 10]);
            $table->string('name')->nullable();
            $table->timestampTz('at');
            $table->morphs('likeable');
            $table->foreignIdFor(TypedUser::class);
            $table->engine('ReplacingMergeTree');
            $table->orderBy('id');
            $table->comment('Hits');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hits');
    }
};
