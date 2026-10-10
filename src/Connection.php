<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Statement;
use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\Enum\Enum;
use Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use RuntimeException;
use Throwable;

class Connection extends BaseConnection
{
    public const DEFAULT_NAME = 'clickhouse';

    /**
     * An INSERT statement, after any comments before it: line comments that start with --, // or a # that
     * whitespace or ! follows, and block comments (without nesting).
     */
    protected const INSERT_STATEMENT_PATTERN = '/\A\s*(?:(?:--|\/\/|#(?=[\s!]))[^\n]*(?:\n|\z)\s*|\/\*.*?\*\/\s*)*insert\b/is';

    /**
     * The client settings that carry a ClickHouse session: session() sets them while its callback runs.
     *
     * @var list<string>
     */
    protected const SESSION_SETTINGS = ['session_id', 'session_timeout', 'session_check'];

    /**
     * The formats that select() can read rows from, in the FORMAT clause of its SQL: whole JSON documents with the
     * names of the columns (see verifySelectFormat()). The compact ones give each row as a list in a list, as the
     * smi2 client reads them.
     *
     * @var list<string>
     */
    protected const SELECT_ROW_FORMATS = ['JSON', 'JSONStrings', 'JSONCompact', 'JSONCompactStrings'];

    /**
     * The character that stands for a string literal, a quoted name, a heredoc or a parenthesized part in the top
     * level of SQL (see getTopLevelOfSql()): no part of a name, a space or a semicolon.
     */
    protected const TOP_LEVEL_OPERAND = '@';

    /**
     * The keywords that can end a query right after a name, so that a column or table named format followed by one
     * of them, as in ORDER BY format DESC or FROM t AS format FINAL, is no FORMAT clause (see findFormatClause()).
     * None of them is the name of a ClickHouse format.
     *
     * @var list<string>
     */
    protected const KEYWORDS_AFTER_A_NAME = ['ASC', 'ASCENDING', 'DESC', 'DESCENDING', 'FINAL'];

    /**
     * The keywords that a name or an expression follows, so that the word format right after one of them is a
     * column or table named format and no FORMAT clause, as in FROM format f or WHERE format IN ('csv') (see
     * findFormatClause()).
     *
     * @var list<string>
     */
    protected const KEYWORDS_BEFORE_A_NAME = [
        'AND', 'AS', 'BY', 'DISTINCT', 'FROM', 'HAVING', 'JOIN', 'NOT', 'ON', 'OR', 'PREWHERE', 'SELECT', 'WHERE',
    ];

    /**
     * The characters that a name or an expression follows: a comma, the dot of a qualified name such as t.format,
     * and the operators other than *, which also stands for every column (SELECT * FORMAT CSV).
     */
    protected const CHARACTERS_BEFORE_A_NAME = ',.=<>!+-/%|^';

    protected Cluster $cluster;

    /**
     * Whether a withoutOnCluster() callback is running, so that getDefaultCluster() returns null.
     */
    protected bool $suspendsTheDefaultCluster = false;

    public function getCluster(): Cluster
    {
        return $this->cluster;
    }

    public function getClient(): Client
    {
        return $this->cluster->getActiveNode();
    }

    /**
     * Create a connection and its nodes from a connection config.
     *
     * The package options datetime_precision, use_on_cluster, insert_format and default_index_type are checked
     * first (see verifyPackageOptions()), so that a wrong value fails before any node is pinged.
     *
     * @param array<string, mixed> $config
     * @return static
     * @throws InvalidArgumentException When a package option has an invalid value
     */
    public static function createWithClient(array $config): self
    {
        $conn = new static(null, $config['database'], '', $config);
        $conn->verifyPackageOptions();
        $nodeConfigs = [];
        if ($cluster = $config['cluster'] ?? null) {
            foreach ($cluster as $node) {
                $nodeConfigs[] = $node + $config;
            }
        } else {
            $nodeConfigs[] = $config;
        }
        $conn->cluster = new Cluster($nodeConfigs);
        return $conn;
    }

    /**
     * Check the package options of the connection config: datetime_precision (see getDateTimePrecision()),
     * insert_format (see getInsertFormat()), use_on_cluster, which must be a boolean and, when it is on, needs a
     * cluster_name, and default_index_type, which must be a string or null (a blank string counts as not set, as the
     * schema grammar reads it). Each option may be left out, which keeps the behaviour of a config without it.
     *
     * @return void
     * @throws InvalidArgumentException When an option has an invalid value, or use_on_cluster is on without a
     *                                  cluster_name
     */
    protected function verifyPackageOptions(): void
    {
        $this->getDateTimePrecision();
        $this->getInsertFormat();

        if ($this->usesOnCluster() && $this->getClusterName() === null) {
            throw new InvalidArgumentException(sprintf(
                'The ClickHouse connection [%s] sets use_on_cluster without a cluster_name. Set cluster_name to the'
                . ' name of the cluster in the remote_servers section of the ClickHouse server config.',
                $this->getName()
            ));
        }

        $defaultIndexType = $this->getConfig('default_index_type');
        if ($defaultIndexType !== null && !is_string($defaultIndexType)) {
            throw new InvalidArgumentException(sprintf(
                "The ClickHouse connection [%s] sets default_index_type to %s. Set it to the type of a data-skipping"
                . " index, such as 'minmax' or 'bloom_filter', or to null.",
                $this->getName(),
                $this->describeOptionValue($defaultIndexType)
            ));
        }
    }

