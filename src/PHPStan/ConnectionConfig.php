<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Illuminate\Container\Container;
use Throwable;

/**
 * Reads the database config of the Laravel application that Larastan boots for the analysis: which connections are
 * ClickHouse connections, which one is the default, and which ones a read-only user uses. Without a booted
 * application, as without Larastan, no connection is a ClickHouse connection, so the extension changes no type.
 */
final class ConnectionConfig
{
    /**
     * Determine if the named connection, or the default connection for null, has the clickhouse driver.
     *
     * @param string|null $name
     * @return bool
     */
    public function isClickHouse(?string $name): bool
    {
        $name ??= $this->defaultConnection();

        return $name !== null && $this->get("database.connections.{$name}.driver") === 'clickhouse';
    }

    /**
     * Determine if the named connection uses a read-only user (its readonly option), who gets the values that
     * ResultValues reads without its settings.
     *
     * @param string $name
     * @return bool
     */
    public function isReadOnly(string $name): bool
    {
        return (bool) $this->get("database.connections.{$name}.readonly");
    }

    /**
     * @return string|null
     */
    private function defaultConnection(): ?string
    {
        $name = $this->get('database.default');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param string $key
     * @return mixed
     */
    private function get(string $key): mixed
    {
        try {
            $container = Container::getInstance();

            return $container->bound('config') ? $container->make('config')->get($key) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
