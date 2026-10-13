<?php

declare(strict_types=1);

namespace Tests\Types\Data;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Types\Models\TypedEvent;
use Tests\Types\Models\TypedEventView;
use Tests\Types\Models\TypedUser;

use function PHPStan\Testing\assertType;

/**
 * The columns of a model, as the extension reads them from the ClickHouse schema dump.
 */
function columnsOfTheClickHouseDump(TypedEvent $event, TypedEventView $view): void
{
    assertType('mixed', $event->id);
    assertType('int', $event->counter);
    assertType('int<0, max>', $event->small);
    assertType('int', $event->signed);
    assertType('string', $event->name);
    assertType('string|null', $event->note);
    assertType('string', $event->code);
    assertType('string', $event->fixed);
    assertType('string', $event->price);
    assertType('float', $event->ratio);
    assertType('bool', $event->flag);
    assertType('string', $event->happened_at);
    assertType('string', $event->uuid);
    assertType("'done'|'new'", $event->status);
    assertType('mixed', $event->tags);
    assertType('mixed', $event->attributes);
    assertType('mixed', $event->big);
    assertType('mixed', $event->computed);
    assertType('string|null', $event->alias_col);
    assertType('string|null', $view->name);
    assertType('float', $view->ratio);
}

/**
 * Larastan's own parser still reads the other dumps.
 */
function columnsOfTheMySqlDump(TypedUser $user): void
{
    assertType('string', $user->name);
    assertType('string|null', $user->email_verified_at);
}

/**
 * The facades give the package's types for the ClickHouse connections of the config, and Laravel's types for the
 * other connections.
 */
function facades(): void
{
    assertType('Oralunal\LaravelClickHouse\Connection', DB::connection('clickhouse'));
    assertType('Oralunal\LaravelClickHouse\Connection', DB::connection());
    assertType('Illuminate\Database\Connection', DB::connection('sqlite'));
    assertType('Oralunal\LaravelClickHouse\QueryBuilder', DB::connection('clickhouse')->table('events')->final());
    assertType('Oralunal\LaravelClickHouse\QueryBuilder', DB::table('events'));
    assertType('Oralunal\LaravelClickHouse\SchemaBuilder', Schema::connection('clickhouse'));
    assertType('bool', Schema::connection('clickhouse')->hasDictionary('users_dict'));
    assertType('Oralunal\LaravelClickHouse\Connection', app('db')->connection('clickhouse'));
}
