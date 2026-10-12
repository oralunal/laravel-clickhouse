<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\ClickHouseException;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Query\Degeneration\Bindings;
use ClickHouseDB\Query\Query;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use Generator;
use InvalidArgumentException;
use JsonException;
use Oralunal\LaravelClickHouse\Enums\Format;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use stdClass;

/**
 * Requests that the package sends through a smi2 client where smi2's own Client methods would send or read them
 * wrongly.
 *
 * - select() reads a SELECT in the format that ClickHouse sends it in. smi2's Client::select() searches the whole
 *   SQL, string literals, comments and names included, for FORMAT followed by a format name that it knows, so
 *   a value such as 'export as format csv' made it read a JSON result as CSV.
 * - selectIntoStream() writes the result of a SELECT into a stream and throws the error that ClickHouse wrote into
 *   it, which smi2's Client::streamRead() reports without its message.
 * - write() logs a write and leaves it out while the connection pretends.
 * - encodeJsonEachRow() and sendInsert() send rows in the JSONEachRow input format, with the INSERT head in the URL
 *   and the rows in the body.
 *
 * @internal The package's own helper: its public static methods are not public API and may change in any release.
 */
final class ClientRequests
{
    /**
     * The format of keyed rows: one JSON object per row.
     */
    private const JSON_EACH_ROW = Format::JsonEachRow->value;

    /**
     * The format in which smi2's Client::select() asks for the result of a query that names no format.
     */
    private const SELECT_FORMAT = 'JSON';

    /**
     * The response header in which ClickHouse names the format of the result.
     */
    private const FORMAT_HEADER = 'X-ClickHouse-Format';

    /**
     * How many bytes at the end of a streamed result selectIntoStream() searches for an error that ClickHouse wrote
     * after the rows (see findExceptionAtTheEnd()). A ClickHouse error message is far shorter.
     */
    private const STREAM_TAIL_BYTES = 65536;

