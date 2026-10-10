<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Query\Degeneration\Bindings;
use ClickHouseDB\Query\Expression\Expression as ClientExpression;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Query\Query;
use ClickHouseDB\Quote\FormatLine;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Type\Type as ClientType;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use ReflectionProperty;
use Stringable;

/**
 * Requests that the package sends through a smi2 client where smi2's own Client methods would send or read them
 * wrongly.
 *
 * - select() reads a SELECT in the format that ClickHouse sends it in. smi2's Client::select() searches the whole
 *   SQL, string literals, comments and names included, for FORMAT followed by a format name that it knows, so
 *   a value such as 'export as format csv' made it read a JSON result as CSV.
 * - write() logs a write and leaves it out while the connection pretends.
 * - compileValuesInsert() writes the INSERT ... VALUES of the package's Values inserts, with every digit of a
 *   float, NaN and INF, and dates at the connection's datetime_precision.
 * - insertJsonEachRow() and insertJsonCompactEachRow() send rows in a JSON input format, with the INSERT head in
 *   the URL and the rows in the body.
 */
final class ClientRequests
{
    /**
     * The input format of keyed rows: one JSON object per row.
     */
    private const JSON_EACH_ROW = Format::JSON_EACH_ROW;

    /**
     * The input format of positional rows: one JSON array per row.
     */
    private const JSON_COMPACT_EACH_ROW = Format::JSON_COMPACT_EACH_ROW;

    /**
     * The format in which smi2's Client::select() asks for the result of a query that names no format.
     */
    private const SELECT_FORMAT = 'JSON';

    /**
     * The response header in which ClickHouse names the format of the result.
     */
    private const FORMAT_HEADER = 'X-ClickHouse-Format';

    /**
     * Run a SELECT and return its statement, read in the format that the SQL names, or JSON when it names none.
     *
     * When smi2's Client::select() sends the SQL as intended, the query goes through Client::select() as before:
     * smi2 adds FORMAT JSON to SQL without a format, adds nothing to SQL that names its format, and reads the
     * result in that format. smi2's own Query class makes that decision (see clientSelectsAsIntended()). Any
     * other query is sent through the client's transport with the SQL as given, its bindings, readonly=2 and the
     * client's curler, so retries apply, as Client::select() does. default_format=JSON makes ClickHouse answer in
     * JSON when the SQL names no format (a FORMAT clause in the SQL wins). The statement reads the result in the
     * format that ClickHouse names in its X-ClickHouse-Format response header, so a FORMAT clause that the caller
     * did not name is read as before. Errors surface when the result is read, as with Client::select().
     *
     * ClickHouse writes an exception into a whole-document JSON result (http_write_exception_in_output_format is
     * on by default since 23.9): a query that fails after the first result block, or after the HTTP 200 headers,
     * ends in a valid JSON document whose last key is "exception". smi2 would read the rows before the error as
     * the result on both routes, so readExceptionWrittenIntoTheResult() puts that exception in place of the body,
     * on either route, so that the statement reports the error with its ClickHouse code, name and version.
     *
     * Only smi2's Bindings degeneration is applied to a rerouted query: a client's other degenerations, such as
     * the Conditions of Client::enableQueryConditions(), are not.
     *
     * @param Client $client
     * @param string $sql
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @param string|null $format The format that the SQL names in its FORMAT clause, or null when it names none
     * @return Statement
     */
    public static function select(Client $client, string $sql, array $bindings = [], ?string $format = null): Statement
    {
        if (self::clientSelectsAsIntended($sql, $format)) {
            $statement = $client->select($sql, $bindings);
            self::readExceptionWrittenIntoTheResult($statement->getRequest(), $statement->getFormat());

            return $statement;
        }

        $request = self::newReroutedSelectRequest($client, $sql, $bindings, $format);
        $client->transport()->getCurler()->execOne($request);

        return self::selectStatement($request);
    }

