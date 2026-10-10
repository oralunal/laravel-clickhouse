<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use BackedEnum;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Query\Query;
use ClickHouseDB\Statement;
use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder;
use Stringable;
use Throwable;

class Builder extends BaseBuilder
{

    use WithClient;
    use BuilderMethodsFromLaravel;

    /**
     * Raw SQL that names a table, with or without its database: each part bare, or quoted with backticks or double
     * quotes (with backslash escapes and a doubled quote inside), such as events, db.events, `db`.`my-table` or
     * "db" . "t". A mutation of a query whose from() is any other raw SQL is refused (see readsFromRawSql()).
     */
    protected const RAW_TABLE_NAME_PATTERN = '/^\s*(?:`(?:[^`\\\\]|\\\\.|``)*`|"(?:[^"\\\\]|\\\\.|"")*"|[A-Za-z_][A-Za-z0-9_]*)'
        . '(?:\s*\.\s*'
        . '(?:`(?:[^`\\\\]|\\\\.|``)*`|"(?:[^"\\\\]|\\\\.|"")*"|[A-Za-z_][A-Za-z0-9_]*))?\s*\z/s';

    /**
     * A mutation condition, outside string literals and quoted names, whose tables ClickHouse resolves only when the
     * mutation runs: a sub-query (SELECT), or IN followed by anything but a parenthesis, as in IN ids, where ids is a
     * table (see refuseConditionsThatClickHouseCannotRun()).
     */
    protected const LATE_RESOLVED_CONDITION_PATTERN = '/\bSELECT\b|\bIN\b(?!\s*\()/i';

    /**
     * The settings of a SELECT sent outside the ClickHouse session of the builder's client (see
     * selectOutsideTheSession()). smi2 leaves a setting whose value is null out of the URL, so the request carries no
     * session.
     *
     * @var array<string, string|null>
     */
    protected const SETTINGS_OUTSIDE_THE_SESSION = [
        'default_format' => Format::JSON,
        'session_id' => null,
        'session_timeout' => null,
        'session_check' => null,
    ];

    /** @var string */
    protected $tableSources;
    /** @var Client */
    protected $client;
    /** @var array<string, bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable> */
    protected $settings = [];

    /**
     * The grammar that compiles the queries of this builder: always the package Grammar (see getGrammar()).
     *
     * @var Grammar
     */
    protected $grammar;

    /**
     * The name of the database connection to use.
     *
     * @var string|null
     */
    protected $connection = Connection::DEFAULT_NAME;

    /**
     * The connection whose options the builder follows (see followConnectionOptions()), or null for a builder that
     * follows none, such as one made with only a client.
     *
     * @var Connection|null
     */
    protected ?Connection $optionsConnection = null;

    /**
     * Whether delete(), update() and truncate() send ON CLUSTER to the default cluster of the followed connection
     * when onCluster() names none (see getEffectiveCluster()); withoutOnCluster() turns it off for this query.
     *
     * @var bool
     */
    protected bool $usesTheDefaultCluster = true;

    /**
     * Create a builder that runs its queries with the given client.
     *
     * Without a client, the builder takes the active node of the connection and follows the options of that
     * connection (see followConnectionOptions()). A builder made with a client follows no connection until
     * followConnectionOptions() is called, as Connection::query() does.
     *
     * A Laravel database expression, such as DB::raw(), needs the grammar of a database connection to give its SQL.
     * The grammar takes it from the connection the builder follows or, when it follows none, from the connection
     * of its name, which is only resolved when such an expression is written (see resolveLaravelGrammar()).
     *
     * @param Client|null $client Client to run queries with; defaults to the connection's active node.
     * @param string|null $connection Connection name used for the client and query logging.
     */
    public function __construct(?Client $client = null, ?string $connection = null)
    {
        $this->grammar = new Grammar();
        if ($connection !== null) {
            $this->connection = $connection;
        }
        $this->grammar->setLaravelGrammarResolver(fn (): \Illuminate\Database\Grammar => $this->resolveLaravelGrammar());

        if ($client !== null) {
            $this->client = $client;

            return;
        }

        $this->client = $this->getThisClient();
        $this->followConnectionOptions($this->resolveConnection());
    }

    /**
     * Get the grammar that writes a Laravel database expression, such as DB::raw(): the query grammar of the
     * connection the builder follows or, when it follows none, of the connection of its name.
     *
     * A builder made with only a client, such as new Builder($client), follows no connection. When the connection
     * of its name cannot be resolved, as outside a Laravel application or for a name that the application does not
     * configure, the expression cannot be written, and the exception says so, with the failure as its previous
     * exception.
     *
     * @return \Illuminate\Database\Grammar
     * @throws InvalidArgumentException When the builder follows no connection and the connection of its name cannot
     *                                  be resolved
     */
    protected function resolveLaravelGrammar(): \Illuminate\Database\Grammar
    {
        if ($this->optionsConnection !== null) {
            return $this->optionsConnection->getQueryGrammar();
        }

        try {
            $connection = $this->resolveConnection();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(sprintf(
                'Cannot write a Laravel database expression without the grammar of a database connection: the builder'
                . ' follows no connection, and its connection [%s] cannot be resolved (%s). Build the query from a'
                . ' ClickHouse connection or a model, or pass raw SQL as an %s.',
                $this->connection,
                $exception->getMessage(),
                Expression::class
            ), 0, $exception);
        }

        return $connection->getQueryGrammar();
    }

    /**
     * Make the builder follow the options of a connection: its grammar writes dates at the connection's
     * datetime_precision, writes a Laravel database expression, such as DB::raw(), with the connection's query
     * grammar, and the connection is kept for the options that are read when a statement is sent.
     *
     * The connection is only stored, not resolved by name, and newQuery() passes it on, so the builders of
     * closures, sub-queries, count() and exists() follow it too. Connection::query() and a builder made without a
     * client call this method; a builder that does not follow a connection writes dates with second precision.
     *
     * @param Connection $connection
     * @return static
     * @throws \InvalidArgumentException When the connection's datetime_precision is invalid
     */
    public function followConnectionOptions(Connection $connection): static
    {
        $this->optionsConnection = $connection;
        $this->grammar->setDateTimePrecision($connection->getDateTimePrecision());

        return $this;
    }

    /**
     * Send delete(), update() and truncate() of this query without ON CLUSTER: an earlier onCluster() is dropped,
     * and so is the default cluster that the use_on_cluster option of the followed connection gives (see
     * getEffectiveCluster()). A later onCluster() names a cluster again.
     *
     * Connection::withoutOnCluster() turns the default off for every query that runs in its callback instead.
     *
     * @return static
     */
    public function withoutOnCluster(): static
    {
        $this->onCluster = null;
        $this->usesTheDefaultCluster = false;

        return $this;
    }

    /**
     * Add settings to the SETTINGS clause of the SELECT statement.
     *
     * Settings from earlier calls are kept. A later value for the same name
     * replaces the earlier one, and a null value removes the setting. A value
     * that Grammar::compileSettingsComponent() cannot write, such as an array or
     * a collection, makes toSql() and get() throw an InvalidArgumentException.
     *
     * An Expression, a RawColumn or a Laravel database expression, such as
     * DB::raw('2'), is written as the SQL it holds.
     *
     * @link https://clickhouse.com/docs/en/sql-reference/statements/select#settings-in-select-query
     * @param array<string, bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable|null>|string $settings For example: ['max_threads' => 3], or a setting name
     * @param bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable|null $value The value, when $settings is a setting name
     * @return $this
     */
    public function settings(array|string $settings, mixed $value = null): self
    {
        if (is_string($settings)) {
            $settings = [$settings => $value];
        }

        foreach ($settings as $name => $settingValue) {
            if ($settingValue === null) {
                unset($this->settings[$name]);
            } else {
                $this->settings[$name] = $settingValue;
            }
        }

        return $this;
    }

