<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

use Illuminate\Database\Schema\Builder as BaseBuilder;

class SchemaBuilder extends BaseBuilder
{
    /** @inheritDoc */
    public function hasTable($table): bool
    {
        return count($this->connection->select(
                $this->grammar->compileTableExists($this->connection->getDatabaseName(), $table)
            )) > 0;
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
