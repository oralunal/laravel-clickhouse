<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Generator;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * Connection::cursor() without a server: the curler of the connection's client plays the HTTP responses of
 * ClickHouse into the request's header and write functions, as curl does, so the result goes through the temporary
 * stream of ClientRequests::selectIntoStream() as it does against a server. Each case of an error is a response that
 * ClickHouse 24.8 sends (checked), or the exception frame of ClickHouse 25 and later.
 */
class ConnectionCursorStreamTest extends TestCase
{
    private const THROW_IF = "Code: 395. DB::Exception: Value passed to 'throwIf' function is non-zero: while executing"
        . " 'FUNCTION throwIf(equals(__table1.number, 3_UInt8) :: 0) -> throwIf(equals(__table1.number, 3_UInt8)) UInt8"
        . " : 2'. (FUNCTION_THROW_IF_VALUE_IS_NON_ZERO) (version 24.8.14.39 (official build))";

    /**
     * A connection whose client sends its requests through a curler that plays the given responses.
     *
     * @param CurlerRolling $curler
     * @param array<string, mixed> $clientConfig Options for the client, such as readonly
     * @return Connection
     */
    private function connection(CurlerRolling $curler, array $clientConfig = []): Connection
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => ''] + $clientConfig);
        $client->database('db');
        $client->transport()->setDirtyCurler($curler);

        $connection = new class (null, 'db', '', ['name' => 'clickhouse']) extends Connection {
            public Client $streamClient;

            public function getClient(): Client
            {
                return $this->streamClient;
            }
        };
        $connection->streamClient = $client;

        return $connection;
    }

    /**
     * The SQL of a request, which smi2 sends as the body of a read.
     *
     * @param CurlerRequest $request
     * @return string
     */
    private static function sentSql(CurlerRequest $request): string
    {
        return (string) $request->getRequestExtendedInfo('sql');
    }

    /**
     * @param CurlerRequest $request
     * @return array<string, string>
     */
    private static function urlParameters(CurlerRequest $request): array
    {
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $parameters);

        return $parameters;
    }

    /**
     * Read every row of a cursor and count those that were yielded before it threw.
     *
     * @param Generator<int, array<string, mixed>> $cursor
     * @param int $yielded
     * @return list<array<string, mixed>>
     */
    private static function read(Generator $cursor, int &$yielded = 0): array
    {
        $rows = [];
        foreach ($cursor as $row) {
            $yielded++;
            $rows[] = $row;
        }

        return $rows;
    }

    public function test_it_yields_each_line_of_the_result_as_an_array_once_it_is_iterated(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"id":1,"s":"a"}' . "\n" . '{"id"', ':"2","s":"it\'s"}' . "\n", "\n"])]);
        $connection = $this->connection($curler);
        $connection->enableQueryLog();

        $cursor = $connection->cursor('SELECT id, s FROM t WHERE id > ? AND s != ?', [0, "it's"]);
        $this->assertSame([], $curler->requests, 'nothing is sent before the first row is asked for');

        $this->assertSame([['id' => 1, 's' => 'a'], ['id' => '2', 's' => "it's"]], self::read($cursor));
        $this->assertCount(1, $curler->requests);
        $this->assertSame("SELECT id, s FROM t WHERE id > 0 AND s != 'it\\'s'\nFORMAT JSONEachRow", self::sentSql($curler->requests[0]));
        $this->assertSame('2', self::urlParameters($curler->requests[0])['readonly']);
        $this->assertSame(
            [['query' => 'SELECT id, s FROM t WHERE id > ? AND s != ?', 'bindings' => [0, "it's"]]],
            array_map(fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']], $connection->getQueryLog())
        );
    }

    public function test_an_empty_result_yields_nothing(): void
    {
        $connection = $this->connection(new CursorStreamCurler([CursorStreamCurler::ok([])]));

        $this->assertSame([], self::read($connection->cursor('SELECT 1 WHERE 0')));
    }

    /**
     * The request asks ClickHouse to replace the bytes of a string that are not valid UTF-8 with U+FFFD, as FORMAT
     * JSON, which select() reads, always does: JSONEachRow writes them as they are otherwise, and the line is not
     * JSON that PHP can decode.
     */
    public function test_the_request_asks_clickhouse_to_replace_bytes_that_are_not_valid_utf8(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"bad":"' . "\u{FFFD}" . '("}' . "\n"])]);

        $rows = self::read($this->connection($curler)->cursor("SELECT unhex('C328') AS bad"));

        $this->assertSame([['bad' => "\u{FFFD}("]], $rows);
        $this->assertSame('1', self::urlParameters($curler->requests[0])['output_format_json_validate_utf8'] ?? null);
    }

    /**
     * A read-only user cannot change a setting, so the request leaves output_format_json_validate_utf8 out, and PHP
     * replaces each byte that is not valid UTF-8 with U+FFFD as the line is decoded, instead of throwing a
     * JsonException in the middle of the rows.
     */
    public function test_a_read_only_user_gets_bytes_that_are_not_valid_utf8_replaced_as_they_are_decoded(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"id":1,"h":"a' . "\xC3\x28" . '"}' . "\n" . '{"id":2,"h":"' . "\xFF\xFE" . 'b"}' . "\n"])]);

        $rows = self::read($this->connection($curler, ['readonly' => true])->cursor('SELECT id, h FROM t'));

        $this->assertSame([['id' => 1, 'h' => "a\u{FFFD}("], ['id' => 2, 'h' => "\u{FFFD}\u{FFFD}b"]], $rows);
        $this->assertArrayNotHasKey('output_format_json_validate_utf8', self::urlParameters($curler->requests[0]));
    }

    /**
     * smi2's placeholders, :name and {name}, and query parameters are filled as select() fills them.
     */
    public function test_smi2_placeholders_are_filled(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"a":"x","b":5}' . "\n"])]);

        $rows = self::read($this->connection($curler)->cursor('SELECT :v AS a, {p:UInt8} + 1 AS b', ['v' => 'x', 'p' => 4]));

        $this->assertSame([['a' => 'x', 'b' => 5]], $rows);
        $this->assertSame("SELECT 'x' AS a, {p:UInt8} + 1 AS b\nFORMAT JSONEachRow", self::sentSql($curler->requests[0]));
        $this->assertSame('4', self::urlParameters($curler->requests[0])['param_p']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sqlEndingProvider(): array
    {
        return [
            'a semicolon' => ['SELECT 1;', "SELECT 1\nFORMAT JSONEachRow"],
            'a semicolon, spaces and a line break' => ["SELECT 1 ; \n", "SELECT 1\nFORMAT JSONEachRow"],
            'a line comment' => ['SELECT 1 -- the last word', "SELECT 1 -- the last word\nFORMAT JSONEachRow"],
            'a SETTINGS clause' => ['SELECT 1 SETTINGS max_threads = 1', "SELECT 1 SETTINGS max_threads = 1\nFORMAT JSONEachRow"],
        ];
    }

    /**
     * FORMAT JSONEachRow goes on a line of its own, after a semicolon at the end is removed, so that a line comment
     * at the end stays a comment.
     */
    #[DataProvider('sqlEndingProvider')]
    public function test_the_format_follows_the_sql_on_a_line_of_its_own(string $query, string $sent): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"1":1}' . "\n"])]);

        self::read($this->connection($curler)->cursor($query));

        $this->assertSame($sent, self::sentSql($curler->requests[0]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function formatClauseProvider(): array
    {
        return [
            'CSV' => ['SELECT 1 FORMAT CSV', 'CSV'],
            'JSON, in lower case' => ['select 1 format JSON', 'JSON'],
            'JSONEachRow' => ['SELECT 1 FORMAT JSONEachRow', 'JSONEachRow'],
            'after SETTINGS' => ['SELECT 1 SETTINGS max_threads = 1 FORMAT TSV;', 'TSV'],
        ];
    }

    #[DataProvider('formatClauseProvider')]
    public function test_a_query_with_a_format_clause_is_refused_before_anything_is_sent(string $query, string $format): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok([])]);
        $connection = $this->connection($curler);
        $connection->enableQueryLog();

        try {
            self::read($connection->cursor($query));
            $this->fail('The FORMAT clause was not refused.');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot read the rows of a query whose FORMAT clause names {$format} with cursor(): cursor() asks for"
                . ' the result in JSONEachRow itself and yields its rows one by one. Leave the FORMAT clause out.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $curler->requests);
        $this->assertSame([], $connection->getQueryLog());
    }

    /**
     * A column or a value named format is no FORMAT clause.
     */
    public function test_a_column_named_format_is_no_format_clause(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"format":"csv"}' . "\n"])]);

        $rows = self::read($this->connection($curler)->cursor("SELECT format FROM t WHERE format = 'FORMAT CSV'"));

        $this->assertSame([['format' => 'csv']], $rows);
    }

    public function test_nothing_is_sent_or_yielded_while_the_connection_pretends(): void
    {
        $curler = new CursorStreamCurler([CursorStreamCurler::ok(['{"id":1}' . "\n"])]);
        $connection = $this->connection($curler);
        $rows = null;

        $log = $connection->pretend(function (Connection $connection) use (&$rows): void {
            $rows = self::read($connection->cursor('SELECT id FROM t WHERE id = ?', [1]));
        });

        $this->assertSame([], $rows);
        $this->assertSame([], $curler->requests);
        $this->assertCount(1, $log);
    }

    /**
     * Each way in which ClickHouse reports an error, and the error that cursor() throws for it.
     *
     * @return array<string, array{array<string, mixed>, int, string, string}>
     */
    public static function errorProvider(): array
    {
        $rows = '{"number":"0","t":0}' . "\n" . '{"number":"1","t":0}' . "\n";
        $throwIf = ['FUNCTION_THROW_IF_VALUE_IS_NON_ZERO', 395, "Value passed to 'throwIf' function is non-zero"];
        $frame = "__exception__\r\nzxcvbnmasdfghjkl\r\n" . str_replace('24.8.14.39', '26.3.1.1', self::THROW_IF)
            . "\r\n" . strlen(self::THROW_IF) . " zxcvbnmasdfghjkl\r\n__exception__\r\n";
        $message = str_replace('24.8.14.39', '26.3.46.2', self::THROW_IF);
        $frameOf263 = "\r\n__exception__\r\nfplqxcnlazszjvds\r\n" . $message . "\n" . (strlen($message) + 1)
            . " fplqxcnlazszjvds\r\n__exception__\r\n";
        $transferClosed = ['errorNo' => 18, 'error' => 'transfer closed with outstanding read data remaining'];

        return [
            'HTTP 404 with the error as its body' => [
                CursorStreamCurler::response(404, ["Code: 47. DB::Exception: Unknown expression identifier 'nosuch' in scope SELECT nosuch FROM t. (UNKNOWN_IDENTIFIER) (version 24.8.14.39 (official build))\n"]),
                47,
                'UNKNOWN_IDENTIFIER',
                "Unknown expression identifier 'nosuch'",
            ],
            'HTTP 500 with rows and the error as a JSON line' => [
                CursorStreamCurler::response(500, [$rows, '{"exception": ' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 500 with the error as a JSON line without a space' => [
                CursorStreamCurler::response(500, ['{"exception":' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows and the error as a JSON line' => [
                CursorStreamCurler::response(200, [$rows, '{"exception": ' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with the error as a JSON line alone' => [
                CursorStreamCurler::response(200, ['{"exception": ' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows of other keys and the error as a JSON line without a space' => [
                CursorStreamCurler::response(200, [$rows, '{"exception":' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows and the error as text' => [
                CursorStreamCurler::response(200, [$rows, self::THROW_IF . "\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows and the error in an exception frame' => [
                CursorStreamCurler::response(200, [$rows, $frame], ['X-ClickHouse-Exception-Tag' => 'zxcvbnmasdfghjkl']),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows and an exception frame whose length is not checked' => [
                CursorStreamCurler::response(200, [$rows, str_replace(strlen(self::THROW_IF) . ' ', '7 ', $frame)]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows, the exception frame of 26.3 and the curl error of the unfinished body' => [
                $transferClosed + CursorStreamCurler::response(
                    200,
                    [$rows, $frameOf263],
                    ['X-ClickHouse-Exception-Tag' => 'fplqxcnlazszjvds']
                ),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
            'HTTP 200 with rows, the error as a JSON line and a curl error' => [
                $transferClosed + CursorStreamCurler::response(200, [$rows, '{"exception": ' . json_encode(self::THROW_IF) . "}\n"]),
                $throwIf[1],
                $throwIf[0],
                $throwIf[2],
            ],
        ];
    }

    /**
     * An error is thrown as smi2 throws the error of a select, with its ClickHouse code, name and message, before
     * any row is yielded, and the query is not logged.
     *
     * @param array<string, mixed> $response
     */
    #[DataProvider('errorProvider')]
    public function test_an_error_of_clickhouse_is_thrown_with_its_message(array $response, int $code, string $name, string $message): void
    {
        $connection = $this->connection(new CursorStreamCurler([$response]));
        $connection->enableQueryLog();
        $yielded = 0;

        try {
            self::read($connection->cursor('SELECT number, throwIf(number = 3) AS t FROM numbers(10)'), $yielded);
            $this->fail('The error was not thrown.');
        } catch (DatabaseException $exception) {
            $this->assertSame($code, $exception->getCode());
            $this->assertSame($name, $exception->getClickHouseExceptionName());
            $this->assertStringStartsWith($message, $exception->getMessage());
            $this->assertStringEndsWith(
                "\nIN:SELECT number, throwIf(number = 3) AS t FROM numbers(10)\nFORMAT JSONEachRow",
                $exception->getMessage()
            );
            $this->assertNotNull($exception->getServerVersion());
        }

        $this->assertSame(0, $yielded);
        $this->assertSame([], $connection->getQueryLog());
    }

    /**
     * @return array<string, array{array<string, int|string>}>
     */
    public static function curlErrorOfAnExceptionFrameProvider(): array
    {
        return [
            'without a curl error' => [[]],
            'with the curl error of the unfinished body' => [['errorNo' => 18, 'error' => 'transfer closed with outstanding read data remaining']],
        ];
    }

    /**
     * An error in an exception frame whose message smi2 cannot read as a ClickHouse error is thrown with its text,
     * also when curl reports the body that ClickHouse 25 and later leave unfinished after the frame.
     *
     * @param array<string, int|string> $curlError
     */
    #[DataProvider('curlErrorOfAnExceptionFrameProvider')]
    public function test_an_exception_frame_without_a_clickhouse_error_is_thrown_with_its_text(array $curlError): void
    {
        $frame = "__exception__\r\nzxcvbnmasdfghjkl\r\nThe query was cancelled\r\n23 zxcvbnmasdfghjkl\r\n__exception__\r\n";
        $connection = $this->connection(new CursorStreamCurler([$curlError + CursorStreamCurler::response(200, ['{"id":1}' . "\n", $frame])]));

        try {
            self::read($connection->cursor('SELECT id FROM t'));
            $this->fail('The error was not thrown.');
        } catch (ClientQueryException $exception) {
            $this->assertNotInstanceOf(DatabaseException::class, $exception);
            $this->assertSame('ClickHouse reported an error after the rows of the result: The query was cancelled', $exception->getMessage());
        }
    }

    /**
     * A row of a column named exception that holds a ClickHouse error, as a select from system.query_log returns
     * it, is a row: ClickHouse writes no space after the colon of a row.
     *
     * @return array<string, array{list<string>, list<array<string, string>>}>
     */
    public static function rowLikeAnErrorProvider(): array
    {
        $error = 'Code: 60. DB::Exception: Unknown table expression identifier \'t\'. (UNKNOWN_TABLE) (version 24.8.14.39 (official build))';
        $line = '{"exception":' . json_encode($error) . "}\n";

        return [
            'one row' => [[$line], [['exception' => $error]]],
            'two rows' => [[$line, $line], [['exception' => $error], ['exception' => $error]]],
        ];
    }

    /**
     * @param list<string> $chunks
     * @param list<array<string, string>> $rows
     */
    #[DataProvider('rowLikeAnErrorProvider')]
    public function test_a_row_that_holds_an_error_message_is_a_row(array $chunks, array $rows): void
    {
        $connection = $this->connection(new CursorStreamCurler([CursorStreamCurler::ok($chunks)]));

        $this->assertSame($rows, self::read($connection->cursor('SELECT exception FROM system.query_log')));
    }

    /**
     * A last row that is longer than the end of the result that is searched for an error is a row, also when the
     * part that is searched starts with the text of an error: here the last 64 KB start at the Code: of the value.
     */
    public function test_a_long_last_row_is_a_row(): void
    {
        $error = 'Code: 1. DB::Exception: x';
        $value = str_repeat('x', 100) . $error . str_repeat('y', 65536 - 3 - strlen($error));
        $chunks = ['{"s":"a"}' . "\n", '{"s":"' . $value . '"}' . "\n"];
        $connection = $this->connection(new CursorStreamCurler([CursorStreamCurler::ok($chunks)]));

        $this->assertSame([['s' => 'a'], ['s' => $value]], self::read($connection->cursor('SELECT s FROM t')));
    }

    /**
     * A request that failed without a ClickHouse error throws as smi2 throws it, without the rows that came before.
     * A timeout after a row that holds a ClickHouse error message is a timeout: the stream ends where curl stopped
     * reading, so the row is not the error.
     */
    public function test_a_request_that_failed_without_an_error_of_clickhouse(): void
    {
        $timeout = CursorStreamCurler::response(200, ['{"id":1}' . "\n" . '{"id"']);
        $timeout['errorNo'] = 28;
        $timeout['error'] = 'Operation timed out after 2000 milliseconds with 13 bytes received';

        try {
            self::read($this->connection(new CursorStreamCurler([$timeout]))->cursor('SELECT id FROM t'));
            $this->fail('The timeout was not thrown.');
        } catch (ClientQueryException $exception) {
            $this->assertNotInstanceOf(DatabaseException::class, $exception);
            $this->assertSame(28, $exception->getCode());
            $this->assertSame('Operation timed out after 2000 milliseconds with 13 bytes received', $exception->getMessage());
        }

        $error = 'Code: 60. DB::Exception: Unknown table expression identifier \'t\'. (UNKNOWN_TABLE) (version 24.8.14.39 (official build))';
        $timeoutAfterARow = CursorStreamCurler::response(200, ['{"exception":' . json_encode($error) . "}\n"]);
        $timeoutAfterARow['errorNo'] = 28;
        $timeoutAfterARow['error'] = 'Operation timed out after 2000 milliseconds with 140 bytes received';

        try {
            self::read($this->connection(new CursorStreamCurler([$timeoutAfterARow]))->cursor('SELECT exception FROM system.query_log'));
            $this->fail('The timeout after a row that holds an error message was not thrown.');
        } catch (ClientQueryException $exception) {
            $this->assertNotInstanceOf(DatabaseException::class, $exception);
            $this->assertSame(28, $exception->getCode());
            $this->assertSame('Operation timed out after 2000 milliseconds with 140 bytes received', $exception->getMessage());
        }

        try {
            self::read($this->connection(new CursorStreamCurler([CursorStreamCurler::response(502, ['<html>Bad Gateway</html>'])]))->cursor('SELECT id FROM t'));
            $this->fail('The HTTP error was not thrown.');
        } catch (ClientQueryException $exception) {
            $this->assertNotInstanceOf(DatabaseException::class, $exception);
            $this->assertSame('HttpCode:502 ;  ;<html>Bad Gateway</html>', $exception->getMessage());
        }
    }

    /**
     * When the connection's retries send a request again, the stream is emptied when the new response starts, so
     * the rows of the failed attempt are not yielded.
     */
    public function test_a_request_that_is_sent_again_yields_the_rows_of_its_last_attempt_only(): void
    {
        $curler = new CursorStreamCurler([
            CursorStreamCurler::response(503, ['{"id":1}' . "\n" . '{"id":2}' . "\n"]),
            CursorStreamCurler::ok(['{"id":1}' . "\n" . '{"id":2}' . "\n" . '{"id":3}' . "\n"]),
        ], 1);

        $rows = self::read($this->connection($curler)->cursor('SELECT id FROM t'));

        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $rows);
        $this->assertSame(2, $curler->attempts);
    }

    /**
     * A curler that keeps the body in the response, instead of handing it to the request's write function, has its
     * rows read as well.
     */
    public function test_a_curler_that_keeps_the_body_in_the_response(): void
    {
        $curler = new class extends CurlerRolling {
            public function __construct()
            {
            }

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $response = new CurlerResponse();
                $response->_info = ['http_code' => 200, 'content_type' => 'application/x-ndjson; charset=UTF-8'];
                $response->_body = '{"id":1}' . "\n" . '{"id":2}' . "\n";
                $request->setResponse($response);

                return 200;
            }
        };

        $this->assertSame([['id' => 1], ['id' => 2]], self::read($this->connection($curler)->cursor('SELECT id FROM t')));
    }
}

/**
 * A curler that plays HTTP responses into the header and write functions of a request, as curl calls them: the
 * status line, the headers and a blank line, then the body in chunks. Each attempt plays the next response, and the
 * last one is kept for any later attempt. The retries of the connection apply, as with CurlerRollingWithRetries.
 */
final class CursorStreamCurler extends CurlerRollingWithRetries
{
    /**
     * Every request that was attempted, once per attempt.
     *
     * @var list<CurlerRequest>
     */
    public array $requests = [];

    public int $attempts = 0;

    /**
     * @param list<array{code: int, chunks: list<string>, headers: array<string, string>, errorNo?: int, error?: string}> $responses
     * @param int $retries
     */
    public function __construct(private array $responses, int $retries = 0)
    {
        $this->setRetries($retries);
    }

    /**
     * A response of ClickHouse in JSONEachRow, with HTTP 200.
     *
     * @param list<string> $chunks
     * @return array{code: int, chunks: list<string>, headers: array<string, string>}
     */
    public static function ok(array $chunks): array
    {
        return self::response(200, $chunks, ['X-ClickHouse-Format' => 'JSONEachRow']);
    }

    /**
     * @param int $code
     * @param list<string> $chunks
     * @param array<string, string> $headers
     * @return array{code: int, chunks: list<string>, headers: array<string, string>}
     */
    public static function response(int $code, array $chunks, array $headers = []): array
    {
        return ['code' => $code, 'chunks' => $chunks, 'headers' => ['X-ClickHouse-Query-Id' => 'q-1'] + $headers];
    }

    protected function attempt(CurlerRequest $request, bool $auto_close): int
    {
        $this->requests[] = $request;
        $this->attempts++;
        $response = count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];

        /** @var array<int, mixed> $options */
        $options = (new ReflectionProperty(CurlerRequest::class, 'options'))->getValue($request);
        $handle = new stdClass();
        $options[CURLOPT_HEADERFUNCTION]($handle, "HTTP/1.1 {$response['code']} Status\r\n");
        foreach ($response['headers'] as $name => $value) {
            $options[CURLOPT_HEADERFUNCTION]($handle, "{$name}: {$value}\r\n");
        }
        $options[CURLOPT_HEADERFUNCTION]($handle, "\r\n");
        foreach ($response['chunks'] as $chunk) {
            $this->assertWritten(strlen($chunk), $options[CURLOPT_WRITEFUNCTION]($handle, $chunk));
        }

        $curlerResponse = new CurlerResponse();
        $curlerResponse->_info = [
            'http_code' => $response['code'],
            'content_type' => 'application/x-ndjson; charset=UTF-8',
            'connect_time' => 0.001,
            'size_upload' => 10,
        ];
        $curlerResponse->_headers = ['http_code' => ''];
        $curlerResponse->_errorNo = $response['errorNo'] ?? 0;
        $curlerResponse->_error = $response['error'] ?? '';
        $request->setResponse($curlerResponse);

        return $response['code'];
    }

    /**
     * @param int $length
     * @param mixed $written What the write function returned
     * @return void
     */
    private function assertWritten(int $length, mixed $written): void
    {
        if ($written !== $length) {
            throw new \RuntimeException("The write function wrote {$written} of {$length} bytes.");
        }
    }
}
