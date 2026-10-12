<?php

declare(strict_types=1);

namespace Tests\Types\Data;

use Illuminate\Support\Collection;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;
use stdClass;
use Tests\Models\Example;

use function PHPStan\dumpType;
use function PHPStan\Testing\assertType;

function baseQueries(Connection $connection): void
{
    // RowTypesTest expects this one message: it shows that PHPStan analysed the file.
    dumpType($connection);

    assertType('Oralunal\LaravelClickHouse\QueryBuilder', Example::select(['id'])->toBase());
    assertType('Oralunal\LaravelClickHouse\QueryBuilder', Example::query()->getQuery());
    assertType('Oralunal\LaravelClickHouse\QueryBuilder', $connection->table('examples'));
    assertType('Oralunal\LaravelClickHouse\QueryBuilder', $connection->table('examples')->where('id', 1));
}

function rows(QueryBuilder $query): void
{
    assertType('Illuminate\Support\Collection<int, stdClass>', $query->get());
    assertType('Illuminate\Support\Collection<int, stdClass>', $query->get(['id', 'name']));
    assertType('stdClass|null', $query->first());
    assertType('stdClass', $query->firstOrFail());
    assertType('stdClass', $query->sole());
    assertType('Illuminate\Support\LazyCollection<int, stdClass>', $query->cursor());
    assertType('Illuminate\Support\LazyCollection<int, stdClass>', $query->lazy());
    assertType('Illuminate\Support\LazyCollection<int, stdClass>', $query->lazyById());
    assertType('Illuminate\Support\LazyCollection<int, stdClass>', $query->lazyByIdDesc());
    assertType('Illuminate\Support\Collection<int, stdClass>', Example::query()->toBase()->get());
    assertType('stdClass|null', Example::query()->toBase()->first());

    // The rows of a model query, as the 3.x to 4.0 upgrade guide reads them.
    assertType('array<int, stdClass>', Example::select(['id'])->toBase()->get()->all());
}

function callbacks(QueryBuilder $query): void
{
    $query->chunk(100, function (Collection $rows): void {
        assertType('Illuminate\Support\Collection<int, stdClass>', $rows);
    });
    $query->each(function (stdClass $row): void {
        assertType('stdClass', $row);
    });
}
