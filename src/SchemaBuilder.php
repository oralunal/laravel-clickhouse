<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Builder as BaseBuilder;

class SchemaBuilder extends BaseBuilder
{
    /**
     * Determine if the given table exists.
     *
     * A ClickHouse error, such as an unknown database, is rethrown as a
     * QueryException; Laravel's parallel testing relies on that to create the
     * per-process test database.
     *
     * @param string $table
     * @return bool
     */
    public function hasTable($table): bool
    {
        $sql = $this->grammar->compileTableExists($this->connection->getDatabaseName(), $table);

        try {
            return count($this->connection->select($sql)) > 0;
        } catch (DatabaseException $e) {
            throw new QueryException($this->connection->getName(), $sql, [], $e);
        }
    }

    /**
     * Create a database on every node.
     *
     * @param string $name
     * @return bool
     */
    public function createDatabase($name): bool
    {
        $this->connection->getCluster()->write('CREATE DATABASE IF NOT EXISTS ' . SchemaGrammar::quoteIdentifier($name));

        return true;
    }

    /**
     * Drop a database on every node, if it exists.
     *
     * @param string $name
     * @return bool
     */
    public function dropDatabaseIfExists($name): bool
    {
        $this->connection->getCluster()->write('DROP DATABASE IF EXISTS ' . SchemaGrammar::quoteIdentifier($name) . ' SYNC');

        return true;
    }

    /**
     * Drop every table, dictionary, view and materialized view in the connection's database.
     *
     * Views are included because the schema dump and the migrations recreate them,
     * which fails while they still exist.
     *
     * @return void
     */
    public function dropAllTables(): void
    {
        $this->dropObjects(fn (array $object): bool => true);
    }

    /**
     * Drop every view and materialized view in the connection's database.
     *
     * @return void
     */
    public function dropAllViews(): void
    {
        $this->dropObjects(fn (array $object): bool => str_ends_with($object['engine'], 'View'));
    }

    /**
     * Drop the matching objects on every node, dependents first.
     *
     * ClickHouse refuses to drop an object that another one still depends on,
     * such as a dictionary's source table or a dictionary used in a column
     * default. Each object therefore waits until everything in this database
     * that depends on it, materialized views included, has been dropped.
     *
     * @param callable(array{name: string, engine: string, dependents: string[]}): bool $filter
     * @return void
     */
    protected function dropObjects(callable $filter): void
    {
        $database = $this->connection->getDatabaseName();

        $pending = [];
        foreach ($this->connection->select(
            "SELECT name, engine, arrayConcat("
            . " arrayFilter((t, d) -> d = :database, loading_dependent_table, loading_dependent_database),"
            . " arrayFilter((t, d) -> d = :database, dependencies_table, dependencies_database)"
            . ") AS dependents"
            . " FROM system.tables"
            . " WHERE database = :database AND is_temporary = 0 AND NOT startsWith(name, '.inner')"
            . " ORDER BY endsWith(engine, 'View') DESC, name",
            ['database' => $database]
        ) as $object) {
            if ($filter($object)) {
                $pending[$object['name']] = $object;
            }
        }

        while ($pending) {
            $next = array_key_first($pending);
            foreach ($pending as $name => $object) {
                if (!array_intersect($object['dependents'], array_keys($pending))) {
                    $next = $name;
                    break;
                }
            }

            $kind = $pending[$next]['engine'] === 'Dictionary' ? 'DICTIONARY' : 'TABLE';
            $this->connection->getCluster()->write(
                "DROP {$kind} IF EXISTS " . SchemaGrammar::quoteIdentifier($database)
                . '.' . SchemaGrammar::quoteIdentifier($next) . ' SYNC'
            );
            unset($pending[$next]);
        }
    }
}
