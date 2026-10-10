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
use Oralunal\LaravelClickHouse\ClientRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ClientRequests::select(): which SELECTs go through smi2's Client::select() and which through the transport,
 * how a rerouted request looks, and how its result is read. Nothing is sent: a canned curler answers.
 */
class ClientRequestsSelectTest extends TestCase
{
    /**
     * SQL, the format it names, and whether smi2's Client::select() sends it as intended.
     *
     * @return array<string, array{string, string|null, bool}>
     */
    public static function routes(): array
    {
        return [
            'no format' => ['SELECT * FROM t WHERE id = 1', null, true],
            'JSON before SETTINGS' => ['SELECT * FROM t WHERE id = 1 FORMAT JSON SETTINGS max_threads=1', 'JSON', true],
            'SETTINGS without a format' => ['SELECT * FROM t SETTINGS max_threads=1', null, true],
            'CSV' => ['SELECT 1 FORMAT CSV', 'CSV', true],
            'JSONCompact' => ['SELECT 1 FORMAT JSONCompact', 'JSONCompact', true],
            'JSONEachRow' => ['SELECT 1 FORMAT JSONEachRow', 'JSONEachRow', true],
            'TabSeparatedWithNames' => ['SELECT 1 FORMAT TabSeparatedWithNames', 'TabSeparatedWithNames', true],
            'TSVRaw' => ['SELECT 1 FORMAT TSVRaw', 'TSVRaw', true],
            'CSVWithNames' => ['SELECT 1 FORMAT CSVWithNames', 'CSVWithNames', true],
            'a format in another letter case' => ['SELECT 1 FORMAT json', 'JSON', true],
            "get()'s CSV before SETTINGS" => ['SELECT * FROM `examples` FORMAT CSV SETTINGS max_threads=2', 'CSV', true],
            "get()'s TSV" => ['SELECT * FROM `examples` FORMAT TSV', 'TSV', true],
            "get()'s nested set operation" => [
                'SELECT `a` FROM `t` EXCEPT (SELECT `a` FROM `r` UNION ALL SELECT `a` FROM `s`) FORMAT JSON SETTINGS max_threads=1',
                'JSON',
                true,
            ],
            "get()'s sub-queries" => [
                'WITH `w` AS (SELECT `id` FROM `r`) SELECT * FROM (SELECT `id` FROM `r`) WHERE `id` IN (SELECT `id` FROM `r`)'
                . ' FORMAT JSON SETTINGS max_threads=1',
                'JSON',
                true,
            ],
            'a trailing semicolon' => ['SELECT 1;', null, true],
            'a trailing semicolon after the format' => ['SELECT 1 FORMAT JSON;', 'JSON', true],
            'spaces around the SQL' => ["  SELECT 1\n", null, true],
            'blank SQL is left to smi2' => ['', null, true],
            'a literal that names a format smi2 knows' => ["SELECT * FROM t WHERE note = 'export as format csv please'", null, false],
            'a literal that names JSON' => ["SELECT * FROM t WHERE note != 'format JSON x'", null, false],
            'a literal before the real format' => ["SELECT * FROM t WHERE note = 'format csv' FORMAT JSON SETTINGS max_threads=1", 'JSON', false],
            'a block comment' => ['SELECT 1 /* FORMAT CSV */', null, false],
            'a line comment' => ["SELECT 1 -- format TSV\n", null, false],
            'a trailing line comment without a format name' => ['SELECT id FROM t ORDER BY id -- only active rows', null, false],
            'a trailing hash comment without a format name' => ['SELECT id FROM t ORDER BY id # note', null, false],
            'a query ending in a comment line' => ["\n    SELECT id\n    FROM t\n    ORDER BY id -- newest last\n", null, false],
            'a trailing comment after a semicolon' => ['SELECT id FROM t; -- c', null, false],
            'an unclosed block comment at the end' => ['SELECT id FROM t /* note', null, false],
            'a trailing double-slash comment' => ['SELECT id FROM t ORDER BY id // only active rows', null, false],
            'a trailing double-slash comment without a space' => ['SELECT id FROM t WHERE id = 1 //one row', null, false],
            'a query ending in a double-slash comment line' => [
                "\n    SELECT id\n    FROM t\n    ORDER BY id // newest last\n",
                null,
                false,
            ],
            'a double-slash comment on an earlier line' => ["SELECT id // the key\nFROM t ORDER BY id", null, true],
            'a double slash inside a string literal is not a comment' => ["SELECT 'http://example.com' AS url FROM t", null, true],
            'a double slash inside a quoted name is not a comment' => ['SELECT `a//b` FROM t', null, true],
            'a double slash inside a double-quoted name is not a comment' => ['SELECT "a//b" FROM t', null, true],
            'a slash after a block comment is a division' => ['SELECT 4 /* four *// 2', null, true],
            'a comment name inside a string literal is not a comment' => ["SELECT '-- not a comment' AS note FROM t", null, true],
            'a hash without a space is not a comment' => ['SELECT 1 AS `#notacomment`', null, true],
            'a comment name inside a heredoc is not a comment' => ['SELECT $$-- not a comment$$ AS h', null, true],
            'a double slash inside a tagged heredoc is not a comment' => ["SELECT \$t\$it's // not a comment\$t\$ AS h", null, true],
            'a $ after a word character is part of a name, not a heredoc' => ['SELECT 1 AS a$$, 2 -- c$$', null, false],
            'a heredoc after a no-break space' => ["SELECT\u{00A0}\$\$a -- b\$\$ AS h", null, true],
            'a tagged heredoc after a no-break space' => ["SELECT\u{00A0}\$t\$a // b\$t\$ AS h", null, true],
            'a quoted name' => ['SELECT `format csv` FROM t', null, false],
            'XML, which smi2 does not know' => ['SELECT 1 FORMAT XML', 'XML', false],
            'Pretty' => ['SELECT 1 FORMAT Pretty', 'Pretty', false],
            'Values' => ['SELECT 1 FORMAT Values', 'Values', false],
            'JSONStrings, which smi2 reads as JSON' => ['SELECT 1 FORMAT JSONStrings', 'JSONStrings', false],
            'a format that the caller does not name' => ['SELECT 1 FORMAT JSONCompact', null, false],
        ];
    }

