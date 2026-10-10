<?php

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;

trait BuilderMethodsFromLaravel
{

    public function useWritePdo()
    {
        return $this;
    }

    /**
     * Get a collection instance containing the values of a given column.
     *
     * When the query selects every column (see Builder::selectsEveryColumn()), as a model query and a query without
     * a select list do, a copy of it selects only the column, and the key when one is given and differs from the
     * column, and the values are read by their position: pluck('id') sends SELECT `id` FROM ..., and an alias, an
     * expression such as DB::raw('a + 1'), an ALIAS or MATERIALIZED column and a column of a joined table work as
     * they would in select(). Otherwise the query runs as it is, and the values are read by the names that
     * ClickHouse gives the columns in the first row (see keyOfAColumnInARow()): pluck('u.s') reads u.s for a column
     * of a joined table, and pluck('t.a') reads a for a column of the query's own table.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string|null $key
     * @return \Illuminate\Support\Collection
     */
    public function pluck($column, $key = null)
    {
        if ($this->selectsEveryColumn()) {
            $results = [];
            $columns = $key === null || $key === $column ? [$column] : [$column, $key];

            foreach ((clone $this)->select($columns)->getRows() as $row) {
                $values = array_values($row);

                if ($key === null) {
                    $results[] = $values[0];
                } else {
                    $results[count($values) > 1 ? $values[1] : $values[0]] = $values[0];
                }
            }

            return collect($results);
        }

        $queryResult = $this->runSelect();

        if (empty($queryResult)) {
            return collect();
        }

        $column = $this->keyOfAColumnInARow($column, (array) $queryResult[0]);

        $key = $key === null ? null : $this->keyOfAColumnInARow($key, (array) $queryResult[0]);

        return is_array($queryResult[0])
            ? $this->pluckFromArrayColumn($queryResult, $column, $key)
            : $this->pluckFromObjectColumn($queryResult, $column, $key);
    }

    /**
     * Strip off the table name or alias from a column identifier.
     *
     * A string is read as given, a package Expression or RawColumn as its SQL, and a Laravel database expression,
     * such as DB::raw(), as the SQL it gives with the grammar of the connection. The part after the last ' as ', in
     * any case, or else after the last dot, is the name, without the backticks around it: 'examples.f_int' gives
     * f_int, 'a as x' gives x, and RawColumn('a + 1', 'y') gives y.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string|null $column
     * @return string|null
     */
    protected function stripTableForPluck($column)
    {
        if (is_null($column)) {
            return $column;
        }

        $columnString = match (true) {
            $column instanceof Expression => (string) $column,
            $column instanceof ExpressionContract => (string) $this->grammar->getLaravelExpressionValue($column),
            default => (string) $column,
        };

        $separator = str_contains(strtolower($columnString), ' as ') ? ' as ' : '\.';

        $name = trim((string) last(preg_split('~' . $separator . '~i', $columnString)));

        return preg_match('/^`(.*)`$/s', $name, $quoted) === 1 ? str_replace('``', '`', $quoted[1]) : $name;
    }

    /**
     * Get the key under which a row holds a selected column, as value() and pluck() read a query with a select list.
     *
     * ClickHouse (24.8 checked) keeps the table or alias part in the name of a column of a joined table, such as
     * u.s for `u`.`s`, and the whole name of a column of a Nested structure, such as n.k for `n`.`k`, but leaves the
     * part of the query's own table out, such as s for `t`.`s`. So a string column is first looked up as written,
     * without its backticks, with a doubled backtick inside them read as one: 'u.s' and '`u`.`s`' give u.s. When
     * the row has no such key, as for 't.s' or 'a as x', the name without its table or alias part is the key (see
     * stripTableForPluck()), which is also the key of an Expression and a Laravel database expression.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @param array<string, mixed> $row
     * @return string
     */
    protected function keyOfAColumnInARow($column, array $row): string
    {
        if (is_string($column)) {
            $written = (string) preg_replace_callback(
                '/`((?:[^`]|``)*)`/',
                fn (array $match): string => str_replace('``', '`', $match[1]),
                trim($column)
            );
            if (array_key_exists($written, $row)) {
                return $written;
            }
        }

        return (string) $this->stripTableForPluck($column);
    }

