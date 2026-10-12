<?php

declare(strict_types=1);

namespace Tests\Types\Data;

use Illuminate\Support\Collection;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;
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
    assertType('Illuminate\Support\Collection<int, array<string, mixed>>', $query->get());
    assertType('Illuminate\Support\Collection<int, array<string, mixed>>', $query->get(['id', 'name']));
    assertType('array<string, mixed>|null', $query->first());
    assertType('array<string, mixed>', $query->firstOrFail());
    assertType('array<string, mixed>', $query->sole());
    assertType('array<string, mixed>|null', $query->find(1));
    assertType('array<string, mixed>|null', $query->findOr(1, fn () => null));
    assertType('0|array<string, mixed>', $query->findOr(1, ['id'], fn (): int => 0));
    assertType('Illuminate\Support\LazyCollection<int, array<string, mixed>>', $query->cursor());
    assertType('Illuminate\Support\LazyCollection<int, array<string, mixed>>', $query->lazy());
    assertType('Illuminate\Support\LazyCollection<int, array<string, mixed>>', $query->lazyById());
    assertType('Illuminate\Support\LazyCollection<int, array<string, mixed>>', $query->lazyByIdDesc());
    assertType('Illuminate\Support\Collection<int, mixed>', $query->chunkMap(fn (array $row): mixed => $row['id']));
    assertType('Illuminate\Support\Collection<int, array<string, mixed>>', Example::query()->toBase()->get());
    assertType('array<string, mixed>|null', Example::query()->toBase()->first());

    // The rows of a model query, as the 3.x to 4.0 upgrade guide reads them.
    assertType('array<int, array<string, mixed>>', Example::select(['id'])->toBase()->get()->all());
}

function callbacks(QueryBuilder $query): void
{
    $query->chunk(100, function (Collection $rows): void {
        assertType('Illuminate\Support\Collection<int, array<string, mixed>>', $rows);
    });
    $query->chunkById(100, function (Collection $rows): void {
        assertType('Illuminate\Support\Collection<int, array<string, mixed>>', $rows);
    });
    $query->each(function (array $row): void {
        assertType('array<string, mixed>', $row);
    });
    $query->eachById(function (array $row): void {
        assertType('array<string, mixed>', $row);
    });
}
