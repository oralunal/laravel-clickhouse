<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

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
     * Statements go to every node, the same way migrations written with
     * Migration::write() do.
     *
     * @param string $path
     * @return void
     */
    public function load($path): void
    {
        foreach (preg_split('/;\s*$/m', $this->files->get($path)) as $statement) {
            if (($statement = trim($statement)) !== '') {
                $this->connection->getCluster()->write($statement);
            }
        }
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
