<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\Schema\SchemaState as BaseSchemaState;

/**
 * Backs `php artisan schema:dump` and the schema loading step of `php artisan migrate`.
 *
 * The dump is built from SHOW CREATE TABLE over HTTP, so no clickhouse-client
 * binary is required. Qualifiers naming the connection's own database are
 * stripped, which lets a dump taken from one database be loaded into another.
 */
class SchemaState extends BaseSchemaState
{
    /**
     * The whitespace and comments that may come before a statement of a
     * dump: -- line comments and block comments.
     */
    protected const LEADING_COMMENTS = '(?:\s+|--[^\n]*(?:\n|\z)|\/\*.*?\*\/)*';

    /**
     * The name of an object in a statement of a dump, qualified by a
     * database or not: each part bare, or quoted with backticks or double
     * quotes, inside which a backslash escapes the next character.
     */
    protected const OBJECT_NAME = '(?:`(?:[^`\\\\]|\\\\.)*`|"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_]\w*)'
        . '(?:\s*\.\s*(?:`(?:[^`\\\\]|\\\\.)*`|"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_]\w*))?';

    /**
     * The connection instance.
     *
     * @var Connection
     */
    protected $connection;

    /**
     * Dump the database's schema into a file.
     *
     * @param BaseConnection $connection
     * @param string $path
     * @return void
     */
    public function dump(BaseConnection $connection, $path): void
    {
        $statements = array_map(
            fn (string $statement): string => $this->withoutDatabaseQualifier($statement),
            $this->createStatements()
        );

        if ($this->hasMigrationTable()) {
            $statements[] = $this->migrationDataStatement();
        }

        $this->files->put($path, implode(PHP_EOL . PHP_EOL, array_filter($statements)) . PHP_EOL);
    }

    /**
     * Load the given schema file into the database.
     *
     * Each INSERT INTO <table> VALUES statement, such as the rows of the
     * migrations table that dump() writes, is sent with
     * SETTINGS async_insert = 0 before VALUES (see withSynchronousInsert()),
     * so that its rows are written when load() returns: Laravel's migrate
     * reads the migrations table right after the load, and an asynchronous
     * insert, the default of ClickHouse 26.x, returns before the rows are
     * written when the connection's settings say wait_for_async_insert = 0,
     * so that migrate would run the migrations of the dump again. A dump
     * written by 3.0.0 is loaded the same way.
     *
     * Without a cluster_name, each statement goes to every node of the
     * connection, one after another, the same way migrations written with
     * Migration::write() do.
     *
     * On a connection with a cluster_name, each CREATE TABLE, VIEW,
     * MATERIALIZED VIEW or DICTIONARY statement gets
     * ON CLUSTER '<cluster_name>' right after the object's name (see
     * withOnCluster()) and goes once to the active node, so that ClickHouse
     * creates the object on every host of the cluster, as the schema builder
     * creates it on such a connection. The dump of a replicated table, such
     * as one that the schema builder made on a connection with a
     * cluster_name and cluster nodes, names the replica path
     * '/clickhouse/tables/{uuid}/{shard}', which ClickHouse only accepts in a
     * CREATE ... ON CLUSTER. An INSERT into a table that the dump creates
     * with a Replicated or Shared engine, such as the rows of a replicated
     * migrations table, goes once to the active node, and the table passes
     * the rows on to its other replicas. Any other statement goes to every
     * node of the connection, as without a cluster_name, so the rows of a
     * migrations table that is not replicated, as 3.0.0 or replicated(false)
     * made it, reach the table of each node.
     *
     * @param string $path
     * @return void
     */
    public function load($path): void
    {
        $statements = array_map(
            fn (string $statement): string => $this->withSynchronousInsert($statement),
            array_values(array_filter(
                array_map('trim', preg_split('/;\s*$/m', $this->files->get($path))),
                fn (string $statement): bool => $statement !== ''
            ))
        );

        $cluster = $this->connection->getClusterName();
        if ($cluster === null) {
            foreach ($statements as $statement) {
                $this->connection->getCluster()->write($statement);
            }

            return;
        }

        $replicatedTables = $this->replicatedTablesCreatedBy($statements);
        foreach ($statements as $statement) {
            $create = $this->withOnCluster($statement, $cluster);

            if ($create !== null) {
                $this->connection->getClient()->write($create);
            } elseif (in_array($this->insertTargetOf($statement), $replicatedTables, true)) {
                $this->connection->getClient()->write($statement);
            } else {
                $this->connection->getCluster()->write($statement);
            }
        }
    }

