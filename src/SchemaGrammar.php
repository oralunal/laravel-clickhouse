<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression as BuilderExpression;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use RuntimeException;
use Stringable;
use Traversable;
use UnitEnum;
use WeakMap;

class SchemaGrammar extends BaseGrammar
{
    /**
     * The column modifiers, in the order ClickHouse reads them after the type:
     * one default expression (DEFAULT, MATERIALIZED, ALIAS or EPHEMERAL), then
     * COMMENT, CODEC and TTL.
     *
     * @var string[]
     */
    protected $modifiers = ['Default', 'StoredAs', 'VirtualAs', 'Ephemeral', 'Comment', 'Codec', 'Ttl'];

    /**
     * Whether the table of each blueprint is a temporary table of the
     * connection's session, as isTemporaryTableOfTheSession() found it.
     *
     * @var WeakMap<Blueprint, bool>|null
     */
    protected ?WeakMap $temporaryTablesOfTheSession = null;

    /**
     * Wrap a ClickHouse identifier (database, table or column name) in backticks.
     *
     * @param string $identifier
     * @return string
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $identifier) . '`';
    }

    /**
     * Quote a value as a ClickHouse string literal, escaping backslashes and single quotes.
     *
     * An array becomes a comma-separated list of string literals.
     *
     * @param string|string[] $value
     * @return string
     */
    public function quoteString($value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'quoteString'], $value));
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value) . "'";
    }

    /**
     * Wrap a column name in backticks.
     *
     * A string is one identifier, dots included, so that the Nested column n.a
     * stays the column `n.a`; Laravel's wrap() would split it at the dot. A
     * column definition is wrapped by its name, and an expression is written
     * as given.
     *
     * @param Fluent|Expression|string $value
     * @return string
     */
    public function wrap($value): string
    {
        if ($value instanceof Fluent) {
            $value = $value->name;
        }

        if ($this->isExpression($value)) {
            return (string) $this->getValue($value);
        }

        return $this->wrapValue((string) $value);
    }

    /**
     * Wrap one identifier in backticks, as quoteIdentifier() does. wrapTable()
     * calls it for each part of a `<database>.<table>` name.
     *
     * @param string $value
     * @return string
     */
    protected function wrapValue($value): string
    {
        return $value === '*' ? $value : static::quoteIdentifier((string) $value);
    }

    /**
     * Compile the query to determine if the given table exists.
     *
     * Like compileTables(), it leaves out views, materialized views with their
     * inner tables, dictionaries and temporary tables, so hasTable() is false
     * for them, as it is on Laravel's other drivers.
     *
     * @param string|null $schema Database name; null means the connection's database.
     * @param string $table
     * @return string
     */
    public function compileTableExists($schema, $table): string
    {
        return 'select name from system.tables where database = '
            . $this->quoteString($schema ?? $this->connection->getDatabaseName())
            . ' and name = ' . $this->quoteString($table)
            . ' and ' . $this->compileTableFilter();
    }

    /**
     * Compile the query to determine if the given dictionary exists.
     *
     * @param string|null $schema Database name; null means the connection's database.
     * @param string $dictionary
     * @return string
     */
    public function compileDictionaryExists(?string $schema, string $dictionary): string
    {
        return 'select name from system.tables where database = '
            . $this->quoteString($schema ?? $this->connection->getDatabaseName())
            . ' and name = ' . $this->quoteString($dictionary)
            . " and engine = 'Dictionary'";
    }

    /**
     * Compile the query to determine the tables.
     *
     * Views, materialized views with their inner tables, dictionaries and
     * temporary tables are left out. The size of a Merge table is NULL: ClickHouse
     * reports the bytes of its source tables, which db:show would count twice.
     *
     * @param string|string[]|null $schema Database name(s); null means the connection's database.
     * @return string
     */
    public function compileTables($schema): string
    {
        return "select name, database as schema, if(engine = 'Merge', NULL, total_bytes) as size,"
            . " nullIf(comment, '') as comment, engine"
            . ' from system.tables where ' . $this->compileSchemaWhereClause($schema, 'database')
            . ' and ' . $this->compileTableFilter()
            . ' order by database, name';
    }

    /**
     * Compile the query to determine the views and materialized views.
     *
     * @param string|string[]|null $schema Database name(s); null means the connection's database.
     * @return string
     */
    public function compileViews($schema): string
    {
        return 'select name, database as schema, as_select as definition'
            . ' from system.tables where ' . $this->compileSchemaWhereClause($schema, 'database')
            . " and endsWith(engine, 'View')"
            . ' order by database, name';
    }

    /**
     * Compile the query to determine the columns, in table order.
     *
     * QueryProcessor::processColumns() turns the rows into Laravel's column arrays.
     *
     * @param string|null $schema Database name; null means the connection's database.
     * @param string $table
     * @return string
     */
    public function compileColumns($schema, $table): string
    {
        return 'select name, type, default_kind, default_expression, comment from system.columns'
            . ' where database = ' . $this->quoteString($schema ?? $this->connection->getDatabaseName())
            . ' and table = ' . $this->quoteString($table)
            . ' order by position';
    }

    /**
     * Compile the query to determine the indexes: the primary key first, if
     * the table has one, then the data-skipping indices in table order.
     *
     * QueryProcessor::processIndexes() turns the rows into Laravel's index arrays.
     *
     * @param string|null $schema Database name; null means the connection's database.
     * @param string $table
     * @return string
     */
    public function compileIndexes($schema, $table): string
    {
        $databaseLiteral = $this->quoteString($schema ?? $this->connection->getDatabaseName());
        $tableLiteral = $this->quoteString($table);

        return 'select index_name as name, expression, index_type as type, is_primary from ('
            . "select 'primary' as index_name, primary_key as expression, 'primary' as index_type,"
            . ' 1 as is_primary, 0 as position'
            . " from system.tables where database = {$databaseLiteral} and name = {$tableLiteral} and primary_key != ''"
            . ' union all'
            . ' select name, expr, type, 0, rowNumberInAllBlocks() + 1'
            . " from system.data_skipping_indices where database = {$databaseLiteral} and table = {$tableLiteral}"
            . ') order by is_primary desc, position';
    }

    /**
     * Compile the query to determine the foreign keys.
     *
     * ClickHouse has no foreign keys, so the query returns no rows.
     *
     * @param string|null $schema
     * @param string $table
     * @return string
     */
    public function compileForeignKeys($schema, $table): string
    {
        return 'select 1 where 0';
    }

    /**
     * Compile the condition that limits a system table query to the given databases.
     *
     * @param string|string[]|null $schema Database name(s); null or an empty list means the connection's database.
     * @param string $column
     * @return string
     */
    protected function compileSchemaWhereClause(string|array|null $schema, string $column): string
    {
        if ($schema === null || $schema === [] || $schema === '') {
            $schema = $this->connection->getDatabaseName();
        }

        return is_array($schema)
            ? "{$column} in (" . $this->quoteString(array_values($schema)) . ')'
            : "{$column} = " . $this->quoteString($schema);
    }

    /**
     * Compile the condition that keeps only the tables among the rows of
     * system.tables: no temporary table, no inner table of a materialized
     * view, no view or materialized view and no dictionary.
     *
     * @return string
     */
    protected function compileTableFilter(): string
    {
        return "is_temporary = 0 and not startsWith(name, '.inner')"
            . " and not endsWith(engine, 'View') and engine != 'Dictionary'";
    }

    /**
     * Compile a create table command.
     *
     * The statement has this layout; the clauses in brackets come from the
     * blueprint (see SchemaBlueprint) and are left out when it does not set them:
     *
     *     CREATE TABLE [IF NOT EXISTS] <table> (<columns>[, INDEX <name> <expression> TYPE <type> [GRANULARITY <n>]])
     *     ENGINE = <engine> [PARTITION BY <expression>] [PRIMARY KEY (<columns>)] ORDER BY (<columns>)
     *     [SAMPLE BY <expression>] [TTL <expression>] [SETTINGS <name>=<value>, ...] [COMMENT '<text>']
     *
     * The engine is the blueprint's engine(), else the connection's `engine`
     * option, else MergeTree(). For an engine of the MergeTree family, the
     * sorting key is orderBy(), with primary() as the PRIMARY KEY when both
     * are given, else primary(), else the first column, as in 3.0.0. When the
     * first column cannot be in a sorting key (see canBeInSortingKey()), a
     * MergeTree, ReplicatedMergeTree or SharedMergeTree table gets ORDER BY
     * tuple(), which means no sorting key, and any other engine of the family
     * throws (see getDefaultSortingKey()). For EmbeddedRocksDB, KeeperMap, Redis and
     * MaterializedPostgreSQL, whose key is the PRIMARY KEY, primary() gives
     * PRIMARY KEY (<columns>). For other engines, primary() and the first
     * column add no key. The clauses set by name (orderBy(), partitionBy(),
     * sampleBy(), ttl(), settings() and comment()) are written for every
     * engine, so that the server accepts or refuses them.
     *
     * An index with a type, such as index('url', null, 'bloom_filter(0.01)'),
     * goes into the column list. An index without a type gets the type of the
     * connection's default_index_type option when the engine belongs to the
     * MergeTree family; otherwise it sends nothing, as in 3.0.0 (see
     * compileCreateIndexes()). The commands that the statement takes in are
     * marked to be skipped, so that none of them compiles to a statement of
     * its own.
     *
     * On a connection with a cluster_name, the statement is CREATE TABLE
     * [IF NOT EXISTS] <table> ON CLUSTER '<cluster_name>' (...), which
     * ClickHouse runs on every host of the cluster, unless the blueprint says
     * withoutOnCluster(). When the connection also lists its nodes in the
     * cluster option, an engine of the MergeTree family becomes replicated,
     * unless the blueprint says replicated(false) (see replicatesTable() and
     * getReplicatedEngine()): MergeTree() gives ReplicatedMergeTree(), with
     * the server's default replica path, and the table gets
     * SETTINGS replicated_deduplication_window=0, so that it keeps every
     * insert as a MergeTree table does (see getReplicatedTableSettings()).
     *
     * Inside a session, the CREATE is not checked against the temporary
     * tables of the session: with or without ON CLUSTER, ClickHouse (24.8
     * checked) creates the table of the database, also when a temporary table
     * of the session has its name.
     *
     * A temporary() table follows its own rules: CREATE TEMPORARY TABLE
     * [IF NOT EXISTS] <table> (...), without ON CLUSTER, which ClickHouse
     * refuses for a temporary table, and with the blueprint's engine() only,
     * never the connection's engine option or a replicated engine. Without
     * engine() there is no ENGINE clause, and the server's
     * default_temporary_table_engine applies (Memory). The other clauses are
     * written as for a table of that engine, so that a temporary table with
     * engine('MergeTree()') gets its sorting key, and one without an engine
     * that names orderBy() or partitionBy() is refused by the server. The
     * table lives as long as the session that creates it: SchemaBuilder
     * refuses to create one outside a session.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command): string
    {
        if ($blueprint->temporary) {
            $engine = $this->getTemporaryTableEngine($blueprint);
            $defaultSettings = [];
        } else {
            $givenEngine = $this->getTableEngine($blueprint);
            $engine = $this->getReplicatedEngine($blueprint, $givenEngine);
            $defaultSettings = $this->getReplicatedTableSettings($givenEngine, $engine);
        }

        $elements = [...$this->getColumns($blueprint), ...$this->compileCreateIndexes($blueprint, (string) $engine)];

        $clauses = [
            $blueprint->temporary ? 'CREATE TEMPORARY TABLE' : 'CREATE TABLE',
            ...($blueprint instanceof SchemaBlueprint && $blueprint->ifNotExists ? ['IF NOT EXISTS'] : []),
            $this->wrapTable($blueprint) . $this->compileOnClusterClause($blueprint),
            '(' . implode(', ', $elements) . ')',
            $engine === null ? null : 'ENGINE = ' . $engine,
            $this->compileTableExpressionClause($blueprint, 'partitionBy', 'PARTITION BY'),
            ...$this->compileTableKeys($blueprint, (string) $engine),
            $this->compileTableExpressionClause($blueprint, 'sampleBy', 'SAMPLE BY'),
            $this->compileTableExpressionClause($blueprint, 'ttl', 'TTL'),
            $this->compileTableSettings($blueprint, $defaultSettings),
            $this->compileTableCommentClause($blueprint),
        ];

        return implode(' ', array_filter($clauses, fn (?string $clause): bool => $clause !== null && $clause !== ''));
    }

    /**
     * Compile a drop table command: DROP TABLE <table>, with
     * ON CLUSTER '<cluster_name>' on a connection with a cluster_name (see
     * compileOnCluster()) and SYNC after drop()->sync() (see compileDropSync()).
     *
     * For a temporary() blueprint, as Schema::dropTemporary() makes, it is
     * DROP TEMPORARY TABLE <table>, which drops only a temporary table of the
     * session: ClickHouse's DROP TABLE drops the temporary table of that name
     * when there is one, and the database table otherwise.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     * @throws QueryException Inside a session, when an ON CLUSTER statement would miss a temporary table of the
     *                        session (see refuseATemporaryTableThatOnClusterMisses())
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropTable($blueprint) . ' ' . $this->wrapTable($blueprint)
            . $this->compileOnCluster($blueprint, 'DROP TABLE') . $this->compileDropSync($command);
    }

    /**
     * Compile a drop table (if exists) command: DROP TABLE IF EXISTS <table>,
     * with ON CLUSTER and SYNC as compileDrop() writes them, or
     * DROP TEMPORARY TABLE IF EXISTS <table> for a temporary() blueprint, as
     * Schema::dropTemporaryIfExists() makes.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     * @throws QueryException Inside a session, when an ON CLUSTER statement would miss a temporary table of the
     *                        session (see refuseATemporaryTableThatOnClusterMisses())
     */
    public function compileDropIfExists(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileDropTable($blueprint) . ' IF EXISTS ' . $this->wrapTable($blueprint)
            . $this->compileOnCluster($blueprint, 'DROP TABLE') . $this->compileDropSync($command);
    }

    /**
     * Compile the start of a DROP of the blueprint's table: DROP TABLE, or
     * DROP TEMPORARY TABLE for a temporary() blueprint.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function compileDropTable(Blueprint $blueprint): string
    {
        return $blueprint->temporary ? 'DROP TEMPORARY TABLE' : 'DROP TABLE';
    }

    /**
     * Compile the query that determines if the session has a temporary table
     * of the given name: EXISTS TEMPORARY TABLE <table>, which returns 1 or 0.
     * Outside a session it returns 0.
     *
     * @param string $table The name of the temporary table, with any table prefix and without a database
     * @return string
     */
    public function compileTemporaryTableExists(string $table): string
    {
        return 'EXISTS TEMPORARY TABLE ' . static::quoteIdentifier($table);
    }

    /**
     * Compile the SYNC modifier of a DROP: ' SYNC' when sync() was called on
     * the drop command, or nothing.
     *
     * On an Atomic database, ClickHouse removes the data of a dropped table
     * only after database_atomic_delay_before_drop_table_sec (480 seconds by
     * default). Until then a replicated table with a fixed replica path, such
     * as one that createMergeTree() creates on a cluster, cannot be created
     * again (REPLICA_ALREADY_EXISTS). SYNC waits until the data and the
     * replica's metadata are gone.
     *
     * @param Fluent $command
     * @return string
     */
    protected function compileDropSync(Fluent $command): string
    {
        return $command->sync ? ' SYNC' : '';
    }

    /**
     * Compile the start of an ALTER statement of the blueprint's table:
     * ALTER TABLE <table>, followed by ON CLUSTER '<cluster_name>' on a
     * connection with a cluster_name (see compileOnCluster()). Every
     * statement of Schema::table() starts with it, the separate statements
     * of change() included.
     *
     * A temporary() blueprint that does not create the table changes the
     * session's temporary table of that name, without ON CLUSTER, and throws
     * when the session has none (see refuseATemporaryTableThatTheSessionLacks()).
     *
     * @param Blueprint $blueprint
     * @return string
     * @throws QueryException Inside a session, when an ON CLUSTER statement would miss a temporary table of the
     *                        session (see refuseATemporaryTableThatOnClusterMisses()); for a temporary() blueprint,
     *                        when the session has no temporary table of that name, or outside a session
     */
    protected function compileAlterTable(Blueprint $blueprint): string
    {
        $this->refuseATemporaryTableThatTheSessionLacks($blueprint, 'ALTER TABLE');

        return 'ALTER TABLE ' . $this->wrapTable($blueprint) . $this->compileOnCluster($blueprint, 'ALTER TABLE');
    }

    /**
     * Compile the ON CLUSTER clause of a statement on the blueprint's table,
     * as compileOnClusterClause() does, after refusing, inside a session, a
     * table whose name is that of a temporary table of the session.
     *
     * ClickHouse runs an ON CLUSTER statement outside the session, so the
     * statement misses a temporary table of the session that has the table's
     * name: DROP TABLE and RENAME TABLE change the database table of that
     * name on every host instead (24.8 checked). Such a statement throws
     * before anything of the blueprint is sent (see
     * refuseATemporaryTableThatOnClusterMisses()). Without ON CLUSTER there
     * is nothing to check.
     *
     * @param Blueprint $blueprint
     * @param string $statement The kind of statement, such as 'DROP TABLE', for the exception message.
     * @return string
     * @throws QueryException Inside a session, when the table's name is that of a temporary table of the session
     */
    protected function compileOnCluster(Blueprint $blueprint, string $statement): string
    {
        $clause = $this->compileOnClusterClause($blueprint);

        if ($clause !== '') {
            $this->refuseATemporaryTableThatOnClusterMisses($blueprint, "{$statement} ... ON CLUSTER");
        }

        return $clause;
    }

    /**
     * Compile the ON CLUSTER clause of a statement on the blueprint's table,
     * with a space in front: ON CLUSTER '<cluster_name>', the connection's
     * cluster_name as an escaped string literal; or nothing when the
     * statements of the blueprint do not go ON CLUSTER (see
     * getOnClusterName()). ClickHouse then runs the statement on every host
     * of the cluster, with an unqualified table name in the database of the
     * connection.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function compileOnClusterClause(Blueprint $blueprint): string
    {
        $cluster = $this->getOnClusterName($blueprint);

        return $cluster === null ? '' : ' ON CLUSTER ' . $this->quoteString($cluster);
    }

    /**
     * Get the cluster that the statements of the blueprint go ON CLUSTER to:
     * the connection's cluster_name (see Connection::getClusterName()), or
     * null for a temporary() blueprint, for a SchemaBlueprint that calls
     * withoutOnCluster(), on a connection without a cluster_name, and on a
     * connection of another driver.
     *
     * Without a cluster_name, the statements run on the active node only, as
     * in 3.0.0, also on a connection that lists several nodes in its cluster
     * option.
     *
     * @param Blueprint $blueprint
     * @return string|null
     */
    protected function getOnClusterName(Blueprint $blueprint): ?string
    {
        if ($blueprint->temporary
            || ($blueprint instanceof SchemaBlueprint && ! $blueprint->onCluster)
            || ! $this->connection instanceof Connection) {
            return null;
        }

        return $this->connection->getClusterName();
    }

    /**
     * Refuse a statement with ON CLUSTER on a table whose name is that of a
     * temporary table of the connection's session (see
     * isTemporaryTableOfTheSession()), by throwing before anything of the
     * blueprint is sent.
     *
     * ClickHouse runs an ON CLUSTER statement outside the session: on 24.8,
     * DROP TABLE ... ON CLUSTER and RENAME TABLE ... ON CLUSTER changed the
     * database table of that name on every host, and left the temporary
     * table as it was, and ALTER TABLE ... ON CLUSTER failed with UNKNOWN_TABLE.
     * A temporary() blueprint compiles without ON CLUSTER and reaches the
     * temporary table, as Schema::dropTemporary() does. ClickHouse cannot
     * rename a temporary table, so for RENAME TABLE the message only points
     * outside the session.
     *
     * @param Blueprint $blueprint
     * @param string $statement The refused statement, such as 'DROP TABLE ... ON CLUSTER'.
     * @return void
     * @throws QueryException When the table's name is that of a temporary table of the session
     */
    protected function refuseATemporaryTableThatOnClusterMisses(Blueprint $blueprint, string $statement): void
    {
        if (! $this->isTemporaryTableOfTheSession($blueprint)) {
            return;
        }

        throw QueryException::cannotReachTemporaryTable(
            $statement,
            $this->connection->getTablePrefix() . $blueprint->getTable(),
            str_starts_with($statement, 'RENAME TABLE')
                ? 'ClickHouse cannot rename a temporary table. Run the statement outside session() to rename the'
                    . ' table of that name on every host.'
                : 'Call $table->temporary() in the blueprint, or Schema::dropTemporary() or dropTemporaryIfExists(),'
                    . ' to change or drop the temporary table without ON CLUSTER, or run the statement outside'
                    . ' session() to change the table of that name on every host.'
        );
    }

    /**
     * Refuse a statement of a temporary() blueprint that would change the
     * table of the database instead of a temporary table of the session, by
     * throwing before anything of the blueprint is sent.
     *
     * A temporary() blueprint compiles without ON CLUSTER. An ALTER TABLE of
     * it changes the session's temporary table of that name; when the
     * session has none, or outside a session, ClickHouse would change the
     * table of that name in the database, on the active node only, and leave
     * the other hosts of a cluster as they were. ClickHouse (24.8 checked)
     * cannot rename a temporary table: RENAME TABLE always renames the table
     * of the database, so it is refused for every temporary() blueprint.
     *
     * A blueprint that creates the table is not checked: its CREATE
     * TEMPORARY TABLE runs first (see SchemaBuilder::build()). DROP TEMPORARY
     * TABLE only ever drops a temporary table, so drops are not checked
     * either. The session is read once per blueprint (see
     * isTemporaryTableOfTheSession()).
     *
     * @param Blueprint $blueprint
     * @param string $statement The statement, such as 'ALTER TABLE' or 'RENAME TABLE'.
     * @return void
     * @throws QueryException When the statement would miss the temporary table
     */
    protected function refuseATemporaryTableThatTheSessionLacks(Blueprint $blueprint, string $statement): void
    {
        if (! $blueprint->temporary || $blueprint->creating()) {
            return;
        }

        $table = $this->connection->getTablePrefix() . $blueprint->getTable();
        $alternative = 'Leave out $table->temporary() to change the table of the database.';

        if ($statement === 'RENAME TABLE') {
            throw new QueryException(
                "Cannot rename the temporary table {$table}: ClickHouse (24.8 checked) cannot rename a temporary"
                . " table, and RENAME TABLE would rename the table {$table} of the database, on the active node"
                . " only. {$alternative}"
            );
        }

        if (! $this->connection instanceof Connection || ! $this->connection->inSession()) {
            throw new QueryException(
                "Cannot send {$statement} for the temporary table {$table} outside a session: a temporary table"
                . " only exists in the session that creates it, and ClickHouse would change the table {$table} of"
                . ' the database instead, on the active node only. Change the temporary table inside the'
                . " connection's session() callback that creates it. {$alternative}"
            );
        }

        if (! $this->isTemporaryTableOfTheSession($blueprint)) {
            throw new QueryException(
                "Cannot send {$statement} for the temporary table {$table}: the session has no temporary table"
                . " named {$table}, so ClickHouse would change the table {$table} of the database instead, on the"
                . ' active node only. Create the temporary table first, with $table->temporary() in'
                . " Schema::create(). {$alternative}"
            );
        }
    }

    /**
     * Determine if the blueprint's table is a temporary table of the
     * connection's session: the connection is in a session (see
     * Connection::inSession()), the table's name has no database, and
     * EXISTS TEMPORARY TABLE <table> returns 1 (see
     * compileTemporaryTableExists()). Outside a session, or for a
     * `<database>.<table>` name, it is false without a query.
     *
     * The query runs on the active node, which is the node of the session,
     * also while the connection pretends, and is not logged (see
     * selectWhilePretending()). Its answer is kept for the blueprint, so that
     * the statements of one blueprint send it once.
     *
     * @param Blueprint $blueprint
     * @return bool
     */
    protected function isTemporaryTableOfTheSession(Blueprint $blueprint): bool
    {
        if (! $this->connection instanceof Connection
            || str_contains($blueprint->getTable(), '.')
            || ! $this->connection->inSession()) {
            return false;
        }

        $this->temporaryTablesOfTheSession ??= new WeakMap();

        if (! isset($this->temporaryTablesOfTheSession[$blueprint])) {
            $row = $this->selectWhilePretending(
                $this->compileTemporaryTableExists($this->connection->getTablePrefix() . $blueprint->getTable())
            )[0] ?? [];

            $this->temporaryTablesOfTheSession[$blueprint] = (int) (reset($row) ?: 0) === 1;
        }

        return $this->temporaryTablesOfTheSession[$blueprint];
    }

    /**
     * Compile the columns that Schema::table() adds into one statement:
     * ALTER TABLE <table> ADD COLUMN <definition> [FIRST|AFTER <column>], ...
     *
     * The added columns make up groups (see getAddCommandGroups()): the
     * first add command of a group compiles every column of it, in the order
     * in which they were defined, at its own position among the statements
     * of the blueprint, and the others compile to nothing. A dropColumn(),
     * renameColumn() or change() between two added columns starts a new
     * group, so that the column added after it is added after it runs:
     * dropColumn('amount') followed by decimal('amount', 10, 2) adds the new
     * column once the old one is gone, and after('x') can name a column that
     * an earlier renameColumn() creates. Without those commands every added
     * column goes into one statement.
     *
     * When the blueprint also has orderBy(), the statement ends with MODIFY
     * ORDER BY (<columns>) of the last orderBy() call: ClickHouse accepts a
     * new sorting key only in the ALTER that adds the columns it appends to
     * the key, and refuses it in a statement of its own (BAD_ARGUMENTS). So
     * orderBy() needs the added columns in one group (see compileOrderBy()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string|null
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command): ?string
    {
        $groups = $this->getAddCommandGroups($blueprint);
        $group = array_find($groups, fn (array $group): bool => $group[0] === $command);

        if ($group === null) {
            return null;
        }

        $clauses = array_map(
            fn (Fluent $add): string => 'ADD COLUMN ' . $this->getColumn($blueprint, $add->column)
                . $this->compileColumnPosition($add->column),
            $group
        );

        $orderByCommands = $this->getCommandsByName($blueprint, 'orderBy');
        if ($orderByCommands !== [] && count($groups) === 1) {
            $clauses[] = 'MODIFY ' . $this->compileSortingKey(array_values((array) end($orderByCommands)->columns));
        }

        return $this->compileAlterTable($blueprint) . ' ' . implode(', ', $clauses);
    }

    /**
     * Get the add commands of the blueprint in groups, in the order of the
     * commands: a command that ends a group (see endsAddCommandGroup())
     * between two add commands puts them into different groups.
     *
     * @param Blueprint $blueprint
     * @return list<non-empty-list<Fluent>>
     */
    protected function getAddCommandGroups(Blueprint $blueprint): array
    {
        $groups = [];
        $group = [];

        foreach ($blueprint->getCommands() as $command) {
            if ($command->name === 'add') {
                $group[] = $command;
            } elseif ($group !== [] && $this->endsAddCommandGroup($command)) {
                $groups[] = $group;
                $group = [];
            }
        }

        if ($group !== []) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Determine if a command of Schema::table() ends a group of added
     * columns, so that the columns added after it are added after it runs:
     * dropColumn() (also from dropTimestamps(), dropMorphs() and the like),
     * renameColumn() and change(), after which a new column may take the
     * name of a dropped one or come after() a renamed one.
     *
     * The statements of the other commands, such as an index, a comment, a
     * TTL or a drop of an index, cannot fail because of a column added before
     * them, so the columns added around them go into one statement.
     *
     * @param Fluent $command
     * @return bool
     */
    protected function endsAddCommandGroup(Fluent $command): bool
    {
        return in_array($command->name, ['dropColumn', 'renameColumn', 'change'], true);
    }

    /**
     * Compile a change column command, from ->change() on a column of
     * Schema::table(), into the statements that give the column the new
     * definition, as Laravel's change() does: what the new definition leaves
     * out is dropped.
     *
     * ClickHouse's MODIFY COLUMN keeps the DEFAULT, MATERIALIZED or ALIAS
     * expression and the comment that a new definition leaves out, and a
     * REMOVE in the same ALTER as the MODIFY is lost. So the column's state is
     * read from system.columns first (see selectColumnState()), and the
     * statements are, each on its own:
     *
     * 1. ALTER TABLE <table> MODIFY COLUMN <column> REMOVE DEFAULT, MATERIALIZED
     *    or ALIAS, when the column has that expression and the new definition
     *    gives none (default(), useCurrent(), storedAs(), virtualAs() or
     *    ephemeral());
     * 2. ALTER TABLE <table> MODIFY COLUMN <column> REMOVE COMMENT, when the
     *    column has a comment and the new definition has no comment();
     * 3. ALTER TABLE <table> MODIFY COLUMN <definition> [FIRST|AFTER <column>];
     * 4. ALTER TABLE <table> RENAME COLUMN <column> TO <name>, after renameTo('<name>').
     *
     * The REMOVE statements come first, so that an old default that the new
     * type cannot hold, such as DEFAULT 'web' for a UInt64, does not fail the
     * MODIFY. The statements run one after another, so a statement that the
     * server refuses, such as a new type for a column of the sorting key
     * (ALTER_OF_COLUMN_IS_FORBIDDEN), leaves the ones before it applied.
     *
     * The CODEC and the TTL of the column are kept unless codec() or ttl()
     * gives new ones; system.columns does not show a column's TTL. An
     * EPHEMERAL default is kept too: ClickHouse cannot remove it.
     *
     * When the column's type accepts NULL and the new type does not, such as
     * Nullable(String) changed to String or Array(Nullable(String)) changed to
     * Array(String), the column is first checked for NULL values (see
     * compileLostNullCondition()), and a NULL value throws before anything is
     * sent: ClickHouse would change the column's type, then fail to convert
     * the NULL values in a mutation, and every later query of the table would
     * fail until the mutation is killed. The rows that a lightweight DELETE
     * removed are checked too (see tableHasRowWhere()): the mutation converts
     * them as well until a merge rewrites their parts.
     *
     * Both reads go to the active node while the statement is compiled, so
     * toSql() and migrate --pretend read the server too; they change nothing,
     * and they run while the connection pretends, so that the pretended
     * statements are the ones that would run. A column that system.columns
     * does not list, such as one of a table that an earlier pretended
     * migration creates, gets only the MODIFY COLUMN and the rename.
     *
     * On a connection with a cluster_name, the statements go ON CLUSTER (see
     * compileAlterTable()), so the NULL check reads every host of the
     * cluster: a table that is not replicated holds other rows on each host.
     * Inside a session, a temporary table of the session is read instead of
     * the database table of that name, as the statements without ON CLUSTER
     * change it (see getTableLocation()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return list<string>
     * @throws RuntimeException When the column holds NULL values and its new type does not accept NULL
     * @throws QueryException Inside a session, when an ON CLUSTER statement would miss a temporary table of the
     *                        session (see refuseATemporaryTableThatOnClusterMisses())
     */
    public function compileChange(Blueprint $blueprint, Fluent $command): array
    {
        $column = $command->column;
        $alterTable = $this->compileAlterTable($blueprint);
        $state = $this->selectColumnState($blueprint, (string) $column->name);
        $statements = [];

        if ($state !== null) {
            $newType = $this->getColumnType($column);

            $lostNull = $this->compileLostNullCondition($state['type'], $newType, $this->wrap($column));

            if ($lostNull !== null && $this->tableHasRowWhere($blueprint, $lostNull)) {
                throw new RuntimeException(
                    "The column [{$column->name}] of the table [{$blueprint->getTable()}] holds NULL values, so its type"
                    . " cannot change from {$state['type']} to {$newType}: ClickHouse would fail to convert them in a"
                    . ' mutation, and every later query of the table would fail until the mutation is killed.'
                    . ' Replace the NULL values first, or keep the column nullable(). Rows removed by a lightweight'
                    . ' DELETE count too until a merge rewrites their parts; ALTER TABLE ' . $this->wrapTable($blueprint)
                    . ' APPLY DELETED MASK removes them.'
                );
            }

            if (in_array($state['default_kind'], ['DEFAULT', 'MATERIALIZED', 'ALIAS'], true)
                && ! $this->givesDefaultExpression($blueprint, $column)) {
                $statements[] = "{$alterTable} MODIFY COLUMN {$this->wrap($column)} REMOVE {$state['default_kind']}";
            }

            if ($state['comment'] !== '' && $column->comment === null) {
                $statements[] = "{$alterTable} MODIFY COLUMN {$this->wrap($column)} REMOVE COMMENT";
            }
        }

        $statements[] = "{$alterTable} MODIFY COLUMN " . $this->getColumn($blueprint, $column) . $this->compileColumnPosition($column);

        if (is_string($column->renameTo) && $column->renameTo !== '' && $column->renameTo !== $column->name) {
            $statements[] = "{$alterTable} RENAME COLUMN {$this->wrap($column)} TO {$this->wrap($column->renameTo)}";
        }

        return $statements;
    }

    /**
     * Determine if a column definition gives the column a default expression:
     * a DEFAULT from default() or useCurrent(), a MATERIALIZED expression from
     * storedAs(), an ALIAS from virtualAs() or an EPHEMERAL default.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return bool
     */
    protected function givesDefaultExpression(Blueprint $blueprint, Fluent $column): bool
    {
        return $this->modifyDefault($blueprint, $column) !== null
            || $column->storedAs !== null
            || $column->virtualAs !== null
            || ! in_array($column->ephemeral, [null, false], true);
    }

    /**
     * Read the type, the kind of default expression (DEFAULT, MATERIALIZED,
     * ALIAS, EPHEMERAL or '') and the comment of a column from system.columns.
     *
     * @param Blueprint $blueprint
     * @param string $column
     * @return array{type: string, default_kind: string, comment: string}|null Null when system.columns does not list the column.
     */
    protected function selectColumnState(Blueprint $blueprint, string $column): ?array
    {
        [$database, $table] = $this->getTableLocation($blueprint);

        $row = $this->selectWhilePretending(
            'SELECT type, default_kind, comment FROM system.columns WHERE database = ' . $this->quoteString($database)
            . ' AND table = ' . $this->quoteString($table) . ' AND name = ' . $this->quoteString($column)
        )[0] ?? null;

        return $row === null ? null : [
            'type' => (string) $row['type'],
            'default_kind' => (string) $row['default_kind'],
            'comment' => (string) $row['comment'],
        ];
    }

    /**
     * Compile the condition that finds the NULL values that a change of type
     * cannot keep, or null when the new type keeps every NULL value of the
     * old one.
     *
     * When the old type accepts NULL (see QueryProcessor::typeAcceptsNull()),
     * such as Nullable(String), LowCardinality(Nullable(String)) or a
     * Variant, and the new type does not, the condition is <value> IS NULL,
     * which reads only the column's null map. When both types are arrays, the
     * rule applies to their elements, at any depth: Array(Nullable(String))
     * changed to Array(String) gives arrayExists(x1 -> x1 IS NULL, <value>),
     * since ClickHouse fails to convert a NULL element in the same way. Other
     * types that hold NULL inside, such as maps and tuples, are not checked.
     *
     * @param string $oldType The type that system.columns gives.
     * @param string $newType The type of the new definition.
     * @param string $value The SQL of the value: the column, or the element of an array.
     * @param int $depth The number of arrays around the value.
     * @return string|null
     */
    protected function compileLostNullCondition(string $oldType, string $newType, string $value, int $depth = 0): ?string
    {
        if (QueryProcessor::typeAcceptsNull($oldType)) {
            return QueryProcessor::typeAcceptsNull($newType) ? null : "{$value} IS NULL";
        }

        if (preg_match('/\AArray\((.+)\)\z/s', $oldType, $oldElement) !== 1
            || preg_match('/\AArray\((.+)\)\z/s', $newType, $newElement) !== 1) {
            return null;
        }

        $element = 'x' . ($depth + 1);
        $condition = $this->compileLostNullCondition($oldElement[1], $newElement[1], $element, $depth + 1);

        return $condition === null ? null : "arrayExists({$element} -> {$condition}, {$value})";
    }

    /**
     * Determine if the blueprint's table holds a row that meets a condition,
     * counting the rows that a lightweight DELETE removed:
     * SELECT 1 FROM <table> WHERE <condition> LIMIT 1 SETTINGS apply_deleted_mask = 0.
     *
     * A lightweight DELETE only hides its rows until a merge rewrites their
     * parts, and a mutation, such as the one of MODIFY COLUMN, still converts
     * them. The setting makes the query read them too; it reads no other column.
     *
     * When the blueprint's statements go ON CLUSTER (see getOnClusterName()),
     * the query reads the table on every host of the cluster:
     * SELECT 1 FROM clusterAllReplicas('<cluster_name>', '<database>', '<table>') WHERE ...,
     * since a host that the active node does not see may hold such a row,
     * and the statements change the table there too. A host that does not
     * have the table, or does not answer, makes the query fail, and so the
     * blueprint, before anything is sent.
     *
     * @param Blueprint $blueprint
     * @param string $condition
     * @return bool
     */
    protected function tableHasRowWhere(Blueprint $blueprint, string $condition): bool
    {
        return $this->selectWhilePretending(
            'SELECT 1 FROM ' . $this->compileTableOnEveryHost($blueprint) . ' WHERE ' . $condition
            . ' LIMIT 1 SETTINGS apply_deleted_mask = 0'
        ) !== [];
    }

    /**
     * Compile the table that a read of the blueprint's rows names: the
     * table, or, when the blueprint's statements go ON CLUSTER (see
     * getOnClusterName()), the table on every host of the cluster:
     * clusterAllReplicas('<cluster_name>', '<database>', '<table>'), with the
     * connection's database for a name without one.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function compileTableOnEveryHost(Blueprint $blueprint): string
    {
        $cluster = $this->getOnClusterName($blueprint);

        if ($cluster === null) {
            return $this->wrapTable($blueprint);
        }

        return 'clusterAllReplicas(' . $this->quoteString([$cluster, ...$this->getDatabaseAndTableName($blueprint)]) . ')';
    }

    /**
     * Run a read-only query that compiling a command needs on the active node,
     * also while the connection pretends, and return its rows. It is not
     * logged: it is not one of the blueprint's statements.
     *
     * The active node's client carries the connection's session, inside
     * session(), so the query sees the temporary tables of the session, as
     * the blueprint's statements do.
     *
     * On a connection of another driver, the query goes through select(),
     * which returns no rows while the connection pretends.
     *
     * @param string $sql
     * @return list<array<string, mixed>>
     */
    protected function selectWhilePretending(string $sql): array
    {
        if ($this->connection instanceof Connection) {
            return array_values(ClientRequests::select($this->connection->getClient(), $sql)->rows());
        }

        return array_values(array_map(fn (mixed $row): array => (array) $row, $this->connection->select($sql)));
    }

    /**
     * Get the database and the name of the blueprint's table, as system
     * tables list them: a `<database>.<table>` name gives that database, any
     * other name the connection's database; the name gets the table prefix.
     *
     * @param Blueprint $blueprint
     * @return array{0: string, 1: string}
     */
    protected function getDatabaseAndTableName(Blueprint $blueprint): array
    {
        $table = $blueprint->getTable();
        $prefix = $this->connection->getTablePrefix();
        $dot = strrpos($table, '.');

        return $dot === false
            ? [$this->connection->getDatabaseName(), $prefix . $table]
            : [substr($table, 0, $dot), $prefix . substr($table, $dot + 1)];
    }

    /**
     * Get the database and the name under which system tables list the
     * table that the blueprint's statements change, as getDatabaseAndTableName()
     * gives them, except for a temporary table of the connection's session
     * (see isTemporaryTableOfTheSession()): system.tables and system.columns
     * list it, inside its session, with the database '', and a statement
     * without ON CLUSTER on its name changes it rather than the database
     * table of that name.
     *
     * @param Blueprint $blueprint
     * @return array{0: string, 1: string}
     */
    protected function getTableLocation(Blueprint $blueprint): array
    {
        if ($this->isTemporaryTableOfTheSession($blueprint)) {
            return ['', $this->connection->getTablePrefix() . $blueprint->getTable()];
        }

        return $this->getDatabaseAndTableName($blueprint);
    }

    /**
     * Compile a drop column command into one statement:
     * ALTER TABLE <table> DROP COLUMN <column>, DROP COLUMN <column>, ...
     *
     * ClickHouse refuses to drop a column of the sorting key, and a column
     * that a data-skipping index uses until the index is dropped: call
     * dropIndex() before dropColumn() in the same blueprint.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileDropColumn(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterTable($blueprint) . ' ' . implode(', ', array_map(
            fn (mixed $column): string => 'DROP COLUMN ' . $this->wrap($column),
            array_values((array) $command->columns)
        ));
    }

    /**
     * Compile a rename column command:
     * ALTER TABLE <table> RENAME COLUMN <column> TO <name>.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterTable($blueprint)
            . ' RENAME COLUMN ' . $this->wrap($command->from) . ' TO ' . $this->wrap($command->to);
    }

    /**
     * Compile a rename table command: RENAME TABLE <table> TO <name>, with
     * ON CLUSTER '<cluster_name>' on a connection with a cluster_name (see
     * compileOnCluster()).
     *
     * A new name without a database keeps the table in the database of a
     * `<database>.<table>` name, as Laravel's other drivers do; ClickHouse
     * would move it to the connection's database.
     *
     * ClickHouse cannot rename a temporary table, so a temporary() blueprint
     * throws (see refuseATemporaryTableThatTheSessionLacks()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     * @throws QueryException Inside a session, when an ON CLUSTER statement would miss a temporary table of the
     *                        session (see refuseATemporaryTableThatOnClusterMisses()); for a temporary() blueprint
     */
    public function compileRename(Blueprint $blueprint, Fluent $command): string
    {
        $this->refuseATemporaryTableThatTheSessionLacks($blueprint, 'RENAME TABLE');

        $to = (string) $command->to;
        $table = $blueprint->getTable();

        if (! str_contains($to, '.') && ($dot = strrpos($table, '.')) !== false) {
            $to = substr($table, 0, $dot) . '.' . $to;
        }

        return 'RENAME TABLE ' . $this->wrapTable($blueprint) . ' TO ' . $this->wrapTable($to)
            . $this->compileOnCluster($blueprint, 'RENAME TABLE');
    }

    /**
     * Compile a table comment command of Schema::table():
     * ALTER TABLE <table> MODIFY COMMENT '<text>'. Schema::create() writes
     * the comment into the CREATE statement instead.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterTable($blueprint) . ' MODIFY COMMENT ' . $this->quoteString((string) $command->comment);
    }

    /**
     * Compile an orderBy() command of Schema::table():
     * ALTER TABLE <table> MODIFY ORDER BY (<columns>).
     *
     * When the blueprint adds columns, the sorting key goes into their
     * statement instead (see compileAdd()), and this compiles to nothing.
     * ClickHouse only accepts a sorting key that appends expressions of
     * columns added in the same ALTER to the old key. So when a
     * dropColumn(), renameColumn() or change() splits the added columns into
     * several statements (see getAddCommandGroups()), this throws before
     * anything is sent: the sorting key could name a column of any of them.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string|null
     * @throws RuntimeException When the added columns go into more than one statement
     */
    public function compileOrderBy(Blueprint $blueprint, Fluent $command): ?string
    {
        $groups = count($this->getAddCommandGroups($blueprint));

        if ($groups > 1) {
            throw new RuntimeException(
                'ClickHouse accepts a new sorting key only in the ALTER that adds its columns, but a dropColumn(),'
                . " renameColumn() or change() between the columns that Schema::table() adds to the table"
                . " [{$blueprint->getTable()}] splits them into {$groups} ALTER statements. Call those commands"
                . ' before or after the added columns, not between them.'
            );
        }

        if ($groups === 1) {
            return null;
        }

        return $this->compileAlterTable($blueprint) . ' MODIFY ' . $this->compileSortingKey(array_values((array) $command->columns));
    }

    /**
     * Refuse a partitionBy() command in Schema::table(): ClickHouse cannot
     * change the partition key of a table.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compilePartitionBy(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse cannot change the partition key of the table [{$blueprint->getTable()}]. Create a new table"
            . ' with partitionBy() in Schema::create() and copy the rows, for example with INSERT INTO ... SELECT.'
        );
    }

    /**
     * Compile a sampleBy() command of Schema::table():
     * ALTER TABLE <table> MODIFY SAMPLE BY <expression>.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileSampleBy(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterTable($blueprint) . ' MODIFY SAMPLE BY ' . $this->getValue($command->expression);
    }

    /**
     * Compile a ttl() command of Schema::table():
     * ALTER TABLE <table> MODIFY TTL <expression>.
     *
     * ClickHouse then applies the new TTL to the rows the table already holds
     * in a MATERIALIZE TTL mutation that runs in the background (see
     * system.mutations), unless the connection sets mutations_sync, or turns
     * materialize_ttl_after_modify off, which skips that mutation.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string
     */
    public function compileTtl(Blueprint $blueprint, Fluent $command): string
    {
        return $this->compileAlterTable($blueprint) . ' MODIFY TTL ' . $this->getValue($command->expression);
    }

    /**
     * Compile a settings() command of Schema::table():
     * ALTER TABLE <table> MODIFY SETTING <name>=<value>, ...
     *
     * Names and values are checked and written as in Schema::create() (see
     * compileSettingsList()); a null value is left out.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string|null Null when no setting has a value.
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type
     */
    public function compileSettings(Blueprint $blueprint, Fluent $command): ?string
    {
        $list = $this->compileSettingsList((array) $command->settings);

        return $list === null ? null : $this->compileAlterTable($blueprint) . ' MODIFY SETTING ' . $list;
    }

    /**
     * Compile an index command of Schema::table() for a data-skipping index:
     * ALTER TABLE <table> ADD INDEX <name> <expression> TYPE <type> [GRANULARITY <n>],
     * and, after materialize(), ALTER TABLE <table> MATERIALIZE INDEX <name>,
     * which builds the index for the rows the table already holds; ADD INDEX
     * only covers the rows inserted after it.
     *
     * MATERIALIZE INDEX starts a mutation that runs in the background, unless
     * the connection sets mutations_sync: the migration goes on while it runs
     * (see system.mutations), and until it is done ClickHouse refuses to drop
     * the index (BAD_ARGUMENTS), as a rollback right after the migration would.
     *
     * An index without a type (see getIndexType()) gets the type of the
     * connection's default_index_type option, but only on a table that can
     * have data-skipping indexes (see tableCanHaveSkipIndexes()); otherwise
     * it compiles to nothing, as in 3.0.0. An index with a type is always
     * sent. Schema::create() writes an index with a type into the CREATE
     * statement instead.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return list<string>|null
     */
    public function compileIndex(Blueprint $blueprint, Fluent $command): ?array
    {
        $type = $this->getIndexType($command);

        if ($type === null) {
            $type = $this->getDefaultIndexType();

            if ($type === null || ! $this->tableCanHaveSkipIndexes($blueprint)) {
                return null;
            }
        }

        $statements = [$this->compileAlterTable($blueprint) . ' ADD INDEX ' . $this->compileIndexDefinition($command, $type)];

        if ($command->materialize) {
            $statements[] = $this->compileAlterTable($blueprint) . ' MATERIALIZE INDEX ' . $this->wrap((string) $command->index);
        }

        return $statements;
    }

    /**
     * Compile a drop index command: ALTER TABLE <table> DROP INDEX IF EXISTS <name>.
     *
     * dropIndex(['url']) drops the index by the name that index('url') gets.
     * IF EXISTS makes the drop of an index that index() never created, because
     * it had no type, succeed without a change: Laravel's down() methods, and
     * dropMorphs(), drop the indexes that up() adds without a type. A table
     * whose engine cannot have data-skipping indexes, such as Memory, Log or
     * Distributed, refuses DROP INDEX even with IF EXISTS (NOT_IMPLEMENTED),
     * so for such a table this compiles to nothing (see tableCanHaveSkipIndexes()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return string|null
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command): ?string
    {
        if (! $this->tableCanHaveSkipIndexes($blueprint)) {
            return null;
        }

        return $this->compileAlterTable($blueprint) . ' DROP INDEX IF EXISTS ' . $this->wrap((string) $command->index);
    }

    /**
     * Determine if the blueprint's table can have data-skipping indexes: its
     * engine in system.tables belongs to the MergeTree family. ClickHouse
     * refuses ADD INDEX and DROP INDEX on the tables of other engines, such
     * as Memory, Log, Null, Distributed, Buffer, Merge and MaterializedView
     * (NOT_IMPLEMENTED).
     *
     * The engine is read from the active node while the statement is
     * compiled, also while the connection pretends, as change() reads the
     * column's state; inside a session, that of a temporary table of the
     * session (see getTableLocation()). A table that system.tables does not
     * list, such as one that an earlier pretended migration creates, counts
     * as one that can, so that its statement is sent and the server decides.
     *
     * @param Blueprint $blueprint
     * @return bool
     */
    protected function tableCanHaveSkipIndexes(Blueprint $blueprint): bool
    {
        [$database, $table] = $this->getTableLocation($blueprint);

        $engine = $this->selectWhilePretending(
            'SELECT engine FROM system.tables WHERE database = ' . $this->quoteString($database)
            . ' AND name = ' . $this->quoteString($table)
        )[0]['engine'] ?? null;

        return $engine === null || $this->isMergeTreeEngine((string) $engine);
    }

    /**
     * Compile a primary key command of Schema::table(), which ClickHouse
     * cannot run. Schema::create() makes primary() the table's key instead
     * (see compileTableKeys()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compilePrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse cannot change the primary key of the table [{$blueprint->getTable()}]. Give the key with"
            . ' primary() or orderBy() in Schema::create(); orderBy() in Schema::table() can append columns that'
            . ' the same call adds to the sorting key.'
        );
    }

    /**
     * Compile a drop primary key command, which ClickHouse cannot run.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileDropPrimary(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse cannot drop the primary key of the table [{$blueprint->getTable()}]: it is part of the"
            . ' table. Create a new table with the key you need and copy the rows.'
        );
    }

    /**
     * Compile a rename index command, which ClickHouse cannot run.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse cannot rename the index [{$command->from}] of the table [{$blueprint->getTable()}]."
            . ' Drop it with dropIndex() and add it again with index().'
        );
    }

    /**
     * Compile a unique key command to nothing: ClickHouse cannot enforce
     * uniqueness. A ReplacingMergeTree table with the key in its sorting key
     * keeps one row per key after its merges.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a drop unique key command to nothing, as unique() adds nothing.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a spatial index command to nothing: ClickHouse has no spatial index.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileSpatialIndex(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a drop spatial index command to nothing, as spatialIndex() adds nothing.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileDropSpatialIndex(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a foreign key command to nothing: ClickHouse has no foreign
     * keys, as disableForeignKeyConstraints() reflects. foreignId()->constrained()
     * creates only the column.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileForeign(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a drop foreign key command to nothing, as foreign() adds
     * nothing. dropConstrainedForeignId() drops only the column.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return null
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command): null
    {
        return null;
    }

    /**
     * Compile a fulltext index command, which ClickHouse cannot run.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileFulltext(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse has no full-text index for fullText() (index [{$command->index}] of the table"
            . " [{$blueprint->getTable()}]). Add a data-skipping index of a token or n-gram type instead, such as"
            . " index('body', null, 'tokenbf_v1(512, 3, 0)')."
        );
    }

    /**
     * Compile a drop fulltext index command, which ClickHouse cannot run.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileDropFullText(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse has no full-text index for dropFullText() (index [{$command->index}] of the table"
            . " [{$blueprint->getTable()}]). Drop a data-skipping index with dropIndex()."
        );
    }

    /**
     * Compile a vector index command, which ClickHouse cannot run as Laravel writes it.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileVectorIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse has no vector index for vectorIndex() (index [{$command->index}] of the table"
            . " [{$blueprint->getTable()}]). Pass a vector similarity index type that the server supports to"
            . ' index() as its algorithm.'
        );
    }

    /**
     * Compile a drop vector index command, which ClickHouse cannot run.
     *
     * @param Blueprint $blueprint
     * @param Fluent $command
     * @return never
     * @throws RuntimeException Always
     */
    public function compileDropVectorIndex(Blueprint $blueprint, Fluent $command): never
    {
        throw new RuntimeException(
            "ClickHouse has no vector index for dropVectorIndex() (index [{$command->index}] of the table"
            . " [{$blueprint->getTable()}]). Drop a data-skipping index with dropIndex()."
        );
    }

    /**
     * Get the engine of a new table: the blueprint's engine(), else the
     * connection's `engine` option, else MergeTree(). A blank value counts
     * as not set.
     *
     * @param Blueprint $blueprint
     * @return string
     */
    protected function getTableEngine(Blueprint $blueprint): string
    {
        foreach ([$blueprint->engine, $this->connection->getConfig('engine')] as $engine) {
            if (is_string($engine) && trim($engine) !== '') {
                return trim($engine);
            }
        }

        return 'MergeTree()';
    }

    /**
     * Get the engine of a new temporary table: the blueprint's engine(), or
     * null, which leaves the ENGINE clause out so that the server's
     * default_temporary_table_engine applies (Memory). The connection's
     * `engine` option does not apply. A blank value counts as not set.
     *
     * The engine is written as given: ClickHouse refuses a temporary table
     * with a Replicated, Shared or KeeperMap engine.
     *
     * @param Blueprint $blueprint
     * @return string|null
     */
    protected function getTemporaryTableEngine(Blueprint $blueprint): ?string
    {
        return is_string($blueprint->engine) && trim($blueprint->engine) !== '' ? trim($blueprint->engine) : null;
    }

    /**
     * Get the engine that a new table gets: the given engine with Replicated
     * in front of its name, when it belongs to the MergeTree family and the
     * table is replicated (see replicatesTable()), and the given engine
     * otherwise.
     *
     * The engine keeps its own parameters and gets no replica path:
     * MergeTree() gives ReplicatedMergeTree(), ReplacingMergeTree(version)
     * gives ReplicatedReplacingMergeTree(version). ClickHouse then takes the
     * replica path and name from its default_replica_path and
     * default_replica_name settings, by default /clickhouse/tables/{uuid}/{shard}
     * and {replica}. The {uuid} of a table created ON CLUSTER is the same on
     * every host, and a new one for every CREATE, so a table that was
     * dropped without SYNC, or renamed, can be created again at once.
     * ClickHouse (24.8 checked) accepts a replicated engine without a replica
     * path only in a CREATE TABLE ... ON CLUSTER of an Atomic database, and
     * refuses it with BAD_ARGUMENTS elsewhere.
     *
     * An engine whose name already starts with Replicated or Shared, and an
     * engine outside the MergeTree family, such as Memory or Distributed,
     * are kept as they are.
     *
     * @param Blueprint $blueprint
     * @param string $engine
     * @return string
     */
    protected function getReplicatedEngine(Blueprint $blueprint, string $engine): string
    {
        if (! $this->replicatesTable($blueprint)) {
            return $engine;
        }

        return (string) preg_replace('/\A(?!Replicated|Shared)(\w*MergeTree)\b/', 'Replicated$1', $engine, 1);
    }

    /**
     * Get the table settings that a new table starts from, before the
     * blueprint's settings(): replicated_deduplication_window = 0 when
     * getReplicatedEngine() made the engine replicated, and none otherwise.
     *
     * A replicated table drops an inserted block that is identical to one of
     * the last replicated_deduplication_window blocks (by default 1000
     * within 7 days on 24.8, 10000 within an hour on 26.3 and 26.8) as a
     * duplicate, while a MergeTree table keeps it (its
     * non_replicated_deduplication_window is 0). So a table that the schema
     * builder makes replicated would silently lose a second, identical
     * insert, such as the row that Laravel's migrator logs again in its
     * migrations table after a rollback. With the window at 0 it keeps every
     * insert, as the MergeTree table that it replaces does (24.8.14 and
     * 26.8.21 checked). ClickHouse 26.3 (26.3.46 checked) deduplicates such
     * a table anyway: there, only an insert with deduplicate_insert =
     * 'disable', or with an insert_deduplication_token of its own, keeps an
     * identical block.
     *
     * settings() with another value for the setting wins, and a null value
     * leaves it to the server's default. An engine that the blueprint or the
     * connection's engine option names as replicated keeps the server's
     * default. So does an engine() with a SETTINGS clause of its own, since a
     * second SETTINGS clause would make the statement invalid.
     *
     * @param string $givenEngine The engine that the blueprint or the connection gives (see getTableEngine()).
     * @param string $engine The engine that the table gets (see getReplicatedEngine()).
     * @return array<string, int>
     */
    protected function getReplicatedTableSettings(string $givenEngine, string $engine): array
    {
        if ($engine === $givenEngine || preg_match('/\bSETTINGS\b/i', $engine) === 1) {
            return [];
        }

        return ['replicated_deduplication_window' => 0];
    }

    /**
     * Determine if a new table gets a replicated engine (see
     * getReplicatedEngine()).
     *
     * SchemaBlueprint::replicated(false) keeps the engine as given, and
     * replicated() makes it replicated on any connection. Otherwise the
     * table is replicated when the CREATE goes ON CLUSTER, on a connection
     * with a cluster_name and without withoutOnCluster() (see
     * getOnClusterName()), and the connection lists its nodes in the cluster
     * option, the condition on which Migration::createMergeTree() makes a
     * table replicated (see Connection::hasClusterNodes()). A temporary table
     * is never replicated.
     *
     * @param Blueprint $blueprint
     * @return bool
     */
    protected function replicatesTable(Blueprint $blueprint): bool
    {
        $replicated = $blueprint instanceof SchemaBlueprint ? $blueprint->replicated : null;

        if ($blueprint->temporary || $replicated === false) {
            return false;
        }

        return $replicated === true
            || ($this->getOnClusterName($blueprint) !== null
                && $this->connection instanceof Connection
                && $this->connection->hasClusterNodes());
    }

    /**
     * Determine if the engine belongs to the MergeTree family, such as
     * MergeTree(), ReplacingMergeTree(version) or ReplicatedMergeTree(...),
     * whose tables take a sorting key.
     *
     * @param string $engine
     * @return bool
     */
    protected function isMergeTreeEngine(string $engine): bool
    {
        return preg_match('/\A\w*MergeTree\s*(?:\(|\z)/', $engine) === 1;
    }

    /**
     * Determine if the engine is an engine of the MergeTree family that merges
     * the rows that have the same sorting key, such as ReplacingMergeTree or
     * SummingMergeTree, and their Replicated variants: every engine of the
     * family except MergeTree, ReplicatedMergeTree and SharedMergeTree.
     *
     * @param string $engine
     * @return bool
     */
    protected function mergesRowsBySortingKey(string $engine): bool
    {
        return $this->isMergeTreeEngine($engine)
            && preg_match('/\A(?:Replicated|Shared)?MergeTree\s*(?:\(|\z)/', $engine) !== 1;
    }

    /**
     * Determine if the engine takes its key from the PRIMARY KEY clause and
     * requires one: EmbeddedRocksDB, KeeperMap, Redis and MaterializedPostgreSQL.
     *
     * @param string $engine
     * @return bool
     */
    protected function isPrimaryKeyEngine(string $engine): bool
    {
        return preg_match('/\A(?:EmbeddedRocksDB|KeeperMap|Redis|MaterializedPostgreSQL)\s*(?:\(|\z)/', $engine) === 1;
    }

    /**
     * Mark every command of the given name as taken in by the CREATE
     * statement, so that it compiles to no statement of its own, and return them.
     *
     * @param Blueprint $blueprint
     * @param string $name
     * @return list<Fluent>
     */
    protected function takeCommands(Blueprint $blueprint, string $name): array
    {
        $commands = array_values($this->getCommandsByName($blueprint, $name));

        foreach ($commands as $command) {
            $command->shouldBeSkipped = true;
        }

        return $commands;
    }

    /**
     * Compile a table clause that takes one expression, such as PARTITION BY,
     * from the last command of the given name. The expression is SQL: a
     * string is written as given, like an expression object.
     *
     * @param Blueprint $blueprint
     * @param string $name The command name, such as partitionBy.
     * @param string $keyword The clause keyword, such as PARTITION BY.
     * @return string|null Null when the blueprint has no such command.
     */
    protected function compileTableExpressionClause(Blueprint $blueprint, string $name, string $keyword): ?string
    {
        $commands = $this->takeCommands($blueprint, $name);

        return $commands === [] ? null : $keyword . ' ' . $this->getValue(end($commands)->expression);
    }

    /**
     * Compile the PRIMARY KEY and ORDER BY clauses of a new table.
     *
     * See compileCreate() for the rules. The last orderBy() wins; the columns
     * of every primary() command make up the primary key.
     *
     * @param Blueprint $blueprint
     * @param string $engine The engine of the table.
     * @return list<string>
     * @throws RuntimeException When the table needs a sorting key that the blueprint does not give (see getDefaultSortingKey())
     */
    protected function compileTableKeys(Blueprint $blueprint, string $engine): array
    {
        $primaryColumns = array_merge(...array_map(
            fn (Fluent $command): array => array_values((array) $command->columns),
            $this->takeCommands($blueprint, 'primary')
        ));
        $orderByCommands = $this->takeCommands($blueprint, 'orderBy');
        $isMergeTree = $this->isMergeTreeEngine($engine);

        $keys = [];
        if ($primaryColumns !== [] && ($isMergeTree ? $orderByCommands !== [] : $this->isPrimaryKeyEngine($engine))) {
            $keys[] = 'PRIMARY KEY (' . $this->columnize($primaryColumns) . ')';
        }

        if ($orderByCommands !== []) {
            $keys[] = $this->compileSortingKey(array_values((array) end($orderByCommands)->columns));
        } elseif ($isMergeTree) {
            $keys[] = $this->compileSortingKey($primaryColumns !== [] ? $primaryColumns : $this->getDefaultSortingKey($blueprint, $engine));
        }

        return $keys;
    }

    /**
     * Compile an ORDER BY clause: the columns in parentheses, or tuple() when
     * there are none, which creates the table without a sorting key.
     *
     * @param list<Fluent|Expression|string> $columns
     * @return string
     */
    protected function compileSortingKey(array $columns): string
    {
        return $columns === [] ? 'ORDER BY tuple()' : 'ORDER BY (' . $this->columnize($columns) . ')';
    }

    /**
     * Get the sorting key of a MergeTree-family table whose blueprint names
     * none: its first column, as in 3.0.0, or no column, which gives ORDER BY
     * tuple(), when that one cannot be in a sorting key (see canBeInSortingKey()).
     *
     * No sorting key is only safe for MergeTree, ReplicatedMergeTree and
     * SharedMergeTree. The other engines of the family, such as
     * ReplacingMergeTree, merge the rows that have the same sorting key, and
     * without one every row has the same key: ReplacingMergeTree then keeps
     * one row per partition. So for them a first column that cannot be in a
     * sorting key throws, before anything is sent.
     *
     * @param Blueprint $blueprint
     * @param string $engine The engine of the table.
     * @return list<Fluent>
     * @throws RuntimeException When the first column cannot be in a sorting key and the engine merges rows by their sorting key
     */
    protected function getDefaultSortingKey(Blueprint $blueprint, string $engine): array
    {
        $column = array_values($blueprint->getAddedColumns())[0] ?? null;

        if ($column === null) {
            return [];
        }

        if ($this->canBeInSortingKey($column)) {
            return [$column];
        }

        if ($this->mergesRowsBySortingKey($engine)) {
            throw new RuntimeException(
                "The first column [{$column->name}] of the table [{$blueprint->getTable()}] cannot be its sorting key,"
                . " and without a sorting key the {$engine} engine would merge the rows of each partition into one."
                . ' Call orderBy() or primary() with the columns that identify a row.'
            );
        }

        return [];
    }

    /**
     * Determine if a column can be in a sorting key.
     *
     * It cannot when its type holds NULL anywhere, such as Nullable(String),
     * LowCardinality(Nullable(String)), Array(Nullable(String)) or
     * Map(String, Nullable(String)), which ClickHouse refuses in a sorting key
     * unless the table sets allow_nullable_key; when its type is a Variant or
     * Dynamic one; when its type is an aggregate function state or Nested,
     * which ClickHouse does not allow in a key; or when it is an ALIAS or
     * EPHEMERAL column, which is not stored.
     *
     * @param Fluent $column
     * @return bool
     */
    protected function canBeInSortingKey(Fluent $column): bool
    {
        $type = $this->getColumnType($column);

        return ! str_contains($type, 'Nullable(')
            && ! QueryProcessor::typeAcceptsNull($type)
            && preg_match('/\A(?:SimpleAggregateFunction|AggregateFunction|Nested)\(/', $type) !== 1
            && $column->virtualAs === null
            && in_array($column->ephemeral, [null, false], true);
    }

    /**
     * Compile the indexes that have a type, which go into the column list of
     * the CREATE statement: the index's own type (see getIndexType()), else,
     * for an engine of the MergeTree family, the connection's
     * default_index_type option (see getDefaultIndexType()). Other engines
     * cannot have data-skipping indexes, so the option does not apply to
     * them; an index's own type is written for every engine, so that the
     * server accepts or refuses it. An index without a type is left out.
     * materialize() adds nothing here: a new table has no rows.
     *
     * Every index command is marked to be skipped, so that none of them
     * compiles to an ALTER statement of its own after the CREATE.
     *
     * @param Blueprint $blueprint
     * @param string $engine The engine of the table.
     * @return list<string>
     */
    protected function compileCreateIndexes(Blueprint $blueprint, string $engine): array
    {
        $defaultType = $this->isMergeTreeEngine($engine) ? $this->getDefaultIndexType() : null;
        $indexes = [];

        foreach ($this->takeCommands($blueprint, 'index') as $command) {
            $type = $this->getIndexType($command) ?? $defaultType;

            if ($type !== null) {
                $indexes[] = 'INDEX ' . $this->compileIndexDefinition($command, $type);
            }
        }

        return $indexes;
    }

    /**
     * Get the ClickHouse type that a data-skipping index names: the type of
     * the algorithm argument of index() or of algorithm(), such as
     * bloom_filter(0.01), or null when it names none. A blank value counts
     * as none.
     *
     * An index without a type gets the connection's default_index_type
     * option on a table of the MergeTree family (see getDefaultIndexType());
     * without that option it sends nothing, as in 3.0.0: ClickHouse requires
     * a type, and Laravel's own migrations, morphs() among them, add indexes
     * without one.
     *
     * @param Fluent $command
     * @return string|null
     */
    protected function getIndexType(Fluent $command): ?string
    {
        return is_string($command->algorithm) && trim($command->algorithm) !== '' ? trim($command->algorithm) : null;
    }

    /**
     * Get the connection's default_index_type option: the type of a
     * data-skipping index that names none, or null when the option is not set.
     * A blank value counts as not set.
     *
     * @return string|null
     */
    protected function getDefaultIndexType(): ?string
    {
        $type = $this->connection->getConfig('default_index_type');

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    /**
     * Compile `<name> <expression> TYPE <type> [GRANULARITY <n>]` for a
     * data-skipping index. The expression is the column, the columns as a
     * tuple, or a raw expression from rawIndex(); the type, such as
     * bloom_filter(0.01), is written as given. Without granularity(),
     * ClickHouse uses a granularity of 1.
     *
     * @param Fluent $command
     * @param string $type The index type (see getIndexType() and getDefaultIndexType()).
     * @return string
     */
    protected function compileIndexDefinition(Fluent $command, string $type): string
    {
        $columns = array_values((array) $command->columns);
        $expression = count($columns) === 1 ? $this->wrap($columns[0]) : '(' . $this->columnize($columns) . ')';

        return $this->wrap((string) $command->index) . ' ' . $expression . ' TYPE ' . $type
            . ($command->granularity === null ? '' : ' GRANULARITY ' . (int) $command->granularity);
    }

    /**
     * Compile the SETTINGS clause of a new table from the given defaults and
     * every settings() command; a later value for the same name wins, and a
     * null value leaves the setting out. Names and values are checked and
     * written by the query builder's settings writer
     * (Grammar::compileSettingsComponent()); a Laravel expression is written
     * as its SQL.
     *
     * @param Blueprint $blueprint
     * @param array<string, mixed> $defaults The settings that the table gets unless settings() names them (see
     *                                       getReplicatedTableSettings())
     * @return string|null Null when no setting is given.
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type
     */
    protected function compileTableSettings(Blueprint $blueprint, array $defaults = []): ?string
    {
        $settings = $defaults;
        foreach ($this->takeCommands($blueprint, 'settings') as $command) {
            $settings = array_replace($settings, (array) $command->settings);
        }

        $list = $this->compileSettingsList($settings);

        return $list === null ? null : 'SETTINGS ' . $list;
    }

    /**
     * Compile settings as `<name>=<value>, ...`. Names and values are checked
     * and written by the query builder's settings writer
     * (Grammar::compileSettingsComponent()); a Laravel expression is written
     * as its SQL, and a null value is left out.
     *
     * @param array<string, mixed> $settings
     * @return string|null Null when no setting has a value.
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type
     */
    protected function compileSettingsList(array $settings): ?string
    {
        $clause = (new Grammar())->compileSettingsComponent(null, array_map(
            fn (mixed $value): mixed => $this->isExpression($value)
                ? new BuilderExpression((string) $this->getValue($value))
                : $value,
            $settings
        ));

        return $clause === '' ? null : substr($clause, strlen('SETTINGS '));
    }

    /**
     * Compile the COMMENT clause of a new table from the last comment() command.
     *
     * @param Blueprint $blueprint
     * @return string|null Null when the blueprint sets no comment.
     */
    protected function compileTableCommentClause(Blueprint $blueprint): ?string
    {
        $commands = $this->takeCommands($blueprint, 'tableComment');

        return $commands === [] ? null : 'COMMENT ' . $this->quoteString((string) end($commands)->comment);
    }

    /**
     * Compile the blueprint's column definitions.
     *
     * @param Blueprint $blueprint
     * @return list<string>
     */
    protected function getColumns(Blueprint $blueprint): array
    {
        return array_values(array_map(
            fn (Fluent $column): string => $this->getColumn($blueprint, $column),
            $blueprint->getAddedColumns()
        ));
    }

    /**
     * Compile one column definition: the name, the type and the modifiers.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string
     */
    protected function getColumn(Blueprint $blueprint, $column): string
    {
        return $this->addModifiers($this->wrap($column) . ' ' . $this->getColumnType($column), $blueprint, $column);
    }

    /**
     * Get the ClickHouse type of a column: the type of its Laravel column
     * method, in Nullable(...) after nullable() and then in
     * LowCardinality(...) after lowCardinality(), which gives
     * LowCardinality(Nullable(String)), the order ClickHouse requires.
     *
     * @param Fluent $column
     * @return string
     */
    protected function getColumnType(Fluent $column): string
    {
        $type = $this->getType($column);

        if ($column->nullable) {
            $type = "Nullable({$type})";
        }

        if ($column->lowCardinality) {
            $type = "LowCardinality({$type})";
        }

        return $type;
    }

    /**
     * Compile the position that ALTER TABLE ... ADD COLUMN and MODIFY COLUMN
     * give a column: ' FIRST' after first(), ' AFTER `<column>`' after after(),
     * or nothing. CREATE TABLE keeps the order in which the columns were added.
     *
     * @param Fluent $column
     * @return string
     */
    protected function compileColumnPosition(Fluent $column): string
    {
        return match (true) {
            (bool) $column->first => ' FIRST',
            $column->after !== null => ' AFTER ' . $this->wrap($column->after),
            default => '',
        };
    }

    /**
     * Get the SQL for a default column modifier.
     *
     * useCurrent() gives the current time in the column's type: now() or
     * now64(<precision>) for a date-time column, today() for a date and
     * toYear(now()) for a year; on other types it adds nothing. Otherwise
     * default() gives the value as a literal (see getColumnDefaultValue()).
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->useCurrent && ($current = $this->getCurrentTimeDefault($column)) !== null) {
            return ' DEFAULT ' . $current;
        }

        return $column->default === null ? null : ' DEFAULT ' . $this->getColumnDefaultValue($column, $column->default);
    }

    /**
     * Get the SQL for a stored generated column modifier: MATERIALIZED <expression>.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     * @throws RuntimeException For storedAsJson(), which needs JSON paths that ClickHouse does not have
     */
    protected function modifyStoredAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->storedAsJson !== null) {
            throw new RuntimeException(
                "ClickHouse does not support storedAsJson() (column [{$column->name}]): use storedAs()"
                . " with an expression such as JSONExtractString(payload, 'name')."
            );
        }

        return $column->storedAs === null ? null : ' MATERIALIZED ' . $this->getValue($column->storedAs);
    }

    /**
     * Get the SQL for a virtual generated column modifier: ALIAS <expression>.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     * @throws RuntimeException For virtualAsJson(), which needs JSON paths that ClickHouse does not have
     */
    protected function modifyVirtualAs(Blueprint $blueprint, Fluent $column): ?string
    {
        if ($column->virtualAsJson !== null) {
            throw new RuntimeException(
                "ClickHouse does not support virtualAsJson() (column [{$column->name}]): use virtualAs()"
                . " with an expression such as JSONExtractString(payload, 'name')."
            );
        }

        return $column->virtualAs === null ? null : ' ALIAS ' . $this->getValue($column->virtualAs);
    }

    /**
     * Get the SQL for an ephemeral column modifier: EPHEMERAL after
     * ephemeral(), or EPHEMERAL <default> after ephemeral($default), with the
     * default written as getColumnDefaultValue() writes it.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyEphemeral(Blueprint $blueprint, Fluent $column): ?string
    {
        return match (true) {
            $column->ephemeral === null, $column->ephemeral === false => null,
            $column->ephemeral === true => ' EPHEMERAL',
            default => ' EPHEMERAL ' . $this->getColumnDefaultValue($column, $column->ephemeral),
        };
    }

    /**
     * Get the SQL for a comment column modifier, as an escaped string literal.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyComment(Blueprint $blueprint, Fluent $column): ?string
    {
        return $column->comment === null ? null : ' COMMENT ' . $this->quoteString((string) $column->comment);
    }

    /**
     * Get the SQL for a compression codec column modifier: codec('ZSTD(3)')
     * gives CODEC(ZSTD(3)).
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyCodec(Blueprint $blueprint, Fluent $column): ?string
    {
        return $column->codec === null ? null : ' CODEC(' . $this->getValue($column->codec) . ')';
    }

    /**
     * Get the SQL for a column TTL modifier: TTL <expression>.
     *
     * @param Blueprint $blueprint
     * @param Fluent $column
     * @return string|null
     */
    protected function modifyTtl(Blueprint $blueprint, Fluent $column): ?string
    {
        return $column->ttl === null ? null : ' TTL ' . $this->getValue($column->ttl);
    }

    /**
     * Get the current-time expression that useCurrent() sets as the default
     * of the column, by the column's type.
     *
     * @param Fluent $column
     * @return string|null Null for a type without a current-time default.
     */
    protected function getCurrentTimeDefault(Fluent $column): ?string
    {
        $precision = (int) $column->precision;

        return match ($column->type) {
            'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => $precision > 0 ? "now64({$precision})" : 'now()',
            'date', 'date32' => 'today()',
            'year' => 'toYear(now())',
            default => null,
        };
    }

    /**
     * Format a value as a literal for the DEFAULT or EPHEMERAL clause of the
     * given column, as getDefaultValue() does.
     *
     * When the column's type holds a Decimal (see typeHoldsDecimal()), a
     * finite float, also one inside an array, is written as a string literal
     * of its text: ClickHouse reads a bare number as a Float64 and truncates
     * it to the Decimal's scale, so DEFAULT 19.99 would store 19.98, while it
     * parses '19.99' into the Decimal exactly.
     *
     * @param Fluent $column
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException For a value that has no ClickHouse literal, such as a collection
     */
    protected function getColumnDefaultValue(Fluent $column, mixed $value): string
    {
        return $this->getDefaultValue(
            $this->typeHoldsDecimal($this->getColumnType($column)) ? $this->writeFloatsAsText($value) : $value
        );
    }

    /**
     * Determine if a ClickHouse type holds a Decimal: Decimal(P, S),
     * Decimal32(S) to Decimal256(S) or one of their aliases NUMERIC, DEC and
     * FIXED, in any letter case, also inside another type, such as
     * Nullable(Decimal(10, 2)) or Array(Decimal(10, 2)).
     *
     * @param string $type
     * @return bool
     */
    protected function typeHoldsDecimal(string $type): bool
    {
        return preg_match('/\b(?:Decimal(?:32|64|128|256)?|Numeric|Dec|Fixed)\s*\(/i', $type) === 1;
    }

    /**
     * Replace a finite float, also one inside an array, with the text that
     * compileFloatLiteral() writes for it, so that getDefaultValue() writes it
     * as a string literal. Any other value is returned as it is.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function writeFloatsAsText(mixed $value): mixed
    {
        return match (true) {
            is_float($value) && is_finite($value) => $this->compileFloatLiteral($value),
            is_array($value) => array_map($this->writeFloatsAsText(...), $value),
            default => $value,
        };
    }

    /**
     * Format a value as a ClickHouse literal for a DEFAULT or EPHEMERAL clause.
     *
     * An expression is written as given; a string as an escaped string
     * literal; a bool as 1 or 0; an int as a number and a float with every
     * digit, or as nan, inf or -inf; a backed enum as its value and any other
     * enum as its name; a date as 'Y-m-d H:i:s' in its own time zone; an array
     * as an array literal of its values, with its keys left out; another
     * Stringable object as its string; null as NULL.
     *
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException For a value that has no ClickHouse literal, such as a collection
     */
    protected function getDefaultValue($value): string
    {
        return match (true) {
            $this->isExpression($value) => (string) $this->getValue($value),
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->compileFloatLiteral($value),
            is_string($value) => $this->quoteString($value),
            $value instanceof BackedEnum => $this->getDefaultValue($value->value),
            $value instanceof UnitEnum => $this->quoteString($value->name),
            $value instanceof DateTimeInterface => $this->quoteString($value->format('Y-m-d H:i:s')),
            is_array($value) => '[' . implode(', ', array_map($this->getDefaultValue(...), array_values($value))) . ']',
            $value instanceof Arrayable, $value instanceof Traversable => throw $this->invalidDefaultValue($value),
            $value instanceof Stringable => $this->quoteString((string) $value),
            default => throw $this->invalidDefaultValue($value),
        };
    }

    /**
     * Write a float with every digit, or as nan, inf or -inf, which are the
     * ClickHouse literals for the values that have no digits.
     *
     * @param float $value
     * @return string
     */
    protected function compileFloatLiteral(float $value): string
    {
        return match (true) {
            is_nan($value) => 'nan',
            is_infinite($value) => $value > 0 ? 'inf' : '-inf',
            default => var_export($value, true),
        };
    }

    /**
     * Build the exception for a default value that getDefaultValue() cannot write.
     *
     * @param mixed $value
     * @return InvalidArgumentException
     */
    protected function invalidDefaultValue(mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'A ClickHouse column default must be a string, number, bool, enum, date, array, Stringable object'
            . ' or expression, ' . get_debug_type($value) . ' given. Pass other SQL as DB::raw().'
        );
    }

    /**
     * Determine if the connection maps Laravel's integer types to their exact
     * widths and signs (the `exact_integer_types` option, false by default).
     *
     * @return bool
     */
    protected function usesExactIntegerTypes(): bool
    {
        return filter_var($this->connection->getConfig('exact_integer_types') ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get the ClickHouse type of an integer column.
     *
     * With exact_integer_types, the type has the column's width and is UInt
     * when the column is unsigned. Without it, the type is the given signed
     * one, as in 3.0.0, and unsigned() is ignored.
     *
     * @param Fluent $column
     * @param int $bits The width with exact_integer_types.
     * @param string $type The type without exact_integer_types.
     * @return string
     */
    protected function compileIntegerType(Fluent $column, int $bits, string $type): string
    {
        if (! $this->usesExactIntegerTypes()) {
            return $type;
        }

        return ($column->unsigned ? 'UInt' : 'Int') . $bits;
    }

    /**
     * Create the column definition for a char type: FixedString(<length>).
     *
     * ClickHouse pads a shorter value with NUL bytes to the length.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeChar(Fluent $column): string
    {
        return 'FixedString(' . (int) $column->length . ')';
    }

    /**
     * Create the column definition for a string type. The length is ignored:
     * a ClickHouse String has none.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeString(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a tiny text type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTinyText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a text type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a medium text type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMediumText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a long text type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeLongText(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a tiny integer type: Int8 or UInt8
     * with exact_integer_types, Int16 without, as in 3.0.0.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTinyInteger(Fluent $column): string
    {
        return $this->compileIntegerType($column, 8, 'Int16');
    }

    /**
     * Create the column definition for a small integer type: Int16 or UInt16
     * with exact_integer_types. Without it, Int16, or Int32 when the column
     * is unsigned, so that the unsigned range up to 65535 fits.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeSmallInteger(Fluent $column): string
    {
        return $this->compileIntegerType($column, 16, $column->unsigned ? 'Int32' : 'Int16');
    }

    /**
     * Create the column definition for a medium integer type: Int32 or
     * UInt32 with exact_integer_types, Int32 without.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMediumInteger(Fluent $column): string
    {
        return $this->compileIntegerType($column, 32, 'Int32');
    }

    /**
     * Create the column definition for an integer type: Int32 or UInt32 with
     * exact_integer_types, Int32 without, as in 3.0.0.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeInteger(Fluent $column): string
    {
        return $this->compileIntegerType($column, 32, 'Int32');
    }

    /**
     * Create the column definition for a big integer type: Int64 or UInt64
     * with exact_integer_types, Int64 without, as in 3.0.0.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBigInteger(Fluent $column): string
    {
        return $this->compileIntegerType($column, 64, 'Int64');
    }

    /**
     * Create the column definition for a float type: Float32 for a precision
     * up to 24 bits, Float64 above, as MySQL decides between FLOAT and DOUBLE.
     * Laravel's float() defaults to a precision of 53, so it gives Float64.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeFloat(Fluent $column): string
    {
        return $column->precision !== null && (int) $column->precision <= 24 ? 'Float32' : 'Float64';
    }

    /**
     * Create the column definition for a double type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDouble(Fluent $column): string
    {
        return 'Float64';
    }

    /**
     * Create the column definition for a decimal type: Decimal(<total>, <places>).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDecimal(Fluent $column): string
    {
        return 'Decimal(' . (int) $column->total . ', ' . (int) $column->places . ')';
    }

    /**
     * Create the column definition for a boolean type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBoolean(Fluent $column): string
    {
        return 'Bool';
    }

    /**
     * Create the column definition for an enumeration type.
     *
     * An array with at least one string key maps each name to its number:
     * ['low' => 1, 'high' => 10] gives Enum('low' = 1, 'high' = 10). Every
     * key is then a name, an integer key too, because PHP turns a numeric
     * string key into an integer: ['active' => 1, '200' => 2] gives
     * Enum('active' = 1, '200' = 2). Every value must then be an integer or
     * a string of one.
     *
     * An array whose keys are all integers is a list of names, written from
     * its values in order whatever its keys, as Laravel's other grammars
     * read the allowed values: ['a', 'b'] gives Enum('a', 'b'), which
     * ClickHouse numbers from 1, array_unique([200, 404, 200]) gives
     * Enum('200', '404'), and ['200' => 1, '404' => 2] gives Enum('1', '2').
     * To number names that are all numbers, write the type with rawColumn():
     * rawColumn('status', "Enum8('200' = 1, '404' = 2)"). AddsColumns::enum()
     * of createMergeTree() follows the same rule. ClickHouse picks Enum8 or
     * Enum16.
     *
     * @param Fluent $column
     * @return string
     * @throws InvalidArgumentException When a value of an array with a string key is not an integer
     */
    protected function typeEnum(Fluent $column): string
    {
        $allowed = (array) $column->allowed;
        $isNumberedByKeys = array_any($allowed, fn (mixed $value, int|string $key): bool => is_string($key));

        $values = [];
        foreach ($allowed as $key => $value) {
            $values[] = $isNumberedByKeys
                ? $this->quoteString((string) $key) . ' = ' . $this->compileEnumNumber($column, (string) $key, $value)
                : $this->quoteString((string) $value);
        }

        return 'Enum(' . implode(', ', $values) . ')';
    }

    /**
     * Determine if a value can be the number of an enum value: an integer or
     * a string of one.
     *
     * @param mixed $number
     * @return bool
     */
    protected function isEnumNumber(mixed $number): bool
    {
        return is_int($number) || (is_string($number) && preg_match('/\A-?\d+\z/', $number) === 1);
    }

    /**
     * Write the number of an enum value, which must be an integer.
     *
     * @param Fluent $column
     * @param string $name
     * @param mixed $number
     * @return string
     * @throws InvalidArgumentException When the number is not an integer
     */
    protected function compileEnumNumber(Fluent $column, string $name, mixed $number): string
    {
        if ($this->isEnumNumber($number)) {
            return (string) (int) $number;
        }

        throw new InvalidArgumentException(
            "The enum column [{$column->name}] needs an integer for the value [{$name}], ["
            . (is_scalar($number) ? (string) $number : get_debug_type($number)) . '] given.'
            . ' An array with a string key maps each name to its number, an integer key being a name too.'
        );
    }

    /**
     * Create the column definition for a json type. A JSON document is stored
     * as a String: ClickHouse 24.8's JSON type is experimental (use rawColumn()
     * with 'JSON' where the server allows it).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeJson(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a jsonb type, stored like json.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeJsonb(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a date type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDate(Fluent $column): string
    {
        return 'Date';
    }

    /**
     * Create the column definition for a Date32 column (SchemaBlueprint::date32()),
     * which covers the years 1900 to 2299.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDate32(Fluent $column): string
    {
        return 'Date32';
    }

    /**
     * Create the column definition for a date-time type.
     *
     * A precision above 0 gives DateTime64(<precision>), otherwise DateTime,
     * and timezone('UTC') adds the time zone: DateTime('UTC'), DateTime64(3, 'UTC').
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDateTime(Fluent $column): string
    {
        $precision = (int) $column->precision;
        $arguments = $precision > 0 ? [(string) $precision] : [];

        if ($column->timezone !== null) {
            $arguments[] = $this->quoteString((string) $column->timezone);
        }

        return ($precision > 0 ? 'DateTime64' : 'DateTime') . ($arguments === [] ? '' : '(' . implode(', ', $arguments) . ')');
    }

    /**
     * Create the column definition for a date-time (with time zone) type,
     * the same as a date-time type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeDateTimeTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a timestamp type, the same as a date-time type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTimestamp(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a timestamp (with time zone) type,
     * the same as a date-time type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /**
     * Create the column definition for a year type: UInt16, the type of
     * ClickHouse's own YEAR alias.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeYear(Fluent $column): string
    {
        return 'UInt16';
    }

    /**
     * Create the column definition for a binary type: FixedString(<length>)
     * when the column is fixed and has a length, String otherwise.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeBinary(Fluent $column): string
    {
        return $column->fixed && (int) $column->length > 0 ? 'FixedString(' . (int) $column->length . ')' : 'String';
    }

    /**
     * Create the column definition for a uuid type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeUuid(Fluent $column): string
    {
        return 'UUID';
    }

    /**
     * Create the column definition for an IP address type: a String, which
     * holds IPv4 and IPv6 addresses as written. SchemaBlueprint::ipv4() and
     * ipv6() create the native types.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeIpAddress(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for a MAC address type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMacAddress(Fluent $column): string
    {
        return 'String';
    }

    /**
     * Create the column definition for an IPv4 column (SchemaBlueprint::ipv4()).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeIpv4(Fluent $column): string
    {
        return 'IPv4';
    }

    /**
     * Create the column definition for an IPv6 column (SchemaBlueprint::ipv6()),
     * which stores an IPv4 address as ::ffff:<address>.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeIpv6(Fluent $column): string
    {
        return 'IPv6';
    }

    /**
     * Create the column definition for a geometry type: the ClickHouse
     * geo type of the subtype. The SRID is ignored.
     *
     * @param Fluent $column
     * @return string
     * @throws RuntimeException For a subtype that ClickHouse has no type for, or no subtype
     */
    protected function typeGeometry(Fluent $column): string
    {
        return match (strtolower((string) $column->subtype)) {
            'point' => 'Point',
            'ring' => 'Ring',
            'linestring' => 'LineString',
            'multilinestring' => 'MultiLineString',
            'polygon' => 'Polygon',
            'multipolygon' => 'MultiPolygon',
            default => throw new RuntimeException(
                "The geometry column [{$column->name}] needs a subtype that ClickHouse has a type for:"
                . ' point, ring, linestring, multilinestring, polygon or multipolygon.'
            ),
        };
    }

    /**
     * Create the column definition for a geography type, the same as a geometry type.
     *
     * @param Fluent $column
     * @return string
     * @throws RuntimeException For a subtype that ClickHouse has no type for, or no subtype
     */
    protected function typeGeography(Fluent $column): string
    {
        return $this->typeGeometry($column);
    }

    /**
     * Create the column definition for a vector type: Array(Float32). The
     * dimensions are not part of a ClickHouse type.
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeVector(Fluent $column): string
    {
        return 'Array(Float32)';
    }

    /**
     * Create the column definition for an array column (SchemaBlueprint::array()).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeArray(Fluent $column): string
    {
        return "Array({$column->innerType})";
    }

    /**
     * Create the column definition for a map column (SchemaBlueprint::map()).
     *
     * @param Fluent $column
     * @return string
     */
    protected function typeMap(Fluent $column): string
    {
        return "Map({$column->keyType}, {$column->valueType})";
    }

    /**
     * Refuse a set column: ClickHouse has no SET type.
     *
     * @param Fluent $column
     * @return never
     * @throws RuntimeException Always
     */
    protected function typeSet(Fluent $column): never
    {
        throw new RuntimeException(
            "ClickHouse has no SET type (column [{$column->name}]). Use an array of an enum,"
            . " for example rawColumn('{$column->name}', \"Array(Enum('a', 'b'))\")."
        );
    }

    /**
     * Refuse a time column: ClickHouse 24.8 has no time-of-day type, and the
     * name Time gives an Int64 column there.
     *
     * @param Fluent $column
     * @return never
     * @throws RuntimeException Always
     */
    protected function typeTime(Fluent $column): never
    {
        throw new RuntimeException(
            "ClickHouse has no time-of-day type for {$column->type}() (column [{$column->name}]). Store the seconds since"
            . ' midnight in an integer column, the time as a string, or use dateTime().'
        );
    }

    /**
     * Refuse a time (with time zone) column, as typeTime() does.
     *
     * @param Fluent $column
     * @return never
     * @throws RuntimeException Always
     */
    protected function typeTimeTz(Fluent $column): never
    {
        $this->typeTime($column);
    }

    /**
     * Refuse a computed column (computed()): ClickHouse writes generated
     * columns with storedAs() and virtualAs().
     *
     * @param Fluent $column
     * @return never
     * @throws RuntimeException Always
     */
    protected function typeComputed(Fluent $column): never
    {
        throw new RuntimeException(
            "ClickHouse has no computed() column (column [{$column->name}]). Give the column a type and use"
            . ' storedAs() for a MATERIALIZED column or virtualAs() for an ALIAS column.'
        );
    }
}
