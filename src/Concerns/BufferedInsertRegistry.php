<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Concerns;

/**
 * The models that have buffered rows, shared by every copy of HasBufferedInserts.
 *
 * A model that uses HasBufferedInserts again, although BaseModel already does, gets its own copy of the trait's
 * static properties, which BaseModel's copy cannot see. This registry is a single list for all of them, so that
 * BaseModel::flushAllBuffers(), the automatic flush at the end of a request or script, and flushAllBuffers() called
 * on any model reach every model with buffered rows. Each model keeps its rows in its own copy of the trait.
 *
 * @internal Used by HasBufferedInserts only.
 */
final class BufferedInsertRegistry
{
    /**
     * The models that have buffered rows, in the order in which they first buffered a row.
     *
     * @var array<class-string, true>
     */
    private static array $models = [];

    /**
     * Register a model that has buffered rows. A model that is registered already keeps its place.
     *
     * @param class-string $model
     * @return void
     */
    public static function add(string $model): void
    {
        self::$models[$model] = true;
    }

    /**
     * Remove a model from the registry, once its rows are sent or discarded.
     *
     * @param class-string $model
     * @return void
     */
    public static function remove(string $model): void
    {
        unset(self::$models[$model]);
    }

    /**
     * Get the models that have buffered rows, in the order in which they first buffered a row.
     *
     * @return list<class-string>
     */
    public static function all(): array
    {
        return array_keys(self::$models);
    }
}
