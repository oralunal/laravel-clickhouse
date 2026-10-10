<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\Carbon;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Closure;
use Illuminate\Database\Query\Expression as LaravelExpression;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Builder::insert(): the Values insert that the model inserts send too (see ClientRequests::compileValuesInsert()),
 * the JSONEachRow insert, the format the connection or the call chooses, the checks before anything is sent, and
 * pretend mode. The same for the insert() of Laravel's query builder (see QueryBuilder::insert()), whose Values
 * insert is Laravel's.
 */
class BuilderInsertSqlTest extends TestCase
{
    /**
     * A client for a port that nothing listens on, whose curler records each request and answers it with an empty
     * HTTP 200 response.
     *
     * @return array{Client, CurlerRolling&object{requests: array<int, CurlerRequest>}}
     */
    private function recordingClient(): array
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $response = new CurlerResponse();
        $response->_info = ['http_code' => 200, 'content_type' => 'text/plain; charset=UTF-8'];
        $response->_headers = ['X-ClickHouse-Summary' => '{"written_rows":"2"}'];
        $response->_body = '';
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
     * A builder on the client that follows a connection without a server, which reads its options from the config.
     *
     * @param Client $client
     * @param array<string, mixed> $config
     * @return Builder
     */
    private function builder(Client $client, array $config = []): Builder
    {
        return (new Builder($client))->followConnectionOptions($this->connection($config))->from('examples');
    }

    /**
     * Laravel's query builder of the examples table, on a connection without a server whose active node is the
     * client.
     *
     * @param Client $client
     * @param array<string, mixed> $config
     * @return QueryBuilder
     */
    private function laravelBuilder(Client $client, array $config = []): QueryBuilder
    {
        $connection = new class (null, 'db', '', $config + ['name' => 'clickhouse', 'fix_default_query_builder' => false]) extends Connection {
            public Client $recordingClient;

            public function getClient(): Client
            {
                return $this->recordingClient;
            }
        };
        $connection->recordingClient = $client;

        /** @var QueryBuilder $query */
        $query = $connection->table('examples');

        return $query;
    }

    /**
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    /**
     * The SQL of a Values insert, which is the body of the request.
     *
     * @param CurlerRequest $request
     * @return string
     */
    private function sentSql(CurlerRequest $request): string
    {
        return (string) $request->getDetails()['parameters'];
    }

    /**
     * The query parameter of a request, which holds the head of a JSONEachRow insert.
     *
     * @param CurlerRequest $request
     * @return string
     */
    private function urlQuery(CurlerRequest $request): string
    {
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

        return (string) ($query['query'] ?? '');
    }

    public function test_a_values_insert_returns_its_statement(): void
    {
        [$client, $curler] = $this->recordingClient();

        $statement = $this->builder($client)->insert([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2, 'f_string' => "it's"]]);