    /**
     * Add ON CLUSTER '<cluster>' to a CREATE TABLE, VIEW, MATERIALIZED VIEW
     * or DICTIONARY statement, right after the object's name and its UUID
     * clause, if any, with the cluster's name escaped as the schema grammar
     * escapes a string. The name may be qualified by a database and quoted
     * with backticks or double quotes, and comments may come before the
     * statement. A statement that already has an ON CLUSTER clause is
     * returned as it is.
     *
     * @param string $statement
     * @param string $cluster
     * @return string|null The statement with ON CLUSTER, or null when it is no such CREATE statement.
     */
    protected function withOnCluster(string $statement, string $cluster): ?string
    {
        $pattern = '/\A(' . self::LEADING_COMMENTS
            . 'CREATE\s+(?:OR\s+REPLACE\s+)?(?:TABLE|VIEW|MATERIALIZED\s+VIEW|DICTIONARY)\s+(?:IF\s+NOT\s+EXISTS\s+)?'
            . self::OBJECT_NAME . '(?:\s+UUID\s+\'(?:[^\'\\\\]|\\\\.)*\')?)(\s+ON\s+CLUSTER\b)?/is';

        if (preg_match($pattern, $statement, $matches) !== 1) {
            return null;
        }

        if (($matches[2] ?? '') !== '') {
            return $statement;
        }

        return $matches[1] . ' ON CLUSTER ' . (new SchemaGrammar($this->connection))->quoteString($cluster)
            . substr($statement, strlen($matches[1]));
    }

    /**
     * Add SETTINGS async_insert = 0 to an INSERT INTO <table> VALUES
     * statement, right before VALUES, so that ClickHouse writes the rows
     * before it answers, whatever the connection's async_insert and
     * wait_for_async_insert settings say. The table may be preceded by TABLE,
     * qualified by a database, quoted as OBJECT_NAME allows and followed by a
     * column list, and comments may come before the statement. Any other
     * statement, such as an INSERT with SETTINGS of its own, a FORMAT clause
     * or a SELECT, is returned as it is.
     *
     * @param string $statement
     * @return string
     */
    protected function withSynchronousInsert(string $statement): string
    {
        $pattern = '/\A(' . self::LEADING_COMMENTS . 'INSERT\s+INTO\s+(?:TABLE\s+)?' . self::OBJECT_NAME
            . '(?:\s*\([^()]*\))?)(\s+VALUES\b)/is';

        return preg_replace($pattern, '$1 SETTINGS async_insert = 0$2', $statement, 1) ?? $statement;
    }

    /**
     * Get the names of the tables that the statements create with a
     * Replicated or Shared engine of the MergeTree family, such as
     * ReplicatedMergeTree('/clickhouse/tables/{uuid}/{shard}', '{replica}'),
     * each without quotes (see unquoteObjectName()).
     *
     * @param list<string> $statements
     * @return list<string>
     */
    protected function replicatedTablesCreatedBy(array $statements): array
    {
        $pattern = '/\A' . self::LEADING_COMMENTS
            . 'CREATE\s+(?:OR\s+REPLACE\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(' . self::OBJECT_NAME . ')/is';

        $tables = [];
        foreach ($statements as $statement) {
            if (preg_match($pattern, $statement, $matches) === 1
                && preg_match('/\bENGINE\s*=\s*(?:Replicated|Shared)\w*MergeTree\b/i', $statement) === 1
            ) {
                $tables[] = $this->unquoteObjectName($matches[1]);
            }
        }

        return $tables;
    }

    /**
     * Get the table that an INSERT INTO statement writes to, without quotes
     * (see unquoteObjectName()).
     *
     * @param string $statement
     * @return string|null Null when the statement is no INSERT INTO.
     */
    protected function insertTargetOf(string $statement): ?string
    {
        $pattern = '/\A' . self::LEADING_COMMENTS . 'INSERT\s+INTO\s+(?:TABLE\s+)?(' . self::OBJECT_NAME . ')/is';

        return preg_match($pattern, $statement, $matches) === 1 ? $this->unquoteObjectName($matches[1]) : null;
    }