    /**
     * Build the request of a SELECT as select() sends it, without sending it, so that a caller can queue it on a
     * curler of its own, as parallel queries do.
     *
     * A query that smi2's Client::select() sends as intended gets the request that Client::select() builds,
     * with smi2's Bindings degeneration only; any other query gets the request that select() sends through the
     * transport. Once the request has run, selectStatement() gives its statement.
     *
     * @param Client $client
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @param string|null $format The format that the SQL names in its FORMAT clause, or null when it names none
     * @return CurlerRequest
     */
    public static function selectRequest(
        Client $client,
        string $sql,
        array $bindings = [],
        ?string $format = null
    ): CurlerRequest {
        if (!self::clientSelectsAsIntended($sql, $format)) {
            return self::newReroutedSelectRequest($client, $sql, $bindings, $format);
        }

        $query = new Query($sql, [self::bindings($bindings)]);
        $query->setFormat(self::SELECT_FORMAT);

        return $client->transport()->getRequestRead($query);
    }

    /**
     * Get the statement of a SELECT request from selectRequest() once the request has run.
     *
     * The statement reads the result in the format that ClickHouse names in its X-ClickHouse-Format response
     * header, and in the format that the request was built with when the response has no such header.
     *
     * A result with one JSON value per line, such as JSONCompactEachRow, JSONStringsEachRow or JSONLines, is read as
     * text, as smi2 reads JSONEachRow, CSV and the other text formats: rawData() returns the body, and rows() throws
     * 'Can`t find meta' (see newTextResultStatement()). smi2 would decode such a body as one JSON document, so that
     * rawData() returned null and rows() an empty list, without an error.
     *
     * @param CurlerRequest $request
     * @return Statement
     */
    public static function selectStatement(CurlerRequest $request): Statement
    {
        $format = $request->isResponseExists() ? self::responseFormat($request->response()) : null;
        if ($format !== null) {
            $request->setRequestExtendedInfo(array_merge($request->getRequestExtendedInfo(), ['format' => $format]));
        }
        $format = $request->getRequestExtendedInfo('format');
        self::readExceptionWrittenIntoTheResult($request, $format);

        return self::hasOneJsonValuePerLine($format) ? self::newTextResultStatement($request) : new Statement($request);
    }

    /**
     * Determine if smi2 would decode the result of a format as one JSON document although ClickHouse writes one JSON
     * value per line in it.
     *
     * smi2's CurlerResponse::rawDataOrJson() decodes the body of every format whose name holds 'json', in any letter
     * case, except those that hold 'JSONEachRow'. Of those, the formats with 'EachRow' in their name, such as
     * JSONCompactEachRow, JSONCompactEachRowWithNames, JSONStringsEachRow and JSONCompactStringsEachRow, and JSONLines
     * and NDJSON, which are other names of JSONEachRow, write one JSON value per line. JSONObjectEachRow writes one
     * JSON object, which smi2 decodes as it does any other whole JSON document.
     *
     * @param mixed $format
     * @return bool
     */
    private static function hasOneJsonValuePerLine(mixed $format): bool
    {
        if (!is_string($format) || stripos($format, 'json') === false || stripos($format, 'JSONEachRow') !== false) {
            return false;
        }

        return (stripos($format, 'EachRow') !== false && stripos($format, 'ObjectEachRow') === false)
            || strcasecmp($format, 'JSONLines') === 0
            || strcasecmp($format, 'NDJSON') === 0;
    }

    /**
     * Build a statement that reads its result as text, for a format with one JSON value per line (see
     * hasOneJsonValuePerLine()).
     *
     * rawData() returns the body, and every method that reads rows, such as rows(), fetchOne(), count() and
     * iteration, throws QueryException 'Can`t find meta', as smi2's Statement does for CSV and the other text
     * formats. Each of them throws the error of a failed query first, as smi2's Statement does.
     *
     * @param CurlerRequest $request
     * @return Statement
     */
    private static function newTextResultStatement(CurlerRequest $request): Statement
    {
        return new class($request) extends Statement {
            /**
             * @return string
             */
            public function rawData(): mixed
            {
                $this->throwIfFailed();

                return $this->getRequest()->response()->body();
            }

            public function extremes(): ?array
            {
                $this->refuseToReadRows();
            }

            public function extremesMin(): array
            {
                $this->refuseToReadRows();
            }

            public function extremesMax(): array
            {
                $this->refuseToReadRows();
            }

            public function totals(): ?array
            {
                $this->refuseToReadRows();
            }

            public function countAll(): int|false
            {
                $this->refuseToReadRows();
            }

            public function statistics(mixed $key = false): mixed
            {
                $this->refuseToReadRows();
            }

            public function count(): int
            {
                $this->refuseToReadRows();
            }

            public function fetchRow(mixed $key = null): mixed
            {
                $this->refuseToReadRows();
            }

            public function fetchOne(mixed $key = null): mixed
            {
                $this->refuseToReadRows();
            }

            public function rowsAsTree($path): array
            {
                $this->refuseToReadRows();
            }

            public function rows(): array
            {
                $this->refuseToReadRows();
            }

            public function rowsGenerator(): Generator
            {
                $this->refuseToReadRows();
            }

            public function valid(): bool
            {
                $this->refuseToReadRows();
            }

            /**
             * Throw the error of a failed query, as smi2's Statement checks a response before it reads it.
             *
             * @return void
             * @throws ClientQueryException When there is no response, or the query failed
             */
            private function throwIfFailed(): void
            {
                if (!$this->getRequest()->isResponseExists()) {
                    throw ClientQueryException::noResponse();
                }

                if ($this->isError()) {
                    $this->error();
                }
            }

            /**
             * @return never
             * @throws ClientQueryException Always: the error of a failed query, or 'Can`t find meta'
             */
            private function refuseToReadRows(): never
            {
                $this->throwIfFailed();

                throw new ClientQueryException('Can`t find meta');
            }
        };
    }

