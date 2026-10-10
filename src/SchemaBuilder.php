<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Exception\DatabaseException;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as BaseBuilder;
use Oralunal\LaravelClickHouse\Exceptions\QueryException as ClickHouseQueryException;

class SchemaBuilder extends BaseBuilder
{
    /**
     * Determine if the given table exists.
     *
     * Views, materialized views and dictionaries are not tables, so
     * hasTable() is false for them, as on Laravel's other drivers; use
     * hasView() and hasDictionary(). A `<database>.<table>` name looks in that
     * database instead of the connection's, and gives false when that
     * database does not exist. A ClickHouse error, such as the connection's
     * database not existing, is rethrown as a QueryException; Laravel's
     * parallel testing relies on that to create the per-process test database.
     *
     * @param string $table
     * @return bool
     */
    public function hasTable($table): bool
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        return $this->selectsAnyRow($this->grammar->compileTableExists(
            $schema ?? $this->connection->getDatabaseName(),
            $this->connection->getTablePrefix() . $table
        ));
    }

    /**
     * Determine if the given dictionary exists.
     *
     * A `<database>.<dictionary>` name looks in that database instead of the
     * connection's, and gives false when that database does not exist. A
     * ClickHouse error, such as the connection's database not existing, is
     * rethrown as a QueryException.
     *
     * @param string $dictionary
     * @return bool
     */
    public function hasDictionary(string $dictionary): bool
    {
        [$schema, $dictionary] = $this->parseSchemaAndTable($dictionary);

        return $this->selectsAnyRow($this->grammar->compileDictionaryExists(
            $schema ?? $this->connection->getDatabaseName(),
            $this->connection->getTablePrefix() . $dictionary
        ));
    }

    /**
     * Run an existence query and determine if it returns a row, rethrowing a
     * ClickHouse error as a QueryException.
     *
     * @param string $sql
     * @return bool
     */
    protected function selectsAnyRow(string $sql): bool
    {
        try {
            return count($this->connection->select($sql)) > 0;
        } catch (DatabaseException $e) {
            throw new QueryException($this->connection->getName(), $sql, [], $e);
        }
    }

    /**
     * Determine if the connection's session has a temporary table of the
     * given name: EXISTS TEMPORARY TABLE <table>, with the table prefix, sent
     * through Connection::hasTemporaryTable(), so the query is logged.
     * Outside a session it is false and no query is sent; while the
     * connection pretends it is false.
     *
     * hasTable(), getTables() and getColumns() read the connection's
     * database, and do not see a temporary table.
     *
     * @param string $table The name of the temporary table, without a database
     * @return bool
     */
    public function hasTemporaryTable(string $table): bool
    {
        return $this->connection instanceof Connection
            && $this->connection->hasTemporaryTable($this->connection->getTablePrefix() . $table);
    }

    /**
     * Drop a temporary table of the connection's session: DROP TEMPORARY TABLE <table>.
     *
     * Unlike Schema::drop(), it never drops the database table of that name:
     * ClickHouse's DROP TABLE drops the temporary table when the session has
     * one, and the database table otherwise. ClickHouse throws when the
     * session has no such temporary table, and outside a session.
     *
     * @param string $table
     * @return void
     */
    public function dropTemporary(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function (Blueprint $blueprint): void {
            $blueprint->temporary();
            $blueprint->drop();
        }));
    }

    /**
     * Drop a temporary table of the connection's session if it exists:
     * DROP TEMPORARY TABLE IF EXISTS <table>. It never drops the database
     * table of that name (see dropTemporary()). Outside a session the
     * statement is still sent and logged, and ClickHouse treats it as a no-op.
     *
     * @param string $table
     * @return void
     */
    public function dropTemporaryIfExists(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function (Blueprint $blueprint): void {
            $blueprint->temporary();
            $blueprint->dropIfExists();
        }));
    }

    /**
     * Run the statements of a blueprint, as Laravel's schema builder does,
     * after refusing a temporary table that would not outlive its CREATE.
     *
     * ClickHouse drops a temporary table at the end of the query that
     * creates it, unless the query runs in a session. So Schema::create()
     * with $table->temporary() outside the connection's session() throws a
     * QueryException, and nothing of the blueprint is sent. A Blueprint built
     * by hand and run with its own build(), or a raw CREATE TEMPORARY TABLE,
     * is not checked. The other statements of a temporary() blueprint, such
     * as those of Schema::table(), are checked while they compile (see
     * SchemaGrammar::refuseATemporaryTableThatTheSessionLacks()).
     *
     * @param Blueprint $blueprint
     * @return void
     * @throws ClickHouseQueryException When the blueprint creates a temporary table outside a session
     */
    protected function build(Blueprint $blueprint): void
    {
        if ($blueprint->temporary
            && $blueprint->creating()
            && ! ($this->connection instanceof Connection && $this->connection->inSession())) {
            throw ClickHouseQueryException::cannotCreateTemporaryTableOutsideSession(
                $this->connection->getTablePrefix() . $blueprint->getTable()
            );
        }

        parent::build($blueprint);
    }

    /**
     * Create the blueprint that Schema::create(), table(), drop() and the
     * other table methods fill: a SchemaBlueprint, which adds the ClickHouse
     * table clauses and column types to Laravel's Blueprint. A resolver set
     * with blueprintResolver() still decides instead.
     *
     * @param string $table
     * @param Closure|null $callback
     * @return Blueprint
     */
    protected function createBlueprint($table, ?Closure $callback = null): Blueprint
    {
        if (isset($this->resolver)) {
            return parent::createBlueprint($table, $callback);
        }

        return Container::getInstance()->make(SchemaBlueprint::class, [
            'connection' => $this->connection,
            'table' => $table,
            'callback' => $callback,
        ]);
    }

    /**
     * Drop a table and wait until ClickHouse has removed its data: DROP TABLE <table> SYNC.
     *
     * Without SYNC, an Atomic database removes the data of a dropped table
     * only after database_atomic_delay_before_drop_table_sec (480 seconds by
     * default), and a replicated table with a fixed replica path cannot be
     * created again until then. On a connection with a cluster_name, the
     * statement goes ON CLUSTER, as Schema::drop() does:
     * DROP TABLE <table> ON CLUSTER '<cluster_name>' SYNC.
     *
     * @param string $table
     * @return void
     */
    public function dropSync(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function (Blueprint $blueprint): void {
            $blueprint->drop()->sync();
        }));
    }

    /**
     * Drop a table if it exists and wait until ClickHouse has removed its
     * data: DROP TABLE IF EXISTS <table> SYNC (see dropSync()).
     *
     * @param string $table
     * @return void
     */
    public function dropIfExistsSync(string $table): void
    {
        $this->build(tap($this->createBlueprint($table), function (Blueprint $blueprint): void {
            $blueprint->dropIfExists()->sync();
        }));
    }

    /**
     * Create a database on every host: CREATE DATABASE IF NOT EXISTS <name>,
     * with ON CLUSTER '<cluster_name>' on a connection with a cluster_name
     * (see statementOnEveryHost()).
     *
     * The statement is logged, and nothing is sent while the connection pretends.
     *
     * @param string $name
     * @return bool
     */
    public function createDatabase($name): bool
    {
        $this->statementOnEveryHost('CREATE DATABASE IF NOT EXISTS ' . SchemaGrammar::quoteIdentifier($name));

        return true;
    }

    /**
     * Drop a database on every host, if it exists: DROP DATABASE IF EXISTS <name> SYNC,
     * with ON CLUSTER '<cluster_name>' on a connection with a cluster_name
     * (see statementOnEveryHost()).
     *
     * The statement is logged, and nothing is sent while the connection pretends.
     *
     * @param string $name
     * @return bool
     */
    public function dropDatabaseIfExists($name): bool
    {
        $this->statementOnEveryHost('DROP DATABASE IF EXISTS ' . SchemaGrammar::quoteIdentifier($name), ' SYNC');

        return true;
    }

    /**
     * Run a statement on every host that the schema builder's statements
     * reach, and return true.
     *
     * On a connection with a cluster_name, the statement is sent once, to the
     * active node, with ON CLUSTER '<cluster_name>' between $statement and
     * $suffix, and ClickHouse runs it on every host of the cluster, as it runs
     * Schema::create(). Hosts that the connection does not list are reached
     * too, so that a database or table that the schema builder's
     * CREATE ... ON CLUSTER puts on them is created or dropped there as well.
     * Without a cluster_name, the statement goes to each node of the
     * connection through Connection::statementOnEveryNode(), as before.
     *
     * Either way the statement is logged once, and nothing is sent while the
     * connection pretends.
     *
     * @param string $statement The statement up to the place of ON CLUSTER, such as DROP TABLE IF EXISTS <table>
     * @param string $suffix The rest of the statement, such as ' SYNC'
     * @return bool
     */
    protected function statementOnEveryHost(string $statement, string $suffix = ''): bool
    {
        $cluster = $this->connection->getClusterName();

        if ($cluster === null) {
            return $this->connection->statementOnEveryNode($statement . $suffix);
        }

        return $this->connection->statement($statement . ' ON CLUSTER ' . $this->grammar->quoteString($cluster) . $suffix);
    }

    /**
     * Get the names of the current schemas for the connection: its database.
     *
     * @return string[]
     */
    public function getCurrentSchemaListing(): array
    {
        return [$this->connection->getDatabaseName()];
    }

    /**
     * Enable foreign key constraints.
     *
     * ClickHouse has no foreign keys, so there is nothing to enable and no
     * query is sent. Laravel calls this, for example, when DatabaseTruncation
     * runs the truncation inside withoutForeignKeyConstraints().
     *
     * @return bool
     */
    public function enableForeignKeyConstraints(): bool
    {
        return true;
    }

    /**
     * Disable foreign key constraints.
     *
     * ClickHouse has no foreign keys, so there is nothing to disable and no
     * query is sent. Laravel calls this, for example, when DatabaseTruncation
     * runs the truncation inside withoutForeignKeyConstraints().
     *
     * @return bool
     */
    public function disableForeignKeyConstraints(): bool
    {
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
     * Drop the matching objects on every host, dependents first.
     *
     * ClickHouse refuses to drop an object that another one still depends on,
     * such as a dictionary's source table or a dictionary used in a column
     * default. Each object therefore waits until everything in this database
     * that depends on it, materialized views included, has been dropped.
     *
     * The objects are those that the active node lists. Each one is dropped
     * with DROP {TABLE|DICTIONARY} IF EXISTS <database>.<name> SYNC, with
     * ON CLUSTER '<cluster_name>' on a connection with a cluster_name, so
     * that every host of the cluster drops it, as Schema::create() creates
     * it there; without a cluster_name, on each node of the connection (see
     * statementOnEveryHost()). Each DROP is logged, and nothing is sent while
     * the connection pretends.
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
            $this->statementOnEveryHost(
                "DROP {$kind} IF EXISTS " . SchemaGrammar::quoteIdentifier($database)
                . '.' . SchemaGrammar::quoteIdentifier($next),
                ' SYNC'
            );
            unset($pending[$next]);
        }
    }
}
