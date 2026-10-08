<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Keeps the ClickHouse connections that migrations write to in step with the
 * connection that holds the migrations table (the "primary" one).
 *
 * Laravel's schema:dump, migrate and migrate:fresh only act on the primary
 * connection. A ClickHouse connection counts as secondary when a migration in
 * the migrator's paths targets it, or when database/schema has a dump for it.
 * Connections that no migration or dump refers to are never touched.
 */
class SecondaryConnections
{
    public function __construct(
        protected Application $app,
        protected Filesystem $files,
    ) {
    }

    /**
     * Get the secondary ClickHouse connections of the given primary connection.
     *
     * @param string|null $primary Defaults to the default connection.
     * @return string[]
     */
    public function names(?string $primary = null): array
    {
        return $this->secondaryNames($primary, $this->migrationConnections());
    }

    /**
     * Dump every secondary connection next to a freshly dumped primary schema.
     *
     * Without these files, `schema:dump --prune` would delete the only record of
     * how to rebuild the secondary connections.
     *
     * @param SchemaDumped $event
     * @return void
     */
    public function dump(SchemaDumped $event): void
    {
        foreach ($this->names($event->connectionName) as $name) {
            /** @var Connection $connection */
            $connection = $this->app['db']->connection($name);
            $path = $this->schemaPath($name);
            $this->files->ensureDirectoryExists(dirname($path));

            $connection->getSchemaState()->withMigrationTable($this->migrationTable())->dump($connection, $path);
        }
    }

    /**
     * Rebuild every empty secondary connection after the primary schema was loaded.
     *
     * The loaded migrations table already marks the secondary connections'
     * migrations as run. An empty secondary connection is therefore restored from
     * its own dump, or, when it has none, its migrations are marked as pending
     * again so the migrator runs them. Connections that still hold objects are
     * left alone.
     *
     * @param SchemaLoaded $event
     * @return void
     */
    public function load(SchemaLoaded $event): void
    {
        $migrations = $this->migrationConnections();

        foreach ($this->secondaryNames($event->connectionName, $migrations) as $name) {
            /** @var Connection $connection */
            $connection = $this->app['db']->connection($name);

            if ($this->hasObjects($connection)) {
                continue;
            }

            if (is_file($path = $this->schemaPath($name))) {
                $connection->getSchemaState()->load($path);
                continue;
            }

            $repository = $this->app['migrator']->getRepository();
            foreach (array_intersect(array_keys($migrations, $name, true), $repository->getRan()) as $migration) {
                $repository->delete((object) ['migration' => $migration]);
            }
        }
    }

    /**
     * Drop every object on the given connection.
     *
     * @param string $name
     * @return void
     */
    public function wipe(string $name): void
    {
        $this->app['db']->connection($name)->getSchemaBuilder()->dropAllTables();
    }

    /**
     * @param string|null $primary
     * @param array<string, string|null> $migrations
     * @return string[]
     */
    protected function secondaryNames(?string $primary, array $migrations): array
    {
        $primary ??= $this->app['db']->getDefaultConnection();

        $dumped = array_map(
            fn (string $path): string => Str::beforeLast(basename($path), '-schema.sql'),
            $this->files->glob($this->app->databasePath('schema/*-schema.sql'))
        );

        $names = array_unique(array_filter(array_merge(array_values($migrations), $dumped)));

        return array_values(array_filter(
            $names,
            fn (string $name): bool => $name !== $primary
                && $this->app['config']->get("database.connections.{$name}.driver") === 'clickhouse'
        ));
    }

    /**
     * Get the connection every migration in the migrator's paths runs on.
     *
     * @return array<string, string|null> Connection names keyed by migration name; null means the primary.
     */
    protected function migrationConnections(): array
    {
        $migrator = $this->app['migrator'];
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [$this->app->databasePath('migrations')])
        );

        $connections = [];
        foreach ($files as $name => $file) {
            try {
                $migration = $this->resolveMigration($name, $file);
            } catch (Throwable) {
                // A file the migrator itself could not run says nothing about connections.
                continue;
            }
            $connections[$name] = method_exists($migration, 'getConnection') ? $migration->getConnection() : null;
        }

        return $connections;
    }

    /**
     * Resolve a migration instance from its file, as the migrator does.
     *
     * @param string $name
     * @param string $file
     * @return object
     */
    protected function resolveMigration(string $name, string $file): object
    {
        $class = Str::studly(implode('_', array_slice(explode('_', $name), 4)));

        if (class_exists($class) && realpath($file) === (new ReflectionClass($class))->getFileName()) {
            return new $class();
        }

        $migration = $this->files->getRequire($file);

        return is_object($migration) ? $migration : new $class();
    }

    /**
     * Determine if the connection's database holds any table, view or dictionary.
     *
     * @param Connection $connection
     * @return bool
     */
    protected function hasObjects(Connection $connection): bool
    {
        return (int) $connection->select(
            "SELECT count() AS objects FROM system.tables"
            . " WHERE database = :database AND is_temporary = 0 AND NOT startsWith(name, '.inner')",
            ['database' => $connection->getDatabaseName()]
        )[0]['objects'] > 0;
    }

    /**
     * Get the path of the connection's schema dump, where `migrate` looks for it.
     *
     * @param string $name
     * @return string
     */
    protected function schemaPath(string $name): string
    {
        return $this->app->databasePath("schema/{$name}-schema.sql");
    }

    /**
     * Get the migrations table name, as `schema:dump` reads it.
     *
     * @return string
     */
    protected function migrationTable(): string
    {
        $migrations = $this->app['config']->get('database.migrations', 'migrations');

        return is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations;
    }
}
