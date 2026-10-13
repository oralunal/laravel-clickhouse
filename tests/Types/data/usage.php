<?php

declare(strict_types=1);

namespace Tests\Types\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Tests\Types\Models\TypedEvent;

use function PHPStan\Testing\assertType;

/**
 * The blueprint of a ClickHouse table, as the documentation uses it: the column, index and drop modifiers of the
 * package are known.
 */
function schema(): void
{
    Schema::connection('clickhouse')->create('visits', function (SchemaBlueprint $table): void {
        assertType('Oralunal\LaravelClickHouse\SchemaColumnDefinition', $table->id());
        $table->string('name')->lowCardinality()->codec('ZSTD(3)');
        $table->dateTime('created_at', 3)->timezone('UTC')->ttl('created_at + INTERVAL 1 DAY');
        $table->unsignedInteger('raw')->ephemeral();
        assertType(
            'Oralunal\LaravelClickHouse\SchemaIndexDefinition',
            $table->index('name', null, 'bloom_filter(0.01)')->granularity(4)
        );
        assertType('Oralunal\LaravelClickHouse\SchemaIndexDefinition', $table->rawIndex('lower(name)', 'idx_lower'));
        $table->engine('MergeTree');
        $table->orderBy('id');
    });

    Schema::table('visits', function (SchemaBlueprint $table): void {
        $table->index('name', 'idx_name')->granularity(2)->materialize();
        $table->string('name')->change()->renameTo('title');
    });

    Schema::table('visits', function (SchemaBlueprint $table): void {
        assertType('Oralunal\LaravelClickHouse\SchemaDropCommand', $table->drop()->sync());
        assertType('Oralunal\LaravelClickHouse\SchemaDropCommand', $table->dropIfExists()->sync(false));
    });
}

/**
 * The queries of a ClickHouse model, with the ClickHouse methods of the query builder.
 */
function models(): void
{
    assertType(
        'Illuminate\Database\Eloquent\Collection<int, Tests\Types\Models\TypedEvent>',
        TypedEvent::query()->final()->where('counter', '>', 1)->get()
    );
    assertType(
        'Tests\Types\Models\TypedEvent|null',
        TypedEvent::where('counter', 1)->settings(['max_threads' => 2])->limitBy(1, 'name')->first()
    );
    assertType('int', TypedEvent::query()->forceDelete(false));
    assertType('int', TypedEvent::query()->update(['name' => 'x'], partition: 1));
    assertType('bool', TypedEvent::optimize(true));
}

/**
 * Parallel queries take the builders of any model.
 */
function parallel(): void
{
    $events = DB::connection('clickhouse')->table('events');

    assertType('array<int|string, mixed>', Parallel::getRows(['models' => TypedEvent::query(), 'rows' => $events]));
    assertType('array<int|string, ClickHouseDB\Statement>', Parallel::get([TypedEvent::query()->final(), $events]));
    assertType(
        'array<int|string, list<stdClass>>',
        DB::connection('clickhouse')->selectParallelly(['a' => 'SELECT 1', 'b' => ['sql' => 'SELECT ?', 'bindings' => [2]]])
    );
}

/**
 * Raw SQL and sessions.
 */
function connection(): void
{
    $clickhouse = DB::connection('clickhouse');

    assertType('list<stdClass>', $clickhouse->select('SELECT 1'));
    assertType('Generator<int, stdClass, mixed, mixed>', $clickhouse->cursor('SELECT 1'));
    assertType('int<0, max>', $clickhouse->session(fn (Connection $session): int => $session->table('events')->count()));
}