    /**
     * The start of a ClickHouse error message, as smi2's Statement::error() reads it.
     */
    private const EXCEPTION_MESSAGE_PATTERN = '/^Code:\s*\d+\.\s*DB::Exception/';

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
     * @param array<string, int|string> $settings Settings of this request only, such as ResultValues::SETTINGS
     * @return Statement
     */
    public static function select(
        Client $client,
        string $sql,
        array $bindings = [],
        ?string $format = null,
        array $settings = []
    ): Statement {
        if (self::clientSelectsAsIntended($sql, $format)) {
            $statement = $client->select($sql, $bindings, null, null, $settings);
            self::readExceptionWrittenIntoTheResult($statement->getRequest(), $statement->getFormat());

            return $statement;
        }

        $request = self::newReroutedSelectRequest($client, $sql, $bindings, $format, $settings);
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
     * @param array<string, int|string> $settings Settings of this request only, such as ResultValues::SETTINGS
     * @return CurlerRequest
     */
    public static function selectRequest(
        Client $client,
        string $sql,
        array $bindings = [],
        ?string $format = null,
        array $settings = []
    ): CurlerRequest {
        if (!self::clientSelectsAsIntended($sql, $format)) {
            return self::newReroutedSelectRequest($client, $sql, $bindings, $format, $settings);
        }

        $query = new Query($sql, [self::bindings($bindings)]);
        $query->setFormat(self::SELECT_FORMAT);

        return $client->transport()->getRequestRead($query, null, null, $settings);
    }

    /**
     * Read the rows of a SELECT statement as stdClass objects whose values have the PHP types of their ClickHouse
     * types, the same on every ClickHouse version (see ResultValues).
     *
     * A JSON result, as select() asks for it, is decoded here with ResultValues::JSON_DECODE_FLAGS, so that an
     * integer that does not fit a PHP int keeps its digits as a string, where smi2 would decode it as a float, and
     * the meta of the result gives the type of each column. A result in another JSON format that a FORMAT clause of
     * the SQL names, such as JSONStrings or JSONCompact, keeps the values that smi2 reads, as the SQL asked for them.
     * The statement of a query that was not sent, while the connection pretends, has no rows.
     *
     * @param Statement $statement
     * @return list<stdClass>
     * @throws DatabaseException When ClickHouse reported an error
     * @throws ClientQueryException When the request failed without a ClickHouse error, or its result is not JSON
     */
    public static function rowsOf(Statement $statement): array
    {
        $request = $statement->getRequest();
        $format = $statement->getFormat();
        if (!is_string($format) || strtoupper($format) !== self::SELECT_FORMAT || !$request->isResponseExists()) {
            return array_map(static fn (array $row): stdClass => (object) $row, array_values($statement->rows()));
        }

        if ($statement->isError()) {
            $statement->error();
        }

        $body = $request->response()->body();
        if (trim((string) $body) === '') {
            return [];
        }

        try {
            $document = json_decode((string) $body, true, 512, ResultValues::JSON_DECODE_FLAGS);
        } catch (JsonException $exception) {
            throw new ClientQueryException(
                'Cannot read the JSON result of the query: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($document) || !is_array($document['meta'] ?? null)) {
            throw new ClientQueryException('Can`t find meta');
        }

        return ResultValues::rows($document['meta'], is_array($document['data'] ?? null) ? $document['data'] : []);
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
     * Run a SELECT with its result in JSONEachRow, one JSON object per line, written into a stream as it arrives,
     * and return its statement. The rows are in the stream, which is left at its end; the statement's response has
     * the response headers and no body.
     *
     * FORMAT JSONEachRow is appended to the SQL on a line of its own, after a semicolon at its end is removed, so
     * that a line comment at the end of the SQL stays a comment; the SQL must not name a format itself. The request
     * is built as select() builds a rerouted query, with readonly=2 and smi2's Bindings degeneration only, and runs
     * on the client's curler, so the connection's retries apply. The stream is emptied each time a response
     * starts, so that a request that is sent again leaves no rows of an earlier attempt in it. A curler that keeps
     * the body in the response instead of handing it to the request's write function, such as a test double, has
     * its body written into the stream, so that its rows are not lost.
     *
     * The request also sends output_format_json_validate_utf8=1, so that ClickHouse replaces the bytes of a string
     * that are not valid UTF-8, such as those of a binary MD5 hash, with U+FFFD, as it always does in FORMAT JSON,
     * which select() reads: the rows are then the rows of select(). Without it, JSONEachRow writes such bytes as they
     * are, and the line is not JSON that PHP can decode (24.8, 26.3 and 26.8 checked). It sends the settings of
     * ResultValues too, so that every value is read exactly. A read-only user, the readonly option of the
     * connection, cannot change a setting, so these settings are not sent for such a user.
     *
     * smi2's Client::streamRead() reports a failed query as 'HttpCode:500 ; ;', since ClickHouse writes the error
     * into the stream and not into the response body. Here the error is read from the end of the stream (see
     * findExceptionAtTheEnd()) and thrown as smi2 throws the error of a select: a DatabaseException with the
     * ClickHouse code, name and version. That includes an error after the first rows of a large result, which
     * ClickHouse (24.8 checked) sends with HTTP 200, as the last line of the result: such a result throws instead of
     * ending in a row that holds the error. ClickHouse 25 and later (26.3 and 26.8 checked) send such an error in an
     * exception frame and then end the response without finishing its chunked body, so curl reports 'transfer
     * closed with outstanding read data remaining' (CURLE_PARTIAL_FILE). An error found at the end of the stream
     * replaces the curl error, since smi2 throws a curl error instead of the error in the body.
     *
     * @param Client $client
     * @param string $sql A query without a FORMAT clause
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @param resource $stream A stream that can be written, read and truncated, such as php://temp
     * @return Statement
     * @throws DatabaseException When ClickHouse reports an error, before or after the rows that it sent
     * @throws ClientQueryException When the request fails without a ClickHouse error, such as a timeout
     */
    public static function selectIntoStream(Client $client, string $sql, array $bindings, mixed $stream): Statement
    {
        $sql = rtrim($sql);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        $request = $client->transport()->getRequestRead(
            new Query($sql . "\nFORMAT " . self::JSON_EACH_ROW, [self::bindings($bindings)]),
            null,
            null,
            $client->settings()->isReadOnlyUser()
                ? []
                : ['output_format_json_validate_utf8' => 1] + ResultValues::SETTINGS
        );
        $request->setRequestExtendedInfo(
            array_merge($request->getRequestExtendedInfo(), ['format' => self::JSON_EACH_ROW])
        );

        $headers = [];
        $request->setHeaderFunction(function (mixed $handle, string $line) use ($stream, &$headers): int {
            if (str_starts_with($line, 'HTTP/')) {
                ftruncate($stream, 0);
                rewind($stream);
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }

            return strlen($line);
        });
        $request->setWriteFunction(fn (mixed $handle, string $data): int => (int) fwrite($stream, $data));

        $client->transport()->getCurler()->execOne($request, true);

        $response = $request->response();
        $response->_headers = array_merge($response->_headers, $headers);
        if (ftell($stream) === 0 && $response->body() !== '') {
            fwrite($stream, $response->body());
        }
        $statement = new Statement($request);

        $httpFailed = $response->http_code() !== 200;
        [$tail, $wholeStream] = self::readTailOfStream($stream);
        $exception = self::findExceptionAtTheEnd($tail, $wholeStream, $httpFailed);
        if (!$httpFailed && $response->error_no() === 0 && $exception === null) {
            return $statement;
        }

        $response->_body = $exception ?? ($wholeStream ? $tail : '');
        if ($exception !== null) {
            $response->_errorNo = 0;
            $response->_error = '';
        }
        $statement->error();

        throw new ClientQueryException(
            'ClickHouse reported an error after the rows of the result: ' . $response->_body
        );
    }

    /**
     * Get the name and the type of each column of the result of a SELECT, as DESCRIBE TABLE (<sql>) gives them,
     * without running the query: the meta that a JSON result holds, for a result in a format without types, such as
     * the JSONEachRow of selectIntoStream(). The SQL goes on lines of its own inside the parentheses, so that a line
     * comment at its end stays a comment, and its bindings are filled as select() fills them.
     *
     * Null when ClickHouse cannot describe the query, as for SQL that is no SELECT, such as SHOW TABLES, or when the
     * request fails. The request is not logged.
     *
     * @param Client $client
     * @param string $sql A query without a FORMAT clause
     * @param array<int|string, mixed> $bindings Bindings for smi2's Bindings degeneration (':name' and '{name}')
     * @return list<array{name: string, type: string}>|null
     */
    public static function columnTypes(Client $client, string $sql, array $bindings = []): ?array
    {
        $sql = rtrim($sql);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        try {
            $rows = self::select($client, "DESCRIBE TABLE (\n{$sql}\n)", $bindings)->rows();
        } catch (ClickHouseException) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !is_string($row['type'] ?? null)) {
                return null;
            }

            $columns[] = ['name' => $row['name'], 'type' => $row['type']];
        }

        return $columns === [] ? null : $columns;
    }

    /**
     * Read the end of a stream, at most STREAM_TAIL_BYTES bytes, and leave the stream at its end.
     *
     * @param resource $stream
     * @return array{0: string, 1: bool} The end of the stream, and whether it is the whole stream
     */
    private static function readTailOfStream(mixed $stream): array
    {
        fseek($stream, 0, SEEK_END);
        $offset = max(0, (int) ftell($stream) - self::STREAM_TAIL_BYTES);
        fseek($stream, $offset);
        $tail = (string) stream_get_contents($stream);

        return [$tail, $offset === 0];
    }

    /**
     * Find the error that ClickHouse wrote at the end of a result in JSONEachRow, and return its message, such as
     * "Code: 395. DB::Exception: ...", or null when the result ends with a row.
     *
     * ClickHouse writes an error after the rows, or as the whole body of a query that failed before its first
     * row, in one of these ways, depending on its version and settings:
     * - as the last line, a JSON object with the one key exception, {"exception": "Code: ..."}, with a space after
     *   the colon (24.8 checked, with http_write_exception_in_output_format on, its default). ClickHouse writes a
     *   row without that space, so a row of a column named exception that holds such a message, as a select from
     *   system.query_log returns it, {"exception":"Code: ..."}, stays a row: it is the error only when the
     *   response has an HTTP code other than 200, or when the row before it has other keys. A curl error alone,
     *   such as a timeout, does not make it the error: the stream then ends where the client stopped reading;
     * - as text on lines of its own, starting with "Code: ", after the last row (24.8 checked, with
     *   http_write_exception_in_output_format off), or as the whole body. No row starts with a letter: each one is
     *   a JSON object;
     * - framed by lines of __exception__ and a random tag, as ClickHouse 25 and later write an error after the HTTP
     *   headers have been sent: __exception__, the tag, the message, the length of the message and the tag, and
     *   __exception__ (26.3 checked: "\r\n__exception__\r\n<tag>\r\n<message>\n<length> <tag>\r\n__exception__\r\n"
     *   after the last row, with the tag also in the X-ClickHouse-Exception-Tag header).
     *
     * @param string $tail The end of the stream (see readTailOfStream())
     * @param bool $wholeStream Whether $tail is the whole stream, so that its first line is a whole line
     * @param bool $failed Whether the response has an HTTP code other than 200, so that a last line
     *                     {"exception":"Code: ..."} is the error
     * @return string|null
     */
    private static function findExceptionAtTheEnd(string $tail, bool $wholeStream, bool $failed): ?string
    {
        $text = rtrim($tail);
        if (str_ends_with($text, '__exception__')) {
            $frame = '/(?:\A|\n)__exception__\r?\n([^\r\n]+)\r?\n(.*)\r?\n\d+ \1\r?\n__exception__\z/s';
            if (preg_match($frame, $text, $match) === 1) {
                return trim($match[2]);
            }
        }

        $lines = preg_split('/\r?\n/', $text) ?: [];
        $last = (string) array_pop($lines);
        if ($lines === [] && !$wholeStream) {
            return null;
        }

        if (str_starts_with($last, '{')) {
            $row = json_decode($last, true);
            if (!is_array($row) || array_keys($row) !== ['exception'] || !is_string($row['exception'])
                || preg_match(self::EXCEPTION_MESSAGE_PATTERN, $row['exception']) !== 1) {
                return null;
            }

            if ($failed || str_starts_with($last, '{"exception": "')) {
                return $row['exception'];
            }

            $previousIsWhole = count($lines) > 1 || ($lines !== [] && $wholeStream);
            $previous = $previousIsWhole ? json_decode((string) end($lines), true) : null;

            return is_array($previous) && array_keys($previous) !== ['exception'] ? $row['exception'] : null;
        }

        $lines[] = $last;
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            if (str_starts_with($lines[$index], '{')) {
                return null;
            }

            if (preg_match(self::EXCEPTION_MESSAGE_PATTERN, $lines[$index]) === 1 && ($index > 0 || $wholeStream)) {
                $message = implode("\n", array_slice($lines, $index));

                return trim((string) preg_replace('/\n\d+ \S+\n__exception__\z/', '', $message));
            }
        }

        return null;
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
     * Check keyed rows as insertJsonEachRow() checks them, and encode them as JSONEachRow, one JSON object per line,
     * without sending anything: every row must be an array with the first row's keys, in any order, and every value
     * must be one that JSON can hold (see JsonEachRowEncoder).
     *
     * Laravel's query builder inserts with it (see QueryBuilder::insert()), with an INSERT head that its own grammar
     * writes.
     *
     * @param array<int|string, array<int|string, mixed>> $rows
     * @param JsonEachRowEncoder $encoder
     * @return array{0: array<int, int|string>, 1: string} The keys of the first row, and the encoded rows
     * @throws QueryException When a row's keys differ from the first row's
     * @throws InvalidArgumentException When a row is not an array, or a value cannot be sent as JSON
     * @throws ClientQueryException When there are no rows
     */
    public static function encodeJsonEachRow(array $rows, JsonEachRowEncoder $encoder): array
    {
        $columns = self::verifyKeys($rows);

        return [$columns, $encoder->encodeRows($rows)];
    }

    /**
     * Build a SELECT request that is sent through the transport with the SQL as given (see select()).
     *
     * @param Client $client
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @param string|null $format
     * @param array<string, int|string> $settings
     * @return CurlerRequest
     */
    private static function newReroutedSelectRequest(
        Client $client,
        string $sql,
        array $bindings,
        ?string $format,
        array $settings = []
    ): CurlerRequest {
        $request = $client->transport()->getRequestRead(
            new Query($sql, [self::bindings($bindings)]),
            null,
            null,
            ['default_format' => self::SELECT_FORMAT] + $settings
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
     * Send an insert with its head in the URL and its rows in the body, and throw when it fails. Nothing is logged,
     * and the connection's pretend mode is not checked: the caller does both.
     *
     * When smi2 attaches the request to an exception, the rows are left out of it. The $body is marked
     * #[\SensitiveParameter] so that it does not appear in a stack trace either (the string form of any exception
     * thrown below this frame, which Laravel's log writes, would otherwise show the first bytes of the rows).
     *
     * @param Client $client
     * @param string $head The INSERT, which names the input format of the body, such as INSERT INTO t (a) FORMAT
     *                     JSONEachRow
     * @param string $body The rows in that format
     * @param string $format The name of the format, for the message that replaces the rows in an exception
     * @return Statement
     * @throws ClientQueryException When the insert fails
     */
    public static function sendInsert(
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
