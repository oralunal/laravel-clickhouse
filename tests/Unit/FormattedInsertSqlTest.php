<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClientRequests;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\JsonEachRowEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * JSONEachRow and JSONCompactEachRow inserts of ClientRequests, and its pretend-aware write(): the SQL of the
 * INSERT head, the checks that run before anything is sent, the request, and pretend mode. Nothing is sent: a
 * canned curler answers.
 */
class FormattedInsertSqlTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, int|string>, string, string}>
     */
    public static function heads(): array
    {
        return [
            'plain names' => ['examples', ['f_int', 'f_string'], 'JSONEachRow', 'INSERT INTO `examples` (`f_int`, `f_string`) FORMAT JSONEachRow'],
            'a backtick, a trailing backslash and a Nested name' => ['examples', ['we`ird', 'ends\\', 'n.a'], 'JSONEachRow', 'INSERT INTO `examples` (`we``ird`, `ends\\\\`, `n.a`) FORMAT JSONEachRow'],
            'integer names' => ['examples', [0, 1], 'JSONEachRow', 'INSERT INTO `examples` (`0`, `1`) FORMAT JSONEachRow'],
            'a database and a table, as given' => ['db.examples', ['f_int'], 'JSONEachRow', 'INSERT INTO db.examples (`f_int`) FORMAT JSONEachRow'],
            'a quoted table, as given' => ['`my table`', ['f_int'], 'JSONEachRow', 'INSERT INTO `my table` (`f_int`) FORMAT JSONEachRow'],
            'a table with a backslash' => ['my\\table', ['f_int'], 'JSONEachRow', 'INSERT INTO `my\\\\table` (`f_int`) FORMAT JSONEachRow'],
            'no columns' => ['examples', [], 'JSONCompactEachRow', 'INSERT INTO `examples` FORMAT JSONCompactEachRow'],
            'columns with keys' => ['examples', [3 => 'b', 7 => 'a'], 'JSONCompactEachRow', 'INSERT INTO `examples` (`b`, `a`) FORMAT JSONCompactEachRow'],
        ];
    }

    /**
     * @param array<int, int|string> $columns
     */
    #[DataProvider('heads')]
    public function test_compile_insert_with_format(string $table, array $columns, string $format, string $sql): void
    {
        $this->assertSame($sql, ClientRequests::compileInsertWithFormat($table, $columns, $format));
    }

    public function test_compile_insert_with_format_refuses_a_format_that_is_not_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse input format name [JSONEachRow; DROP TABLE t].');

        ClientRequests::compileInsertWithFormat('examples', ['a'], 'JSONEachRow; DROP TABLE t');
    }

    public function test_a_missing_key_is_refused_before_anything_is_sent(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string]. Every row must have'
            . " the first row's keys, in any order"
        );

        ClientRequests::insertJsonEachRow(
            $this->clientThatSendsNothing(),
            $this->connection(),
            'examples',
            [['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2]],
            $this->encoder()
        );
    }

    public function test_an_extra_key_is_refused_before_anything_is_sent(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'Cannot insert the rows as JSONEachRow: the row at index 2 has the keys [f_other], which the first row'
            . ' does not have.'
        );

        ClientRequests::insertJsonEachRow(
            $this->clientThatSendsNothing(),
            $this->connection(),
            'examples',
            [['f_int' => 1], ['f_int' => 2], ['f_int' => 3, 'f_other' => 4]],
            $this->encoder()
        );
    }

    public function test_a_row_with_other_keys_names_both(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(
            'the row at index b lacks the keys [x] and has the keys [y], which the first row does not have.'
        );

        ClientRequests::insertJsonEachRow(
            $this->clientThatSendsNothing(),
            $this->connection(),
            'examples',
            ['a' => ['x' => 1], 'b' => ['y' => 1]],
            $this->encoder()
        );
    }

    public function test_a_row_that_is_not_an_array_is_refused_before_anything_is_sent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the rows as JSONEachRow: the row at index 1 must be an array, int given.');

        ClientRequests::insertJsonEachRow($this->clientThatSendsNothing(), $this->connection(), 'examples', [['a' => 1], 5], $this->encoder());
    }

    public function test_no_rows_are_refused_as_smi2_refuses_them(): void
    {
        $this->expectException(ClientQueryException::class);
        $this->expectExceptionMessage('Inserting empty values array is not supported in ClickHouse');

        ClientRequests::insertJsonEachRow($this->clientThatSendsNothing(), $this->connection(), 'examples', [], $this->encoder());
    }

    public function test_a_value_that_cannot_be_sent_is_refused_before_anything_is_sent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the value of column [f_string] in the row at index 0 as JSON: Malformed UTF-8');

        ClientRequests::insertJsonEachRow(
            $this->clientThatSendsNothing(),
            $this->connection(),
            'examples',
            [['f_string' => "\xFF"]],
            $this->encoder()
        );
    }

    public function test_insert_json_each_row_sends_the_head_in_the_url_and_the_rows_in_the_body(): void
    {
        [$client, $curler] = $this->client($this->response(200, '', [
            'X-ClickHouse-Summary' => '{"read_rows":"2","written_rows":"2"}',
        ]));
        $rows = [
            ['f_int' => 1, 'f_string' => 'text :0 {0} :f_int ON CLUSTER x', 'created_at' => new DateTimeImmutable('2026-10-09 11:12:13')],
            ['created_at' => new DateTimeImmutable('2026-10-09 11:12:14'), 'f_string' => 'b', 'f_int' => 2],
        ];

        $statement = ClientRequests::insertJsonEachRow($client, $this->connection(), 'examples', $rows, $this->encoder());

        $this->assertCount(1, $curler->requests);
        $request = $curler->requests[0];
        $query = $this->urlQuery($request);
        $this->assertSame('INSERT INTO `examples` (`f_int`, `f_string`, `created_at`) FORMAT JSONEachRow', $query['query']);
        $this->assertSame('0', $query['readonly']);
        $this->assertSame(
            '{"f_int":1,"f_string":"text :0 {0} :f_int ON CLUSTER x","created_at":"2026-10-09 11:12:13"}' . "\n"
            . '{"created_at":"2026-10-09 11:12:14","f_string":"b","f_int":2}',
            $request->getDetails()['parameters']
        );
        $this->assertStringStartsWith('application/json', $request->getDetails()['headers']['Content-Type']);
        $this->assertFalse($statement->isError());
        $this->assertSame('INSERT INTO `examples` (`f_int`, `f_string`, `created_at`) FORMAT JSONEachRow', $statement->sql());
        $this->assertSame('2', $statement->summary('written_rows'));
    }

    public function test_insert_json_each_row_goes_through_the_clients_curler_once_per_attempt(): void
    {
        [$client, $curler] = $this->client($this->response(200, ''));

        ClientRequests::insertJsonEachRow($client, $this->connection(), 'db.examples', [['a' => 1]], $this->encoder());

        $this->assertSame('INSERT INTO db.examples (`a`) FORMAT JSONEachRow', $this->urlQuery($curler->requests[0])['query']);
    }

    public function test_a_clickhouse_error_names_the_head_and_not_the_rows(): void
    {
        [$client] = $this->client($this->response(
            400,
            "Code: 27. DB::Exception: Cannot parse input: expected '\"' before: 'notanumber': (while reading the value"
            . " of key k): (at row 1)\n: While executing ParallelParsingBlockInputFormat. (CANNOT_PARSE_INPUT_ASSERTION_FAILED)"
            . " (version 24.8.14.39 (official build))\n",
            ['X-ClickHouse-Exception-Code' => '27'],
            'text/plain; charset=UTF-8'
        ));

        try {
            ClientRequests::insertJsonEachRow($client, $this->connection(), 'examples', [['k' => 'secret-value']], $this->encoder());
            $this->fail('The failed insert did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame(27, $exception->getCode());
            $this->assertStringEndsWith("IN:INSERT INTO `examples` (`k`) FORMAT JSONEachRow", $exception->getMessage());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        }
    }

    public function test_a_failed_insert_keeps_the_payload_out_of_the_exception_string_and_trace(): void
    {
        [$client] = $this->client($this->response(
            400,
            "Code: 27. DB::Exception: Cannot parse input: (CANNOT_PARSE_INPUT_ASSERTION_FAILED)"
            . " (version 24.8.14.39 (official build))\n",
            ['X-ClickHouse-Exception-Code' => '27'],
            'text/plain; charset=UTF-8'
        ));

        // (string) $exception is what Laravel writes to the log as the stack trace, with argument values when
        // zend.exception_ignore_args is off, the php.ini-development default. The php.ini-production default, which
        // CI runners use, leaves every argument out, so the test turns it off to see the frames with their values.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

        try {
            ClientRequests::insertJsonEachRow(
                $client,
                $this->connection(),
                'examples',
                [['id' => 'secret-payload-value-0123456789']],
                $this->encoder()
            );
            $this->fail('The failed insert did not throw.');
        } catch (DatabaseException $exception) {
            // #[\SensitiveParameter] on the body parameter of sendInsert() keeps the row values (the payload) out
            // of that frame, while the head, which names only columns, still appears.
            $this->assertStringNotContainsString('secret-payload-value', (string) $exception);
            $this->assertStringContainsString('SensitiveParameterValue', (string) $exception);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    public function test_an_http_error_leaves_the_rows_out_of_the_exception(): void
    {
        [$client] = $this->client($this->response(502, '<html>Bad Gateway</html>', [], 'text/html'));

        try {
            ClientRequests::insertJsonEachRow($client, $this->connection(), 'examples', [['k' => 'secret-value']], $this->encoder());
            $this->fail('The failed insert did not throw.');
        } catch (ClientQueryException $exception) {
            $this->assertSame(502, $exception->getCode());
            $this->assertStringNotContainsString('secret-value', $exception->getMessage());
            $this->assertSame('(20 bytes of JSONEachRow rows, left out)', $exception->getRequestDetails()['parameters']);
            $this->assertStringNotContainsString('secret-value', json_encode($exception->getRequestDetails()));
        }
    }

    public function test_insert_json_each_row_while_pretending_logs_the_head_and_sends_nothing(): void
    {
        $connection = $this->connection(pretending: true);
        $connection->expects($this->once())
            ->method('logQuery')
            ->with('INSERT INTO `examples` (`f_int`) FORMAT JSONEachRow', [], 0.0);

        $statement = ClientRequests::insertJsonEachRow(
            $this->clientThatSendsNothing(),
            $connection,
            'examples',
            [['f_int' => 1], ['f_int' => 2]],
            $this->encoder()
        );

        $this->assertFalse($statement->isError());
        $this->assertSame('INSERT INTO `examples` (`f_int`) FORMAT JSONEachRow', $statement->sql());
    }

    public function test_the_key_check_runs_while_pretending_too(): void
    {
        $connection = $this->connection(pretending: true);
        $connection->expects($this->never())->method('logQuery');

        $this->expectException(QueryException::class);

        ClientRequests::insertJsonEachRow($this->clientThatSendsNothing(), $connection, 'examples', [['a' => 1], ['b' => 1]], $this->encoder());
    }

    public function test_insert_json_compact_each_row_with_columns(): void
    {
        [$client, $curler] = $this->client($this->response(200, ''));

        ClientRequests::insertJsonCompactEachRow(
            $client,
            $this->connection(),
            'examples',
            [[1, 'a', NAN], ['x' => 2, 'y' => 'b', 'z' => 1.5]],
            $this->encoder(),
            ['f_int', 'f_string', 'f_float']
        );

        $request = $curler->requests[0];
        $this->assertSame(
            'INSERT INTO `examples` (`f_int`, `f_string`, `f_float`) FORMAT JSONCompactEachRow',
            $this->urlQuery($request)['query']
        );
        $this->assertSame("[1,\"a\",\"nan\"]\n[2,\"b\",1.5]", $request->getDetails()['parameters']);
    }

    public function test_insert_json_compact_each_row_without_columns(): void
    {
        [$client, $curler] = $this->client($this->response(200, ''));

        ClientRequests::insertJsonCompactEachRow($client, $this->connection(), 'examples', [[1, 'a']], $this->encoder());

        $this->assertSame('INSERT INTO `examples` FORMAT JSONCompactEachRow', $this->urlQuery($curler->requests[0])['query']);
    }

    public function test_a_compact_row_with_another_number_of_values_is_refused_before_anything_is_sent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the rows as JSONCompactEachRow: the row at index 1 has 1 values, and the first row 2.');

        ClientRequests::insertJsonCompactEachRow($this->clientThatSendsNothing(), $this->connection(), 'examples', [[1, 'a'], [2]], $this->encoder());
    }

    public function test_a_compact_row_with_another_number_of_values_than_the_columns_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the rows as JSONCompactEachRow: the row at index 0 has 2 values, and the columns 3.');

        ClientRequests::insertJsonCompactEachRow(
            $this->clientThatSendsNothing(),
            $this->connection(),
            'examples',
            [[1, 'a']],
            $this->encoder(),
            ['a', 'b', 'c']
        );
    }

    public function test_no_compact_rows_are_refused_as_smi2_refuses_them(): void
    {
        $this->expectException(ClientQueryException::class);

        ClientRequests::insertJsonCompactEachRow($this->clientThatSendsNothing(), $this->connection(), 'examples', [], $this->encoder());
    }

    public function test_insert_json_compact_each_row_while_pretending_logs_the_head(): void
    {
        $connection = $this->connection(pretending: true);
        $connection->expects($this->once())
            ->method('logQuery')
            ->with('INSERT INTO `examples` (`a`) FORMAT JSONCompactEachRow', [], 0.0);

        $statement = ClientRequests::insertJsonCompactEachRow(
            $this->clientThatSendsNothing(),
            $connection,
            'examples',
            [[1]],
            $this->encoder(),
            ['a']
        );

        $this->assertFalse($statement->isError());
    }

    public function test_write_sends_through_client_write_when_not_pretending(): void
    {
        $statement = $this->createStub(Statement::class);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('write')
            ->with('ALTER TABLE `examples` DELETE WHERE `f_int` = :f', ['f' => 1])
            ->willReturn($statement);
        $connection = $this->connection();
        $connection->expects($this->never())->method('logQuery');

        $this->assertSame(
            $statement,
            ClientRequests::write($client, $connection, 'ALTER TABLE `examples` DELETE WHERE `f_int` = :f', ['f' => 1])
        );
    }

    public function test_write_while_pretending_logs_the_statement_and_sends_nothing(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('write');
        $client->expects($this->never())->method('transport');
        $connection = $this->connection(pretending: true);
        $connection->expects($this->once())
            ->method('logQuery')
            ->with('TRUNCATE TABLE `examples`', ['a' => 1], 0.0);

        $statement = ClientRequests::write($client, $connection, 'TRUNCATE TABLE `examples`', ['a' => 1]);

        $this->assertFalse($statement->isError());
        $this->assertSame('TRUNCATE TABLE `examples`', $statement->sql());
    }

    public function test_a_pretended_statement_is_a_successful_empty_write(): void
    {
        $statement = ClientRequests::pretendedStatement('OPTIMIZE TABLE `examples` FINAL');

        $this->assertFalse($statement->isError());
        $this->assertFalse($statement->error());
        $this->assertSame('OPTIMIZE TABLE `examples` FINAL', $statement->sql());
        $this->assertSame([], $statement->rows());
        $this->assertNull($statement->summary());
        $this->assertSame(200, $statement->responseInfo()['http_code']);
        $this->assertSame(0.0, $statement->totalTimeRequest());
        $this->assertSame('0 bytes', $statement->info()['size_upload']);
    }

    /**
     * A real client for a port that nothing listens on, whose curler answers with the given response and records
     * the requests.
     *
     * @param CurlerResponse $response
     * @return array{Client, CurlerRolling&object{requests: array<int, CurlerRequest>}}
     */
    private function client(CurlerResponse $response): array
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->database('scratch');
        $curler = new class($response) extends CurlerRolling {
            /** @var array<int, CurlerRequest> */
            public array $requests = [];

            public function __construct(private CurlerResponse $response)
            {
            }

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->requests[] = $request;
                $request->setResponse($this->response);

                return $this->response->http_code();
            }
        };
        $client->transport()->setDirtyCurler($curler);

        return [$client, $curler];
    }

    /**
     * A client mock that fails the test when anything would be sent.
     *
     * @return Client&MockObject
     */
    private function clientThatSendsNothing(): Client
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('transport');
        $client->expects($this->never())->method('write');
        $client->expects($this->never())->method('insert');

        return $client;
    }

    /**
     * @param bool $pretending
     * @return Connection&MockObject
     */
    private function connection(bool $pretending = false): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('pretending')->willReturn($pretending);

        return $connection;
    }

    /**
     * @return JsonEachRowEncoder
     */
    private function encoder(): JsonEachRowEncoder
    {
        return new JsonEachRowEncoder(fn (DateTimeInterface $value): string => $value->format('Y-m-d H:i:s'));
    }

    /**
     * @param int $httpCode
     * @param string $body
     * @param array<string, string> $headers
     * @param string|null $contentType
     * @return CurlerResponse
     */
    private function response(int $httpCode, string $body, array $headers = [], ?string $contentType = 'text/plain; charset=UTF-8'): CurlerResponse
    {
        $response = new CurlerResponse();
        $response->_info = ['http_code' => $httpCode, 'content_type' => $contentType];
        $response->_headers = $headers;
        $response->_body = $body;

        return $response;
    }

    /**
     * @param CurlerRequest $request
     * @return array<string, string>
     */
    private function urlQuery(CurlerRequest $request): array
    {
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

        return $query;
    }
}