    /**
     * Write an object name, as OBJECT_NAME matches it, without its quotes and
     * escapes, its database and its name joined by a dot, so that
     * `migrations`, "migrations" and migrations are one name.
     *
     * @param string $name
     * @return string
     */
    protected function unquoteObjectName(string $name): string
    {
        preg_match_all('/`((?:[^`\\\\]|\\\\.)*)`|"((?:[^"\\\\]|\\\\.)*)"|([A-Za-z_]\w*)/s', $name, $parts, PREG_SET_ORDER);

        return implode('.', array_map(
            fn (array $part): string => ($part[3] ?? '') !== ''
                ? $part[3]
                : (string) preg_replace('/\\\\(.)/s', '$1', $part[1] !== '' ? $part[1] : ($part[2] ?? '')),
            $parts
        ));
    }

    /**
     * Get the CREATE statement of every table, view and dictionary, ordered so
     * that each one comes after the objects it references.
     *
     * @return array<string, string> Statements keyed by object name.
     */
    protected function createStatements(): array
    {
        $objects = $this->connection->select(
            "SELECT name, engine FROM system.tables"
            . " WHERE database = :database AND is_temporary = 0 AND NOT startsWith(name, '.inner')"
            . " ORDER BY name",
            ['database' => $this->connection->getDatabaseName()]
        );

        // Dictionaries first (a column default may call dictGet()), views last.
        usort(
            $objects,
            fn (array $a, array $b): int => $this->creationRank($a['engine']) <=> $this->creationRank($b['engine'])
        );

        $database = $this->quoteIdentifier($this->connection->getDatabaseName());
        $pending = [];
        foreach ($objects as $object) {
            $pending[$object['name']] = $this->connection->select(
                "SHOW CREATE TABLE {$database}." . $this->quoteIdentifier($object['name'])
            )[0]['statement'] . ';';
        }

        // A view can select from another view, which must exist first.
        $sorted = [];
        while ($pending) {
            $next = array_key_first($pending);
            foreach ($pending as $name => $statement) {
                if (!$this->referencesAny($statement, array_diff(array_keys($pending), [$name]))) {
                    $next = $name;
                    break;
                }
            }
            $sorted[$next] = $pending[$next];
            unset($pending[$next]);
        }

        return $sorted;
    }

    /**
     * Build an INSERT statement that restores the rows of the migrations table.
     *
     * @return string|null Null when the migrations table is empty.
     */
    protected function migrationDataStatement(): ?string
    {
        $table = $this->quoteIdentifier($this->getMigrationTable());

        $rows = array_column(
            $this->connection->select("SELECT formatRow('Values', *) AS row FROM {$table} ORDER BY batch, migration"),
            'row'
        );

        if ($rows === []) {
            return null;
        }

        return "INSERT INTO {$table} VALUES" . PHP_EOL . implode(',' . PHP_EOL, $rows) . ';';
    }

    /**
     * Determine the creation order group for the given table engine.
     *
     * @param string $engine
     * @return int
     */
    protected function creationRank(string $engine): int
    {
        return match (true) {
            $engine === 'Dictionary' => 0,
            str_ends_with($engine, 'View') => 2,
            default => 1,
        };
    }

    /**
     * Determine if the statement references any of the given objects of the
     * connection's database. ClickHouse always qualifies such references.
     *
     * @param string $statement
     * @param string[] $names
     * @return bool
     */
    protected function referencesAny(string $statement, array $names): bool
    {
        foreach ($names as $name) {
            $pattern = '/(?<![\w`])' . $this->identifierPattern($this->connection->getDatabaseName())
                . '\.' . $this->identifierPattern($name) . '(?![\w`])/';

            if (preg_match($pattern, $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the connection's database name from qualified identifiers.
     *
     * @param string $statement
     * @return string
     */
    protected function withoutDatabaseQualifier(string $statement): string
    {
        return preg_replace(
            '/(?<![\w`.])' . $this->identifierPattern($this->connection->getDatabaseName()) . '\.(?=[\w`])/',
            '',
            $statement
        );
    }

    /**
     * Get a regex matching the identifier either bare or wrapped in backticks.
     *
     * @param string $identifier
     * @return string
     */
    protected function identifierPattern(string $identifier): string
    {
        return '(?:' . preg_quote($this->quoteIdentifier($identifier), '/') . '|' . preg_quote($identifier, '/') . ')';
    }

    /**
     * Wrap the identifier in backticks.
     *
     * @param string $identifier
     * @return string
     */
    protected function quoteIdentifier(string $identifier): string
    {
        return SchemaGrammar::quoteIdentifier($identifier);
    }
}