    /**
     * Retrieve column values from rows represented as objects.
     *
     * @param array $queryResult
     * @param string $column
     * @param string|null $key
     * @return \Illuminate\Support\Collection
     */
    protected function pluckFromObjectColumn($queryResult, $column, $key)
    {
        $results = [];

        if (is_null($key)) {
            foreach ($queryResult as $row) {
                $results[] = $row->$column;
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row->$key] = $row->$column;
            }
        }

        return collect($results);
    }

    /**
     * Retrieve column values from rows represented as arrays.
     *
     * @param array $queryResult
     * @param string $column
     * @param string|null $key
     * @return \Illuminate\Support\Collection
     */
    protected function pluckFromArrayColumn($queryResult, $column, $key)
    {
        $results = [];

        if (is_null($key)) {
            foreach ($queryResult as $row) {
                $results[] = $row[$column];
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row[$key]] = $row[$column];
            }
        }

        return collect($results);
    }

    /**
     * Execute the given callback while selecting the given columns.
     *
     * After running the callback, the columns are reset to the original value. pluck() no longer uses it.
     *
     * @param array $columns
     * @param callable $callback
     * @return mixed
     */
    protected function onceWithColumns($columns, $callback)
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        $result = $callback();

        $this->columns = $original;

        return $result;
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        return $this->getRows();
    }


    /**
     * Retrieve the minimum value of a given column.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @return mixed
     */
    public function min($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the maximum value of a given column.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @return mixed
     */
    public function max($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Retrieve the sum of the values of a given column.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @return mixed
     */
    public function sum($column)
    {
        $result = $this->aggregate(__FUNCTION__, [$column]);

        return $result ?: 0;
    }

    /**
     * Retrieve the average of the values of a given column.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @return mixed
     */
    public function avg($column)
    {
        return $this->aggregate(__FUNCTION__, [$column]);
    }

    /**
     * Alias for the "avg" method.
     *
     * @param \Illuminate\Contracts\Database\Query\Expression|Expression|string $column
     * @return mixed
     */
    public function average($column)
    {
        return $this->avg($column);
    }

    /**
     * Execute an aggregate function on the database.
     *
     * The aggregate reads the rows that the query returns (see Builder::getQueryForAggregate()): max('id') sends
     * SELECT max(`id`) AS `aggregate` FROM ... A query with set operations, GROUP BY, HAVING, LIMIT BY, an alias or
     * an expression in its select list, or an ORDER BY entry that defines an alias or adds, multiplies or limits the
     * rows, such as orderByRaw('id DESC LIMIT 1 BY grp'), is aggregated in a subquery (see
     * Builder::countsInASubquery()), such as
     * SELECT max(`id`) AS `aggregate` FROM (SELECT `id` FROM `t` UNION ALL SELECT `id` FROM `u`), so the column must
     * be one that the query returns. The FORMAT of the query is left out, and the result is null when no row
     * comes back.
     *
     * A string column is quoted as a name, part by part at its dots, as select() quotes it, so it cannot add SQL to
     * the query; '*' is written as it is. Pass SQL as raw(), a RawColumn or DB::raw(): sum(raw('a * b')).
     *
     * The function is a plain identifier, such as max or uniqExact, or a parametric aggregate with a list of
     * numbers as its parameters, such as quantile(0.5), quantiles(0.5, 0.9) or topK(2):
     * aggregate('quantile(0.5)', ['a']) sends SELECT quantile(0.5)(`a`) AS `aggregate` FROM ...
     *
     * @param string $function The function: a plain identifier, optionally followed by a list of numbers in parentheses
     * @param array<int, string|Expression|ExpressionContract>|string|Expression|ExpressionContract $columns
     * @return mixed
     * @throws InvalidArgumentException When the function is not a plain identifier, optionally followed by a list of
     *                                  numbers in parentheses
     */
    public function aggregate($function, $columns = ['*'])
    {
        $rows = $this->getQueryForAggregate((string) $function, is_array($columns) ? $columns : [$columns])->getRows();

        return $rows === [] ? null : ($rows[0]['aggregate'] ?? null);
    }

    /**
     * Select the aggregate, as aggregate() runs it on a query that it does not read in a subquery.
     *
     * @param string $function
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @return $this
     * @throws InvalidArgumentException When the function is not a plain identifier, optionally followed by a list of
     *                                  numbers in parentheses
     */
    protected function setAggregate($function, $columns)
    {
        $this->select($this->compileAggregate((string) $function, (array) $columns));

        return $this;
    }

    /**
     * Compile an aggregate column: <function>(<columns>) AS `aggregate`.
     *
     * A string column is quoted as a name, part by part at its dots, with its backticks and backslashes escaped:
     * max('t.id') gives max(`t`.`id`). '*' is written as it is, and a package Expression, a RawColumn or a Laravel
     * database expression, such as DB::raw(), as the SQL it holds.
     *
     * The function is a plain identifier, optionally followed by the parameters of a parametric aggregate: a list
     * of numbers in parentheses, such as quantile(0.5) or quantiles(0.5, 0.9), which gives
     * quantiles(0.5, 0.9)(`a`). A number is written in decimal, with an optional sign, fraction and exponent, so
     * the parameters cannot add SQL to the query either.
     *
     * @param string $function
     * @param array<int, string|Expression|ExpressionContract> $columns
     * @return Expression
     * @throws InvalidArgumentException When the function is not a plain identifier, optionally followed by a list of
     *                                  numbers in parentheses
     */
    protected function compileAggregate(string $function, array $columns): Expression
    {
        $number = '[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?';
        if (preg_match("/^[A-Za-z_][A-Za-z0-9_]*(?:\\(\\s*{$number}(?:\\s*,\\s*{$number})*\\s*\\))?\\z/", $function) !== 1) {
            throw new InvalidArgumentException(
                "Invalid aggregate function name [{$function}]: a function name must match [A-Za-z_][A-Za-z0-9_]*,"
                . ' optionally followed by a list of numbers in parentheses, such as quantile(0.5).'
            );
        }

        $compiled = array_map(
            fn (mixed $column): string => match (true) {
                $column === '*' => '*',
                $column instanceof Expression => (string) $column,
                $column instanceof ExpressionContract => (string) $this->grammar->getLaravelExpressionValue($column),
                default => (string) $this->grammar->wrap(new Identifier((string) $column)),
            },
            array_values($columns)
        );

        return new Expression($function . '(' . implode(', ', $compiled) . ') AS `aggregate`');
    }

    /**
     * Insert one associative row, or a list of them, into the table for writes, and return the statement of the
     * insert.
     *
     * The format is $format, or else the insert_format option of the connection that the builder follows, or of
     * the connection of its name for a builder that follows none (see Builder::getConnectionForWrites()):
     * - Values: as with smi2's Client::insertAssocBulk(), every row must have the keys of the first row, in the
     *   same order, and each key is sent as a column name with its backticks and backslashes escaped (see
     *   Grammar::escapeInsertColumns()). The values are written as the model inserts write them (see
     *   ClientRequests::compileValuesInsert()): a float with every digit, NaN, INF and -INF as nan, inf and -inf, a
     *   date at the datetime_precision of the followed connection, and raw SQL as the SQL it holds;
     * - JSONEachRow: every row must have the keys of the first row, in any order, and the rows are sent as one
     *   JSON object per line after INSERT INTO <table> (<keys>) FORMAT JSONEachRow (see
     *   ClientRequests::insertJsonEachRow()).
     *
     * No rows, and a row without keys, are refused in both formats, with smi2's QueryException 'Inserting empty
     * values array is not supported in ClickHouse'. Nothing is sent when a check fails. While the connection
     * pretends, the INSERT, or the head of a JSONEachRow insert, is logged and nothing is sent.
     *
     * A builder that follows no connection, and whose connection cannot be resolved (see
     * Builder::getConnectionForWrites()), inserts in Values, as 3.0.0 did, and sends the insert; it cannot insert
     * in JSONEachRow.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $values One row, or a list of rows
     * @param string|null $format Format::VALUES or Format::JSON_EACH_ROW, in any letter case; null uses the
     *                            connection's insert_format
     * @return Statement
     * @throws InvalidArgumentException When the format is neither Values nor JSONEachRow, or, in JSONEachRow, a value
     *                                  cannot be sent as JSON or the builder has no connection
     * @throws \ClickHouseDB\Exception\QueryException When there are no rows, a row has no keys, in Values a row's
     *                                                keys or their order differ from the first row's, or the insert
     *                                                fails
     * @throws QueryException When, in JSONEachRow, a row's keys differ from the first row's
     */
    public function insert(array $values, ?string $format = null): Statement
    {
        $format = $format === null ? null : $this->insertFormatName($format);
        $rows = isset($values[0]) && is_array($values[0]) ? $values : [$values];
        if (in_array([], $rows, true)) {
            throw \ClickHouseDB\Exception\QueryException::cannotInsertEmptyValues();
        }

        $table = $this->getTableForWrites();
        $connection = $this->getConnectionForWrites();

        if (($format ?? $connection?->getInsertFormat()) === Format::JSON_EACH_ROW) {
            if ($connection === null) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot insert in %s with a builder that follows no connection and whose connection [%s] cannot'
                    . ' be resolved. Build the query from a ClickHouse connection or a model, or insert in %s.',
                    Format::JSON_EACH_ROW,
                    $this->connection,
                    Format::VALUES
                ));
            }

            return ClientRequests::insertJsonEachRow(
                $this->client,
                $connection,
                $table,
                $rows,
                new JsonEachRowEncoder($this->grammar->formatDateTime(...))
            );
        }

        [$columns, $positionalRows] = $this->client->prepareInsertAssocBulk($rows);
        $sql = ClientRequests::compileValuesInsert(
            $this->grammar,
            $table,
            $positionalRows,
            $this->grammar->escapeInsertColumns($columns)
        );

        return $this->sendWrite($connection, $sql);
    }

    /**
     * Get the name of an insert format, as Format spells it, from a name in any letter case and with surrounding
     * spaces.
     *
     * @param string $format
     * @return string Format::VALUES or Format::JSON_EACH_ROW
     * @throws InvalidArgumentException For any other format
     */
    protected function insertFormatName(string $format): string
    {
        foreach ([Format::VALUES, Format::JSON_EACH_ROW] as $allowed) {
            if (strcasecmp(trim($format), $allowed) === 0) {
                return $allowed;
            }
        }

        throw new InvalidArgumentException(sprintf(
            "insert() takes the format '%s' or '%s', [%s] given.",
            Format::VALUES,
            Format::JSON_EACH_ROW,
            $format
        ));
    }

    /**
     * Insert the rows of files in an input format into the table for writes, one INSERT per file, sent together by
     * smi2's Client::insertBatchFiles().
     *
     * The statement of each file is INSERT INTO <table> ( <columns> ) FORMAT <format>, with the file as its body:
     * table('my_table')->insertFiles('/data/a.csv', 'csvwithnames', ['k', 'we`ird']) sends
     * INSERT INTO `my_table` ( `k`,`we``ird` ) FORMAT CSVWithNames. Each column name is quoted as one identifier,
     * so a name taken from input cannot change the statement; without columns the list is left out. The format
     * name is matched in any letter case.
     *
     * Every path must be a readable file: otherwise nothing is sent. Each file is a separate INSERT, and the files
     * are sent together: smi2 throws for the first file that the server refused, or that timed out, after every
     * file was sent, so the files before and after it may have been inserted. Each statement is logged when every
     * file was inserted. While the connection pretends, the statements are logged and nothing is sent or read. A
     * builder that follows no connection, and whose connection cannot be resolved (see
     * Builder::getConnectionForWrites()), sends the files and logs nothing.
     *
     * Each file has to be sent and inserted within the connection's timeout_query (2 seconds in the packaged
     * configuration), counted from the start of its request, so raise timeout_query for large files. The
     * connection's retries do not apply to these requests.
     *
     * @param string|array<int, string> $paths
     * @param string $format One of smi2's Client::SUPPORTED_FORMATS, in any letter case: TabSeparated,
     *                       TabSeparatedWithNames, CSV, CSVWithNames, JSONEachRow, CSVWithNamesAndTypes or
     *                       TSVWithNamesAndTypes
     * @param array<int, string> $columns
     * @return array<string, Statement> The statement of each file, keyed by path
     * @throws InvalidArgumentException For a format that smi2 cannot send from a file, before anything is sent
     * @throws QueryException When the client uses a ClickHouse session, or a path is not a readable file, before
     *                        anything is sent
     * @throws \ClickHouseDB\Exception\QueryException When requests of the client are still pending, before anything
     *                                                is sent, or an insert fails or times out (a DatabaseException
     *                                                when the server refused it)
     */
    public function insertFiles(string|array $paths, string $format = Format::CSV, array $columns = []): array
    {
        $format = $this->insertFileFormat($format);
        if ($this->client->getSession() !== false) {
            throw new QueryException(
                'Cannot insert files in a ClickHouse session: smi2/phpclickhouse sends the files as asynchronous'
                . ' requests, which fail in a session after the file is sent, and ClickHouse runs one query of a'
                . ' session at a time. Insert the files outside session().'
            );
        }

        $table = $this->getTableForWrites();
        $quotedColumns = array_map(
            fn (int|string $column): string => $this->grammar->quoteIdentifier((string) $column),
            array_values($columns)
        );
        $connection = $this->getConnectionForWrites();

        if ($connection?->pretending()) {
            $sql = $quotedColumns === []
                ? "INSERT INTO {$table} FORMAT {$format}"
                : "INSERT INTO {$table} ( " . implode(',', $quotedColumns) . " ) FORMAT {$format}";
            $statements = [];
            foreach ((array) $paths as $path) {
                $connection->logQuery($sql, [], 0.0);
                $statements[$path] = ClientRequests::pretendedStatement($sql);
            }

            return $statements;
        }

        foreach ((array) $paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw new QueryException("Cannot insert the file [{$path}]: it is not a readable file. No file was sent.");
            }
        }

        $statements = $this->client->insertBatchFiles($table, $paths, $quotedColumns, $format);
        foreach ($statements as $statement) {
            $connection?->logQuery($statement->sql(), [], round($statement->totalTimeRequest() * 1000, 2));
        }

        return $statements;
    }

    /**
     * Get the name of a format that smi2's Client::insertBatchFiles() sends, as smi2 spells it, from a name in any
     * letter case.
     *
     * @param string $format
     * @return string
     * @throws InvalidArgumentException When smi2 cannot send the format from a file
     */
    protected function insertFileFormat(string $format): string
    {
        foreach (Client::SUPPORTED_FORMATS as $supported) {
            if (strcasecmp($supported, $format) === 0) {
                return $supported;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'insertFiles() cannot send the format [%s]: use one of %s, in any letter case.',
            $format,
            implode(', ', Client::SUPPORTED_FORMATS)
        ));
    }
}