    /**
     * Determine if smi2's Client::select() sends the SQL as intended and reads its result in the right format.
     *
     * That is so when smi2's Query class, given the JSON format as Client::select() gives it, adds FORMAT JSON to
     * SQL that names no format, adds nothing to SQL that names the given format, and keeps that format (in any
     * letter case). It is not so when smi2 finds FORMAT and a format name that it knows elsewhere in the SQL, in
     * a string literal, a comment or a name, or does not know the format that the SQL names, such as XML,
     * Pretty or Values. smi2's list of format names is not copied here: its Query class decides.
     *
     * A SQL without a format that ends inside a comment is also rerouted: smi2 would append its FORMAT JSON to
     * the end of the SQL, inside the comment, so ClickHouse would answer in its default format and smi2 would
     * read a non-JSON result as JSON, silently returning no rows or throwing 'Can`t find meta'. Blank SQL is
     * left to Client::select(), which refuses it.
     *
     * @param string $sql
     * @param string|null $format The format that the SQL names in its FORMAT clause, or null when it names none
     * @return bool
     */
    public static function clientSelectsAsIntended(string $sql, ?string $format): bool
    {
        if (trim($sql) === '') {
            return true;
        }

        $query = new Query($sql);
        $query->setFormat(self::SELECT_FORMAT);
        $sent = $query->toSql();

        $intended = trim($sql);
        if (str_ends_with($intended, ';')) {
            $intended = substr($intended, 0, -1);
        }
        if ($format === null) {
            if (self::endsInsideAComment($intended)) {
                return false;
            }
            $intended .= ' FORMAT ' . self::SELECT_FORMAT;
        }

        return $sent === $intended && strcasecmp((string) $query->getFormat(), $format ?? self::SELECT_FORMAT) === 0;
    }

    /**
     * Send a write, or, while the connection pretends, log it and return a successful empty statement without
     * sending it.
     *
     * The write goes through Client::write(), which throws when it fails. While pretending, the statement is
     * logged with logQuery(), so that pretend() lists it, and the returned statement's isError() is false, so
     * that code that checks it goes on as after a write that succeeded.
     *
     * @param Client $client
     * @param Connection $connection The connection whose pretend mode decides
     * @param string $sql
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration
     * @return Statement
     */
    public static function write(Client $client, Connection $connection, string $sql, array $bindings = []): Statement
    {
        if ($connection->pretending()) {
            $connection->logQuery($sql, $bindings, 0.0);

            return self::pretendedStatement($sql);
        }

        return $client->write($sql, $bindings);
    }