    #[DataProvider('routes')]
    public function test_smi2s_query_class_decides_the_route(string $sql, ?string $format, bool $throughClient): void
    {
        $this->assertSame($throughClient, ClientRequests::clientSelectsAsIntended($sql, $format));
    }

    public function test_a_query_that_smi2_sends_as_intended_goes_through_client_select(): void
    {
        $statement = $this->createStub(Statement::class);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('select')
            ->with('SELECT * FROM `t` WHERE `a` = :a', ['a' => 1])
            ->willReturn($statement);
        $client->expects($this->never())->method('transport');

        $this->assertSame($statement, ClientRequests::select($client, 'SELECT * FROM `t` WHERE `a` = :a', ['a' => 1]));
    }

    public function test_a_rerouted_query_is_sent_as_given_with_default_format(): void
    {
        [$client, $curler] = $this->client($this->jsonResponse([['id' => 1, 'note' => 'export as format csv please']]));
        $sql = "SELECT * FROM t WHERE note = 'export as format csv please' AND id >= :min AND x = {name}";

        $rows = ClientRequests::select($client, $sql, ['min' => 1, 'name' => 'n'])->rows();

        $this->assertSame([['id' => 1, 'note' => 'export as format csv please']], $rows);
        $this->assertCount(1, $curler->requests);
        $request = $curler->requests[0];
        $this->assertSame(
            "SELECT * FROM t WHERE note = 'export as format csv please' AND id >= 1 AND x = n",
            $request->getDetails()['parameters']
        );
        $query = $this->urlQuery($request);
        $this->assertSame('JSON', $query['default_format']);
        $this->assertArrayNotHasKey('http_write_exception_in_output_format', $query);
        $this->assertSame('2', $query['readonly']);
        $this->assertSame('scratch', $query['database']);
    }