    /**
     * @return array<string, bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Get the elapsed time in milliseconds since a given starting point.
     *
     * @param float $start
     * @return float
     */
    protected function getElapsedTime($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Run the query and return its statement.
     *
     * The SQL that is sent, and logged, is that of getQueryToSend(): the SQL of
     * toSql(), with FORMAT JSON when the query has settings and no format. It is
     * sent with ClientRequests::select(), which reads the result in the format
     * that the query names, or JSON when it names none, also when a value, a
     * name or a comment in the SQL holds FORMAT and a format name, such as a
     * where() value 'export as format csv'.
     *
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @return Statement
     */
    public function get(array $bindings = []): Statement
    {
        $query = $this->getQueryToSend();
        $sql = $query->toSql();
        $start = microtime(true);

        $statement = ClientRequests::select($this->client, $sql, $bindings, $query->getFormat()?->getValue());

        $this->resolveConnection()->logQuery($sql, $bindings, $this->getElapsedTime($start));

        return $statement;
    }

    /**
     * Get the query that get() sends: this query, or a copy of it that names the
     * JSON format when the query has settings and no format.
     *
     * The client asks for JSON by adding FORMAT JSON to the end of a query that
     * has no FORMAT clause, so after its SETTINGS clause. ClickHouse 24.8 rejects
     * FORMAT there when the query ends with a set-operation operand in
     * parentheses, as $a->except($b->unionAll($c)) does. The copy compiles to
     * ... FORMAT JSON SETTINGS ..., which ClickHouse accepts, and the client adds
     * nothing to a query that names its format. Only the outer query gets the
     * format: its sub-queries are compiled from their own builders.
     *
     * Code that sends the query itself, such as a batch of parallel queries,
     * sends the toSql() of this query on getQueryClient() as get() sends it:
     * with ClientRequests::select(), or ClientRequests::selectRequest() and
     * then ClientRequests::selectStatement() for a request that it runs itself,
     * passing getFormat()?->getValue() of this query as the format. smi2's
     * Client::select() and Client::selectAsync() search the whole SQL for FORMAT
     * and a format name, so a value such as 'export as format csv' would make
     * them read the result in the wrong format.
     *
     * @return static
     */
    public function getQueryToSend(): self
    {
        if ($this->format !== null || $this->settings === []) {
            return $this;
        }

        $query = clone $this;
        $query->format = new Format(Format::JSON);

        return $query;
    }

    /**
     * Get the client that get() runs the query with: the one the builder was made with, or the active node of its
     * connection.
     *
     * @return Client
     */
    public function getQueryClient(): Client
    {
        return $this->client;
    }

    /**
     * Get the grammar that compiles the queries of this builder.
     *
     * Laravel's QueryExecuted::toRawSql() calls it on the query builder of the connection, so that a DB::listen()
     * callback can write the bindings of a logged query into its SQL (see SubstitutesBindings).
     *
     * @return Grammar
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Run the query and return its rows as associative arrays.
     *
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @return array<int, array<string, mixed>>
     */
    public function getRows(array $bindings = []): array
    {
        return $this->get($bindings)->rows();
    }

    /**
     * Run the query and return its rows, as getRows() returns them, in a Laravel collection.
     *
     * The rows stay associative arrays: collection methods such as pluck(), keyBy() and sum() work on them, and
     * $row->id needs (object) $row. get() keeps returning smi2's Statement.
     *
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @return Collection<int, array<string, mixed>>
     */
    public function getCollection(array $bindings = []): Collection
    {
        return new Collection($this->getRows($bindings));
    }

    /**
     * Run the query for its first row, as an associative array as getRows() returns rows, or null when it returns
     * no row.
     *
     * One row is fetched, by a copy of the query (see getQueryForRows()): within its own LIMIT and OFFSET, and from
     * the whole result of a set operation. When the query selects every column (see selectsEveryColumn()), as a
     * model query and a query without a select list do, the given columns replace *; otherwise they are ignored, as
     * in Laravel.
     *
     * @param array<int, string|Expression|ExpressionContract>|string $columns
     * @return array<string, mixed>|null
     */
    public function first(array|string $columns = ['*']): ?array
    {
        $query = $this->getQueryForRows(1);
        $columns = is_array($columns) ? $columns : [$columns];
        if ($columns !== ['*'] && $this->selectsEveryColumn()) {
            $query->select($columns);
        }

        return $query->getRows()[0] ?? null;
    }

    /**
     * Run the query for one value of its first row, or null when it returns no row.
     *
     * When the query selects every column (see selectsEveryColumn()), only the given column is selected, and its
     * value is read by its position, because ClickHouse names an expression that has no alias after its SQL, such
     * as plus(a, 1) for raw('a + 1'). Otherwise the query runs as it is, and the value is read by the name that
     * ClickHouse gives the column, as pluck() reads it (see keyOfAColumnInARow()): value('u.s') reads u.s for a
     * column of a joined table and value('n.k') reads n.k for a column of a Nested structure, while value('t.a')
     * reads a for a column of the query's own table, and value('a as x') reads x.
     *
     * @param string|Expression|ExpressionContract $column
     * @return mixed
     * @throws InvalidArgumentException When the query has a select list and its row has no column of that name
     */
    public function value(string|Expression|ExpressionContract $column): mixed
    {
        if ($this->selectsEveryColumn()) {
            $row = $this->first([$column]);

            return $row === null ? null : (array_values($row)[0] ?? null);
        }

        $row = $this->first();
        if ($row === null) {
            return null;
        }

        $name = $this->keyOfAColumnInARow($column, $row);
        if (!array_key_exists($name, $row)) {
            throw new InvalidArgumentException("The query does not select the column [{$name}] that value() reads.");
        }

        return $row[$name];
    }

    /**
     * Run the query in pages of $count rows and pass each page to the callback, as Laravel's chunk() does.
     *
     * The callback gets the rows of a page, as getRows() returns them, and the page number, which starts at 1.
     * Chunking stops when a page comes back empty, without calling the callback, after a page with fewer than
     * $count rows, and when the callback returns false. Each page is read by a copy of the query (see
     * getQueryForRows()), so the builder is left unchanged: a LIMIT and OFFSET of the query limit the rows that
     * chunk() reads, and a set operation is paged as a whole. ClickHouse returns rows in no fixed order without
     * an ORDER BY, so pages of a query without one can overlap or leave rows out.
     *
     * @param int $count Rows per page, at least 1
     * @param callable(array<int, array<string, mixed>>, int): mixed $callback
     * @return void
     * @throws InvalidArgumentException When $count is less than 1
     */
    public function chunk(int $count, callable $callback): void
    {
        if ($count < 1) {
            throw new InvalidArgumentException("The chunk size should be at least 1, {$count} given.");
        }

        $page = 1;
        do {
            $query = $this->getQueryForRows($count, ($page - 1) * $count);
            if ($query->getLimit()?->getLimit() === 0) {
                return;
            }

            $rows = $query->getRows();
            $rowCount = count($rows);
            if ($rowCount === 0 || $callback($rows, $page) === false) {
                return;
            }

            unset($rows);
            $page++;
        } while ($rowCount === $count);
    }

    /**
     * Get a copy of the query that reads $count rows past $offset, as the paging methods run it, so that the
     * builder keeps its own clauses.
     *
     * A query with set operations (UNION, INTERSECT, EXCEPT) is read as a whole, as
     * SELECT * FROM (<query>) LIMIT <offset>, <count>, with the SETTINGS of the query on the outer query and its
     * FORMAT left out. A LIMIT of such a query belongs to its first SELECT, and stays there. Any other query is
     * copied with LIMIT <offset>, <count>. With $withinOwnLimit, as chunk(), first() and value() read rows, the copy
     * stays within the query's own LIMIT and OFFSET: the offset counts from its own OFFSET, and the count is cut to
     * what is left of its own LIMIT, down to 0. Without it, as paginate() and simplePaginate() read rows, the LIMIT
     * and OFFSET replace those of the query, as in Laravel.
     *
     * @param int $count
     * @param int|null $offset
     * @param bool $withinOwnLimit
     * @return static
     */
    protected function getQueryForRows(int $count, ?int $offset = null, bool $withinOwnLimit = true): self
    {
        if ($this->getUnions() !== []) {
            $query = $this->newQuery()->select('*')->from($this->cloneWithout(['settings' => [], 'format' => null]));
            $query->settings = $this->settings;

            return $query->limit($count, $offset);
        }

        $ownLimit = $this->getLimit();
        if ($withinOwnLimit && $ownLimit !== null) {
            $skipped = $offset ?? 0;
            $count = max(0, min($count, (int) $ownLimit->getLimit() - $skipped));
            $offset = $ownLimit->getOffset() === null && $offset === null
                ? null
                : (int) $ownLimit->getOffset() + $skipped;
        }

        return (clone $this)->limit($count, $offset);
    }

    /**
     * Determine if the query selects every column: it has no select list, or only *, and no set operation.
     *
     * A model query selects *. pluck(), first() and value() select only their columns from such a query.
     *
     * @return bool
     */
    protected function selectsEveryColumn(): bool
    {
        if ($this->getUnions() !== []) {
            return false;
        }

        $columns = $this->getColumns();
        if ($columns === []) {
            return true;
        }

        if (count($columns) !== 1) {
            return false;
        }

        $name = $columns[0]->getColumnName();

        return $name instanceof Identifier
            && (string) $name === '*'
            && $columns[0]->getFunctions() === []
            && $columns[0]->getAlias() === null;
    }

    /**
     * For delete query
     * @param string $table
     * @return $this
     */
    public function setSourcesTable(string $table): self
    {
        $this->tableSources = $table;

        return $this;
    }

    /**
     * Resolve the table targeted by data-modifying queries (ALTER TABLE, DELETE FROM, TRUNCATE, INSERT).
     *
     * A sources table set with setSourcesTable(), as every model query does, is
     * written as given. Otherwise the from() table is compiled as in a SELECT: a
     * name is quoted part by part, so default.my-table becomes `default`.`my-table`,
     * and an Expression is written as is.
     *
     * @return string
     * @throws GrammarException When the query has neither a sources table nor a from() table
     */
    protected function getTableForWrites(): string
    {
        if ($this->tableSources !== null) {
            return $this->tableSources;
        }

        $table = $this->getFrom()?->getTable();
        if ($table === null) {
            throw GrammarException::wrongFrom();
        }

        return (string) $this->grammar->wrap($table);
    }

    /**
     * Delete the rows that match the where conditions.
     *
     * $lightweight = true sends a lightweight DELETE FROM: the deleted rows no
     * longer show up in selects once the call returns. $lightweight = false
     * sends ALTER TABLE ... DELETE, a mutation that rewrites whole data parts:
     * a heavy operation not designed for frequent use. With null, the
     * connection's use_lightweight_delete option decides (false by default).
     *
     * A lightweight delete with a partition sends DELETE FROM ... IN PARTITION,
     * which older ClickHouse releases, 24.8 included, reject with a syntax error.
     *
     * The statement gets ON CLUSTER for onCluster(), or for the default cluster of
     * the use_on_cluster option of the followed connection unless withoutOnCluster()
     * was called on the query or the connection (see getEffectiveCluster()). While
     * the connection pretends, the statement is logged and not sent (see
     * ClientRequests::write()).
     *
     * Only the where and prewhere conditions take part: PREWHERE conditions are part
     * of the WHERE clause (see compileMutationWheres()). Before anything is sent, the
     * delete is refused:
     * - for a query that uses a clause the delete would ignore, such as a JOIN, a
     *   LIMIT, FINAL, SETTINGS or an alias in the select list, before the connection
     *   is resolved (see getClausesAMutationWouldIgnore());
     * - as a lightweight delete, for a condition that holds UNION, INTERSECT or
     *   EXCEPT (see refuseASetOperationInALightweightDelete());
     * - in a session, as a lightweight delete or with ON CLUSTER, when a temporary
     *   table of the session has the table's name (see
     *   refuseATemporaryTableThatTheStatementMisses());
     * - for a condition with a sub-query or an IN <table> that ClickHouse cannot run
     *   in a SELECT, such as one that reads a column of the deleted table, or, in a
     *   session, a temporary table of the session (see
     *   refuseConditionsThatClickHouseCannotRun()).
     *
     * @link https://clickhouse.com/docs/en/sql-reference/statements/delete
     * @link https://clickhouse.com/docs/en/sql-reference/statements/alter/delete
     * @param bool|null $lightweight
     * @param int|string|Expression|ExpressionContract|null $partition Only delete in this partition (IN PARTITION): an int is written as a number, a string as a string literal, an Expression or a Laravel database expression, such as DB::raw(), as the SQL it holds
     * @return Statement
     * @throws QueryException When the query has neither a where nor a prewhere condition, or the delete is refused
     *                        for one of the reasons above
     * @throws GrammarException When the query has no table
     */
    public function delete(
        ?bool $lightweight = null,
        int|string|Expression|ExpressionContract|null $partition = null
    ): Statement {
        if ($this->getWheres() === [] && $this->getPreWheres() === []) {
            throw QueryException::cannotDeleteWithoutWhere();
        }
        $this->verifyClausesOfMutation('delete');

        $table = $this->getTableForWrites();
        $connection = $this->getConnectionForWrites();
        $lightweight ??= (bool) $connection?->getConfig('use_lightweight_delete');
        $wheres = $this->compileMutationWheres();
        if ($lightweight) {
            $this->refuseASetOperationInALightweightDelete($wheres);
        }

        $cluster = $this->getEffectiveCluster();
        if ($lightweight) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $connection,
                $cluster === null ? 'DELETE FROM' : 'DELETE FROM ... ON CLUSTER',
                $cluster === null
                    ? 'Call delete(false), which sends ALTER TABLE ... DELETE to the temporary table.'
                    : 'Call delete(false) without ON CLUSTER, which sends ALTER TABLE ... DELETE to the temporary'
                        . ' table: leave out onCluster(), and call withoutOnCluster() when the connection sets'
                        . ' use_on_cluster.'
            );
        } elseif ($cluster !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $connection,
                'ALTER TABLE ... ON CLUSTER ... DELETE',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        $target = $table . $this->compileOnClusterClause();
        $partitionClause = $this->compilePartitionClause($partition);
        $sql = $lightweight
            ? "DELETE FROM {$target}{$partitionClause} {$wheres}"
            : "ALTER TABLE {$target} DELETE{$partitionClause} {$wheres}";

        $this->refuseConditionsThatClickHouseCannotRun(
            $connection,
            'delete',
            $table,
            $wheres,
            $lightweight || $cluster !== null
        );

        return $this->sendWrite($connection, $sql);
    }

