<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Console;

use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;

/**
 * The --database option of make:clickhouse-model and make:clickhouse-migration: the ClickHouse connection that the
 * generated class uses.
 */
final class ConnectionOption
{
    /**
     * Get the ClickHouse connection for a generated class: the connection of the option, else the default connection
     * when it is a ClickHouse connection, else the connection named clickhouse, else the first ClickHouse connection
     * of the config.
     *
     * @param array<array-key, mixed> $connections The connections of the config, database.connections
     * @param mixed $default The default connection, database.default
     * @param string|null $requested The value of the option
     * @return string
     * @throws InvalidArgumentException When the option names no ClickHouse connection, or the config has none
     */
    public static function resolve(array $connections, mixed $default, ?string $requested): string
    {
        $clickHouse = array_values(array_filter(
            array_map('strval', array_keys($connections)),
            static fn (string $name): bool => is_array($connections[$name] ?? null)
                && ($connections[$name]['driver'] ?? null) === 'clickhouse'
        ));

        if ($requested !== null && $requested !== '') {
            if (!in_array($requested, $clickHouse, true)) {
                throw new InvalidArgumentException(
                    "The connection [{$requested}] is not a ClickHouse connection of config/database.php."
                );
            }

            return $requested;
        }

        foreach ([is_string($default) ? $default : null, Connection::DEFAULT_NAME] as $candidate) {
            if ($candidate !== null && in_array($candidate, $clickHouse, true)) {
                return $candidate;
            }
        }

        return $clickHouse[0] ?? throw new InvalidArgumentException(
            'config/database.php has no ClickHouse connection, a connection with the clickhouse driver.'
        );
    }
}