    public function test_a_rerouted_query_with_a_format_is_read_in_the_format_that_clickhouse_names(): void
    {
        [$client] = $this->client($this->response(200, "<?xml version='1.0' ?>\n<result/>", 'application/xml', ['X-ClickHouse-Format' => 'XML']));

        $statement = ClientRequests::select($client, 'SELECT 1 AS x FORMAT XML', [], 'XML');

        $this->assertSame('XML', $statement->getFormat());
        $this->assertSame("<?xml version='1.0' ?>\n<result/>", $statement->rawData());
    }

    public function test_a_format_in_the_sql_that_the_caller_does_not_name_is_read_as_clickhouse_names_it(): void
    {
        [$client] = $this->client($this->response(
            200,
            '{"meta":[{"name":"id","type":"UInt8"}],"data":[[1],[2]],"rows":2}',
            'application/json; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSONCompact']
        ));

        $rows = ClientRequests::select($client, 'SELECT id FROM t FORMAT JSONCompact')->rows();

        $this->assertSame([[[1]], [[2]]], $rows);
    }

    public function test_without_the_format_header_the_given_format_is_used(): void
    {
        [$client] = $this->client($this->response(200, '<result/>', 'application/xml'));

        $statement = ClientRequests::select($client, 'SELECT 1 FORMAT XML', [], 'XML');

        $this->assertSame('XML', $statement->getFormat());
    }

    public function test_without_the_format_header_and_without_a_format_json_is_used(): void
    {
        [$client] = $this->client($this->response(200, '{"meta":[{"name":"x","type":"UInt8"}],"data":[{"x":1}],"rows":1}'));

        $statement = ClientRequests::select($client, 'SELECT 1 AS x /* FORMAT CSV */');

        $this->assertSame('JSON', $statement->getFormat());
        $this->assertSame([['x' => 1]], $statement->rows());
    }

    public function test_an_error_of_a_rerouted_query_surfaces_on_rows_with_its_name(): void
    {
        [$client] = $this->client($this->response(
            404,
            "Code: 60. DB::Exception: Unknown table expression identifier 'nope' in scope SELECT * FROM nope WHERE"
            . " x = 'format csv'. (UNKNOWN_TABLE) (version 24.8.14.39 (official build))\n",
            'text/plain; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSON', 'X-ClickHouse-Exception-Code' => '60']
        ));

        $statement = ClientRequests::select($client, "SELECT * FROM nope WHERE x = 'format csv'");

        try {
            $statement->rows();
            $this->fail('rows() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame(60, $exception->getCode());
            $this->assertSame('UNKNOWN_TABLE', $exception->getClickHouseExceptionName());
            $this->assertStringEndsWith("IN:SELECT * FROM nope WHERE x = 'format csv'", $exception->getMessage());
        }
    }

    public function test_a_rerouted_query_that_fails_after_the_first_block_throws_with_its_name(): void
    {
        [$client] = $this->client($this->response(
            500,
            $this->jsonWithException(395, "Value passed to 'throwIf' function is non-zero.", 'FUNCTION_THROW_IF_VALUE_IS_NON_ZERO'),
            'application/json; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSON', 'X-ClickHouse-Exception-Code' => '395']
        ));

        try {
            ClientRequests::select($client, "SELECT throwIf(number = 5) FROM numbers(10) /* format csv */")->rows();
            $this->fail('rows() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame(395, $exception->getCode());
            $this->assertSame('FUNCTION_THROW_IF_VALUE_IS_NON_ZERO', $exception->getClickHouseExceptionName());
            $this->assertStringContainsString("Value passed to 'throwIf'", $exception->getMessage());
        }
    }

    public function test_a_rerouted_query_that_fails_after_http_200_still_throws(): void
    {
        [$client] = $this->client($this->response(
            200,
            $this->jsonWithException(159, 'Timeout exceeded.', 'TIMEOUT_EXCEEDED'),
            'application/json; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSON']
        ));

        $statement = ClientRequests::select($client, "SELECT sleepEachRow(1) FROM numbers(100) /* format csv */");

        $this->assertTrue($statement->isError());
        try {
            $statement->rows();
            $this->fail('rows() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame(159, $exception->getCode());
            $this->assertSame('TIMEOUT_EXCEEDED', $exception->getClickHouseExceptionName());
        }
    }

    public function test_a_query_on_the_client_select_route_that_fails_after_http_200_throws(): void
    {
        [$client] = $this->client($this->response(
            200,
            $this->jsonWithException(241, 'Memory limit exceeded.', 'MEMORY_LIMIT_EXCEEDED'),
            'application/json; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSON']
        ));

        $statement = ClientRequests::select($client, 'SELECT number FROM numbers(3000000)');

        $this->assertTrue($statement->isError());
        try {
            $statement->rows();
            $this->fail('rows() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame(241, $exception->getCode());
            $this->assertSame('MEMORY_LIMIT_EXCEEDED', $exception->getClickHouseExceptionName());
        }
    }

    public function test_a_successful_json_result_with_a_column_named_exception_is_not_read_as_an_error(): void
    {
        $body = json_encode([
            'meta' => [['name' => 'exception', 'type' => 'String']],
            'data' => [['exception' => 'Code: 1. DB::Exception: not a real error']],
            'rows' => 1,
            'statistics' => ['elapsed' => 0.001, 'rows_read' => 1, 'bytes_read' => 1],
        ]);
        [$client] = $this->client($this->response(200, $body, 'application/json; charset=UTF-8', ['X-ClickHouse-Format' => 'JSON']));

        $rows = ClientRequests::select($client, 'SELECT col AS exception FROM t')->rows();

        $this->assertSame([['exception' => 'Code: 1. DB::Exception: not a real error']], $rows);
    }

    public function test_a_read_only_user_gets_no_extra_settings_beside_default_format(): void
    {
        [$client, $curler] = $this->client($this->jsonResponse([]), ['readonly' => true]);

        ClientRequests::select($client, "SELECT 1 /* format csv */");

        $query = $this->urlQuery($curler->requests[0]);
        $this->assertSame('JSON', $query['default_format']);
        $this->assertArrayNotHasKey('http_write_exception_in_output_format', $query);
    }

    public function test_select_request_builds_without_sending(): void
    {
        [$client, $curler] = $this->client();

        $request = ClientRequests::selectRequest($client, "SELECT 1 /* format csv */");

        $this->assertSame([], $curler->requests);
        $this->assertFalse($request->isResponseExists());
        $this->assertSame('SELECT 1 /* format csv */', $request->getDetails()['parameters']);
        $this->assertSame('JSON', $request->getRequestExtendedInfo('format'));
        $this->assertSame('JSON', $this->urlQuery($request)['default_format']);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, string|null}>
     */
    public static function queriesThatSmi2SendsAsIntended(): array
    {
        return [
            'no format' => ['SELECT * FROM t WHERE a = :a', ['a' => 'x'], null],
            'a named format' => ['SELECT * FROM t FORMAT JSONCompact', [], 'JSONCompact'],
            'a typed parameter' => ['SELECT {p1:UInt8} + {p2:UInt8} AS s', ['p1' => 3, 'p2' => 4], null],
        ];
    }

    /**
     * @param array<string, mixed> $bindings
     */
    #[DataProvider('queriesThatSmi2SendsAsIntended')]
    public function test_select_request_of_a_query_that_smi2_sends_as_intended_is_the_request_of_client_select(
        string $sql,
        array $bindings,
        ?string $format
    ): void {
        [$client, $curler] = $this->client($this->jsonResponse([]));
        $client->select($sql, $bindings);
        $sent = $curler->requests[0];

        $built = ClientRequests::selectRequest($client, $sql, $bindings, $format);

        $this->assertSame($sent->getUrl(), $built->getUrl());
        $this->assertSame($sent->getDetails()['parameters'], $built->getDetails()['parameters']);
        $this->assertSame($sent->getRequestExtendedInfo('format'), $built->getRequestExtendedInfo('format'));
        $this->assertSame($sent->getHeaders(), $built->getHeaders());
    }

    public function test_select_statement_reads_the_format_header_of_the_response(): void
    {
        [$client] = $this->client();
        $request = ClientRequests::selectRequest($client, 'SELECT id FROM t FORMAT JSONCompact');
        $request->setResponse($this->response(
            200,
            '{"meta":[{"name":"id","type":"UInt8"}],"data":[[7]],"rows":1}',
            'application/json',
            ['x-clickhouse-format' => 'JSONCompact']
        ));

        $this->assertSame([[[7]]], ClientRequests::selectStatement($request)->rows());
    }

    public function test_select_statement_keeps_the_built_format_without_a_response(): void
    {
        [$client] = $this->client();
        $request = ClientRequests::selectRequest($client, 'SELECT 1 FORMAT Pretty', [], 'Pretty');

        $this->assertSame('Pretty', ClientRequests::selectStatement($request)->getFormat());
    }

    public function test_select_statement_ignores_a_format_header_that_is_not_a_name(): void
    {
        [$client] = $this->client();
        $request = ClientRequests::selectRequest($client, 'SELECT 1 FORMAT XML', [], 'XML');
        $request->setResponse($this->response(200, '<result/>', 'application/xml', ['X-ClickHouse-Format' => 'X M L']));

        $this->assertSame('XML', ClientRequests::selectStatement($request)->getFormat());
    }

    public function test_blank_sql_is_refused_by_smi2(): void
    {
        [$client] = $this->client();

        $this->expectException(ClientQueryException::class);
        $this->expectExceptionMessage('Empty Query');

        ClientRequests::select($client, '  ');
    }

    /**
     * A real client for a port that nothing listens on, whose curler answers with the given responses in turn
     * and records the requests.
     *
     * @param CurlerResponse|null $response
     * @param array<string, mixed> $connectParams
     * @return array{Client, CurlerRolling&object{requests: array<int, CurlerRequest>}}
     */
    private function client(?CurlerResponse $response = null, array $connectParams = []): array
    {
        $client = new Client($connectParams + ['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->database('scratch');
        $curler = new class($response) extends CurlerRolling {
            /** @var array<int, CurlerRequest> */
            public array $requests = [];

            public function __construct(private ?CurlerResponse $response)
            {
            }

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->requests[] = $request;
                $request->setResponse($this->response ?? new CurlerResponse());

                return $request->response()->http_code();
            }
        };
        $client->transport()->setDirtyCurler($curler);

        return [$client, $curler];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return CurlerResponse
     */
    private function jsonResponse(array $rows): CurlerResponse
    {
        $meta = array_map(fn (string $name): array => ['name' => $name, 'type' => 'String'], array_keys($rows[0] ?? ['x' => 1]));

        return $this->response(
            200,
            json_encode(['meta' => $meta, 'data' => $rows, 'rows' => count($rows)]),
            'application/json; charset=UTF-8',
            ['X-ClickHouse-Format' => 'JSON']
        );
    }

    /**
     * @param int $httpCode
     * @param string $body
     * @param string|null $contentType
     * @param array<string, string> $headers
     * @return CurlerResponse
     */
    private function response(
        int $httpCode,
        string $body,
        ?string $contentType = 'application/json; charset=UTF-8',
        array $headers = []
    ): CurlerResponse {
        $response = new CurlerResponse();
        $response->_info = ['http_code' => $httpCode, 'content_type' => $contentType];
        $response->_headers = $headers;
        $response->_body = $body;

        return $response;
    }

    /**
     * A whole-document JSON result whose last key is the exception ClickHouse writes when a query fails after the
     * first result block, as http_write_exception_in_output_format does by default.
     *
     * @param int $code
     * @param string $message
     * @param string $name
     * @return string
     */
    private function jsonWithException(int $code, string $message, string $name): string
    {
        return (string) json_encode([
            'meta' => [['name' => 'x', 'type' => 'UInt8']],
            'data' => [['x' => 1]],
            'rows' => 1,
            'exception' => "Code: {$code}. DB::Exception: {$message} ({$name}) (version 24.8.14.39 (official build))",
        ]);
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