        $this->assertInstanceOf(Statement::class, $statement);
        $this->assertFalse($statement->isError());
        $this->assertCount(1, $curler->requests);
        $this->assertSame(
            "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (1,'a'),  (2,'it\\'s')",
            $this->sentSql($curler->requests[0])
        );
    }

    public function test_one_row_is_inserted_as_a_list_of_one_row(): void
    {
        [$client, $curler] = $this->recordingClient();

        $this->builder($client)->insert(['f_int' => 1, 'f_string' => 'a']);

        $this->assertSame("INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (1,'a')", $this->sentSql($curler->requests[0]));
    }

    /**
     * Values inserts write what the model inserts write: every digit of a float, NaN, INF and -INF as nan, inf and
     * -inf, also inside arrays, raw SQL as the SQL it holds, and a bool as smi2's quoted 'true'.
     */
    public function test_a_values_insert_writes_floats_with_every_digit_and_nan_and_inf(): void
    {
        [$client, $curler] = $this->recordingClient();
        $precision = ini_get('precision');

        $this->builder($client)->insert([[
            'sum' => 0.1 + 0.2,
            'third' => 1 / 3,
            'whole' => 2.0,
            'nan' => NAN,
            'inf' => INF,
            'arr' => [1.5, -INF, [1 / 3]],
            'raw' => new Expression('now()'),
            'laravel' => new LaravelExpression('today()'),
            'flag' => true,
        ]]);

        $this->assertSame(
            'INSERT INTO `examples` (`sum`,`third`,`whole`,`nan`,`inf`,`arr`,`raw`,`laravel`,`flag`)  VALUES  '
            . "(0.30000000000000004,0.3333333333333333,2,nan,inf,[1.5,-inf,[0.3333333333333333]],now(),today(),'true')",
            $this->sentSql($curler->requests[0])
        );
        $this->assertSame($precision, ini_get('precision'));
    }

    /**
     * Dates follow the datetime_precision of the followed connection; a builder made with only a client writes
     * seconds.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function datePrecisionProvider(): array
    {
        return [
            'second' => [[], "('2024-01-02 03:04:05',['2024-01-02 03:04:05'])"],
            'microsecond' => [['datetime_precision' => 'microsecond'], "('2024-01-02 03:04:05.123456',['2024-01-02 03:04:05.123456'])"],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('datePrecisionProvider')]
    public function test_a_values_insert_writes_dates_at_the_precision_of_the_connection(array $config, string $values): void
    {
        [$client, $curler] = $this->recordingClient();
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');

        $this->builder($client, $config)->insert([['d6' => $date, 'a6' => [$date]]]);

        $this->assertSame("INSERT INTO `examples` (`d6`,`a6`)  VALUES  {$values}", $this->sentSql($curler->requests[0]));
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function jsonEachRowProvider(): array
    {
        return [
            'the format of the call' => [[], Format::JSON_EACH_ROW],
            'the format of the call in another letter case' => [[], ' jsoneachrow '],
            "the connection's insert_format" => [['insert_format' => 'JSONEachRow'], null],
        ];
    }

    /**
     * A JSONEachRow insert sends the head in the URL and one JSON object per row in the body, with every row's keys
     * in any order, floats with every digit and dates at the connection's precision.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('jsonEachRowProvider')]
    public function test_a_json_each_row_insert(array $config, ?string $format): void
    {
        [$client, $curler] = $this->recordingClient();
        $date = Carbon::parse('2024-01-02 03:04:05.5', 'UTC');

        $statement = $this->builder($client, $config + ['datetime_precision' => 'microsecond'])->insert([
            ['f_int' => 1, 'f_float' => 0.1 + 0.2, 'd6' => $date],
            ['d6' => $date, 'f_float' => NAN, 'f_int' => 2],
        ], $format);

        $this->assertCount(1, $curler->requests);
        $this->assertSame('INSERT INTO `examples` (`f_int`, `f_float`, `d6`) FORMAT JSONEachRow', $this->urlQuery($curler->requests[0]));
        $this->assertSame(
            '{"f_int":1,"f_float":0.30000000000000004,"d6":"2024-01-02 03:04:05.500000"}' . "\n"
            . '{"d6":"2024-01-02 03:04:05.500000","f_float":"nan","f_int":2}',
            $this->sentSql($curler->requests[0])
        );
        $this->assertSame('2', $statement->summary('written_rows'));
    }

    /**
     * Values as the format of the call wins over the connection's JSONEachRow.
     */
    public function test_the_format_of_the_call_wins_over_the_connection(): void
    {
        [$client, $curler] = $this->recordingClient();

        $this->builder($client, ['insert_format' => 'JSONEachRow'])->insert([['f_int' => 1]], 'values');

        $this->assertSame('INSERT INTO `examples` (`f_int`)  VALUES  (1)', $this->sentSql($curler->requests[0]));
    }

    /**
     * Each insert path's checks, which throw before anything is sent.
     *
     * @return array<string, array{Closure(Builder): mixed, class-string, string}>
     */
    public static function refusedInsertProvider(): array
    {
        $empty = 'Inserting empty values array is not supported in ClickHouse';

        return [
            'no rows' => [fn (Builder $query) => $query->insert([]), ClientQueryException::class, $empty],
            'a row without keys' => [fn (Builder $query) => $query->insert([[]]), ClientQueryException::class, $empty],
            'a later row without keys' => [fn (Builder $query) => $query->insert([['f_int' => 1], []]), ClientQueryException::class, $empty],
            'no rows, as JSONEachRow' => [fn (Builder $query) => $query->insert([], Format::JSON_EACH_ROW), ClientQueryException::class, $empty],
            'a row without keys, as JSONEachRow' => [
                fn (Builder $query) => $query->insert([['f_int' => 1], []], Format::JSON_EACH_ROW),
                ClientQueryException::class,
                $empty,
            ],
            'another key order in Values' => [
                fn (Builder $query) => $query->insert([['f_int' => 1, 'f_string' => 'a'], ['f_string' => 'b', 'f_int' => 2]]),
                ClientQueryException::class,
                'Fields not match: f_string,f_int and f_int,f_string on element 1',
            ],
            'other keys in JSONEachRow' => [
                fn (Builder $query) => $query->insert([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2]], Format::JSON_EACH_ROW),
                QueryException::class,
                'Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string].',
            ],
            'another format' => [
                fn (Builder $query) => $query->insert([['f_int' => 1]], 'CSV'),
                InvalidArgumentException::class,
                "insert() takes the format 'Values' or 'JSONEachRow', [CSV] given.",
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $insert
     * @param class-string $exception
     */
    #[DataProvider('refusedInsertProvider')]
    public function test_a_refused_insert_sends_nothing(Closure $insert, string $exception, string $message): void
    {
        [$client, $curler] = $this->recordingClient();

        try {
            $insert($this->builder($client));
            $this->fail('The insert should throw');
        } catch (ClientQueryException|InvalidArgumentException $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringStartsWith($message, $thrown->getMessage());
        }

        $this->assertSame([], $curler->requests);
    }

    /**
     * While the followed connection pretends, each insert is logged once, a JSONEachRow insert by its head, and
     * nothing is sent. A JSONEachRow insert still encodes its rows, so a value that the real insert refuses, raw SQL
     * or a string that is not valid UTF-8, throws and is not logged.
     */
    public function test_inserts_are_logged_and_not_sent_while_the_connection_pretends(): void
    {
        [$client, $curler] = $this->recordingClient();
        $connection = $this->connection();
        $statements = [];
        $refused = [];

        $log = $connection->pretend(function () use ($client, $connection, &$statements, &$refused): void {
            $query = fn (): Builder => (new Builder($client))->followConnectionOptions($connection)->from('examples');
            $statements[] = $query()->insert([['f_int' => 1, 'f_float' => 0.1 + 0.2]]);
            $statements[] = $query()->insert([['f_int' => 2]], Format::JSON_EACH_ROW);
            foreach ([new LaravelExpression("'x'"), "\xff"] as $value) {
                try {
                    $query()->insert([['f_int' => 3, 'f_string' => $value]], Format::JSON_EACH_ROW);
                } catch (InvalidArgumentException $exception) {
                    $refused[] = $exception->getMessage();
                }
            }
        });

        $this->assertSame(
            [
                'INSERT INTO `examples` (`f_int`,`f_float`)  VALUES  (1,0.30000000000000004)',
                'INSERT INTO `examples` (`f_int`) FORMAT JSONEachRow',
            ],
            array_column($log, 'query')
        );
        $this->assertSame([], $curler->requests);
        foreach ($statements as $statement) {
            $this->assertFalse($statement->isError());
        }
        $this->assertCount(2, $refused);
        $this->assertStringStartsWith('Cannot insert the value of column [f_string] in the row at index 0 as JSON: raw SQL', $refused[0]);
        $this->assertStringStartsWith(
            'Cannot insert the value of column [f_string] in the row at index 0 as JSON: Malformed UTF-8 characters',
            $refused[1]
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function laravelJsonEachRowProvider(): array
    {
        return [
            'the format of the call' => [[], Format::JSON_EACH_ROW],
            'the format of the call in another letter case' => [[], ' jsoneachrow '],
            "the connection's insert_format" => [['insert_format' => 'JSONEachRow'], null],
        ];
    }

    /**
     * Laravel's query builder sends a JSONEachRow insert with the head that its grammar writes in the URL and one
     * JSON object per row in the body, in the first row's key order, logs the head once without bindings, and
     * returns true. The values are written as the package builder writes them: floats with every digit, NaN as
     * "nan", dates at the connection's datetime_precision, null, and arrays and collections as JSON arrays.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('laravelJsonEachRowProvider')]
    public function test_laravels_query_builder_inserts_json_each_row(array $config, ?string $format): void
    {
        [$client, $curler] = $this->recordingClient();
        $query = $this->laravelBuilder($client, $config + ['datetime_precision' => 'microsecond']);
        $query->getConnection()->enableQueryLog();
        $date = Carbon::parse('2024-01-02 03:04:05.5', 'UTC');
        $head = 'insert into "examples" ("f_int", "f_float", "d6", "tags", "note") format JSONEachRow';

        $inserted = $query->insert([
            ['f_int' => 1, 'f_float' => 0.1 + 0.2, 'd6' => $date, 'tags' => collect(['a', "it's"]), 'note' => null],
            ['note' => 'n', 'tags' => [], 'd6' => $date, 'f_float' => NAN, 'f_int' => 2],
        ], $format);

        $this->assertTrue($inserted);
        $this->assertCount(1, $curler->requests);
        $this->assertSame($head, $this->urlQuery($curler->requests[0]));
        $this->assertSame(
            '{"f_int":1,"f_float":0.30000000000000004,"d6":"2024-01-02 03:04:05.500000","tags":["a","it\'s"],"note":null}' . "\n"
            . '{"note":"n","tags":[],"d6":"2024-01-02 03:04:05.500000","f_float":"nan","f_int":2}',
            $this->sentSql($curler->requests[0])
        );
        $this->assertSame(
            [['query' => $head, 'bindings' => []]],
            array_map(fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']], $query->getConnection()->getQueryLog())
        );
    }

    /**
     * Without JSONEachRow, the insert is Laravel's: insert ... values with bindings, which the connection writes as
     * literals; Values as the format of the call wins over the connection's insert_format.
     *
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function laravelValuesProvider(): array
    {
        return [
            'no format' => [[], null],
            'Values as the format of the call' => [['insert_format' => 'JSONEachRow'], 'values'],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('laravelValuesProvider')]
    public function test_laravels_query_builder_inserts_values_as_laravel_does(array $config, ?string $format): void
    {
        [$client, $curler] = $this->recordingClient();

        $this->assertTrue($this->laravelBuilder($client, $config)->insert(['f_int' => 1, 'f_float' => 0.1 + 0.2, 'tags' => collect(['a'])], $format));

        $this->assertCount(1, $curler->requests);
        $this->assertSame(
            "insert into \"examples\" (\"f_float\", \"f_int\", \"tags\") values (0.30000000000000004, 1, ['a'])",
            $this->sentSql($curler->requests[0])
        );
    }

    /**
     * Each check of the insert() of Laravel's query builder, which throws before anything is sent or logged.
     *
     * @return array<string, array{Closure(QueryBuilder): mixed, class-string, string}>
     */
    public static function laravelRefusedInsertProvider(): array
    {
        return [
            'another format' => [
                fn (QueryBuilder $query) => $query->insert([['f_int' => 1]], 'CSV'),
                InvalidArgumentException::class,
                "insert() takes the format 'Values' or 'JSONEachRow', [CSV] given.",
            ],
            'a row without keys' => [
                fn (QueryBuilder $query) => $query->insert([['f_int' => 1], []], Format::JSON_EACH_ROW),
                ClientQueryException::class,
                'Inserting empty values array is not supported in ClickHouse',
            ],
            'other keys' => [
                fn (QueryBuilder $query) => $query->insert([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2]], Format::JSON_EACH_ROW),
                QueryException::class,
                'Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string].',
            ],
            'a row that is no array' => [
                fn (QueryBuilder $query) => $query->insert([['f_int' => 1], 2], Format::JSON_EACH_ROW),
                InvalidArgumentException::class,
                'Cannot insert the rows as JSONEachRow: the row at index 1 must be an array, int given.',
            ],
            'raw SQL' => [
                fn (QueryBuilder $query) => $query->insert(['f_int' => 1, 'f_string' => new LaravelExpression("upper('x')")], Format::JSON_EACH_ROW),
                InvalidArgumentException::class,
                'Cannot insert the value of column [f_string] in the row at index 0 as JSON: raw SQL',
            ],
            'invalid UTF-8' => [
                fn (QueryBuilder $query) => $query->insert(['f_int' => 1, 'f_string' => "\xff"], Format::JSON_EACH_ROW),
                InvalidArgumentException::class,
                'Cannot insert the value of column [f_string] in the row at index 0 as JSON: Malformed UTF-8 characters',
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): mixed $insert
     * @param class-string $exception
     */
    #[DataProvider('laravelRefusedInsertProvider')]
    public function test_a_refused_insert_of_laravels_query_builder_sends_and_logs_nothing(Closure $insert, string $exception, string $message): void
    {
        [$client, $curler] = $this->recordingClient();
        $query = $this->laravelBuilder($client);
        $query->getConnection()->enableQueryLog();

        try {
            $insert($query);
            $this->fail('The insert should throw');
        } catch (ClientQueryException|InvalidArgumentException $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringStartsWith($message, $thrown->getMessage());
        }

        $this->assertSame([], $curler->requests);
        $this->assertSame([], $query->getConnection()->getQueryLog());
    }

    public function test_laravels_query_builder_inserts_no_rows_as_laravel_does(): void
    {
        [$client, $curler] = $this->recordingClient();
        $query = $this->laravelBuilder($client);
        $query->getConnection()->enableQueryLog();

        $this->assertTrue($query->insert([], Format::JSON_EACH_ROW));
        $this->assertTrue($query->insert([]));
        $this->assertSame([], $curler->requests);
        $this->assertSame([], $query->getConnection()->getQueryLog());
    }

    /**
     * While the connection pretends, a JSONEachRow insert of Laravel's query builder is logged once by its head and
     * not sent, and a row that the real insert refuses is refused.
     */
    public function test_laravels_query_builder_logs_and_sends_nothing_while_the_connection_pretends(): void
    {
        [$client, $curler] = $this->recordingClient();
        $connection = $this->laravelBuilder($client)->getConnection();
        $results = [];
        $refused = null;

        $log = $connection->pretend(function () use ($connection, &$results, &$refused): void {
            $results[] = $connection->table('examples')->insert([['f_int' => 1]], Format::JSON_EACH_ROW);
            $results[] = $connection->table('examples')->insert([['f_int' => 2]]);
            try {
                $connection->table('examples')->insert([['f_int' => 3, 'f_string' => "\xff"]], Format::JSON_EACH_ROW);
            } catch (InvalidArgumentException $exception) {
                $refused = $exception;
            }
        });

        $this->assertSame([true, true], $results);
        $this->assertSame(
            ['insert into "examples" ("f_int") format JSONEachRow', 'insert into "examples" ("f_int") values (2)'],
            array_column($log, 'query')
        );
        $this->assertSame([], $curler->requests);
        $this->assertInstanceOf(InvalidArgumentException::class, $refused);
    }

    /**
     * The beforeQuery() callbacks of the builder run before the head is written, and the beforeExecuting() callbacks
     * of the connection get the head.
     */
    public function test_laravels_query_builder_runs_the_callbacks_of_a_json_each_row_insert(): void
    {
        [$client, $curler] = $this->recordingClient();
        $query = $this->laravelBuilder($client);
        $executing = [];
        $query->getConnection()->beforeExecuting(function (string $sql, array $bindings) use (&$executing): void {
            $executing[] = [$sql, $bindings];
        });
        $query->beforeQuery(fn (QueryBuilder $query) => $query->from('db.examples_copy'));

        $query->insert(['f_int' => 1], Format::JSON_EACH_ROW);

        $head = 'insert into "db"."examples_copy" ("f_int") format JSONEachRow';
        $this->assertSame([[$head, []]], $executing);
        $this->assertSame($head, $this->urlQuery($curler->requests[0]));
    }

    /**
     * The table for writes: a sources table as written, and a from() table as a SELECT writes it.
     *
     * @return array<string, array{Closure(Builder): Builder, string, string}>
     */
    public static function tableProvider(): array
    {
        return [
            'a sources table' => [
                fn (Builder $query): Builder => $query->setSourcesTable('db.examples_sources'),
                'INSERT INTO db.examples_sources (`f_int`)  VALUES  (1)',
                'INSERT INTO db.examples_sources (`f_int`) FORMAT JSONEachRow',
            ],
            'a name that needs quoting' => [
                fn (Builder $query): Builder => $query->from('default.my-table'),
                'INSERT INTO `default`.`my-table` (`f_int`)  VALUES  (1)',
                'INSERT INTO `default`.`my-table` (`f_int`) FORMAT JSONEachRow',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $table
     */
    #[DataProvider('tableProvider')]
    public function test_both_formats_insert_into_the_table_for_writes(Closure $table, string $valuesSql, string $jsonHead): void
    {
        [$client, $curler] = $this->recordingClient();

        $table($this->builder($client))->insert([['f_int' => 1]]);
        $table($this->builder($client))->insert([['f_int' => 1]], Format::JSON_EACH_ROW);

        $this->assertSame($valuesSql, $this->sentSql($curler->requests[0]));
        $this->assertSame($jsonHead, $this->urlQuery($curler->requests[1]));
    }
}