    /** @inheritDoc */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** @inheritDoc */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the default post processor, which shapes the results of the schema queries.
     *
     * @return QueryProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new QueryProcessor();
    }

    /**
     * Get the version of the ClickHouse server behind the active node, for example "24.8.14.39".
     *
     * @return string
     */
    public function getServerVersion(): string
    {
        return (string) $this->scalar('SELECT version()');
    }

    /**
     * Get a human-readable name for the connection driver, as `php artisan db:show` prints it.
     *
     * @return string
     */
    public function getDriverTitle(): string
    {
        return 'ClickHouse';
    }

    /** @inheritDoc */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection, used by `schema:dump` and `migrate`.
     *
     * @param Filesystem|null $files
     * @param callable|null $processFactory
     * @return SchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): SchemaState
    {
        return new SchemaState($this, $files, $processFactory);
    }

    /**
     * Run a select statement on the active node and return its rows as associative arrays.
     *
     * The query and its bindings are logged as given. While the connection pretends, nothing is sent and the
     * result is empty. Otherwise the bindings are prepared and a FORMAT clause is checked (see
     * prepareSelectForClient()), and the query goes through ClientRequests::select(), which reads the result as
     * JSON even when a string literal or a comment in the SQL names a format.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @param bool $useReadPdo Ignored: every query runs on the active node
     * @param array<int, mixed> $fetchUsing Ignored
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException When the bindings do not fit the query's placeholders (see
     *                                  SubstitutesBindings::prepareQueryForClient())
     * @throws QueryException When the query's FORMAT clause names a format that select() cannot read rows from,
     *                        before anything is sent (see verifySelectFormat())
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->run($query, $bindings, function (string $query, array $bindings): array {
            if ($this->pretending()) {
                return [];
            }

            [$sql, $clientBindings] = $this->prepareSelectForClient($query, $bindings);

            return ClientRequests::select($this->getClient(), $sql, $clientBindings)->rows();
        });
    }

    /**
     * Run SQL queries at the same time on the active node and return the rows of each, keyed and ordered as given.
     *
     * Each entry is the SQL of a SELECT, or an array with its SQL and bindings, which fill "?" placeholders, or
     * smi2's :name and {name} placeholders, as in select(). A 'connection' key in an entry is ignored: every query
     * runs on this connection. Each query runs as select() runs it, through Parallel::getRows(): the
     * beforeExecuting() callbacks run before the batch is sent, a query is logged with the time of its own request
     * when it succeeds, and while the connection pretends nothing is sent and its rows are empty. Upstream's
     * laravel-clickhouse/laravel-clickhouse has a method of the same name.
     *
     * @param array<int|string, string|array{sql: string, bindings?: array<int|string, mixed>}> $queries
     * @param int $concurrency How many requests are in flight at once, at least 2
     * @return array<int|string, array<int, array<string, mixed>>>
     * @throws InvalidArgumentException When an entry is neither SQL nor an array with an sql string, or is invalid
     *                                  as Parallel::getRows() says, before anything is sent
     * @throws QueryException When the FORMAT clause of an entry names a format that select() cannot read rows from,
     *                        before anything is sent (see verifySelectFormat())
     * @throws LogicException When the connection's client uses a ClickHouse session, before anything is sent
     * @throws ParallelQueryException When a query failed, after every query has finished
     */
    public function selectParallelly(array $queries, int $concurrency = Parallel::DEFAULT_CONCURRENCY): array
    {
        $entries = [];
        foreach ($queries as $key => $query) {
            if (is_string($query)) {
                $query = ['sql' => $query];
            }

            if (!is_array($query) || !is_string($query['sql'] ?? null)) {
                throw new InvalidArgumentException(sprintf(
                    'Parallel query [%s] must be SQL or an array with an sql string, %s given.',
                    $key,
                    get_debug_type($query)
                ));
            }

            $entries[$key] = ['connection' => $this] + $query;
        }

        return Parallel::getRows($entries, $concurrency);
    }

    /**
     * Run a select statement and yield its rows one by one, as select() returns them.
     *
     * The smi2 client has no server-side cursor, so every row is read before the first one is yielded. While the
     * connection pretends, nothing is sent and nothing is yielded. The query runs when the first row is asked for.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @param bool $useReadPdo Ignored: every query runs on the active node
     * @param array<int, mixed> $fetchUsing Ignored
     * @return Generator<int, array<string, mixed>>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
    {
        yield from $this->select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    /**
     * Refuse to run a query for several result sets: ClickHouse answers each query with one result set, and Laravel's
     * selectResultSets() needs a PDO, which this connection does not have. Nothing is sent or logged, also while the
     * connection pretends.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @param bool $useReadPdo
     * @param array<int, mixed> $fetchUsing
     * @return never
     * @throws LogicException Always
     */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): never
    {
        throw new LogicException(
            'ClickHouse returns one result set per query, so selectResultSets() is not supported. Use select().'
        );
    }

    /**
     * Execute a statement on the active node and return true, as Laravel's connections do.
     *
     * The query and its bindings are logged as given. While the connection pretends, nothing is sent. Otherwise
     * the bindings are prepared (see prepareQueryForClient()) and the statement goes through
     * ClientRequests::write(), which throws when ClickHouse reports an error.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return bool
     * @throws InvalidArgumentException When the bindings do not fit the query's placeholders
     */
    public function statement($query, $bindings = []): bool
    {
        return $this->run($query, $bindings, function (string $query, array $bindings): bool {
            if ($this->pretending()) {
                return true;
            }

            [$sql, $clientBindings] = $this->prepareQueryForClient($query, $bindings);
            $statement = ClientRequests::write($this->getClient(), $this, $sql, $clientBindings);
            $this->recordsHaveBeenModified();

            return !$statement->isError();
        });
    }

    /**
     * Execute a statement on the active node and return the number of rows it affected, as far as ClickHouse
     * reports it (see countAffectedRows()): for an INSERT, the rows that ClickHouse reports as written, which include
     * the rows that the materialized views of the table wrote and are 0 for an asynchronous insert; 1 for any other
     * statement. Laravel's update(), delete() and insertUsing() run through this method.
     *
     * The query and its bindings are logged as given. While the connection pretends, nothing is sent and 0 is
     * returned.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return int
     * @throws InvalidArgumentException When the bindings do not fit the query's placeholders
     */
    public function affectingStatement($query, $bindings = []): int
    {
        return $this->run($query, $bindings, function (string $query, array $bindings): int {
            if ($this->pretending()) {
                return 0;
            }

            [$sql, $clientBindings] = $this->prepareQueryForClient($query, $bindings);
            $statement = ClientRequests::write($this->getClient(), $this, $sql, $clientBindings);
            $count = $this->countAffectedRows($sql, $statement);
            $this->recordsHaveBeenModified($count > 0);

            return $count;
        });
    }

    /**
     * Count the rows that a statement affected.
     *
     * ClickHouse reports in the X-ClickHouse-Summary header how many rows an INSERT wrote (written_rows), but reports
     * 0 for a mutation (ALTER TABLE ... UPDATE or DELETE, DELETE FROM) and for other statements. An INSERT, after any
     * leading comments, returns that number, 0 included. Any other statement, and an INSERT whose response has no
     * number, returns 1, as in 3.0.0, so that code which checks the result of update() or delete() goes on as before.
     *
     * written_rows is not always the number of rows that the INSERT inserted (24.8 checked):
     * - it also counts the rows that the materialized views of the table wrote, so an INSERT of 3 rows into a table
     *   with one materialized view that keeps every row returns 6;
     * - it is 0 for an asynchronous insert (the async_insert setting, from the query, the connection's settings or
     *   the user's profile), also when the query waits for its rows to be written (wait_for_async_insert). The
     *   response does not tell such an insert apart from one that wrote nothing.
     *
     * @param string $query The statement that was sent
     * @param Statement $statement
     * @return int
     */
    protected function countAffectedRows(string $query, Statement $statement): int
    {
        if (preg_match(static::INSERT_STATEMENT_PATTERN, $query) === 1) {
            $writtenRows = $statement->summary('written_rows');

            if (is_int($writtenRows) || (is_string($writtenRows) && ctype_digit($writtenRows))) {
                return (int) $writtenRows;
            }
        }

        return 1;
    }

    /**
     * Run SQL as it is on the active node, without bindings or placeholders, and return true.
     *
     * The query is logged. While the connection pretends, nothing is sent. A "?" or "??" in the SQL is sent as it
     * is.
     *
     * @param string $query
     * @return bool
     */
    public function unprepared($query): bool
    {
        return $this->run($query, [], function (string $query): bool {
            if ($this->pretending()) {
                return true;
            }

            $change = !ClientRequests::write($this->getClient(), $this, $query)->isError();
            $this->recordsHaveBeenModified($change);

            return $change;
        });
    }

    /**
     * Execute a statement on every node of the connection, one after another, and return true.
     *
     * The statement is logged once. While the connection pretends, nothing is sent. Each node gets the statement
     * through Cluster::write(), which throws at the first node that fails, after the nodes before it have run it.
     * A connection without cluster nodes has one node. A "??" in the statement is sent as "?", as statement() sends
     * it, except in an INSERT (see prepareQueryForClient()).
     *
     * @param string $query
     * @return bool
     */
    public function statementOnEveryNode(string $query): bool
    {
        return $this->run($query, [], function (string $query): bool {
            if ($this->pretending()) {
                return true;
            }

            [$sql] = $this->prepareQueryForClient($query, []);
            $this->cluster->write($sql);
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /**
     * Get the SQL and the bindings to hand to the smi2 client for a query that is about to run.
     *
     * - An INSERT without bindings, after any leading comments, is returned as it is, as unprepared() sends it.
     *   The rows after its FORMAT clause, such as "INSERT INTO t FORMAT TSV" followed by tab-separated lines, are
     *   data and not SQL, so a "??" in them must reach ClickHouse as it is.
     * - The bindings go through prepareBindings(), so dates are written at the connection's datetime_precision.
     * - QueryGrammar::prepareQueryForClient() writes the bindings of "?" placeholders into the SQL, or makes them
     *   safe for smi2's own :name and {name} substitution, and writes each "??" as "?" (see
     *   SubstitutesBindings::prepareQueryForClient()).
     *
     * The deprecated "#@?" markers of 3.0.0's query grammar are no longer turned into :0, :1 and so on: Laravel's
     * query builder writes "?", and a "#@?" that SQL holds, such as one in a string literal, is sent as it is.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     * @throws InvalidArgumentException When the query mixes "?" placeholders with smi2 placeholders or query
     *                                  parameters, the number of "?" placeholders differs from the number of
     *                                  bindings, or a binding cannot be written as a literal
     */
    protected function prepareQueryForClient(string $query, array $bindings): array
    {
        if ($bindings === [] && preg_match(static::INSERT_STATEMENT_PATTERN, $query) === 1) {
            return [$query, []];
        }

        $bindings = $this->prepareBindings($bindings);

        /** @var QueryGrammar $grammar */
        $grammar = $this->getQueryGrammar();

        return $grammar->prepareQueryForClient($query, $bindings);
    }

    /**
     * Get the SQL and the bindings that select() hands to the smi2 client for a SELECT: the query and its bindings
     * as prepareQueryForClient() prepares them, once the FORMAT clause of the SQL has been checked (see
     * verifySelectFormat()).
     *
     * Parallel::get() and getRows() call it for a Laravel query builder and for SQL, so that a query of a batch is
     * sent as select() sends it.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     * @throws InvalidArgumentException When the bindings do not fit the query's placeholders
     * @throws QueryException When the FORMAT clause names a format that select() cannot read rows from
     */
    public function prepareSelectForClient(string $query, array $bindings): array
    {
        [$sql, $clientBindings] = $this->prepareQueryForClient($query, $bindings);
        $this->verifySelectFormat($sql);

        return [$sql, $clientBindings];
    }

    /**
     * Refuse a SELECT whose FORMAT clause names a format that select() cannot read rows from, before it is sent.
     *
     * select() returns the rows of a JSON result, and the smi2 client reads rows only from the formats of
     * SELECT_ROW_FORMATS, in any letter case. Any other FORMAT clause failed (ClickHouse 24.8 checked): a format
     * that smi2 does not know, such as XML, Pretty, Values or Null, got smi2's FORMAT JSON after it and a
     * SYNTAX_ERROR; a text format, such as CSV, TSV or JSONEachRow, threw 'Can`t find meta' after the query ran,
     * or returned no rows when the result was empty; and JSONCompactEachRow returned no rows at all. A query
     * without a FORMAT clause, or with FORMAT in a string literal, a comment or a sub-query, is not refused (see
     * findFormatClause()).
     *
     * The message names only the ways that read a result in that format (see describeWaysToReadFormat()).
     *
     * @param string $sql
     * @return void
     * @throws QueryException When the FORMAT clause names another format
     */
    protected function verifySelectFormat(string $sql): void
    {
        $format = $this->findFormatClause($sql);
        if ($format === null) {
            return;
        }

        foreach (static::SELECT_ROW_FORMATS as $rowFormat) {
            if (strcasecmp($format, $rowFormat) === 0) {
                return;
            }
        }

        throw new QueryException(sprintf(
            'Cannot read rows from a query whose FORMAT clause names %s: select() and parallel queries read the rows'
            . ' of a JSON result (%s). %s',
            $format,
            implode(', ', static::SELECT_ROW_FORMATS),
            $this->describeWaysToReadFormat($sql, $format)
        ));
    }

    /**
     * Describe the ways to read the result of a SELECT in the format that its FORMAT clause names, for the message
     * of verifySelectFormat(). It names each way only when it reads that format:
     * - the package query builder, format('<name>')->get()->rawData(), for a format of the Format enum, in any
     *   letter case;
     * - the smi2 client, getClient()->select($sql)->rawData(), when smi2 sends the SQL as it is and reads the
     *   result in the format that it names (see ClientRequests::clientSelectsAsIntended()). smi2 adds a second FORMAT
     *   to a format that it does not know, such as XML or Pretty, and decodes a format with one JSON value per line,
     *   such as JSONCompactEachRow, as one JSON document, which gives null.
     * When neither does, the message says that the package cannot read a result in that format.
     *
     * @param string $sql The SQL as it would be sent
     * @param string $format The format as the FORMAT clause names it
     * @return string
     */
    protected function describeWaysToReadFormat(string $sql, string $format): string
    {
        $ways = [];
        foreach (Format::toArray() as $name) {
            if (strcasecmp($name, $format) === 0) {
                $ways[] = "with the package query builder, format('{$name}')->get()->rawData()";
                break;
            }
        }

        if (ClientRequests::clientSelectsAsIntended($sql, $format)) {
            $ways[] = 'with the smi2 client, getClient()->select($sql)->rawData()';
        }

        if ($ways === []) {
            return sprintf('Leave the FORMAT clause out: the package cannot read a result in %s.', $format);
        }

        return sprintf('Leave the FORMAT clause out, or read the result in %s %s.', $format, implode(', or ', $ways));
    }

    /**
     * Get the format that the FORMAT clause of a query names, or null when the query has none.
     *
     * The clause is the word FORMAT and a name at the top level of the query (see getTopLevelOfSql()), outside
     * string literals, quoted names, comments, heredocs and parentheses, that only a SETTINGS clause and a semicolon
     * may follow, as ClickHouse reads it at the end of a SELECT. So FORMAT and a format name in a value, a comment
     * or a sub-query is no FORMAT clause, and neither is a column, alias or table function named format, as in
     * SELECT x AS format FROM t or SELECT * FROM format(CSV, '1'). The word format is also no FORMAT clause:
     * - when the word after it is a keyword that can end a query after a name (KEYWORDS_AFTER_A_NAME), as in
     *   ORDER BY format DESC;
     * - when it follows a keyword or a character after which a name or an expression comes
     *   (KEYWORDS_BEFORE_A_NAME and CHARACTERS_BEFORE_A_NAME), as in FROM format f, ORDER BY id, format ASC or
     *   WHERE x = 1 AND format IN ('csv').
     * A name that a string literal, a quoted name or parentheses follow, as in WHERE format LIKE 'c%', is no FORMAT
     * clause either, because the rest of the query is not empty. Each word FORMAT is tried, from the last one, so
     * SELECT 1 AS format FORMAT CSV names CSV. The words are found in a lower-case copy of the top level, without
     * case-insensitive patterns, which are slow on a large query, and the rest of the query is not copied, so the
     * search takes linear time.
     *
     * @param string $sql
     * @return string|null
     */
    protected function findFormatClause(string $sql): ?string
    {
        $topLevel = $this->getTopLevelOfSql($sql);
        $lowerTopLevel = strtolower($topLevel);
        $found = preg_match_all(
            '/\bformat(?=\s+([a-z_][a-z0-9_]*))/',
            $lowerTopLevel,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        if (!$found) {
            return null;
        }

        foreach (array_reverse($matches) as $match) {
            [$lowerName, $nameOffset] = $match[1];
            $restOffset = $nameOffset + strlen($lowerName);
            if (preg_match('/\G\s*(?:settings\b[^;]*)?(?:;\s*)?\z/', $lowerTopLevel, $rest, 0, $restOffset) !== 1
                || in_array(strtoupper($lowerName), static::KEYWORDS_AFTER_A_NAME, true)
                || $this->followsWhatANameFollows($topLevel, $match[0][1])) {
                continue;
            }

            return substr($topLevel, $nameOffset, strlen($lowerName));
        }

        return null;
    }

    /**
     * Determine if the top level of SQL before a word, past any whitespace, ends in a keyword or a character after
     * which a name or an expression comes (see KEYWORDS_BEFORE_A_NAME and CHARACTERS_BEFORE_A_NAME), so that the
     * word is a name. The SQL is read backwards from the word, so a long query costs no more than a short one.
     *
     * @param string $topLevel The top level of the SQL (see getTopLevelOfSql())
     * @param int $offset The position of the word
     * @return bool
     */
    protected function followsWhatANameFollows(string $topLevel, int $offset): bool
    {
        $end = $offset;
        while ($end > 0 && ctype_space($topLevel[$end - 1])) {
            $end--;
        }
        if ($end === 0) {
            return false;
        }

        if (str_contains(static::CHARACTERS_BEFORE_A_NAME, $topLevel[$end - 1])) {
            return true;
        }

        $start = $end;
        while ($start > 0 && (ctype_alnum($topLevel[$start - 1]) || $topLevel[$start - 1] === '_')) {
            $start--;
        }

        return in_array(strtoupper(substr($topLevel, $start, $end - $start)), static::KEYWORDS_BEFORE_A_NAME, true);
    }

    /**
     * Get the top level of SQL: the SQL with each comment replaced by a space, and each string literal, quoted
     * name and heredoc, and each parenthesized part, replaced by TOP_LEVEL_OPERAND, so that a word after one of
     * them follows something, as FORMAT follows the IN list in WHERE x IN (1) FORMAT CSV.
     *
     * The SQL is scanned once from the left. A string literal or a name in double quotes or backticks ends at its
     * closing quote, past backslash escapes and doubled quotes. A line comment starts with --, // or a # that
     * whitespace or ! follows, and ends at the end of the line; a block comment may nest. A heredoc is $$...$$ or
     * $tag$...$tag$. These are the rules of ClientRequests and of the "?" scanner of SubstitutesBindings. An
     * unclosed quote, comment or parenthesis runs to the end of the SQL.
     *
     * @param string $sql
     * @return string
     */
    protected function getTopLevelOfSql(string $sql): string
    {
        $length = strlen($sql);
        $topLevel = '';
        $depth = 0;
        $position = 0;

        while ($position < $length) {
            $stop = $position + strcspn($sql, "'\"`-#/\$()", $position);
            if ($depth === 0) {
                $topLevel .= substr($sql, $position, $stop - $position);
            }
            if ($stop >= $length) {
                break;
            }

            $character = $sql[$stop];
            $next = $sql[$stop + 1] ?? '';
            $replacement = static::TOP_LEVEL_OPERAND;
            if ($character === "'" || $character === '"' || $character === '`') {
                $position = $this->positionAfterQuotedText($sql, $stop);
            } elseif (($character === '-' && $next === '-')
                || ($character === '/' && $next === '/')
                || ($character === '#' && ($next === '!' || ($next !== '' && ctype_space($next))))) {
                $end = strpos($sql, "\n", $stop);
                $position = $end === false ? $length : $end + 1;
                $replacement = ' ';
            } elseif ($character === '/' && $next === '*') {
                $position = $this->positionAfterBlockComment($sql, $stop);
                $replacement = ' ';
            } elseif ($character === '$' && ($end = $this->positionAfterHeredoc($sql, $stop)) > $stop + 1) {
                $position = $end;
            } elseif ($character === '(') {
                $depth++;
                $position = $stop + 1;
                if ($depth > 1) {
                    continue;
                }
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
                $position = $stop + 1;
                continue;
            } else {
                if ($depth === 0) {
                    $topLevel .= $character;
                }
                $position = $stop + 1;
                continue;
            }

            if ($depth === 0 || $character === '(') {
                $topLevel .= $replacement;
            }
        }

        return $topLevel;
    }

    /**
     * Get the position after a string literal or quoted name that starts at a position, or the end of the SQL when
     * it is not closed. A backslash escapes the next character, and a doubled quote stays inside.
     *
     * @param string $sql
     * @param int $position The position of the opening quote
     * @return int
     */
    protected function positionAfterQuotedText(string $sql, int $position): int
    {
        $length = strlen($sql);
        $quote = $sql[$position];
        $position++;

        while (($position += strcspn($sql, '\\' . $quote, $position)) < $length) {
            if ($sql[$position] !== '\\' && ($sql[$position + 1] ?? '') !== $quote) {
                return $position + 1;
            }
            $position = min($position + 2, $length);
        }

        return $length;
    }

    /**
     * Get the position after a block comment that starts with the /* at a position, with nested block comments, or
     * the end of the SQL when it is not closed.
     *
     * @param string $sql
     * @param int $position The position of the /*
     * @return int
     */
    protected function positionAfterBlockComment(string $sql, int $position): int
    {
        $length = strlen($sql);
        $depth = 0;

        while (($position += strcspn($sql, '/*', $position)) < $length) {
            $pair = substr($sql, $position, 2);
            if ($pair === '/*') {
                $depth++;
                $position += 2;
            } elseif ($pair === '*/') {
                $depth--;
                $position += 2;
                if ($depth === 0) {
                    return $position;
                }
            } else {
                $position++;
            }
        }

        return $length;
    }

    /**
     * Get the position after a heredoc that starts with the $ at a position ($$...$$ or $tag$...$tag$, whose tag is
     * the text up to the next $), or the position after the $ when it is no heredoc or is not closed. A $ that
     * follows an ASCII letter or digit, an underscore or another $ is part of a name, not a heredoc.
     *
     * @param string $sql
     * @param int $position The position of the $
     * @return int
     */
    protected function positionAfterHeredoc(string $sql, int $position): int
    {
        $previous = $position > 0 ? $sql[$position - 1] : ' ';
        $isWordCharacter = strspn($previous, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_') === 1;
        if ($previous !== '$' && !$isWordCharacter) {
            $tagEnd = strpos($sql, '$', $position + 1);
            if ($tagEnd !== false) {
                $delimiter = substr($sql, $position, $tagEnd + 1 - $position);
                $end = strpos($sql, $delimiter, $tagEnd + 1);
                if ($end !== false) {
                    return $end + strlen($delimiter);
                }
            }
        }

        return $position + 1;
    }

    /**
     * Prepare the bindings of a query for execution: each DateTimeInterface becomes its text at the connection's
     * datetime_precision (see formatDateTime()), and each bool 1 or 0, also inside arrays. Every other value is
     * left as it is: QueryGrammar::prepareQueryForClient() writes enums, Stringable values, raw SQL, smi2 types and
     * NaN as ClickHouse reads them.
     *
     * Laravel's toRawSql() also calls this method before it writes the bindings into the SQL.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     * @throws InvalidArgumentException When the connection's datetime_precision is invalid
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = null;
        $prepare = function (mixed $value) use (&$grammar, &$prepare): mixed {
            if ($value instanceof DateTimeInterface) {
                $grammar ??= $this->newBuilderGrammar();

                return $grammar->formatDateTime($value);
            }

            if (is_bool($value)) {
                return (int) $value;
            }

            return is_array($value) ? array_map($prepare, $value) : $value;
        };

        return array_map($prepare, $bindings);
    }

    /**
     * Run a SQL statement and log it with its bindings, as given, when it succeeds.
     *
     * The connection's beforeExecuting() callbacks run first (see runBeforeExecutingCallbacks()). Unlike Laravel's
     * run(), it neither reconnects a PDO, which this connection does not have, nor wraps the client's exceptions in
     * Laravel's QueryException.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @param Closure $callback
     * @return mixed
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $this->runBeforeExecutingCallbacks($query, $bindings);

        $start = microtime(true);

        $result = $callback($query, $bindings);

        $this->logQuery($query, $bindings, $this->getElapsedTime($start));

        return $result;
    }

    /**
     * Call the connection's beforeExecuting() callbacks with a query, its bindings and the connection, as run()
     * does before it sends a query.
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return void
     */
    public function runBeforeExecutingCallbacks(string $query, array $bindings): void
    {
        foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
            $beforeExecutingCallback($query, $bindings, $this);
        }
    }

    /**
     * Escape a value as a ClickHouse literal, for SQL that is written by hand.
     *
     * With $binary, a string becomes a hex string literal, x'<hex>', which ClickHouse reads as a String, also in
     * VALUES. Otherwise the query grammar's compileLiteral() writes the value: a string with its backslashes and
     * quotes escaped, an array as an array literal such as [1, 'a'], a float with every digit, NaN, INF and -INF as
     * nan, inf and -inf, a bool as 1 or 0, and a date at the connection's datetime_precision. As in Laravel, a string
     * with a NUL byte or invalid UTF-8 must be escaped as binary. null is null in both cases.
     *
     * @param mixed $value
     * @param bool $binary
     * @return string
     * @throws RuntimeException When a string has a NUL byte or invalid UTF-8 and $binary is false, or $binary is true
     *                          and the value is not a string
     * @throws InvalidArgumentException When the value cannot be written as a literal
     */
    public function escape($value, $binary = false)
    {
        if ($value === null) {
            return 'null';
        }

        if ($binary) {
            return $this->escapeBinary($value);
        }

        if (is_string($value)) {
            if (str_contains($value, "\0")) {
                throw new RuntimeException('Strings with null bytes cannot be escaped. Use the binary escape option.');
            }

            if (preg_match('//u', $value) === false) {
                throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
            }
        }

        /** @var QueryGrammar $grammar */
        $grammar = $this->getQueryGrammar();

        return $grammar->compileLiteral($value);
    }

    /**
     * Escape a string as a ClickHouse string literal, with backslashes, quotes and NUL bytes escaped (see
     * Grammar::compileStringLiteral()).
     *
     * @param string $value
     * @return string
     */
    protected function escapeString($value)
    {
        return $this->newBuilderGrammar()->compileStringLiteral((string) $value);
    }

    /**
     * Escape a string as a hex string literal, x'<hex>', which ClickHouse reads as a String.
     *
     * @param mixed $value
     * @return string
     * @throws RuntimeException When the value is not a string
     */
    protected function escapeBinary($value)
    {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Only strings can be escaped as binary values, %s given.',
                get_debug_type($value)
            ));
        }

        return "x'" . bin2hex($value) . "'";
    }

    /**
     * Refuse to start a transaction: ClickHouse has none.
     *
     * The connection is not reconnected first, as Laravel's beginTransaction() would do for a connection without
     * a PDO.
     *
     * @return never
     * @throws LogicException Always
     */
    public function beginTransaction(): never
    {
        throw $this->transactionsAreNotSupported();
    }

    /**
     * Refuse to run a callback in a transaction: ClickHouse has none. The callback is not run.
     *
     * @param Closure $callback
     * @param int $attempts
     * @return never
     * @throws LogicException Always
     */
    public function transaction(Closure $callback, $attempts = 1): never
    {
        throw $this->transactionsAreNotSupported();
    }

    /**
     * Refuse to commit a transaction: ClickHouse has none. Laravel's commit() would fire the committed event
     * without a transaction.
     *
     * rollBack() is left to Laravel: without a transaction it does nothing, so the teardown of a test or an
     * error handler that calls it stays harmless. transactionLevel() is always 0.
     *
     * @return never
     * @throws LogicException Always
     */
    public function commit(): never
    {
        throw $this->transactionsAreNotSupported();
    }

    /**
     * Get the exception that the transaction methods throw.
     *
     * @return LogicException
     */
    protected function transactionsAreNotSupported(): LogicException
    {
        return new LogicException(
            'ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for ClickHouse'
            . ' connections and leave them out of $connectionsToTransact.'
        );
    }

    /**
     * Get the precision with which the connection writes DateTimeInterface values, from its datetime_precision
     * option: DateTimePrecision::SECOND, also when the option is left out, null or blank (as an empty environment
     * variable gives), or DateTimePrecision::MICROSECOND.
     *
     * The option takes 'second' or 'microsecond' in any letter case and with surrounding spaces, or a
     * DateTimePrecision instance. A config file should hold the string: Laravel's config:cache cannot write an
     * instance into the cached config. An instance works in a config that is set at runtime, such as one given to
     * DB::connectUsing().
     *
     * @return string
     * @throws InvalidArgumentException For any other value
     */
    public function getDateTimePrecision(): string
    {
        $value = $this->getConfig('datetime_precision');
        if ($value instanceof DateTimePrecision) {
            return $value->getValue();
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return DateTimePrecision::SECOND;
        }

        $precision = is_string($value) ? strtolower(trim($value)) : null;
        if ($precision !== null && DateTimePrecision::isValid($precision)) {
            return $precision;
        }

        throw $this->invalidOption('datetime_precision', $value, array_values(DateTimePrecision::toArray()));
    }

    /**
     * Create a grammar of the package builder that writes values as this connection does: dates at the connection's
     * datetime_precision, and a Laravel database expression, such as DB::raw(), through the connection's query
     * grammar.
     *
     * @return Grammar
     * @throws InvalidArgumentException When the connection's datetime_precision is invalid
     */
    public function newBuilderGrammar(): Grammar
    {
        return (new Grammar())
            ->setDateTimePrecision($this->getDateTimePrecision())
            ->setLaravelGrammarResolver(fn (): \Illuminate\Database\Grammar => $this->getQueryGrammar());
    }

    /**
     * Format a date as the connection writes it, without quotes and in the date's own time zone: 'Y-m-d H:i:s', or
     * 'Y-m-d H:i:s.u' for a date with a sub-second part when datetime_precision is 'microsecond'.
     *
     * @param DateTimeInterface $value
     * @return string
     * @throws InvalidArgumentException When the connection's datetime_precision is invalid
     */
    public function formatDateTime(DateTimeInterface $value): string
    {
        return $this->newBuilderGrammar()->formatDateTime($value);
    }

    /**
     * Get the input format of the package's inserts, from the insert_format option: Format::VALUES, also when the
     * option is left out, null or blank, or Format::JSON_EACH_ROW.
     *
     * The option takes 'Values' or 'JSONEachRow' in any letter case and with surrounding spaces, or a Format instance
     * of one of them. A config file should hold the string: Laravel's config:cache cannot write an instance into the
     * cached config. An instance works in a config that is set at runtime, such as one given to DB::connectUsing().
     *
     * @return string
     * @throws InvalidArgumentException For any other value
     */
    public function getInsertFormat(): string
    {
        $value = $this->getConfig('insert_format');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return Format::VALUES;
        }

        $name = $value instanceof Format ? $value->getValue() : $value;
        if (is_string($name)) {
            foreach ([Format::VALUES, Format::JSON_EACH_ROW] as $format) {
                if (strcasecmp(trim($name), $format) === 0) {
                    return $format;
                }
            }
        }

        throw $this->invalidOption('insert_format', $value, [Format::VALUES, Format::JSON_EACH_ROW]);
    }

    /**
     * Get the name of the cluster that the connection's cluster_name option names, without surrounding whitespace,
     * or null when the option is left out or blank. So a name read from an environment variable with a stray space,
     * such as ' company_cluster ', is written as ON CLUSTER 'company_cluster', which ClickHouse finds.
     *
     * @return string|null
     */
    public function getClusterName(): ?string
    {
        $name = $this->getConfig('cluster_name');
        if (!is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name !== '' ? $name : null;
    }

    /**
     * Determine if the connection lists its nodes in the cluster option, as Migration::createMergeTree() checks
     * before it makes a table replicated. An empty list counts as none.
     *
     * @return bool
     */
    public function hasClusterNodes(): bool
    {
        $nodes = $this->getConfig('cluster');

        return is_array($nodes) && $nodes !== [];
    }

    /**
     * Get the cluster that the package's mutations and TRUNCATE statements send ON CLUSTER to by default: the
     * cluster_name while the use_on_cluster option is on and no withoutOnCluster() callback runs, null otherwise.
     *
     * @return string|null
     * @throws InvalidArgumentException When use_on_cluster is not a boolean
     */
    public function getDefaultCluster(): ?string
    {
        if ($this->suspendsTheDefaultCluster || !$this->usesOnCluster()) {
            return null;
        }

        return $this->getClusterName();
    }

    /**
     * Run a callback without the default cluster: getDefaultCluster() returns null while it runs, also in nested
     * calls, and the previous state comes back when it returns or throws.
     *
     * @template TReturn
     * @param callable(static): TReturn $callback It gets this connection
     * @return TReturn
     */
    public function withoutOnCluster(callable $callback): mixed
    {
        $previous = $this->suspendsTheDefaultCluster;
        $this->suspendsTheDefaultCluster = true;

        try {
            return $callback($this);
        } finally {
            $this->suspendsTheDefaultCluster = $previous;
        }
    }

    /**
     * Determine if the use_on_cluster option is on. It takes a boolean, or a string or number that
     * FILTER_VALIDATE_BOOLEAN reads as one, such as 'true', '0' or ''. Left out or null, it is off.
     *
     * @return bool
     * @throws InvalidArgumentException For any other value
     */
    protected function usesOnCluster(): bool
    {
        $value = $this->getConfig('use_on_cluster');
        if ($value === null) {
            return false;
        }

        $enabled = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($enabled === null) {
            throw $this->invalidOption('use_on_cluster', $value, [true, false]);
        }

        return $enabled;
    }

    /**
     * Run a callback in one ClickHouse HTTP session on the active node, and return what the callback returns.
     *
     * Every query that the connection sends to its active node while the callback runs joins the session: the
     * queries of models, of both query builders, of the schema builder and of statement() and select(), because they
     * all go through the active node's client. A statement that is sent to every node of a cluster, such as one of
     * statementOnEveryNode(), reaches the other nodes outside the session. A temporary table that a query of the
     * session creates, and a SET, stay visible to the later queries of the session and to no other query. The
     * callback gets this connection.
     *
     * The session gets a random 32-character id, and is opened with SELECT 1, which is not logged, before the
     * callback runs, so that a timeout above the server's max_session_timeout fails with INVALID_SESSION_TIMEOUT
     * before the callback runs. Every query of the callback is then sent with session_id, session_timeout and
     * session_check=1, so that a query after the session has closed fails with SESSION_NOT_FOUND instead of running
     * in a new, empty session. While the callback runs:
     * - the active node of the connection's cluster is pinned, so Cluster::slideNode() throws: a session only exists
     *   on the node that opened it;
     * - the client's curler is a CurlerRollingInSession, which sends a failed request again, up to the
     *   connection's retries, only when the request never reached the server, and refuses smi2's asynchronous
     *   requests, which smi2 would send again and again; parallel queries are refused as well (see Parallel).
     *
     * When the callback returns or throws, the client's session settings, its curler and the pin are restored, so
     * later queries carry no session, or the session that an outer call or smi2's useSession() set. A nested call
     * opens a separate session. A session is not a transaction: each statement takes effect when it runs. ClickHouse
     * closes the session $timeout seconds after its last query ends, together with its temporary tables; it cannot
     * be closed earlier. Queries of other connections, queued jobs and other processes are not part of the session.
     *
     * A DatabaseException that names this session's id and has the code SESSION_NOT_FOUND (372) or
     * SESSION_IS_LOCKED (373) is thrown as QueryException::sessionNotFound() or sessionIsLocked(), with the
     * ClickHouse error as the previous exception. Any other exception is thrown as it is.
     *
     * Read rows inside the callback: a lazy reader, such as the generator of cursor() or a LazyCollection of
     * Laravel's cursor() or lazy(), runs its query only when it is read, so after the session has ended, outside
     * it, where a temporary table of the session is unknown, or a table of the same name in the database is read
     * instead. So a callback that returns a Generator or a LazyCollection gets a LogicException, after it has run;
     * return iterator_to_array() of the generator or ->all() of the collection instead. Such a reader inside
     * another value that the callback returns is not detected.
     *
     * @template TReturn
     * @param callable(static): TReturn $callback
     * @param int $timeout Seconds that ClickHouse keeps the session after its last query ends, from 1 to the
     *                     server's max_session_timeout (3600 by default)
     * @return TReturn
     * @throws InvalidArgumentException When $timeout is below 1, before anything is sent
     * @throws QueryException When the session closed between two queries (SESSION_NOT_FOUND) or still runs an
     *                        earlier query (SESSION_IS_LOCKED)
     * @throws LogicException When the callback returns a Generator or a LazyCollection
     */
    public function session(callable $callback, int $timeout = 60): mixed
    {
        if ($timeout < 1) {
            throw new InvalidArgumentException(sprintf(
                'The timeout of a ClickHouse session must be at least 1 second, %d given: ClickHouse closes a session'
                . ' with a timeout of 0 at the end of the query that opens it.',
                $timeout
            ));
        }

        $client = $this->getClient();
        $settings = $client->settings();
        $transport = $client->transport();
        $previousSettings = [];
        foreach (static::SESSION_SETTINGS as $name) {
            $previousSettings[$name] = $settings->get($name);
        }
        $previousCurler = $transport->getCurler();
        $sessionId = bin2hex(random_bytes(16));
        $cluster = $this->cluster ?? null;

        $cluster?->pinActiveNode();
        try {
            $transport->setDirtyCurler(new CurlerRollingInSession(
                $previousCurler instanceof CurlerRollingWithRetries ? $previousCurler->getRetries() : 0
            ));

            $settings->set('session_id', $sessionId);
            $settings->set('session_timeout', $timeout);
            $settings->set('session_check', null);
            $client->select('SELECT 1')->rows();
            $settings->set('session_check', 1);

            $result = $callback($this);
            if ($result instanceof Generator || $result instanceof LazyCollection) {
                throw new LogicException(sprintf(
                    'The session() callback returned a lazy reader (%s), which would run its query after the session'
                    . ' has ended, outside it. Read the rows inside the callback and return them, for example with'
                    . ' iterator_to_array() or ->all().',
                    get_debug_type($result)
                ));
            }

            return $result;
        } catch (DatabaseException $exception) {
            throw $this->describeSessionException($exception, $sessionId, $timeout);
        } finally {
            foreach ($previousSettings as $name => $value) {
                $settings->set($name, $value);
            }
            if ($previousCurler !== null) {
                $transport->setDirtyCurler($previousCurler);
            } else {
                $transport->setCurler();
            }
            $cluster?->unpinActiveNode();
        }
    }

    /**
     * Get the exception that session() throws for a ClickHouse error: QueryException::sessionNotFound() for
     * SESSION_NOT_FOUND (372) and QueryException::sessionIsLocked() for SESSION_IS_LOCKED (373), when the error
     * names the session's id, and the error as it is otherwise.
     *
     * @param DatabaseException $exception
     * @param string $sessionId
     * @param int $timeout
     * @return Throwable
     */
    protected function describeSessionException(
        DatabaseException $exception,
        string $sessionId,
        int $timeout
    ): Throwable {
        if (!str_contains($exception->getMessage(), $sessionId)) {
            return $exception;
        }

        return match ($exception->getCode()) {
            372 => QueryException::sessionNotFound($sessionId, $timeout, $exception),
            373 => QueryException::sessionIsLocked($sessionId, $exception),
            default => $exception,
        };
    }

    /**
     * Determine if the queries of the connection carry a ClickHouse session id: while a session() callback runs, or
     * after smi2's useSession() on the active node's client.
     *
     * @return bool
     */
    public function inSession(): bool
    {
        return $this->getClient()->getSession() !== false;
    }

    /**
     * Determine if the session of the connection has a temporary table of a given name.
     *
     * Outside a session there is none, and no query is sent. Inside one, EXISTS TEMPORARY TABLE `<name>` runs
     * through select(), so it is logged, and is false while the connection pretends.
     *
     * @param string $table The name of the temporary table, without a database
     * @return bool
     */
    public function hasTemporaryTable(string $table): bool
    {
        if (!$this->inSession()) {
            return false;
        }

        $exists = $this->scalar('EXISTS TEMPORARY TABLE ' . (new Grammar())->quoteIdentifier($table));

        return (int) $exists === 1;
    }

    /**
     * Get an exception for an option of the connection config that has an invalid value. The message shows a string
     * as it is, a number or a bool as PHP writes it, an instance of the package's Enum by its value, and any other
     * value by its type.
     *
     * @param string $option
     * @param mixed $value
     * @param list<bool|string> $allowed
     * @return InvalidArgumentException
     */
    protected function invalidOption(string $option, mixed $value, array $allowed): InvalidArgumentException
    {
        $allowed = array_map(
            fn (bool|string $value): string => is_bool($value) ? var_export($value, true) : "'{$value}'",
            $allowed
        );

        return new InvalidArgumentException(sprintf(
            'The [%s] option of the ClickHouse connection [%s] must be %s, [%s] given.',
            $option,
            $this->getName(),
            implode(' or ', $allowed),
            $this->describeOptionValue($value)
        ));
    }

    /**
     * Describe the value of a connection option for an exception message: a string as it is, a number or a bool as
     * PHP writes it, an instance of the package's Enum by its value, and any other value by its type.
     *
     * @param mixed $value
     * @return string
     */
    protected function describeOptionValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => var_export($value, true),
            $value instanceof Enum => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * Get a new query builder: the package Builder, following this connection's options (see
     * Builder::followConnectionOptions()), when the fix_default_query_builder option is on, as the packaged config
     * sets it; Laravel's query builder, as the package's QueryBuilder subclass, otherwise.
     *
     * @return Builder|QueryBuilder
     */
    public function query()
    {
        if ($this->getConfig('fix_default_query_builder')) {
            return (new Builder($this->getClient(), $this->getName()))->followConnectionOptions($this);
        }

        return new QueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }
}