    /**
     * Build a statement over an empty HTTP 200 response, for a write that was not sent while the connection
     * pretends: isError() is false, rows() is empty and summary() is null.
     *
     * @param string $sql The statement that sql() returns
     * @return Statement
     */
    public static function pretendedStatement(string $sql): Statement
    {
        $response = new CurlerResponse();
        $response->_info = [
            'url' => '',
            'content_type' => null,
            'http_code' => 200,
            'header_size' => 0,
            'request_size' => 0,
            'total_time' => 0.0,
            'namelookup_time' => 0.0,
            'connect_time' => 0.0,
            'pretransfer_time' => 0.0,
            'starttransfer_time' => 0.0,
            'redirect_time' => 0.0,
            'size_upload' => 0,
            'size_download' => 0,
            'speed_upload' => 0,
            'speed_download' => 0,
            'download_content_length' => 0,
            'upload_content_length' => 0,
        ];

        $request = new CurlerRequest();
        $request->setRequestExtendedInfo(['sql' => $sql, 'query' => null, 'format' => null]);
        $request->setResponse($response);

        return new Statement($request);
    }

    /**
     * Compile the INSERT that smi2's Client::insert() sends for rows and column names, byte for byte, except for
     * the values that smi2 writes differently from the rest of the package (see prepareValuesForValuesInsert()):
     * - a finite float is written with every digit, as the shortest text that reads back as the same float (1/3 as
     *   0.3333333333333333, 2.0 as 2, 1e25 as 1.0E+25): smi2 writes PHP's string of the float, so the ini setting
     *   precision is set to -1 while the rows are written, and restored afterwards, also when a value throws (the
     *   __toString() of a Stringable value runs with that setting too);
     * - NaN, INF and -INF are written as nan, inf and -inf;
     * - a date with a sub-second part is written at the datetime_precision of the grammar (see
     *   Grammar::formatDateTime()); every other date is left to smi2, which writes the same 'Y-m-d H:i:s' text;
     * - raw SQL, this package's Expression or a Laravel database expression such as DB::raw(), is written as the
     *   SQL it holds, as smi2 writes its own Raw expression;
     * - a Stringable object whose value property smi2 cannot read, such as a value object with a private $value,
     *   is written as its string.
     * Every other value is written by smi2; a PHP bool, for example, is sent as the quoted string 'true' or 'false'.
     * The rows are prepared and written one at a time, and a row without such a value is not copied, so the
     * insert needs little more memory than the SQL it sends.
     *
     * The model inserts, the buffer flush and Builder::insert() send their Values rows with it, through write().
     *
     * @param Grammar $grammar A grammar of the connection, such as Connection::newBuilderGrammar() gives
     * @param string $table A table name, quoted as one identifier when it has no backtick and no dot, as smi2 does
     * @param array<int|string, mixed> $rows The rows; one that is not an array is left for smi2 to refuse
     * @param array<int, string> $columns The escaped column names (see Grammar::escapeInsertColumns())
     * @return string
     * @throws ClientQueryException When there are no rows
     */
    public static function compileValuesInsert(Grammar $grammar, string $table, array $rows, array $columns): string
    {
        if ($rows === []) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        if (stripos($table, '`') === false && stripos($table, '.') === false) {
            $table = '`' . $table . '`';
        }
        $sql = 'INSERT INTO ' . $table;
        if (count($columns) !== 0) {
            $sql .= ' (`' . implode('`,`', $columns) . '`) ';
        }
        $sql .= ' VALUES ';

        $formatsDates = $grammar->getDateTimePrecision() !== DateTimePrecision::SECOND;
        $precision = (string) ini_get('precision');
        ini_set('precision', '-1');
        try {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $row = self::prepareValuesForValuesInsert($grammar, $formatsDates, $row) ?? $row;
                }
                $sql .= ' (' . FormatLine::Insert($row) . '), ';
            }
        } finally {
            ini_set('precision', $precision);
        }

        return trim($sql, ', ');
    }

    /**
     * Replace the values of a row, or of an array value at any depth, that smi2 would write differently from the
     * rest of the package (see compileValuesInsert()): NaN, INF and -INF with smi2 Raw expressions of nan, inf and
     * -inf, and objects as prepareObjectForValuesInsert() replaces them. Finite floats are left as they are.
     *
     * @param Grammar $grammar
     * @param bool $formatsDates Whether the grammar writes dates with a sub-second part
     * @param array<int|string, mixed> $values
     * @return array<int|string, mixed>|null The values with the replacements, or null when no value is replaced,
     *                                       so that the caller keeps the array it has without a copy
     */
    private static function prepareValuesForValuesInsert(Grammar $grammar, bool $formatsDates, array $values): ?array
    {
        $replaced = false;
        foreach ($values as $key => $value) {
            if (is_float($value)) {
                if (is_finite($value)) {
                    continue;
                }
                $replacement = new Raw($grammar->compileLiteral($value));
            } elseif (is_array($value)) {
                $replacement = self::prepareValuesForValuesInsert($grammar, $formatsDates, $value);
            } elseif (is_object($value)) {
                $replacement = self::prepareObjectForValuesInsert($grammar, $formatsDates, $value);
            } else {
                continue;
            }

            if ($replacement !== null) {
                $values[$key] = $replacement;
                $replaced = true;
            }
        }

        return $replaced ? $values : null;
    }

    /**
     * Replace an object that smi2 would write differently from the rest of the package (see compileValuesInsert()):
     * - a date with the text of Grammar::formatDateTime() when the grammar writes dates with a sub-second part and
     *   that text is not the 'Y-m-d H:i:s' that smi2 writes;
     * - this package's Expression, or a Laravel database expression, with a smi2 Raw expression of its SQL;
     * - a Stringable object whose value property is not public with its string: smi2 reads a value property
     *   before it calls __toString(), and fails on one that is private or protected.
     * smi2's own types and expressions, and every other object, are left to smi2. So is this package's Identifier,
     * a name that a VALUES row cannot hold.
     *
     * @param Grammar $grammar
     * @param bool $formatsDates Whether the grammar writes dates with a sub-second part
     * @param object $value
     * @return Raw|string|null The replacement, or null to leave the object to smi2
     */
    private static function prepareObjectForValuesInsert(Grammar $grammar, bool $formatsDates, object $value): Raw|string|null
    {
        if ($value instanceof DateTimeInterface) {
            if (!$formatsDates) {
                return null;
            }
            $text = $grammar->formatDateTime($value);

            return $text === $value->format('Y-m-d H:i:s') ? null : $text;
        }

        if ($value instanceof ClientType || $value instanceof ClientExpression || $value instanceof Identifier) {
            return null;
        }

        if ($value instanceof Expression || $value instanceof ExpressionContract) {
            return new Raw($grammar->compileLiteral($value));
        }

        if ($value instanceof Stringable && property_exists($value, 'value')
            && !(new ReflectionProperty($value, 'value'))->isPublic()) {
            return (string) $value;
        }

        return null;
    }

    /**
     * Insert keyed rows as JSONEachRow: INSERT INTO <table> (<keys of the first row>) FORMAT JSONEachRow, followed
     * by one JSON object per row.
     *
     * Every row must have the first row's keys, in any order; otherwise nothing is sent. The INSERT head goes in
     * the URL and the rows in the request body, so smi2 never substitutes bindings in the data, a retry sends
     * the whole body again, and an error message names the head instead of carrying the data: ClickHouse (24.8
     * checked) quotes at most about 160 bytes around a value that it cannot parse. While the connection
     * pretends, the head is logged and nothing is sent.
     *
     * @param Client $client
     * @param Connection $connection The connection whose pretend mode decides
     * @param string $table A table name, or a name with a database or backticks, written as given
     * @param array<int|string, array<int|string, mixed>> $rows
     * @param JsonEachRowEncoder $encoder
     * @return Statement
     * @throws QueryException When a row's keys differ from the first row's
     * @throws InvalidArgumentException When a row is not an array, or a value cannot be sent as JSON
     * @throws ClientQueryException When there are no rows, or the insert fails
     */
    public static function insertJsonEachRow(
        Client $client,
        Connection $connection,
        string $table,
        array $rows,
        JsonEachRowEncoder $encoder
    ): Statement {
        $head = self::compileInsertWithFormat($table, self::verifyKeys($rows), self::JSON_EACH_ROW);
        if ($connection->pretending()) {
            $connection->logQuery($head, [], 0.0);

            return self::pretendedStatement($head);
        }

        return self::sendInsert($client, $head, $encoder->encodeRows($rows), self::JSON_EACH_ROW);
    }

    /**
     * Insert positional rows as JSONCompactEachRow: INSERT INTO <table> [(<columns>)] FORMAT JSONCompactEachRow,
     * followed by one JSON array per row.
     *
     * Without columns, the values of a row go to the table's columns in their order, as in a Values insert.
     * Every row must have as many values as the first row, and as the columns when they are given; otherwise
     * nothing is sent. The head and the rows are sent as insertJsonEachRow() sends them, and while the
     * connection pretends, the head is logged and nothing is sent.
     *
     * @param Client $client
     * @param Connection $connection The connection whose pretend mode decides
     * @param string $table A table name, or a name with a database or backticks, written as given
     * @param array<int|string, array<int|string, mixed>> $rows
     * @param JsonEachRowEncoder $encoder
     * @param array<int, int|string> $columns
     * @return Statement
     * @throws InvalidArgumentException When a row is not an array or has another number of values, or a value
     *                                  cannot be sent as JSON
     * @throws ClientQueryException When there are no rows, or the insert fails
     */
    public static function insertJsonCompactEachRow(
        Client $client,
        Connection $connection,
        string $table,
        array $rows,
        JsonEachRowEncoder $encoder,
        array $columns = []
    ): Statement {
        self::verifyValueCounts($rows, $columns);
        $head = self::compileInsertWithFormat($table, $columns, self::JSON_COMPACT_EACH_ROW);
        if ($connection->pretending()) {
            $connection->logQuery($head, [], 0.0);

            return self::pretendedStatement($head);
        }

        return self::sendInsert($client, $head, $encoder->encodeCompactRows($rows), self::JSON_COMPACT_EACH_ROW);
    }

    /**
     * Compile the head of an insert whose rows follow in an input format: INSERT INTO <table> [(<columns>)]
     * FORMAT <format>.
     *
     * Each column is quoted as one identifier, with backticks and backslashes escaped (see
     * Grammar::escapeInsertColumns()), so a Nested name such as n.a stays one name. A table name without a
     * backtick or a dot is quoted as one identifier too, as smi2's Client::insert() does; a name with a database
     * or with backticks is written as given. Without columns the list is left out.
     *
     * @param string $table
     * @param array<int, int|string> $columns
     * @param string $format An input format name, such as JSONEachRow
     * @return string
     * @throws InvalidArgumentException When the format is not a plain name
     */
    public static function compileInsertWithFormat(string $table, array $columns, string $format): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $format) !== 1) {
            throw new InvalidArgumentException("Invalid ClickHouse input format name [{$format}].");
        }

        $grammar = new Grammar();
        if (!str_contains($table, '`') && !str_contains($table, '.')) {
            $table = '`' . $grammar->escapeInsertColumns([$table])[0] . '`';
        }

        $sql = 'INSERT INTO ' . $table;
        if ($columns !== []) {
            $quoted = array_map(
                fn (string $column): string => '`' . $column . '`',
                $grammar->escapeInsertColumns(array_values($columns))
            );
            $sql .= ' (' . implode(', ', $quoted) . ')';
        }

        return $sql . ' FORMAT ' . $format;
    }

    /**
     * Build a SELECT request that is sent through the transport with the SQL as given (see select()).
     *
     * @param Client $client
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @param string|null $format
     * @return CurlerRequest
     */
    private static function newReroutedSelectRequest(
        Client $client,
        string $sql,
        array $bindings,
        ?string $format
    ): CurlerRequest {
        $request = $client->transport()->getRequestRead(
            new Query($sql, [self::bindings($bindings)]),
            null,
            null,
            ['default_format' => self::SELECT_FORMAT]
        );
        $request->setRequestExtendedInfo(
            array_merge($request->getRequestExtendedInfo(), ['format' => $format ?? self::SELECT_FORMAT])
        );

        return $request;
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @return Bindings
     */
    private static function bindings(array $bindings): Bindings
    {
        $degeneration = new Bindings();
        $degeneration->bindParams($bindings);

        return $degeneration;
    }

    /**
     * Get the format that ClickHouse names in the X-ClickHouse-Format header of a response, or null.
     *
     * @param CurlerResponse $response
     * @return string|null
     */
    private static function responseFormat(CurlerResponse $response): ?string
    {
        $format = trim((string) self::responseHeader($response, self::FORMAT_HEADER));

        return $format !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*\z/', $format) === 1 ? $format : null;
    }

    /**
     * Read a response header by name, without regard to letter case, or null when the response has no such header.
     *
     * @param CurlerResponse $response
     * @param string $name
     * @return string|null
     */
    private static function responseHeader(CurlerResponse $response, string $name): ?string
    {
        foreach ($response->_headers as $header => $value) {
            if (is_string($header) && strcasecmp($header, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Put an exception that ClickHouse wrote into a whole-document JSON result in place of the response body, so
     * that the statement reports the error with its ClickHouse code, name and version.
     *
     * ClickHouse writes an exception into the result (http_write_exception_in_output_format is on by default
     * since 23.9) when a query fails after the first result block, or after the HTTP 200 headers have been sent,
     * which a large result causes. The body is then a valid JSON document whose last key is "exception", and
     * smi2 reads the rows before the error as the result: on an HTTP 200 it reports no error at all, and on an
     * HTTP error it loses the exception name and version in the surrounding JSON. The body is replaced only when
     * ClickHouse named an exception code in a header, or when the result is a whole-document JSON format (its
     * name contains "json" but not "EachRow", so a JSONEachRow row with a column named "exception" is not
     * mistaken for an error) and the body ends in its "exception" key with a ClickHouse exception message.
     *
     * @param CurlerRequest $request
     * @param mixed $format The format the result is read in
     * @return void
     */
    private static function readExceptionWrittenIntoTheResult(CurlerRequest $request, mixed $format): void
    {
        if (!$request->isResponseExists()) {
            return;
        }

        $response = $request->response();
        $hasExceptionCodeHeader = self::responseHeader($response, 'X-ClickHouse-Exception-Code') !== null;
        $wholeJsonDocument = is_string($format)
            && stripos($format, 'json') !== false
            && stripos($format, 'EachRow') === false;
        if (!$hasExceptionCodeHeader && !$wholeJsonDocument) {
            return;
        }

        $tail = substr($response->_body, -65536);
        if (!str_contains($tail, '"exception"')
            || preg_match('/"exception"\s*:\s*("(?:[^"\\\\]++|\\\\.)*+")\s*\}\s*\z/', $tail, $match) !== 1) {
            return;
        }

        $exception = json_decode($match[1]);
        if (is_string($exception) && preg_match('/^Code:\s*\d+\.\s*DB::Exception/', $exception) === 1) {
            $response->_body = $exception;
        }
    }

    /**
     * Determine if a SQL ends inside a comment, so that a FORMAT clause appended to the end would be part of it.
     *
     * The SQL is scanned once from the left, skipping string literals and quoted identifiers (with backslash
     * escapes and a doubled quote), heredocs ($$...$$ or $tag$...$tag$) and closed comments. It ends inside a
     * comment when a line comment (--, //, or # that a space or ! follows) has no newline before the end, or a
     * block comment (which may nest) is never closed. These are the comments that ClickHouse reads (24.8
     * checked), and the ones that the "?" scanner of SubstitutesBindings skips.
     *
     * @param string $sql
     * @return bool
     */
    private static function endsInsideAComment(string $sql): bool
    {
        $length = strlen($sql);
        $position = 0;

        while (($position += strcspn($sql, "'\"`-#/\$", $position)) < $length) {
            $character = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            if ($character === "'" || $character === '"' || $character === '`') {
                $position = self::positionAfterQuotedText($sql, $position);
            } elseif (($character === '-' && $next === '-')
                || ($character === '/' && $next === '/')
                || ($character === '#' && ($next === '!' || ($next !== '' && ctype_space($next))))) {
                $end = strpos($sql, "\n", $position);
                if ($end === false) {
                    return true;
                }
                $position = $end + 1;
            } elseif ($character === '/' && $next === '*') {
                $depth = 0;
                $closed = false;
                while (($position += strcspn($sql, '/*', $position)) < $length) {
                    $pair = substr($sql, $position, 2);
                    if ($pair === '/*') {
                        $depth++;
                        $position += 2;
                    } elseif ($pair === '*/') {
                        $depth--;
                        $position += 2;
                        if ($depth === 0) {
                            $closed = true;
                            break;
                        }
                    } else {
                        $position++;
                    }
                }
                if (!$closed) {
                    return true;
                }
            } elseif ($character === '$') {
                $position = self::positionAfterHeredoc($sql, $position);
            } else {
                $position++;
            }
        }

        return false;
    }

    /**
     * Get the position after a string literal or quoted identifier that starts at a position, or the end of the
     * SQL when it is not closed. A backslash escapes the next character, and a doubled quote stays inside.
     *
     * @param string $sql
     * @param int $position The position of the opening quote
     * @return int
     */
    private static function positionAfterQuotedText(string $sql, int $position): int
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
     * Get the position after a heredoc that starts with the $ at a position ($$...$$ or $tag$...$tag$, whose tag
     * is the text up to the next $), or the position after the $ when it is no heredoc or is not closed. A $ that
     * follows a word character (an ASCII letter or digit, or an underscore) or another $ is part of a name, not a
     * heredoc. A byte of a multibyte UTF-8 character is no word character: outside quotes, ClickHouse reads it as
     * part of a Unicode whitespace character, such as a no-break space before a $$ heredoc, or as a syntax error.
     *
     * @param string $sql
     * @param int $position The position of the $
     * @return int
     */
    private static function positionAfterHeredoc(string $sql, int $position): int
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
     * Check that every row is an array with the first row's keys, in any order, and return those keys.
     *
     * @param array<int|string, mixed> $rows
     * @return array<int, int|string>
     * @throws QueryException When a row's keys differ from the first row's
     * @throws InvalidArgumentException When a row is not an array
     * @throws ClientQueryException When there are no rows
     */
    private static function verifyKeys(array $rows): array
    {
        $first = null;
        foreach ($rows as $index => $row) {
            self::verifyRow($row, $index, self::JSON_EACH_ROW);
            if ($first === null) {
                $first = $row;
                continue;
            }

            if (count($row) !== count($first) || array_diff_key($row, $first) !== []) {
                throw QueryException::insertKeysDiffer($index, array_keys($row), array_keys($first));
            }
        }

        if ($first === null) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        return array_keys($first);
    }

    /**
     * Check that every row is an array with as many values as the first row, and as the columns when given.
     *
     * @param array<int|string, mixed> $rows
     * @param array<int, int|string> $columns
     * @return void
     * @throws InvalidArgumentException When a row is not an array or has another number of values
     * @throws ClientQueryException When there are no rows
     */
    private static function verifyValueCounts(array $rows, array $columns): void
    {
        if ($rows === []) {
            throw ClientQueryException::cannotInsertEmptyValues();
        }

        $expected = $columns === [] ? null : count($columns);
        $expectedFrom = 'the columns';
        foreach ($rows as $index => $row) {
            self::verifyRow($row, $index, self::JSON_COMPACT_EACH_ROW);
            if ($expected === null) {
                $expected = count($row);
                $expectedFrom = 'the first row';
                continue;
            }

            if (count($row) !== $expected) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot insert the rows as %s: the row at index %s has %d values, and %s %d.',
                    self::JSON_COMPACT_EACH_ROW,
                    $index,
                    count($row),
                    $expectedFrom,
                    $expected
                ));
            }
        }
    }

    /**
     * @param mixed $row
     * @param int|string $index
     * @param string $format
     * @return void
     * @throws InvalidArgumentException When the row is not an array
     */
    private static function verifyRow(mixed $row, int|string $index, string $format): void
    {
        if (!is_array($row)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot insert the rows as %s: the row at index %s must be an array, %s given.',
                $format,
                $index,
                get_debug_type($row)
            ));
        }
    }

    /**
     * Send an insert with its head in the URL and its rows in the body, and throw when it fails.
     *
     * When smi2 attaches the request to an exception, the rows are left out of it. The $body is marked
     * #[\SensitiveParameter] so that it does not appear in a stack trace either (the string form of any exception
     * thrown below this frame, which Laravel's log writes, would otherwise show the first bytes of the rows).
     *
     * @param Client $client
     * @param string $head
     * @param string $body
     * @param string $format
     * @return Statement
     * @throws ClientQueryException When the insert fails
     */
    private static function sendInsert(
        Client $client,
        string $head,
        #[\SensitiveParameter] string $body,
        string $format
    ): Statement {
        $transport = $client->transport();
        $request = $transport->writeStreamData($head);
        $request->parameters_json($body);
        $transport->getCurler()->execOne($request);

        $statement = new Statement($request);
        if ($statement->isError()) {
            try {
                $statement->error();
            } catch (ClientQueryException $exception) {
                $details = $exception->getRequestDetails();
                if (array_key_exists('parameters', $details)) {
                    $details['parameters'] = sprintf('(%d bytes of %s rows, left out)', strlen($body), $format);
                    $exception->setRequestDetails($details);
                }

                throw $exception;
            }
        }

        return $statement;
    }
}