    /**
     * Update the rows that match the where conditions with ALTER TABLE ... UPDATE.
     *
     * Note! This is a mutation that rewrites whole data parts: a heavy operation
     * not designed for frequent use.
     *
     * The statement gets ON CLUSTER as delete() gets it (see getEffectiveCluster()).
     * While the connection pretends, the statement is logged and not sent (see
     * ClientRequests::write()).
     *
     * Only the where and prewhere conditions take part: PREWHERE conditions are part
     * of the WHERE clause (see compileMutationWheres()). Before anything is sent, the
     * update is refused:
     * - for a query that uses a clause the update would ignore, such as a JOIN, a
     *   LIMIT, FINAL, SETTINGS or an alias in the select list, before the connection
     *   is resolved (see getClausesAMutationWouldIgnore());
     * - in a session, with ON CLUSTER, when a temporary table of the session has the
     *   table's name (see refuseATemporaryTableThatTheStatementMisses());
     * - for a condition with a sub-query or an IN <table> that ClickHouse cannot run
     *   in a SELECT, such as one that reads a column of the updated table, or, in a
     *   session, a temporary table of the session (see
     *   refuseConditionsThatClickHouseCannotRun()).
     *
     * Each column name is quoted as one identifier, with its backticks and
     * backslashes escaped, so a Nested name such as n.a is written as `n.a`.
     *
     * @link https://clickhouse.com/docs/en/sql-reference/statements/alter/update
     * @param array<string, mixed> $values Column => value; an Expression value is written as is and an array as an array literal, such as ['a', 'b']
     * @param int|string|Expression|ExpressionContract|null $partition Only update in this partition (IN PARTITION): an int is written as a number, a string as a string literal, an Expression or a Laravel database expression, such as DB::raw(), as the SQL it holds
     * @return Statement
     * @throws QueryException When $values is empty, the query has neither a where nor a prewhere condition, or the
     *                        update is refused for one of the reasons above
     * @throws GrammarException When the query has no table
     */
    public function update(array $values, int|string|Expression|ExpressionContract|null $partition = null): Statement
    {
        if (empty($values)) {
            throw QueryException::cannotUpdateEmptyValues();
        }
        if ($this->getWheres() === [] && $this->getPreWheres() === []) {
            throw QueryException::cannotUpdateWithoutWhere();
        }
        $this->verifyClausesOfMutation('update');

        $table = $this->getTableForWrites();
        $connection = $this->getConnectionForWrites();
        $cluster = $this->getEffectiveCluster();
        if ($cluster !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $connection,
                'ALTER TABLE ... ON CLUSTER ... UPDATE',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        $set = [];
        foreach ($values as $key => $value) {
            $set[] = $this->grammar->quoteIdentifier((string) $key) . ' = '
                . (is_array($value) ? $this->grammar->compileArray($value) : $this->grammar->wrap($value));
        }
        $wheres = $this->compileMutationWheres();
        $sql = 'ALTER TABLE ' . $table . $this->compileOnClusterClause()
            . ' UPDATE ' . implode(', ', $set) . $this->compilePartitionClause($partition)
            . ' ' . $wheres;

        $this->refuseConditionsThatClickHouseCannotRun($connection, 'update', $table, $wheres, $cluster !== null);

        return $this->sendWrite($connection, $sql);
    }

    /**
     * Get the connection that a write of this query follows: the connection whose options the builder follows (see
     * followConnectionOptions()) or, for a builder that follows none, such as one made with only a client, the
     * connection of its name.
     *
     * Its use_lightweight_delete option is the default of delete(), its insert_format the default of insert(), and
     * while it pretends, writes are logged and not sent (see ClientRequests::write()). The write itself goes to the
     * builder's client.
     *
     * A builder that follows no connection, and whose connection cannot be resolved, as outside a Laravel
     * application or for a name that the application does not configure, writes as in 3.0.0: with no connection,
     * so delete() sends ALTER TABLE ... DELETE, insert() sends Values, and every write is sent (see sendWrite()).
     *
     * @return Connection|null The connection, or null when the builder follows none and its own cannot be resolved
     */
    protected function getConnectionForWrites(): ?Connection
    {
        if ($this->optionsConnection !== null) {
            return $this->optionsConnection;
        }

        try {
            return $this->resolveConnection();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Send a write on the builder's client: through ClientRequests::write(), which logs it and leaves it out while
     * the connection pretends, or, without a connection (see getConnectionForWrites()), straight through the
     * client's write(), as 3.0.0 sent it.
     *
     * @param Connection|null $connection The connection of the write (see getConnectionForWrites())
     * @param string $sql
     * @return Statement
     */
    protected function sendWrite(?Connection $connection, string $sql): Statement
    {
        return $connection === null
            ? $this->client->write($sql)
            : ClientRequests::write($this->client, $connection, $sql);
    }

    /**
     * Get the cluster that delete(), update() and truncate() send ON CLUSTER to: the cluster of onCluster() or,
     * when the query names none, the default cluster of the followed connection (see Connection::getDefaultCluster(),
     * which is null unless use_on_cluster is on and no Connection::withoutOnCluster() callback runs), unless
     * withoutOnCluster() was called on the query. A builder that follows no connection has no default cluster.
     *
     * It is read when the statement is sent, so Connection::withoutOnCluster() also covers a builder made before its
     * callback runs, and it never resolves the connection by name.
     *
     * @return string|null
     * @throws InvalidArgumentException When the use_on_cluster option of the followed connection is not a boolean
     */
    protected function getEffectiveCluster(): ?string
    {
        $cluster = $this->getOnCluster();
        if ($cluster !== null || !$this->usesTheDefaultCluster) {
            return $cluster;
        }

        return $this->optionsConnection?->getDefaultCluster();
    }

    /**
     * Describe how to send a statement without ON CLUSTER, for the message of a statement that would miss a
     * temporary table of the session (see refuseATemporaryTableThatTheStatementMisses()).
     *
     * @return string
     */
    protected function describeHowToLeaveOutOnCluster(): string
    {
        return 'Send it without ON CLUSTER, so that it reaches the temporary table: leave out onCluster(), and call'
            . ' withoutOnCluster() when the connection sets use_on_cluster.';
    }

    /**
     * Refuse a statement that would miss a temporary table of the session and change the table of that name in the
     * database instead.
     *
     * ClickHouse (24.8 checked) runs a lightweight DELETE FROM on the database table of the name, and runs every ON
     * CLUSTER statement outside the session, on the table of that name in the database of every node, while ALTER
     * TABLE ... DELETE, ALTER TABLE ... UPDATE and TRUNCATE TABLE without ON CLUSTER reach the temporary table. So
     * when the builder's client carries a session id and the table of the write is a name without a database (see
     * getUnqualifiedTableForWrites()), EXISTS TEMPORARY TABLE `<name>` is sent on the same client first (see
     * isATemporaryTableOfTheSession()). Outside a session, for a name with a database, for raw SQL and while the
     * connection pretends, nothing is sent.
     *
     * @param Connection|null $connection The connection of the write (see getConnectionForWrites())
     * @param string $statement The statement, such as 'DELETE FROM' or 'TRUNCATE TABLE ... ON CLUSTER'
     * @param string $alternative A sentence that says what to call instead
     * @return void
     * @throws QueryException When the session has a temporary table of the name (see
     *                        QueryException::cannotReachTemporaryTable())
     */
    protected function refuseATemporaryTableThatTheStatementMisses(
        ?Connection $connection,
        string $statement,
        string $alternative
    ): void {
        $table = $this->getUnqualifiedTableForWrites();
        if ($table === null || !$this->client->getSession() || $connection?->pretending()) {
            return;
        }

        if ($this->isATemporaryTableOfTheSession($table)) {
            throw QueryException::cannotReachTemporaryTable($statement, $table, $alternative);
        }
    }

    /**
     * Determine if the session of the builder's client has a temporary table of a given name: EXISTS TEMPORARY TABLE
     * `<name>` is sent on the client, unlogged.
     *
     * @param string $table A name without a database (see getUnqualifiedTableForWrites())
     * @return bool
     */
    protected function isATemporaryTableOfTheSession(string $table): bool
    {
        $sql = 'EXISTS TEMPORARY TABLE ' . $this->grammar->quoteIdentifier($table);

        return (int) ClientRequests::select($this->client, $sql)->fetchOne('result') === 1;
    }

    /**
     * Get the table of a write as a name without a database, as a temporary table is named: the sources table, or
     * else the from() table when it is a name, with the backticks around it taken off. Null for a name with a
     * database, a name with spaces or an inner dot, and raw SQL, which are not checked against the temporary tables
     * of a session.
     *
     * @return string|null
     */
    protected function getUnqualifiedTableForWrites(): ?string
    {
        $table = $this->tableSources;
        if ($table === null) {
            $from = $this->getFrom()?->getTable();
            $table = $from instanceof Identifier ? (string) $from : null;
        }
        if ($table === null) {
            return null;
        }

        $table = trim($table);
        if (preg_match('/^`((?:[^`\\\\]|``)*)`\z/', $table, $quoted) === 1) {
            $table = str_replace('``', '`', $quoted[1]);
        } elseif (preg_match('/[`"\s]/', $table) === 1) {
            return null;
        }

        return $table === '' || str_contains($table, '.') ? null : $table;
    }

    /**
     * Refuse a lightweight delete whose conditions hold UNION, INTERSECT or EXCEPT, outside string literals and quoted
     * names, as a whereIn() or whereExists() sub-query with a set operation writes them.
     *
     * ClickHouse (24.8 checked) runs a lightweight DELETE FROM as a mutation that fails for such a condition with
     * 'UNION mode UNION_DEFAULT must be normalized', and the failed mutation stays: every later mutation of the table
     * waits behind it until KILL MUTATION. ALTER TABLE ... DELETE runs the same condition. Raw SQL with one of these
     * words, in a comment or for another reason, such as SELECT * EXCEPT (a), is refused too.
     *
     * @param string $wheres The compiled WHERE clause of the delete
     * @return void
     * @throws QueryException When the conditions hold a set operation
     */
    protected function refuseASetOperationInALightweightDelete(string $wheres): void
    {
        if (preg_match('/\b(?:UNION|INTERSECT|EXCEPT)\b/i', $this->getSqlOutsideQuotes($wheres)) !== 1) {
            return;
        }

        throw new QueryException(
            'Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION, INTERSECT or'
            . ' EXCEPT: ClickHouse (24.8 checked) fails such a delete and leaves its mutation behind, which blocks'
            . ' every later mutation of the table until KILL MUTATION. Nothing was sent. Call delete(false), which'
            . ' sends ALTER TABLE ... DELETE with the same condition, or select the keys first, and delete by them'
            . " instead: whereIn('id', \$query->pluck('id'))."
        );
    }

    /**
     * Refuse a mutation whose conditions hold a sub-query or an IN <table> that ClickHouse cannot run in a SELECT,
     * before the mutation is sent.
     *
     * ClickHouse (24.8 checked) accepts ALTER TABLE ... DELETE and UPDATE with a condition whose sub-query reads a
     * column of the mutated table, such as whereExists() with whereColumn('orders.user_id', 'users.id') on the
     * table users, and then fails the mutation again and again: it never finishes, and every later mutation of the
     * table waits behind it until KILL MUTATION. A lightweight DELETE FROM fails and leaves the same mutation. The
     * same happens for a table that a sub-query or IN <table>, such as whereIn('id', raw('ids')), names and that
     * does not exist when the mutation runs. So when the conditions hold SELECT, or IN followed by anything but a
     * parenthesis, outside string literals and quoted names, EXPLAIN PLAN SELECT 1 FROM <table> <conditions> is sent
     * on the builder's client first, unlogged. ClickHouse refuses it for such a condition, and for any other
     * condition that it cannot run, such as one that names a column that a sub-query's table does not have. Other
     * conditions are left for ClickHouse to check when it gets the mutation, and nothing is sent for them, nor while
     * the connection pretends.
     *
     * The EXPLAIN ends with SETTINGS use_index_for_in_with_subqueries = 0, on a line of its own so that a comment at
     * the end of the conditions cannot hide it: otherwise ClickHouse would run an IN sub-query on a column of the
     * primary key to build its set for the index, so that the call waited for it, while the mutation is queued at
     * once. ClickHouse still runs a scalar sub-query, such as where('id', '=', fn ($q) => $q->selectRaw('max(id)')),
     * once for the EXPLAIN, before the mutation runs it again.
     *
     * In a session, ClickHouse runs a mutation of a table of the database in the background, outside the session,
     * where the temporary tables of the session do not exist. The EXPLAIN is then sent outside the session too (see
     * selectOutsideTheSession()), so that a condition that reads a temporary table of the session is refused: for a
     * lightweight DELETE FROM and a statement with ON CLUSTER, and for ALTER TABLE ... DELETE or UPDATE of a table
     * that is not a temporary table of the session (see isATemporaryTableOfTheSession(), which sends EXISTS
     * TEMPORARY TABLE for a name without a database; a name with a database and raw SQL are taken for tables of the
     * database). A mutation of a temporary table runs in the session, so its EXPLAIN is sent in the session.
     *
     * @param Connection|null $connection The connection of the write (see getConnectionForWrites())
     * @param string $statement The statement: delete or update
     * @param string $table The table of the write, as the mutation names it
     * @param string $wheres The compiled WHERE clause of the mutation
     * @param bool $runsOutsideTheSession Whether ClickHouse runs the mutation outside the session whatever its table:
     *                                    a lightweight DELETE FROM, or a statement with ON CLUSTER
     * @return void
     * @throws QueryException When ClickHouse refuses the EXPLAIN, with its DatabaseException as the previous exception
     */
    protected function refuseConditionsThatClickHouseCannotRun(
        ?Connection $connection,
        string $statement,
        string $table,
        string $wheres,
        bool $runsOutsideTheSession = false
    ): void {
        if ($connection?->pretending()
            || preg_match(static::LATE_RESOLVED_CONDITION_PATTERN, $this->getSqlOutsideQuotes($wheres)) !== 1
        ) {
            return;
        }

        $sql = "EXPLAIN PLAN SELECT 1 FROM {$table} {$wheres}\nSETTINGS use_index_for_in_with_subqueries = 0";
        $temporaryTable = $this->getUnqualifiedTableForWrites();
        $outsideTheSession = $this->client->getSession()
            && ($runsOutsideTheSession
                || $temporaryTable === null
                || !$this->isATemporaryTableOfTheSession($temporaryTable));

        try {
            ($outsideTheSession ? $this->selectOutsideTheSession($sql) : ClientRequests::select($this->client, $sql))
                ->rows();
        } catch (DatabaseException $exception) {
            throw new QueryException(
                $this->describeConditionsThatClickHouseCannotRun($statement, $exception, $outsideTheSession),
                0,
                $exception
            );
        }
    }

    /**
     * Describe the refusal of a mutation whose EXPLAIN ClickHouse refused (see
     * refuseConditionsThatClickHouseCannotRun()). The message names the error and, for an EXPLAIN sent outside the
     * session, the temporary tables of the session, which the mutation cannot read.
     *
     * @param string $statement The statement: delete or update
     * @param DatabaseException $exception The refusal of the EXPLAIN
     * @param bool $outsideTheSession Whether the EXPLAIN was sent outside the session
     * @return string
     */
    protected function describeConditionsThatClickHouseCannotRun(
        string $statement,
        DatabaseException $exception,
        bool $outsideTheSession
    ): string {
        $error = $exception->getClickHouseExceptionName() ?? 'code ' . $exception->getCode();
        $reason = $outsideTheSession
            ? sprintf(
                'it refused the condition in a SELECT outside the session with %2$s. ClickHouse (24.8 checked) runs the'
                . ' mutation outside the session, where the temporary tables of the session do not exist, so a'
                . ' condition that reads one is such a condition, and so is a sub-query that reads a column of the'
                . ' table of the %1$s: ClickHouse accepts the mutation and then fails it again and again',
                $statement,
                $error
            )
            : sprintf(
                'it refused the condition in a SELECT with %2$s. A sub-query that reads a column of the table of the'
                . ' %1$s, such as whereExists() with whereColumn() on that table, is such a condition: ClickHouse (24.8'
                . ' checked) accepts the mutation and then fails it again and again',
                $statement,
                $error
            );

        return sprintf(
            'Cannot %1$s with a where condition that ClickHouse cannot run: %2$s, which blocks every later mutation of'
            . " the table until KILL MUTATION. Nothing was sent. Select the keys first, and %1\$s by them instead:"
            . " whereIn('id', \$query->pluck('id')).",
            $statement,
            $reason
        );
    }

    /**
     * Run a SELECT on the builder's client outside the ClickHouse session of the client: the request leaves out
     * session_id, session_timeout and session_check, so that the temporary tables of the session do not exist for
     * it, as for a mutation that ClickHouse runs in the background. The SQL is sent as given, with readonly=2 and
     * default_format=JSON, through the client's curler, and the statement reads the result in the format that
     * ClickHouse names (see ClientRequests::selectStatement()).
     *
     * @param string $sql
     * @return Statement
     */
    protected function selectOutsideTheSession(string $sql): Statement
    {
        $transport = $this->client->transport();
        $request = $transport->getRequestRead(new Query($sql), null, null, static::SETTINGS_OUTSIDE_THE_SESSION);
        $request->setRequestExtendedInfo(array_merge($request->getRequestExtendedInfo(), ['format' => Format::JSON]));
        $transport->getCurler()->execOne($request);

        return ClientRequests::selectStatement($request);
    }

    /**
     * Get raw SQL without its string literals in single quotes and its names in double quotes or backticks (with
     * backslash escapes and doubled quotes), each replaced by a space, so that a word in a value or a name is not
     * read as a keyword. If the SQL is too long for PCRE to scan, it is returned as it is, so that a word in it still
     * counts.
     *
     * @param string $sql
     * @return string
     */
    protected function getSqlOutsideQuotes(string $sql): string
    {
        return preg_replace(
            '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`(?:[^`\\\\]++|\\\\.|``)*+`/s',
            ' ',
            $sql
        ) ?? $sql;
    }

    /**
     * Compile the WHERE clause of a mutation (DELETE FROM, ALTER TABLE ... DELETE or UPDATE).
     *
     * Mutations have no PREWHERE, so the prewhere conditions are joined to the
     * where conditions: WHERE (<prewheres>) AND (<wheres>), or WHERE <prewheres>
     * when the query has no where condition. Without prewhere conditions the
     * clause is the one a SELECT gets.
     *
     * @return string
     */
    protected function compileMutationWheres(): string
    {
        $preWheres = $this->getPreWheres();
        $wheres = $this->getWheres();

        if ($preWheres !== [] && $wheres !== []) {
            $wheres = [
                (new TwoElementsLogicExpression($this))->firstElement($preWheres)->concatOperator(Operator::AND),
                (new TwoElementsLogicExpression($this))->firstElement($wheres)->concatOperator(Operator::AND),
            ];
        } elseif ($wheres === []) {
            $wheres = $preWheres;
        }

        return $this->grammar->compileWheresComponent($this, $wheres);
    }

    /**
     * Refuse a mutation of this query when it uses a clause that the mutation would ignore.
     *
     * The paging methods of this builder, such as chunk(), paginate() and first(),
     * run on copies, so a LIMIT the exception names was set on the query itself.
     *
     * @param string $statement The statement: delete or update
     * @return void
     * @throws QueryException When the query uses a clause that getClausesAMutationWouldIgnore() returns
     */
    protected function verifyClausesOfMutation(string $statement): void
    {
        $clauses = $this->getClausesAMutationWouldIgnore();

        if ($clauses !== []) {
            throw QueryException::cannotMutateWithClauses($statement, $clauses);
        }
    }

    /**
     * Get the clauses of this query that a mutation would ignore, in the order of a SELECT statement.
     *
     * A mutation (DELETE FROM, ALTER TABLE ... DELETE or UPDATE) only takes the where
     * and prewhere conditions. Every other clause that can change which rows the query
     * selects, or what its conditions read, is returned, because without it the
     * mutation could change rows that the query does not select:
     *
     * - WITH;
     * - 'SELECT aliases or expressions': a selected column that is not plain (see
     *   isPlainColumn()), such as an alias, a function, a DISTINCT column, a
     *   sub-query, a withAlias() name or any raw SQL, which can define an alias
     *   without AS. ClickHouse reads a condition on a select alias as the aliased
     *   expression, while the mutation reads the table's column of that name;
     * - 'a FROM sub-query': with a sources table, as every model query has, the
     *   mutation writes to that table and ignores the sub-query; without one it
     *   would name the sub-query as its table, which ClickHouse rejects. A from()
     *   table that differs from the sources table, as with a Buffer table, is
     *   allowed;
     * - 'a FROM table function or raw SQL': raw SQL in from() that is not a table
     *   name, such as raw('(SELECT ...)'), raw('numbers(3)'), raw('t FINAL'),
     *   raw('t AS x'), From::merge() or From::remote() (see readsFromRawSql()). With
     *   a sources table the mutation ignores it, and without one ClickHouse rejects
     *   it. A raw table name, such as raw('db.events'), is allowed;
     * - FINAL, which merges the rows of Summing, Aggregating, Collapsing and
     *   Replacing tables before the conditions read them;
     * - SAMPLE, ARRAY JOIN, JOIN, GROUP BY and HAVING;
     * - ORDER BY, when an entry defines an alias, which the conditions read
     *   instead of the table's column of that name (see
     *   hasAnOrderThatDefinesAnAlias()), calls arrayJoin() in raw SQL, which
     *   drops the rows whose array is empty and repeats the others, or is
     *   neither a name nor raw SQL (see hasAnOrderThatDropsOrMultipliesRows()),
     *   aggregates or takes DISTINCT values (see hasAnOrderThatAggregates()), or
     *   limits the rows with LIMIT, LIMIT ... BY, OFFSET or FETCH, or adds a set
     *   operation with UNION, INTERSECT or EXCEPT, in its raw SQL (see
     *   hasAnOrderThatLimitsRows()). The raw SQL of an entry includes the raw
     *   SQL given to its Column functions (see getRawSqlOfAnOrder());
     * - LIMIT BY, LIMIT (an offset included) and the set operations, each named
     *   by its operator, such as UNION ALL or EXCEPT;
     * - SETTINGS: they are not sent with a mutation, and many of them, such as
     *   limit, offset, additional_table_filters or an overflow mode of 'break',
     *   change which rows a SELECT returns.
     *
     * FORMAT is not returned, nor is any other ORDER BY: it sorts the rows, and
     * WITH FILL only adds rows that the table does not have.
     *
     * @return string[]
     */
    protected function getClausesAMutationWouldIgnore(): array
    {
        $clauses = array_keys(array_filter([
            'WITH' => $this->getWiths() !== [],
            'SELECT aliases or expressions' => !$this->selectsOnlyPlainColumns(),
            'a FROM sub-query' => $this->getFrom()?->getQueryBuilder() !== null,
            'a FROM table function or raw SQL' => $this->readsFromRawSql(),
            'FINAL' => $this->getFrom()?->getFinal() === true,
            'SAMPLE' => $this->getSample() !== null,
            'ARRAY JOIN' => $this->getArrayJoin() !== null,
            'JOIN' => !empty($this->getJoins()),
            'GROUP BY' => $this->getGroups() !== [],
            'HAVING' => $this->getHavings() !== [],
            'ORDER BY' => $this->hasAnOrderThatDefinesAnAlias()
                || $this->hasAnOrderThatDropsOrMultipliesRows()
                || $this->hasAnOrderThatAggregates()
                || $this->hasAnOrderThatLimitsRows(),
            'LIMIT BY' => $this->getLimitBy() !== null,
            'LIMIT' => $this->getLimit() !== null,
        ]));

        return array_merge(
            $clauses,
            array_values(array_unique($this->getUnionTypes())),
            $this->settings === [] ? [] : ['SETTINGS']
        );
    }

    /**
     * Determine if the from() table is raw SQL that is not a table name: an Expression, a RawColumn or a Laravel
     * database expression, such as DB::raw(), that was not compiled from a sub-query builder (see
     * From::getQueryBuilder(), which 'a FROM sub-query' covers), and whose SQL does not match
     * RAW_TABLE_NAME_PATTERN.
     *
     * ClickHouse (24.8 checked) reads the rows of such a FROM clause otherwise than the table: a mutation of
     * from(raw('(SELECT * FROM t WHERE a < 3)')) with the sources table t removed rows that the query does not
     * select, and so did from(raw('numbers(3)')) and from(raw('t FINAL')), whose SELECT fails. Without a sources
     * table ClickHouse rejects ALTER TABLE, DELETE FROM and TRUNCATE TABLE of such SQL. A table alias belongs in
     * from('t', 'x'), whose alias is allowed.
     *
     * @return bool
     */
    protected function readsFromRawSql(): bool
    {
        $from = $this->getFrom();
        $table = $from?->getTable();
        if ($from === null || $from->getQueryBuilder() !== null
            || !($table instanceof Expression || $table instanceof ExpressionContract)) {
            return false;
        }

        return preg_match(static::RAW_TABLE_NAME_PATTERN, $this->getRawSql($table)) !== 1;
    }

    /**
     * Remove every row of the table with TRUNCATE TABLE. Where conditions are ignored.
     *
     * The statement gets ON CLUSTER as delete() gets it (see getEffectiveCluster()), so with the use_on_cluster
     * option every node is emptied. In a session, a TRUNCATE with ON CLUSTER of a name that a temporary table of the
     * session has is refused before it is sent (see refuseATemporaryTableThatTheStatementMisses()). While the
     * connection pretends, the statement is logged and not sent (see ClientRequests::write()).
     *
     * @link https://clickhouse.com/docs/en/sql-reference/statements/truncate
     * @return Statement
     * @throws GrammarException When the query has no table
     * @throws QueryException When the statement would miss a temporary table of the session
     */
    public function truncate(): Statement
    {
        $table = $this->getTableForWrites();
        $connection = $this->getConnectionForWrites();
        if ($this->getEffectiveCluster() !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $connection,
                'TRUNCATE TABLE ... ON CLUSTER',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        $sql = 'TRUNCATE TABLE ' . $table . $this->compileOnClusterClause();

        return $this->sendWrite($connection, $sql);
    }

    /**
     * Compile the ON CLUSTER clause that follows the table name, for the cluster of getEffectiveCluster(), or
     * nothing when it is null.
     *
     * @return string
     */
    protected function compileOnClusterClause(): string
    {
        $cluster = $this->getEffectiveCluster();

        return $cluster === null ? '' : ' ON CLUSTER ' . $this->grammar->quoteString($cluster);
    }

    /**
     * Compile the IN PARTITION clause of a mutation, if a partition is given.
     *
     * @param int|string|Expression|ExpressionContract|null $partition
     * @return string
     */
    protected function compilePartitionClause(int|string|Expression|ExpressionContract|null $partition): string
    {
        return $partition === null ? '' : ' IN PARTITION ' . $this->grammar->wrap($partition);
    }

    /**
     * Determine if the query returns at least one row.
     *
     * At most one row is fetched, and it holds only a constant.
     *
     * Settings that act on the result of a query, such as offset, limit and
     * additional_result_filter, are not supported: they would act on the result
     * of the query that exists() runs instead (see getQueryForExists()), so
     * offset and limit can hide its only row, and additional_result_filter fails
     * because that result has none of the query's columns.
     *
     * @return bool
     */
    public function exists(): bool
    {
        return $this->getQueryForExists()->getRows() !== [];
    }

    /**
     * Determine if the query returns no rows.
     *
     * @return bool
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Build the query that exists() runs: one row holding the constant 1 when
     * this query returns a row, no row otherwise. It can be sent in a batch of
     * parallel queries, as get() sends it (see getQueryToSend()).
     *
     * A query that only selects plain columns is rewritten in place: it selects
     * 1, drops its ORDER BY and keeps at most one row past its OFFSET. When it
     * reads rows from a query that uses INTERSECT or EXCEPT, in from(), a join or
     * a withExpression(), it also gets WHERE NOT ignore(*) (see
     * readEveryColumnOfIntersectOrExcept()). A query that selects expressions,
     * aliases or withAlias() names, or uses GROUP BY, HAVING, LIMIT BY, a set
     * operation (UNION, INTERSECT, EXCEPT) or an ORDER BY entry that does more
     * than sort the rows (see hasOnlyPlainOrders()), such as orderByRaw(), an
     * alias or a withAlias() name, is wrapped in a subquery instead, because
     * replacing its columns could change whether it returns a row. The wrapped
     * query drops an ORDER BY of plain columns, unless it has set operations:
     * there the ORDER BY decides which rows the LIMIT of the first query keeps,
     * and so what INTERSECT and EXCEPT return. FORMAT is dropped, and SETTINGS
     * apply to the outer query (see selectFromSubquery()).
     *
     * For MyTable::where('field_two', '>', 0)->orderBy('created_at') it is
     * SELECT 1 FROM `my_table` WHERE `field_two` > 0 LIMIT 1.
     *
     * @return static
     */
    public function getQueryForExists(): self
    {
        $query = clone $this;
        $query->format = null;
        if ($this->hasOnlyPlainOrders() && $this->getUnions() === []) {
            $query->orders = [];
        }

        if ($this->canCheckExistenceInPlace()) {
            $limit = $this->getLimit();
            $query->select(new Expression('1'))->limit(min(1, $limit?->getLimit() ?? 1), $limit?->getOffset());

            return static::readEveryColumnOfIntersectOrExcept($query, $this);
        }

        $query->settings = [];

        return $this->selectFromSubquery(new Expression('1'), $query)->limit(1);
    }

    /**
     * Build a query that selects the given column from a subquery, with the
     * SETTINGS of this query, as exists() and count() wrap a query.
     *
     * When the subquery uses INTERSECT or EXCEPT, or reads rows from a query
     * that does, the outer query gets WHERE NOT ignore(*) (see
     * readEveryColumnOfIntersectOrExcept()).
     *
     * @param Expression $column
     * @param self $subquery
     * @return static
     */
    protected function selectFromSubquery(Expression $column, self $subquery): self
    {
        $query = $this->newQuery()->select($column)->from($subquery);
        $query->settings = $this->settings;

        return static::readEveryColumnOfIntersectOrExcept($query, $subquery);
    }

    /**
     * Add WHERE NOT ignore(*) to a query that exists() or count() runs when the
     * rows it reads come from a query that uses INTERSECT or EXCEPT: a from()
     * sub-query, a joined sub-query or a withExpression() query, at any depth (see
     * usesIntersectOrExcept()). It then reads every column of those rows, as
     * get() does with *.
     *
     * ClickHouse (24.8 and 24.10 checked, with the analyzer on) removes the
     * columns an outer query does not read from the queries that INTERSECT and
     * EXCEPT compare. Without it, SELECT 1 FROM (<a> EXCEPT <b>) and
     * SELECT count() FROM (<a> EXCEPT <b>) compare fewer columns than get() and
     * get a different answer: the count of an EXCEPT that returns two rows was 0.
     * The same holds for WITH `w` AS (<a> EXCEPT <b>) SELECT count() FROM `w` and
     * for a join on (<a> EXCEPT <b>). NOT ignore(*) is true for every row, but
     * ClickHouse reads every column of the FROM clause and its joins for it, also
     * when a withExpression() query with INTERSECT or EXCEPT is only used in a
     * condition, where it changes no answer.
     *
     * @param self $query The query that exists() or count() runs
     * @param BaseBuilder $source The subquery that $query selects from or, when $query is built in place from
     *                            this query and so keeps its WITH, FROM and JOIN clauses, this query
     * @return static
     */
    protected static function readEveryColumnOfIntersectOrExcept(self $query, BaseBuilder $source): self
    {
        if (static::usesIntersectOrExcept($source)) {
            $query->whereRaw('NOT ignore(*)');
        }

        return $query;
    }

    /**
     * Determine if exists() can replace the columns of this query with a
     * constant without changing whether the query returns a row.
     *
     * @return bool
     */
    protected function canCheckExistenceInPlace(): bool
    {
        if ($this->getGroups() !== []
            || $this->getHavings() !== []
            || $this->getUnions() !== []
            || $this->getLimitBy() !== null
            || !$this->hasOnlyPlainOrders()
        ) {
            return false;
        }

        return $this->selectsOnlyPlainColumns();
    }

    /**
     * Determine if every selected column is plain (see isPlainColumn()), so that
     * replacing the columns cannot change the rows the query returns.
     *
     * A query without columns selects *, which is plain. A column that is not
     * plain, such as an aggregate, an arrayJoin(), a DISTINCT column (distinct()
     * or raw), an alias or a withAlias() name, can decide how many rows the query
     * returns, or be used by its conditions.
     *
     * @return bool
     */
    protected function selectsOnlyPlainColumns(): bool
    {
        foreach ($this->getColumns() as $column) {
            if (!$this->isPlainColumn($column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if every ORDER BY entry only sorts the rows, so that exists() can drop it: a plain column, or the
     * rand() that inRandomOrder() adds (see isRandomOrder()).
     *
     * Any other entry added with orderByRaw() stays: WITH FILL can add rows to the result, and LIMIT, LIMIT BY,
     * OFFSET or FETCH can leave rows out.
     *
     * @return bool
     */
    protected function hasOnlyPlainOrders(): bool
    {
        foreach ($this->getOrders() as [$column]) {
            if (!$this->isPlainColumn($column) && !$this->isRandomOrder($column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if an ORDER BY entry is the rand() that inRandomOrder() adds, which only sorts the rows.
     *
     * @param Column $column
     * @return bool
     */
    protected function isRandomOrder(Column $column): bool
    {
        $name = $column->getColumnName();

        return $name instanceof Expression
            && $column->getFunctions() === []
            && $column->getAlias() === null
            && (string) $name === 'rand()';
    }

    /**
     * Determine if a column is a bare column name or *, with no function and no alias.
     *
     * A name that withAlias() gave to an expression is not plain: the expression
     * may be an aggregate or an arrayJoin(), which changes the rows the query
     * returns, and ClickHouse only evaluates it while the query refers to it.
     *
     * @param Column $column
     * @return bool
     */
    protected function isPlainColumn(Column $column): bool
    {
        $name = $column->getColumnName();

        return $name instanceof Identifier
            && $column->getFunctions() === []
            && $column->getAlias() === null
            && preg_match('/\sas\s/i', (string) $name) !== 1
            && !in_array((string) $name, $this->getWithAliasNames(), true);
    }

    /**
     * Get the names that withAlias() gave to expressions of this query.
     *
     * @return array<int, string>
     */
    protected function getWithAliasNames(): array
    {
        $names = [];
        foreach ($this->getWiths() as $with) {
            if ($with['type'] === self::WITH_ALIAS) {
                $names[] = $with['name'];
            }
        }

        return $names;
    }

    /**
     * Get a new builder on the same client and connection name, which follows the options of the same connection
     * as this one, if any (see followConnectionOptions()).
     *
     * @return static
     */
    public function newQuery(): self
    {
        $query = new static($this->client, $this->connection);

        if ($this->optionsConnection !== null) {
            $query->followConnectionOptions($this->optionsConnection);
        }

        return $query;
    }

    /**
     * Count the rows the query returns, leaving out its LIMIT and OFFSET.
     *
     * A query with set operations (UNION, INTERSECT, EXCEPT), GROUP BY, HAVING,
     * LIMIT BY, a selected column that is not plain (see isPlainColumn()), such
     * as an aggregate, an arrayJoin(), a DISTINCT column, an alias or a
     * withAlias() name, or an ORDER BY entry that defines an alias (see
     * hasAnOrderThatDefinesAnAlias()), adds or multiplies rows (see
     * hasAnOrderThatAddsOrMultipliesRows()), limits them or adds a set operation,
     * such as orderByRaw('id DESC LIMIT 1 BY grp') or orderByRaw('a EXCEPT
     * SELECT ...') (see hasAnOrderThatLimitsRows()), is
     * counted in a subquery (see getSubqueryAggregateQuery()): replacing its
     * columns with count() would count the rows of its first SELECT, of one
     * group or of the table instead, lose an alias that its conditions use, or
     * leave out the rows its ORDER BY adds or the limit that its ORDER BY sets.
     * Such a limit in raw SQL is part of the rows that get() returns, so it is
     * counted. Any other query replaces its columns with count() and drops its
     * ORDER BY (see getCountQuery()). When it reads rows from a query that uses
     * INTERSECT or EXCEPT, in from(), a join or a withExpression(), it also gets
     * WHERE NOT ignore(*) (see readEveryColumnOfIntersectOrExcept()). The FORMAT
     * of the query is left out.
     *
     * In a query with set operations, this builder attaches the LIMIT and OFFSET
     * to the first SELECT, where they only limit the rows of that SELECT. They
     * stay in the subquery, with the ORDER BY that decides which rows they keep,
     * so count() counts the rows that get() returns. paginate() takes its total
     * from count().
     *
     * @return int
     */
    public function count(): int
    {
        $result = $this->getQueryForCount()->getRows();

        return (int) ($result[0]['count'] ?? 0);
    }

    /**
     * Build the query that count() runs: one row whose count column holds the number of rows of this query (see
     * count()). It can be sent in a batch of parallel queries, as get() sends it (see getQueryToSend()).
     *
     * For MyTable::where('field_two', '>', 0)->orderBy('created_at') it is
     * SELECT count() as `count` FROM `my_table` WHERE `field_two` > 0, and for a grouped query
     * SELECT count() AS `count` FROM (SELECT `kind` FROM `t` GROUP BY `kind`). ClickHouse sends the count as a
     * string, such as '3'.
     *
     * @return static
     */
    public function getQueryForCount(): self
    {
        if ($this->countsInASubquery()) {
            return $this->getSubqueryAggregateQuery(new Expression('count() AS `count`'), true);
        }

        $query = $this->getCountQuery();
        $query->format = null;

        return static::readEveryColumnOfIntersectOrExcept($query, $this);
    }

    /**
     * Build the query that min(), max(), sum(), avg() and aggregate() run, with the given aggregate as its only
     * column (see BuilderMethodsFromLaravel::aggregate()).
     *
     * The aggregate reads the rows that the query returns: a query that count() counts in a subquery, such as one
     * with set operations, GROUP BY, an alias or an expression in its select list, or an orderByRaw() entry with
     * LIMIT BY, is aggregated in a subquery (see getSubqueryAggregateQuery()), so the aggregated column must be one
     * that the query returns. Any other query replaces its columns with the aggregate and drops its ORDER BY, LIMIT
     * and FORMAT; when it reads rows from a query that uses INTERSECT or EXCEPT, it also gets WHERE NOT ignore(*)
     * (see readEveryColumnOfIntersectOrExcept()).
     *
     * @param string $function The aggregate function, such as max or quantile(0.5)
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @return static
     * @throws InvalidArgumentException When the function is not a plain identifier, optionally followed by a list of
     *                                  numbers in parentheses (see BuilderMethodsFromLaravel::compileAggregate())
     */
    protected function getQueryForAggregate(string $function, array $columns): self
    {
        if ($this->countsInASubquery()) {
            return $this->getSubqueryAggregateQuery($this->compileAggregate($function, $columns));
        }

        $query = $this->cloneWithout(['orders' => [], 'limit' => null, 'format' => null])->setAggregate($function, $columns);

        return static::readEveryColumnOfIntersectOrExcept($query, $this);
    }

    /**
     * Determine if count() has to count the rows of this query in a subquery, as the aggregates do.
     *
     * @return bool
     */
    protected function countsInASubquery(): bool
    {
        return $this->getUnions() !== []
            || $this->getGroups() !== []
            || $this->getHavings() !== []
            || $this->getLimitBy() !== null
            || !$this->selectsOnlyPlainColumns()
            || $this->hasAnOrderThatDefinesAnAlias()
            || $this->hasAnOrderThatAddsOrMultipliesRows()
            || $this->hasAnOrderThatLimitsRows();
    }

    /**
     * Determine if an ORDER BY entry of this query defines an alias: a column with
     * an alias, as as() gives, a name with ' as ' in it, in any case, or raw SQL
     * with the word AS in it, in any case, which orderByRaw(), raw(), a RawColumn
     * with an alias and a Laravel database expression, such as DB::raw(), give.
     *
     * ClickHouse (24.8 checked) lets the where and prewhere conditions read an alias
     * defined in ORDER BY, so a condition on that name reads the aliased expression
     * rather than the table's column of that name. ClickHouse has no ORDER BY alias
     * without AS. Raw SQL that has AS in it for another reason, such as
     * CAST(x AS String) or a sub-query that selects an alias, counts too. So does
     * raw SQL given to a column function, such as plus(raw('0 AS b')) (see
     * getRawSqlOfAnOrder()).
     *
     * @return bool
     */
    protected function hasAnOrderThatDefinesAnAlias(): bool
    {
        foreach ($this->getOrders() as [$column]) {
            $name = $column->getColumnName();

            if ($column->getAlias() !== null
                || ($name instanceof Identifier && preg_match('/\sas\s/i', (string) $name) === 1)
            ) {
                return true;
            }

            foreach ($this->getRawSqlOfAnOrder($column) as $sql) {
                if (preg_match('/\bAS\b/i', $sql) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the raw SQL that an ORDER BY entry writes as it is: the SQL of its name when the name is raw SQL, which
     * orderByRaw(), raw(), a RawColumn and a Laravel database expression, such as DB::raw(), give, the SQL of a raw
     * value given to its plus() or multiple(), and the condition of its sumIf(), which is always raw SQL. A string
     * or a number given to plus() or multiple() is written as a literal, so it holds no SQL.
     *
     * @param Column $column
     * @return array<int, string>
     */
    protected function getRawSqlOfAnOrder(Column $column): array
    {
        $sql = [];
        $name = $column->getColumnName();
        if ($name instanceof Expression || $name instanceof ExpressionContract) {
            $sql[] = $this->getRawSql($name);
        }

        foreach ($column->getFunctions() as $function) {
            $params = $function['params'] ?? null;

            if ($function['function'] === 'sumIf') {
                $sql[] = (string) $params;
            } elseif (in_array($function['function'], ['plus', 'multiple'], true)
                && ($params instanceof Expression || $params instanceof ExpressionContract)
            ) {
                $sql[] = $this->getRawSql($params);
            }
        }

        return $sql;
    }

    /**
     * Determine if an ORDER BY entry of this query aggregates or takes DISTINCT values with a column function: sum(),
     * max(), sumIf(), count() or distinct().
     *
     * ClickHouse (24.8 checked) refuses the SELECT of such an order, with NOT_AN_AGGREGATE or a syntax error, while
     * a mutation of the query would change every row that its conditions match. A mutation refuses it, so that the
     * mutation does not run where the query fails.
     *
     * @return bool
     */
    protected function hasAnOrderThatAggregates(): bool
    {
        foreach ($this->getOrders() as [$column]) {
            foreach ($column->getFunctions() as $function) {
                if (in_array($function['function'], ['sum', 'max', 'sumIf', 'count', 'distinct'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if an ORDER BY entry of this query can add rows to its result or multiply them.
     *
     * WITH FILL adds rows and arrayJoin() multiplies them, in raw SQL, which
     * orderByRaw() and raw() give, or in the expression of a withAlias() name
     * that the entry orders by or that its raw SQL mentions: ClickHouse evaluates
     * that expression because the ORDER BY refers to it. An entry that is
     * neither a name nor raw SQL counts as one that can (see hasAnOrderMatching()).
     * Other entries, apart from one that defines an alias (see
     * hasAnOrderThatDefinesAnAlias()) or limits the rows (see
     * hasAnOrderThatLimitsRows()), only sort the rows, so count() can drop
     * them. Counting in a subquery would cost more for them: ClickHouse (24.8
     * checked) skips sorting the rows of a subquery that is only counted, but
     * still reads the columns it would sort by.
     *
     * @return bool
     */
    protected function hasAnOrderThatAddsOrMultipliesRows(): bool
    {
        return $this->hasAnOrderMatching('/\bWITH\s+FILL\b|\barrayJoin\b/i', $this->getWithAliasNames());
    }

    /**
     * Determine if an ORDER BY entry of this query can limit the rows of its result, or change them with a set
     * operation: raw SQL with LIMIT, OFFSET, FETCH, UNION, INTERSECT or EXCEPT, in any case, outside quotes and
     * parentheses (see getTopLevelOfSql()), or an entry that is neither a name nor raw SQL (see
     * hasAnOrderMatching()).
     *
     * ClickHouse (24.8 checked) reads such words after ORDER BY as clauses of the select, so that
     * orderByRaw('id DESC LIMIT 1 BY grp') returns one row for each grp, and orderByRaw('id DESC LIMIT 2'),
     * orderByRaw('id OFFSET 3 ROWS') or orderByRaw('id OFFSET 1 ROW FETCH FIRST 2 ROWS ONLY') fewer rows than the
     * conditions match. orderByRaw('a EXCEPT SELECT * FROM t WHERE b = 20') makes the query a set operation, which
     * returns the rows of its first SELECT that the other SELECT does not; INTERSECT keeps only those that it does,
     * and UNION adds the rows of the other SELECT. Every entry of the ORDER BY decides which rows a limit keeps, and
     * with WITH TIES how many, so count() and the aggregates keep the whole ORDER BY of such a query in their
     * subquery (see getSubqueryAggregateQuery()). A sub-query in an entry, such as (SELECT max(id) FROM t LIMIT 1),
     * keeps its LIMIT inside its parentheses and does not count, nor does a word in a string literal or a quoted
     * name. A word in a comment counts.
     *
     * @return bool
     */
    protected function hasAnOrderThatLimitsRows(): bool
    {
        return $this->hasAnOrderMatching('/\b(?:LIMIT|OFFSET|FETCH|UNION|INTERSECT|EXCEPT)\b/i', [], true);
    }

    /**
     * Determine if an ORDER BY entry of this query can add rows to its result: raw SQL with WITH FILL, in any
     * case, or an entry that is neither a name nor raw SQL (see hasAnOrderMatching()).
     *
     * The entries before a WITH FILL entry decide the groups in which ClickHouse (24.8 checked) adds the rows, so
     * count() and the aggregates keep the whole ORDER BY of such a query in their subquery (see
     * getSubqueryAggregateQuery()).
     *
     * @return bool
     */
    protected function hasAnOrderThatAddsRows(): bool
    {
        return $this->hasAnOrderMatching('/\bWITH\s+FILL\b/i');
    }

    /**
     * Determine if an ORDER BY entry of this query can drop rows from its result or multiply them,
     * so that a mutation of the query could change rows that it does not select.
     *
     * arrayJoin() in raw SQL, which orderByRaw() and raw() give, drops the rows
     * whose array is empty and repeats the others. An entry that is neither a
     * name nor raw SQL counts as one that can (see hasAnOrderMatching()). WITH
     * FILL does not count: it only adds rows that the table does not have. The
     * withAlias() names are not looked at, because a mutation refuses WITH on its
     * own (see getClausesAMutationWouldIgnore()).
     *
     * @return bool
     */
    protected function hasAnOrderThatDropsOrMultipliesRows(): bool
    {
        return $this->hasAnOrderMatching('/\barrayJoin\b/i');
    }

    /**
     * Determine if an ORDER BY entry of this query matches: raw SQL that matches the
     * pattern or mentions one of the names, an entry that orders by one of the
     * names, or an entry that is neither a name nor raw SQL.
     *
     * Raw SQL is what orderByRaw(), raw() and a Laravel database expression, such
     * as DB::raw(), give, and what a column function of the entry writes as it is,
     * such as plus(raw('arrayJoin(tags)')) (see getRawSqlOfAnOrder()). An entry
     * whose name is neither, such as a nested Column, matches, because what it
     * does to the rows is not known.
     *
     * @param string $pattern A regular expression for the raw SQL of an entry
     * @param array<int, string> $names Names, such as withAlias() names, that an entry may order by or its raw SQL may mention
     * @param bool $atTheTopLevel Whether the pattern only reads the raw SQL outside quotes and parentheses (see
     *                            getTopLevelOfSql())
     * @return bool
     */
    protected function hasAnOrderMatching(string $pattern, array $names = [], bool $atTheTopLevel = false): bool
    {
        foreach ($this->getOrders() as [$column]) {
            $name = $column->getColumnName();

            if ($name instanceof Identifier && in_array((string) $name, $names, true)) {
                return true;
            }

            if (!$name instanceof Identifier && !$name instanceof Expression && !$name instanceof ExpressionContract) {
                return true;
            }

            foreach ($this->getRawSqlOfAnOrder($column) as $sql) {
                if (preg_match($pattern, $atTheTopLevel ? $this->getTopLevelOfSql($sql) : $sql) === 1
                    || array_filter($names, fn (string $alias): bool => str_contains($sql, $alias)) !== []
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the SQL of raw SQL: the value of an Expression, or what a Laravel database expression, such as DB::raw(),
     * gives with the grammar of the connection (see Grammar::getLaravelExpressionValue()).
     *
     * @param Expression|ExpressionContract $expression
     * @return string
     * @throws InvalidArgumentException When the expression is a Laravel one and the grammar has no Laravel grammar
     *                                  resolver
     */
    protected function getRawSql(Expression|ExpressionContract $expression): string
    {
        return $expression instanceof Expression
            ? (string) $expression
            : (string) $this->grammar->getLaravelExpressionValue($expression);
    }

    /**
     * Get the top level of raw SQL, as QueryGrammar::getTopLevelOfSql() gets it for Laravel's query builder: the SQL
     * with each string literal in single quotes, each identifier in double quotes or backticks (with backslash
     * escapes and doubled quotes), and then each parenthesized part, innermost first, replaced by a space. Unbalanced
     * quotes and parentheses are left as they are. If the SQL is too long for PCRE to scan, it is returned as it is,
     * so that a word in it still counts.
     *
     * @param string $sql
     * @return string
     */
    protected function getTopLevelOfSql(string $sql): string
    {
        $topLevel = preg_replace(
            '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`(?:[^`\\\\]++|\\\\.|``)*+`/s',
            ' ',
            $sql
        );

        $count = 1;
        while ($topLevel !== null && $count > 0) {
            $topLevel = preg_replace('/\([^()]*+\)/', ' ', $topLevel, -1, $count);
        }

        return $topLevel ?? $sql;
    }

    /**
     * Build the query that count() and the aggregates run for a query that they read in a subquery:
     * SELECT <aggregate> FROM (<query>), with the SETTINGS of the query on the outer query (see
     * selectFromSubquery()), such as SELECT count() AS `count` FROM (<query>).
     *
     * The subquery leaves out the FORMAT and SETTINGS of the query. A query with set operations (UNION,
     * INTERSECT, EXCEPT) keeps the rest: its LIMIT and OFFSET belong to its first SELECT, and its ORDER BY decides
     * which rows they keep, and so what INTERSECT and EXCEPT return, so the subquery returns the rows that get()
     * returns. Any other query also leaves out its LIMIT and OFFSET, and the ORDER BY entries of plain columns.
     * orderByRaw() entries stay, because WITH FILL adds rows, and so does an entry that defines an alias, because
     * the conditions can read it (see hasAnOrderThatDefinesAnAlias()). When an entry can add rows (see
     * hasAnOrderThatAddsRows()), the whole ORDER BY stays: ClickHouse fills the rows of WITH FILL within each group
     * of the entries before it, so ORDER BY `grp` ASC, x WITH FILL fills x for each grp, while x WITH FILL alone
     * fills it once over every row. When an entry can limit the rows with raw LIMIT, LIMIT BY, OFFSET or FETCH (see
     * hasAnOrderThatLimitsRows()), the whole ORDER BY stays too: it decides which rows they keep, and with WITH TIES
     * how many, so ORDER BY `a` ASC, id DESC LIMIT 2 keeps other rows than id DESC LIMIT 2 alone. With the LIMIT BY
     * of limitBy(), the whole ORDER BY stays for an aggregate, because it decides which rows LIMIT BY keeps and so
     * the values the aggregate reads; when the rows are only counted, it does not change how many there are, and
     * the ORDER BY entries of plain columns are left out as well, unless an entry can add or limit rows.
     *
     * @param Expression $aggregate The column of the outer query, such as count() AS `count`
     * @param bool $countsRows Whether the aggregate only counts the rows, as count() does
     * @return static
     */
    protected function getSubqueryAggregateQuery(Expression $aggregate, bool $countsRows = false): self
    {
        if ($this->getUnions() !== []) {
            return $this->selectFromSubquery($aggregate, $this->cloneWithout(['format' => null, 'settings' => []]));
        }

        $orders = $this->getOrders();
        if (($countsRows || $this->getLimitBy() === null)
            && !$this->hasAnOrderThatAddsRows()
            && !$this->hasAnOrderThatLimitsRows()
        ) {
            $orders = array_values(array_filter(
                $orders,
                fn (array $order): bool => !$this->isPlainColumn($order[0])
            ));
        }

        $subquery = $this->cloneWithout(['limit' => null, 'format' => null, 'settings' => [], 'orders' => $orders]);

        return $this->selectFromSubquery($aggregate, $subquery);
    }

    /**
     * Build the query that paginate() runs for the rows of a page: a copy of this query whose LIMIT and OFFSET
     * replace its own, as in Laravel, such as SELECT * FROM `t` ORDER BY `id` ASC LIMIT 15, 15 for page 2 of 15
     * rows. A query with set operations (UNION, INTERSECT, EXCEPT) is paged as a whole:
     * SELECT * FROM (<a> UNION ALL <b>) LIMIT 15, 15 (see getQueryForRows()).
     *
     * With getQueryForCount() for the total, it can be sent in a batch of parallel queries, as get() sends it (see
     * getQueryToSend()). A copy of the query with limit() would not do for a set operation: its LIMIT would belong
     * to the first SELECT only, while getQueryForCount() counts the whole result.
     *
     * @param int $perPage
     * @param int $page The page number, from 1
     * @return static
     */
    public function getQueryForPage(int $perPage, int $page = 1): self
    {
        return $this->getQueryForRows($perPage, $perPage * ($page - 1), false);
    }

    /**
     * Paginate the given query.
     *
     * The total comes from count(), and the page is read by a copy of the query (see getQueryForPage()), so the
     * builder keeps no LIMIT. The page's LIMIT and OFFSET replace those of the query, as in Laravel, and a set
     * operation is paged as a whole: SELECT * FROM (<a> UNION ALL <b>) LIMIT 0, 15. $columns is not used.
     *
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $count = $this->count();

        $perPage = ($perPage instanceof Closure
            ? $perPage($count)
            : $perPage
        ) ?: 15;

        $results = $this->getQueryForPage((int) $perPage, (int) $page)->getRows();

        return new LengthAwarePaginator(
            $results,
            $count,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * The page, and one row more to tell whether a next page exists, is read by a copy of the query (see
     * getQueryForRows()), as paginate() reads it. $columns is not used.
     *
     * @param  int|null  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: 15;

        $results = $this->getQueryForRows($perPage + 1, ($page - 1) * $perPage, false)
            ->getRows();

        return new Paginator(
            $results,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        );
    }

}
