<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Cluster;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;

/**
 * The connection without a server: its package options (datetime_precision, insert_format, use_on_cluster,
 * cluster_name, cluster and default_index_type), the API that the package's builders and schema code call, and the way it hands a query
 * to the smi2 client, which a canned client on port 1 answers without sending anything.
 */
class ConnectionOptionsTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    /**
     * A connection whose active node is a smi2 client on port 1 with a curler that records each request and answers
     * it with the next canned response, or with an empty HTTP 200 when none is left.
     *
     * @param array<string, mixed> $config
     * @param CurlerResponse ...$responses
     * @return array{0: Connection, 1: CurlerRolling, 2: Client} The curler lists the requests in its $requests property
     */
    private function connectionWithCannedClient(array $config = [], CurlerResponse ...$responses): array
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->database('db');
        $curler = new class ($responses) extends CurlerRolling {
            /** @var array<int, CurlerRequest> */
            public array $requests = [];

            /**
             * @param array<int, CurlerResponse> $responses
             */
            public function __construct(private array $responses)
            {
            }

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->requests[] = $request;
                $request->setResponse(array_shift($this->responses) ?? ConnectionOptionsTest::response(200, ''));

                return $request->response()->http_code();
            }
        };
        $client->transport()->setDirtyCurler($curler);

        $connection = new class (null, 'db', '', $config + ['name' => 'clickhouse']) extends Connection {
            public Client $cannedClient;

            public function getClient(): Client
            {
                return $this->cannedClient;
            }
        };
        $connection->cannedClient = $client;

        return [$connection, $curler, $client];
    }

    /**
     * A connection with a given cluster, whose active node is that cluster's, as on a real connection.
     *
     * @param Cluster $cluster
     * @return Connection
     */
    private function connectionWithCluster(Cluster $cluster): Connection
    {
        $connection = new class (null, 'db', '', ['name' => 'clickhouse']) extends Connection {
            public function useCluster(Cluster $cluster): void
            {
                $this->cluster = $cluster;
            }
        };
        $connection->useCluster($cluster);

        return $connection;
    }

    /**
     * @param int $httpCode
     * @param string $body
     * @param array<string, string> $headers
     * @param string $contentType
     * @return CurlerResponse
     */
    public static function response(
        int $httpCode,
        string $body,
        array $headers = [],
        string $contentType = 'text/plain; charset=UTF-8'
    ): CurlerResponse {
        $response = new CurlerResponse();
        $response->_info = ['http_code' => $httpCode, 'content_type' => $contentType];
        $response->_headers = $headers;
        $response->_body = $body;

        return $response;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return CurlerResponse
     */
    private static function jsonResponse(array $rows): CurlerResponse
    {
        $meta = array_map(
            fn (string $name): array => ['name' => $name, 'type' => 'String'],
            array_keys($rows[0] ?? ['x' => 1])
        );

        return self::response(
            200,
            (string) json_encode(['meta' => $meta, 'data' => $rows, 'rows' => count($rows)]),
            ['X-ClickHouse-Format' => 'JSON'],
            'application/json; charset=UTF-8'
        );
    }

    /**
     * @param string|null $writtenRows The written_rows of the X-ClickHouse-Summary header, or null for no header
     * @return CurlerResponse
     */
    private static function writeResponse(?string $writtenRows): CurlerResponse
    {
        return self::response(
            200,
            '',
            $writtenRows === null ? [] : ['X-ClickHouse-Summary' => '{"read_rows":"0","written_rows":"' . $writtenRows . '"}']
        );
    }

    /**
     * @param CurlerRequest $request
     * @return string
     */
    private static function sentSql(CurlerRequest $request): string
    {
        return (string) $request->getRequestExtendedInfo('sql');
    }

    // datetime_precision

    public function test_datetime_precision_is_second_when_the_option_is_left_out_or_null(): void
    {
        $this->assertSame(DateTimePrecision::SECOND, $this->connection()->getDateTimePrecision());
        $this->assertSame(DateTimePrecision::SECOND, $this->connection(['datetime_precision' => null])->getDateTimePrecision());
    }

    /**
     * A blank value is what an empty CLICKHOUSE_DATETIME_PRECISION environment variable gives.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function validDateTimePrecisions(): array
    {
        return [
            'second' => ['second', 'second'],
            'microsecond' => ['microsecond', 'microsecond'],
            'blank' => ['', 'second'],
            'only spaces' => [' ', 'second'],
            'upper case' => ['SECOND', 'second'],
            'another letter case' => ['Microsecond', 'microsecond'],
            'surrounding spaces' => [' microsecond ', 'microsecond'],
            'an enum instance' => [new DateTimePrecision(DateTimePrecision::MICROSECOND), 'microsecond'],
        ];
    }

    /**
     * The normalised value is what the connection's builder grammar gets, whose own rule takes only the lowercase
     * values.
     */
    #[DataProvider('validDateTimePrecisions')]
    public function test_datetime_precision_takes_second_or_microsecond_in_any_letter_case_or_an_enum_instance(
        mixed $value,
        string $expected
    ): void {
        $connection = $this->connection(['datetime_precision' => $value]);

        $this->assertSame($expected, $connection->getDateTimePrecision());
        $this->assertSame($expected, $connection->newBuilderGrammar()->getDateTimePrecision());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidDateTimePrecisions(): array
    {
        return [
            'millisecond' => ['millisecond', 'millisecond'],
            'a space inside' => ['micro second', 'micro second'],
            'an int' => [6, '6'],
            'a bool' => [true, 'true'],
            'an array' => [['second'], 'array'],
        ];
    }

    #[DataProvider('invalidDateTimePrecisions')]
    public function test_any_other_datetime_precision_throws(mixed $value, string $described): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The [datetime_precision] option of the ClickHouse connection [clickhouse] must be 'second' or"
            . " 'microsecond', [{$described}] given."
        );

        $this->connection(['datetime_precision' => $value])->getDateTimePrecision();
    }

    // insert_format

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function validInsertFormats(): array
    {
        return [
            'null' => [null, 'Values'],
            'blank' => ['  ', 'Values'],
            'Values' => ['Values', 'Values'],
            'values' => ['values', 'Values'],
            'JSONEachRow' => ['JSONEachRow', 'JSONEachRow'],
            'jsoneachrow with spaces' => [' jsoneachrow ', 'JSONEachRow'],
            'a Format instance' => [new Format(Format::JSON_EACH_ROW), 'JSONEachRow'],
        ];
    }

    #[DataProvider('validInsertFormats')]
    public function test_insert_format_takes_values_or_json_each_row_in_any_letter_case(mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->connection(['insert_format' => $value])->getInsertFormat());
    }

    public function test_insert_format_is_values_when_the_option_is_left_out(): void
    {
        $this->assertSame(Format::VALUES, $this->connection()->getInsertFormat());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidInsertFormats(): array
    {
        return [
            'CSV' => ['CSV', 'CSV'],
            'a Format instance of another format' => [new Format(Format::CSV), 'CSV'],
            'an int' => [1, '1'],
        ];
    }

    #[DataProvider('invalidInsertFormats')]
    public function test_any_other_insert_format_throws(mixed $value, string $described): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The [insert_format] option of the ClickHouse connection [clickhouse] must be 'Values' or 'JSONEachRow',"
            . " [{$described}] given."
        );

        $this->connection(['insert_format' => $value])->getInsertFormat();
    }

    // cluster_name, cluster, use_on_cluster

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function clusterNames(): array
    {
        return [
            'left out' => [null, null],
            'blank' => [' ', null],
            'not a string' => [['company_cluster'], null],
            'a name' => ['company_cluster', 'company_cluster'],
            'a name with surrounding whitespace' => [" company_cluster \t\n", 'company_cluster'],
        ];
    }

    #[DataProvider('clusterNames')]
    public function test_cluster_name_is_a_non_blank_string_or_null(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->connection(['cluster_name' => $value])->getClusterName());
    }

    public function test_cluster_nodes_are_a_non_empty_cluster_list(): void
    {
        $this->assertFalse($this->connection()->hasClusterNodes());
        $this->assertFalse($this->connection(['cluster' => []])->hasClusterNodes());
        $this->assertTrue($this->connection(['cluster' => [['host' => 'a'], ['host' => 'b']]])->hasClusterNodes());
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null}>
     */
    public static function defaultClusters(): array
    {
        return [
            'use_on_cluster left out' => [['cluster_name' => 'company_cluster'], null],
            'use_on_cluster off' => [['cluster_name' => 'company_cluster', 'use_on_cluster' => false], null],
            "use_on_cluster 'false'" => [['cluster_name' => 'company_cluster', 'use_on_cluster' => 'false'], null],
            'use_on_cluster on' => [['cluster_name' => 'company_cluster', 'use_on_cluster' => true], 'company_cluster'],
            "use_on_cluster '1'" => [['cluster_name' => 'company_cluster', 'use_on_cluster' => '1'], 'company_cluster'],
            'a cluster_name with surrounding spaces' => [
                ['cluster_name' => ' company_cluster ', 'use_on_cluster' => true],
                'company_cluster',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('defaultClusters')]
    public function test_the_default_cluster_is_the_cluster_name_while_use_on_cluster_is_on(array $config, ?string $expected): void
    {
        $this->assertSame($expected, $this->connection($config)->getDefaultCluster());
    }

    /**
     * A cluster_name with surrounding whitespace, as an environment variable can give it, is written without it, on
     * Laravel's query builder and on the package builder: ClickHouse refuses ON CLUSTER ' company_cluster ' with
     * CLUSTER_DOESNT_EXIST.
     */
    public function test_a_cluster_name_with_surrounding_spaces_is_written_without_them(): void
    {
        [$connection, $curler, $client] = $this->connectionWithCannedClient([
            'cluster_name' => ' company_cluster ',
            'use_on_cluster' => true,
            'fix_default_query_builder' => false,
        ]);

        $log = $connection->pretend(function (Connection $connection) use ($client): void {
            $connection->table('t')->where('id', 1)->delete();
            (new Builder($client))->followConnectionOptions($connection)->from('t')->where('id', 1)->delete();
        });

        $this->assertCount(2, $log);
        foreach ($log as $entry) {
            $this->assertMatchesRegularExpression("/ on cluster 'company_cluster' delete /i", $entry['query']);
        }
        $this->assertSame([], $curler->requests);
    }

    public function test_an_invalid_use_on_cluster_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The [use_on_cluster] option of the ClickHouse connection [clickhouse] must be true or false, [maybe] given.'
        );

        $this->connection(['cluster_name' => 'company_cluster', 'use_on_cluster' => 'maybe'])->getDefaultCluster();
    }

    public function test_without_on_cluster_suspends_the_default_cluster_and_nests(): void
    {
        $connection = $this->connection(['cluster_name' => 'company_cluster', 'use_on_cluster' => true]);

        $result = $connection->withoutOnCluster(function (Connection $passed) use ($connection): string {
            $this->assertSame($connection, $passed);
            $this->assertNull($connection->getDefaultCluster());

            $connection->withoutOnCluster(fn () => $this->assertNull($connection->getDefaultCluster()));
            $this->assertNull($connection->getDefaultCluster());

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertSame('company_cluster', $connection->getDefaultCluster());
    }

    public function test_without_on_cluster_restores_the_default_cluster_after_an_exception(): void
    {
        $connection = $this->connection(['cluster_name' => 'company_cluster', 'use_on_cluster' => true]);

        try {
            $connection->withoutOnCluster(function (): never {
                throw new LogicException('in the callback');
            });
            $this->fail('The exception of the callback was not rethrown.');
        } catch (LogicException $exception) {
            $this->assertSame('in the callback', $exception->getMessage());
        }

        $this->assertSame('company_cluster', $connection->getDefaultCluster());
    }

    // createWithClient()

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPackageOptions(): array
    {
        return [
            'datetime_precision' => [['datetime_precision' => 'millisecond'], '[datetime_precision]'],
            'insert_format' => [['insert_format' => 'CSV'], '[insert_format]'],
            'use_on_cluster without cluster_name' => [
                ['use_on_cluster' => true],
                'The ClickHouse connection [broken] sets use_on_cluster without a cluster_name.',
            ],
            'use_on_cluster with a blank cluster_name' => [
                ['use_on_cluster' => true, 'cluster_name' => ''],
                'sets use_on_cluster without a cluster_name.',
            ],
            'an invalid use_on_cluster' => [['use_on_cluster' => 'maybe', 'cluster_name' => 'c'], '[use_on_cluster]'],
            'a default_index_type that is an int' => [
                ['default_index_type' => 5],
                "The ClickHouse connection [broken] sets default_index_type to 5. Set it to the type of a data-skipping"
                . " index, such as 'minmax' or 'bloom_filter', or to null.",
            ],
            'a default_index_type that is a bool' => [['default_index_type' => true], 'sets default_index_type to true.'],
            'a default_index_type that is an array' => [['default_index_type' => ['minmax']], 'sets default_index_type to array.'],
        ];
    }

    /**
     * A blank default_index_type counts as not set, as the schema grammar reads it.
     *
     * @return array<string, array{mixed}>
     */
    public static function validDefaultIndexTypes(): array
    {
        return ['left out' => [null], 'blank' => [''], 'a type' => ['minmax'], 'a type with arguments' => ['bloom_filter(0.01)']];
    }

    #[DataProvider('validDefaultIndexTypes')]
    public function test_default_index_type_takes_a_string_or_null(mixed $value): void
    {
        $connection = new class (null, 'db', '', ['name' => 'clickhouse', 'default_index_type' => $value]) extends Connection {
            public function verify(): void
            {
                $this->verifyPackageOptions();
            }
        };

        $connection->verify();

        $this->addToAssertionCount(1);
    }

    /**
     * The options are checked before any node is pinged: a node on port 1 would fail with a TransportException.
     *
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidPackageOptions')]
    public function test_create_with_client_checks_the_package_options_before_connecting(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Connection::createWithClient($options + [
            'name' => 'broken',
            'host' => '127.0.0.1',
            'port' => '1',
            'database' => 'db',
            'username' => 'default',
            'password' => '',
            'timeout_connect' => 0.1,
        ]);
    }

    // newBuilderGrammar(), formatDateTime() and prepareBindings()

    public function test_new_builder_grammar_writes_values_as_the_connection_does(): void
    {
        $connection = $this->connection(['datetime_precision' => 'microsecond']);
        $grammar = $connection->newBuilderGrammar();

        $this->assertInstanceOf(Grammar::class, $grammar);
        $this->assertNotSame($grammar, $connection->newBuilderGrammar());
        $this->assertSame(DateTimePrecision::MICROSECOND, $grammar->getDateTimePrecision());
        $this->assertSame(
            "'2024-01-02 03:04:05.123456'",
            $grammar->compileLiteral(new DateTimeImmutable('2024-01-02 03:04:05.123456'))
        );
        $this->assertSame('now()', $grammar->compileLiteral(new LaravelExpression('now()')));
        $this->assertSame(DateTimePrecision::SECOND, $this->connection()->newBuilderGrammar()->getDateTimePrecision());
    }

    public function test_format_date_time_follows_the_precision_in_the_dates_own_time_zone(): void
    {
        $date = new DateTimeImmutable('2024-01-02 03:04:05.5', new DateTimeZone('Europe/Istanbul'));
        $wholeSecond = new DateTimeImmutable('2024-01-02 03:04:05');

        $this->assertSame('2024-01-02 03:04:05', $this->connection()->formatDateTime($date));
        $microsecond = $this->connection(['datetime_precision' => 'microsecond']);
        $this->assertSame('2024-01-02 03:04:05.500000', $microsecond->formatDateTime($date));
        $this->assertSame('2024-01-02 03:04:05', $microsecond->formatDateTime($wholeSecond));
    }

    public function test_prepare_bindings_formats_dates_and_bools_also_inside_arrays(): void
    {
        $date = new DateTimeImmutable('2024-01-02 03:04:05.123456');
        $bindings = ['a' => $date, 'b' => true, 'c' => [false, [$date]], 'd' => 'x'];

        $this->assertSame(
            ['a' => '2024-01-02 03:04:05', 'b' => 1, 'c' => [0, ['2024-01-02 03:04:05']], 'd' => 'x'],
            $this->connection()->prepareBindings($bindings)
        );
        $this->assertSame(
            [
                'a' => '2024-01-02 03:04:05.123456',
                'b' => 1,
                'c' => [0, ['2024-01-02 03:04:05.123456']],
                'd' => 'x',
            ],
            $this->connection(['datetime_precision' => 'microsecond'])->prepareBindings($bindings)
        );
    }

    /**
     * Enums, Stringable values, raw SQL, NaN and the rest are left to QueryGrammar::prepareQueryForClient(), which
     * writes them as ClickHouse reads them. Casting a Stringable to a string here would quote raw SQL.
     */
    public function test_prepare_bindings_leaves_every_other_value_as_it_is(): void
    {
        $expression = new Expression('now()');
        $laravelExpression = new LaravelExpression('now()');
        $stringable = Str::of('a');
        $bindings = [null, 1, 1.5, NAN, 'x', IntBackedEnumFixture::High, $stringable, $expression, $laravelExpression];

        $prepared = $this->connection()->prepareBindings($bindings);

        $this->assertSame([null, 1, 1.5], array_slice($prepared, 0, 3));
        $this->assertNan($prepared[3]);
        $this->assertSame(['x', IntBackedEnumFixture::High, $stringable, $expression, $laravelExpression], array_slice($prepared, 4));
    }

    // runBeforeExecutingCallbacks() and transactions

    public function test_run_before_executing_callbacks_calls_each_callback_with_the_query(): void
    {
        $connection = $this->connection();
        $calls = [];
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $passed) use (&$calls): void {
            $calls[] = [$query, $bindings, $passed];
        });
        $connection->beforeExecuting(function () use (&$calls): void {
            $calls[] = 'second';
        });

        $connection->runBeforeExecutingCallbacks('SELECT ?', [1]);

        $this->assertSame([['SELECT ?', [1], $connection], 'second'], $calls);
    }

    /**
     * Laravel's methods would reconnect first: without a reconnector they throw LostConnectionException.
     *
     * @return array<string, array{callable(Connection): mixed}>
     */
    public static function transactionMethods(): array
    {
        return [
            'beginTransaction' => [fn (Connection $connection) => $connection->beginTransaction()],
            'transaction' => [fn (Connection $connection) => $connection->transaction(fn () => throw new LogicException('ran'))],
            'commit' => [fn (Connection $connection) => $connection->commit()],
        ];
    }

    /**
     * @param callable(Connection): mixed $call
     */
    #[DataProvider('transactionMethods')]
    public function test_transactions_throw_a_logic_exception(callable $call): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'ClickHouse does not support transactions. In tests, use the DatabaseTruncation trait for ClickHouse'
            . ' connections and leave them out of $connectionsToTransact.'
        );

        $call($this->connection());
    }

    public function test_roll_back_does_nothing_and_the_transaction_level_is_zero(): void
    {
        $connection = $this->connection();

        $connection->rollBack();
        $connection->rollBack(0);

        $this->assertSame(0, $connection->transactionLevel());
    }

    /**
     * Laravel's selectResultSets() would fail on the missing PDO, with an Error, after the query was logged.
     */
    public function test_select_result_sets_throws_before_sending_or_logging_anything(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();
        $connection->enableQueryLog();
        $calls = [
            'outside pretend()' => fn () => $connection->selectResultSets('SELECT ?', [1]),
            'inside pretend()' => fn () => $connection->pretend(
                fn (Connection $connection) => $connection->selectResultSets('SELECT ?', [1])
            ),
        ];

        foreach ($calls as $description => $call) {
            try {
                $call();
                $this->fail("selectResultSets() did not throw {$description}.");
            } catch (LogicException $exception) {
                $this->assertSame(
                    'ClickHouse returns one result set per query, so selectResultSets() is not supported. Use select().',
                    $exception->getMessage()
                );
            }

            $this->assertSame([], $connection->getQueryLog(), "The query was logged {$description}.");
        }

        $this->assertSame([], $curler->requests);
    }

    // select(), statement(), affectingStatement(), unprepared() and cursor()

    public function test_select_writes_question_mark_bindings_into_the_sql_and_logs_the_query_as_given(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient(
            ['datetime_precision' => 'microsecond'],
            self::jsonResponse([['id' => '1']])
        );
        $connection->enableQueryLog();
        $date = new DateTimeImmutable('2024-01-02 03:04:05.123456');

        $rows = $connection->select('SELECT id FROM t WHERE d = ? AND f = ? AND s = ?', [$date, false, "it's"]);

        $this->assertSame([['id' => '1']], $rows);
        $this->assertCount(1, $curler->requests);
        $this->assertSame(
            "SELECT id FROM t WHERE d = '2024-01-02 03:04:05.123456' AND f = 0 AND s = 'it\\'s' FORMAT JSON",
            self::sentSql($curler->requests[0])
        );
        $log = $connection->getQueryLog();
        $this->assertSame('SELECT id FROM t WHERE d = ? AND f = ? AND s = ?', $log[0]['query']);
        $this->assertSame([$date, false, "it's"], $log[0]['bindings']);
    }

    public function test_select_leaves_named_bindings_to_smi2_with_the_dates_formatted(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient(
            ['datetime_precision' => 'microsecond'],
            self::jsonResponse([['id' => '1']])
        );

        $connection->select('SELECT id FROM t WHERE d = :d AND f = :f', ['d' => new DateTimeImmutable('2024-01-02 03:04:05.5'), 'f' => true]);

        $this->assertSame(
            "SELECT id FROM t WHERE d = '2024-01-02 03:04:05.500000' AND f = 1 FORMAT JSON",
            self::sentSql($curler->requests[0])
        );
    }

    /**
     * Laravel's query grammar writes "?", so the deprecated "#@?" markers of 3.0.0 are no longer turned into :0, :1:
     * a "#@?" in a string literal is sent as it is, where QueryGrammar::prepareParameters() made it ':0'.
     */
    public function test_select_sends_the_deprecated_parameter_marker_as_it_is(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient(
            [],
            self::jsonResponse([['a' => '#@?']]),
            self::jsonResponse([['a' => '#@?', 'b' => '1']])
        );

        $connection->select("SELECT '#@?' AS a");
        $connection->select("SELECT '#@?' AS a, ? AS b", [1]);

        $this->assertSame("SELECT '#@?' AS a FORMAT JSON", self::sentSql($curler->requests[0]));
        $this->assertSame("SELECT '#@?' AS a, 1 AS b FORMAT JSON", self::sentSql($curler->requests[1]));
    }

    /**
     * A FORMAT clause that select() cannot read rows from failed in 3.0.0: a format that smi2 does not know got a
     * second FORMAT (SYNTAX_ERROR), a text format threw 'Can`t find meta' after the query ran, and
     * JSONCompactEachRow returned no rows.
     *
     * The message names only the ways that read the format (ClickHouse 24.8 checked): the package query builder's
     * format() for a format of the Format enum, and smi2's getClient()->select()->rawData() for a format that smi2
     * finds in the SQL and reads. smi2 adds a second FORMAT to XML, Pretty, Values and Null, and returns null for
     * JSONCompactEachRow and JSONStringsEachRow; JSONStringsEachRow and TSVRaw are not in the Format enum.
     *
     * @return array<string, array{string, array<int, mixed>, string, string}>
     */
    public static function formatClausesThatSelectCannotRead(): array
    {
        $both = "Leave the FORMAT clause out, or read the result in %s with the package query builder, format('%s')"
            . '->get()->rawData(), or with the smi2 client, getClient()->select($sql)->rawData().';
        $builder = "Leave the FORMAT clause out, or read the result in %s with the package query builder,"
            . " format('%s')->get()->rawData().";

        return [
            'CSV' => ['SELECT 1 FORMAT CSV', [], 'CSV', sprintf($both, 'CSV', 'CSV')],
            'XML, which smi2 does not know' => ['SELECT 1 FORMAT XML', [], 'XML', sprintf($builder, 'XML', 'XML')],
            'Null in lower case with a semicolon' => ['select 1 format Null;', [], 'Null', sprintf($builder, 'Null', 'Null')],
            'JSONEachRow before SETTINGS' => [
                'SELECT 1 FORMAT JSONEachRow SETTINGS max_threads = 1',
                [],
                'JSONEachRow',
                sprintf($both, 'JSONEachRow', 'JSONEachRow'),
            ],
            'TSV after SETTINGS' => [
                "SELECT 1 SETTINGS max_threads = 1, format_csv_delimiter = ';' FORMAT TSV",
                [],
                'TSV',
                sprintf($both, 'TSV', 'TSV'),
            ],
            'JSONCompactEachRow, which smi2 reads as null' => [
                'SELECT 1 FORMAT JSONCompactEachRow',
                [],
                'JSONCompactEachRow',
                sprintf($builder, 'JSONCompactEachRow', 'JSONCompactEachRow'),
            ],
            'after a sub-query and a string' => [
                "SELECT (SELECT 'FORMAT JSON') FORMAT Pretty",
                [],
                'Pretty',
                sprintf($builder, 'Pretty', 'Pretty'),
            ],
            'with a binding' => ['SELECT ? FORMAT CSV', [1], 'CSV', sprintf($both, 'CSV', 'CSV')],
            'after a comment' => ["SELECT 1 -- FORMAT JSON\nFORMAT Values", [], 'Values', sprintf($builder, 'Values', 'Values')],
            'in lower case, with the name of the Format enum' => ['SELECT 1 FORMAT csv', [], 'csv', sprintf($both, 'csv', 'CSV')],
            'TSVRaw, which only smi2 reads' => [
                'SELECT 1 FORMAT TSVRaw',
                [],
                'TSVRaw',
                'Leave the FORMAT clause out, or read the result in TSVRaw with the smi2 client,'
                . ' getClient()->select($sql)->rawData().',
            ],
            'JSONStringsEachRow, which neither reads' => [
                'SELECT 1 FORMAT JSONStringsEachRow',
                [],
                'JSONStringsEachRow',
                'Leave the FORMAT clause out: the package cannot read a result in JSONStringsEachRow.',
            ],
            'after an IN list' => ['SELECT * FROM t WHERE x IN (1, 2) FORMAT XML', [], 'XML', sprintf($builder, 'XML', 'XML')],
            'after an alias named format' => ['SELECT 1 AS format FORMAT CSV', [], 'CSV', sprintf($both, 'CSV', 'CSV')],
            'after ORDER BY a column named format' => [
                'SELECT * FROM t ORDER BY format FORMAT Pretty',
                [],
                'Pretty',
                sprintf($builder, 'Pretty', 'Pretty'),
            ],
            'after DESC' => ['SELECT * FROM t ORDER BY x DESC FORMAT CSV', [], 'CSV', sprintf($both, 'CSV', 'CSV')],
            'after a table function' => ['SELECT * FROM numbers(2) FORMAT CSV', [], 'CSV', sprintf($both, 'CSV', 'CSV')],
        ];
    }

    /**
     * @param array<int, mixed> $bindings
     */
    #[DataProvider('formatClausesThatSelectCannotRead')]
    public function test_select_refuses_a_format_clause_that_it_cannot_read_before_sending(
        string $sql,
        array $bindings,
        string $format,
        string $advice
    ): void {
        [$connection, $curler] = $this->connectionWithCannedClient();
        $connection->enableQueryLog();

        try {
            $connection->select($sql, $bindings);
            $this->fail('The FORMAT clause was not refused.');
        } catch (QueryException $exception) {
            $this->assertSame(
                "Cannot read rows from a query whose FORMAT clause names {$format}: select() and parallel queries"
                . ' read the rows of a JSON result (JSON, JSONStrings, JSONCompact, JSONCompactStrings). '
                . $advice,
                $exception->getMessage()
            );
            $this->assertInstanceOf(ClientQueryException::class, $exception);
        }

        $this->assertSame([], $curler->requests);
        $this->assertSame([], $connection->getQueryLog());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function queriesWithoutAFormatClauseThatSelectCannotRead(): array
    {
        return [
            'JSON' => ['SELECT 1 AS x FORMAT JSON'],
            'JSONCompact in lower case' => ['SELECT 1 AS x format jsoncompact'],
            'JSONStrings before SETTINGS' => ['SELECT 1 AS x FORMAT JSONStrings SETTINGS max_threads = 1'],
            'JSON after SETTINGS' => ['SELECT 1 AS x SETTINGS max_threads = 1 FORMAT JSON'],
            'a string literal' => ["SELECT 'x FORMAT CSV' AS x"],
            'a quoted name' => ['SELECT 1 AS `FORMAT CSV`'],
            'a heredoc' => ['SELECT $$FORMAT CSV$$ AS x'],
            'a -- comment' => ['SELECT 1 AS x -- FORMAT CSV'],
            'a // comment' => ['SELECT 1 AS x // FORMAT CSV'],
            'a # comment' => ['SELECT 1 AS x # FORMAT CSV'],
            'a nested block comment' => ['SELECT 1 AS x /* a /* b */ FORMAT CSV */'],
            'a sub-query' => ['SELECT * FROM (SELECT 1 AS x FORMAT CSV)'],
            'a column named format' => ['SELECT format FROM t'],
            'an alias named format' => ['SELECT 1 AS format FROM t SETTINGS max_threads = 1'],
            'the format table function' => ["SELECT * FROM format(CSV, '1')"],
            'a function whose name starts with format' => ["SELECT formatDateTime(now(), '%Y') AS x"],
            'a column named format in an IN condition' => ["SELECT id FROM t WHERE format IN ('csv')"],
            'a column named format in IN after AND' => ["SELECT id FROM t WHERE id > 0 AND format IN ('xml')"],
            'a column named format in LIKE' => ["SELECT id FROM t WHERE format LIKE 'c%'"],
            'a column named format in ILIKE' => ["SELECT id FROM t WHERE format ILIKE 'c%'"],
            'a column named format in REGEXP' => ["SELECT id FROM t WHERE format REGEXP '^c'"],
            'a column named format in IN with a table' => ['SELECT id FROM t WHERE format IN allowed'],
            'ORDER BY a column named format DESC' => ['SELECT id FROM t ORDER BY format DESC'],
            'ORDER BY a qualified column named format DESC' => ['SELECT f.id FROM t AS f ORDER BY f.format DESC'],
            'ORDER BY a column named format ASC before SETTINGS' => [
                'SELECT id FROM t WHERE id > 0 ORDER BY id ASC, format ASC SETTINGS max_threads = 1',
            ],
            'ORDER BY a column named format in an expression' => ['SELECT id FROM t ORDER BY 1 - format DESC'],
            'ORDER BY a column named format with a collation' => ["SELECT id FROM t ORDER BY format COLLATE 'en'"],
            'a table named format with an alias' => ['SELECT id FROM format f'],
            'a qualified table named format with an alias' => ['SELECT f.id FROM db.format f'],
            'a table named format after a comma, with an alias' => ['SELECT * FROM t, format f'],
            'a table named format with FINAL' => ['SELECT id FROM format FINAL'],
            'an alias named format with FINAL' => ['SELECT id FROM t AS format FINAL'],
            'a table named format with USING' => ['SELECT t.id FROM t JOIN format USING (id)'],
            'a table named format with ON' => ['SELECT t.id FROM t JOIN format ON (t.id = format.id)'],
            'a boolean column named format before AND' => ['SELECT id FROM t WHERE format AND (id > 0)'],
        ];
    }

    #[DataProvider('queriesWithoutAFormatClauseThatSelectCannotRead')]
    public function test_select_sends_a_query_without_a_format_clause_that_it_cannot_read(string $sql): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient([], self::jsonResponse([['x' => '1']]));

        $connection->select($sql);

        $this->assertCount(1, $curler->requests);
    }

    public function test_select_reads_a_literal_that_names_a_format_as_json(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient([], self::jsonResponse([['note' => 'format csv']]));

        $rows = $connection->select("SELECT note FROM t WHERE note = 'format csv'");

        $this->assertSame([['note' => 'format csv']], $rows);
        $this->assertSame("SELECT note FROM t WHERE note = 'format csv'", self::sentSql($curler->requests[0]));
        $this->assertStringContainsString('default_format=JSON', $curler->requests[0]->getUrl());
    }

    public function test_select_refuses_a_placeholder_count_mismatch_before_sending(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();

        try {
            $connection->select('SELECT ? AS a', [1, 2]);
            $this->fail('The mismatch was not refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('The query has 1 "?" placeholder, but 2 bindings were given.', $exception->getMessage());
        }

        $this->assertSame([], $curler->requests);
    }

    public function test_statement_and_insert_write_the_bindings_and_return_true(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();

        $this->assertTrue($connection->insert('INSERT INTO t (a, b, c) VALUES (?, ?, ?)', [1, 19.99, new DateTimeImmutable('2024-01-02 03:04:05')]));
        $this->assertTrue($connection->statement('ALTER TABLE t DELETE WHERE a = ?', [1]));

        $this->assertSame(
            "INSERT INTO t (a, b, c) VALUES (1, 19.99, '2024-01-02 03:04:05')",
            self::sentSql($curler->requests[0])
        );
        $this->assertSame('ALTER TABLE t DELETE WHERE a = 1', self::sentSql($curler->requests[1]));
        $this->assertTrue($connection->hasModifiedRecords());
    }

    /**
     * @return array<string, array{string, string|null, int}>
     */
    public static function affectedRows(): array
    {
        return [
            'an INSERT returns written_rows' => ['INSERT INTO t VALUES (1), (2)', '5', 5],
            'an INSERT that wrote nothing returns 0' => ['insert into t select 1 where 0', '0', 0],
            'an INSERT without a summary returns 1' => ['INSERT INTO t VALUES (1)', null, 1],
            'an INSERT after a block comment' => ["/* c */ INSERT INTO t VALUES (1)", '3', 3],
            'an INSERT after a -- comment' => ["-- c\n  insert into t values (1)", '3', 3],
            'an INSERT after a // comment' => ["// c\ninsert into t values (1)", '3', 3],
            'an INSERT after a # comment' => ["# c\ninsert into t values (1)", '3', 3],
            'an INSERT after an empty # comment' => ["#\ninsert into t values (1)", '3', 3],
            'a mutation returns 1' => ['ALTER TABLE t DELETE WHERE a = 1', '0', 1],
            'a lightweight delete returns 1' => ['DELETE FROM t WHERE a = 1', '0', 1],
            'DDL returns 1' => ['CREATE TABLE t2 (a UInt8) ENGINE = Memory', null, 1],
            'a word that starts with insert is no INSERT' => ['inserted', '5', 1],
            'WITH before INSERT is no INSERT' => ['WITH 1 AS x INSERT INTO t VALUES (1)', '5', 1],
        ];
    }

    #[DataProvider('affectedRows')]
    public function test_affecting_statement_counts_the_rows_an_insert_wrote(string $sql, ?string $writtenRows, int $expected): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient([], self::writeResponse($writtenRows));

        $this->assertSame($expected, $connection->affectingStatement($sql));
        $this->assertCount(1, $curler->requests);
        $this->assertSame($expected > 0, $connection->hasModifiedRecords());
    }

    public function test_update_and_delete_return_the_count_of_affecting_statement(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient([], self::writeResponse('0'), self::writeResponse('7'));

        $this->assertSame(1, $connection->update('ALTER TABLE t UPDATE a = ? WHERE b = ?', [1, 2]));
        $this->assertSame(7, $connection->affectingStatement('INSERT INTO t SELECT ?', [1]));
        $this->assertSame('ALTER TABLE t UPDATE a = 1 WHERE b = 2', self::sentSql($curler->requests[0]));
        $this->assertSame('INSERT INTO t SELECT 1', self::sentSql($curler->requests[1]));
    }

    /**
     * The rows after the FORMAT clause of an INSERT are data, not SQL: a "??" in them is no escaped "?". 3.0.0 sent
     * such an INSERT as it was.
     *
     * @return array<string, array{callable(Connection, string): mixed, string}>
     */
    public static function insertsWithoutBindings(): array
    {
        return [
            'TSV through insert()' => [
                fn (Connection $connection, string $sql) => $connection->insert($sql),
                "INSERT INTO t FORMAT TSV\n1\twhat??\n2\tit's ?? a\n",
            ],
            'CSV through statement()' => [
                fn (Connection $connection, string $sql) => $connection->statement($sql),
                "insert into t format CSV\n3,why??\n4,\"?? \"\"q\"\"\"\n",
            ],
            'CustomSeparated after a comment through affectingStatement()' => [
                fn (Connection $connection, string $sql) => $connection->affectingStatement($sql),
                "-- rows\nINSERT INTO t SETTINGS format_custom_field_delimiter = ';' FORMAT CustomSeparated\n5;a??\n",
            ],
            'VALUES with a quoted "??"' => [
                fn (Connection $connection, string $sql) => $connection->insert($sql),
                "INSERT INTO t VALUES (6, 'ok??')",
            ],
        ];
    }

    /**
     * @param callable(Connection, string): mixed $run
     */
    #[DataProvider('insertsWithoutBindings')]
    public function test_an_insert_without_bindings_is_sent_as_it_is(callable $run, string $sql): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient([], self::writeResponse('1'));
        $connection->enableQueryLog();

        $run($connection, $sql);

        $this->assertSame($sql, self::sentSql($curler->requests[0]));
        $this->assertSame([$sql], array_column($connection->getQueryLog(), 'query'));
    }

    public function test_an_insert_with_bindings_writes_them_and_each_double_question_mark_as_one(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();

        $connection->insert('INSERT INTO t SELECT ? > 0 ?? ? : 2', [1, 'a']);

        $this->assertSame("INSERT INTO t SELECT 1 > 0 ? 'a' : 2", self::sentSql($curler->requests[0]));
    }

    public function test_statement_on_every_node_sends_an_insert_without_bindings_as_it_is(): void
    {
        $sql = "INSERT INTO t FORMAT TSV\n1\twhat??\n";
        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())->method('write')->with($sql, []);
        $connection = $this->connectionWithCluster($cluster);

        $this->assertTrue($connection->statementOnEveryNode($sql));
    }

    public function test_unprepared_sends_the_sql_as_it_is(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();
        $connection->enableQueryLog();

        $this->assertTrue($connection->unprepared("SELECT '?' ?? :0"));

        $this->assertSame("SELECT '?' ?? :0", self::sentSql($curler->requests[0]));
        $this->assertSame("SELECT '?' ?? :0", $connection->getQueryLog()[0]['query']);
    }

    public function test_cursor_yields_the_rows_of_a_json_each_row_result_once_it_is_iterated(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient(
            [],
            self::response(200, "{\"n\":\"1\"}\n{\"n\":\"2\"}\n", ['X-ClickHouse-Format' => 'JSONEachRow'], 'application/x-ndjson; charset=UTF-8')
        );

        $cursor = $connection->cursor('SELECT number AS n FROM numbers(?) WHERE number > ?', [3, 0]);
        $this->assertSame([], $curler->requests);

        $this->assertSame([['n' => '1'], ['n' => '2']], iterator_to_array($cursor));
        $this->assertSame(
            "SELECT number AS n FROM numbers(3) WHERE number > 0\nFORMAT JSONEachRow",
            self::sentSql($curler->requests[0])
        );
    }

    // pretend()

    public function test_nothing_reaches_the_cluster_while_pretending(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->never())->method('getActiveNode');
        $cluster->expects($this->never())->method('write');
        $connection = $this->connectionWithCluster($cluster);

        $results = [];
        $log = $connection->pretend(function (Connection $connection) use (&$results): void {
            $results[] = $connection->select('SELECT * FROM t WHERE id = ?', [1]);
            $results[] = $connection->statement('CREATE TABLE t2 (id UInt8) ENGINE = Memory');
            $results[] = $connection->insert('INSERT INTO t (id) VALUES (?)', [2]);
            $results[] = $connection->affectingStatement('ALTER TABLE t DELETE WHERE id = ?', [3]);
            $results[] = $connection->update('ALTER TABLE t UPDATE s = ? WHERE id = ?', ['x', 4]);
            $results[] = $connection->delete('DELETE FROM t WHERE id = ?', [5]);
            $results[] = $connection->unprepared('DROP TABLE t');
            $results[] = $connection->statementOnEveryNode('DROP TABLE t2');
            $results[] = iterator_to_array($connection->cursor('SELECT 1'));
        });

        $this->assertSame([[], true, true, 0, 0, 0, true, true, []], $results);
        $this->assertSame(
            [
                'SELECT * FROM t WHERE id = 1',
                'CREATE TABLE t2 (id UInt8) ENGINE = Memory',
                'INSERT INTO t (id) VALUES (2)',
                'ALTER TABLE t DELETE WHERE id = 3',
                "ALTER TABLE t UPDATE s = 'x' WHERE id = 4",
                'DELETE FROM t WHERE id = 5',
                'DROP TABLE t',
                'DROP TABLE t2',
                'SELECT 1',
            ],
            array_column($log, 'query')
        );
        $this->assertFalse($connection->hasModifiedRecords());
    }

    // statementOnEveryNode()

    public function test_statement_on_every_node_writes_through_the_cluster_and_logs_once(): void
    {
        $cluster = $this->createMock(Cluster::class);
        $cluster->expects($this->once())->method('write')->with('DROP TABLE IF EXISTS `t` ?', []);
        $cluster->expects($this->never())->method('getActiveNode');
        $connection = $this->connectionWithCluster($cluster);
        $connection->enableQueryLog();

        $this->assertTrue($connection->statementOnEveryNode('DROP TABLE IF EXISTS `t` ??'));

        $this->assertSame(['DROP TABLE IF EXISTS `t` ??'], array_column($connection->getQueryLog(), 'query'));
        $this->assertTrue($connection->hasModifiedRecords());
    }

    // inSession() and hasTemporaryTable()

    public function test_in_session_follows_the_session_id_of_the_client(): void
    {
        [$connection, , $client] = $this->connectionWithCannedClient();

        $this->assertFalse($connection->inSession());
        $client->useSession('s1');
        $this->assertTrue($connection->inSession());
        $client->settings()->set('session_id', null);
        $this->assertFalse($connection->inSession());
    }

    public function test_has_temporary_table_sends_nothing_outside_a_session(): void
    {
        [$connection, $curler] = $this->connectionWithCannedClient();

        $this->assertFalse($connection->hasTemporaryTable('tmp'));
        $this->assertSame([], $curler->requests);
    }

    public function test_has_temporary_table_asks_the_session(): void
    {
        [$connection, $curler, $client] = $this->connectionWithCannedClient(
            [],
            self::jsonResponse([['result' => 1]]),
            self::jsonResponse([['result' => 0]])
        );
        $client->useSession('s1');

        $this->assertTrue($connection->hasTemporaryTable('we`ird'));
        $this->assertFalse($connection->hasTemporaryTable('tmp'));

        $this->assertSame('EXISTS TEMPORARY TABLE `we``ird` FORMAT JSON', self::sentSql($curler->requests[0]));
        $this->assertSame('EXISTS TEMPORARY TABLE `tmp` FORMAT JSON', self::sentSql($curler->requests[1]));
    }

    // query() and Builder::followConnectionOptions()

    public function test_query_returns_the_package_builder_that_follows_the_connection(): void
    {
        [$connection] = $this->connectionWithCannedClient([
            'fix_default_query_builder' => true,
            'datetime_precision' => 'microsecond',
        ]);
        $date = new DateTimeImmutable('2024-01-02 03:04:05.123456');

        $query = $connection->query();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame(
            "SELECT * FROM `t` WHERE `d` = '2024-01-02 03:04:05.123456'",
            $query->from('t')->where('d', $date)->toSql()
        );
        $this->assertSame(
            "SELECT * FROM `t` WHERE (`d` = '2024-01-02 03:04:05.123456') AND `id` IN (SELECT `id` FROM `r` WHERE `d` < '2024-01-02 03:04:05.123456')",
            $connection->table('t')
                ->where(fn (Builder $nested) => $nested->where('d', $date))
                ->whereIn('id', fn (Builder $sub) => $sub->select('id')->from('r')->where('d', '<', $date))
                ->toSql()
        );
        $this->assertSame(
            "SELECT * FROM `r` WHERE `d` = '2024-01-02 03:04:05.123456'",
            $query->newQuery()->from('r')->where('d', $date)->toSql()
        );
    }

    public function test_a_builder_that_follows_no_connection_writes_seconds(): void
    {
        [, , $client] = $this->connectionWithCannedClient();

        $query = (new Builder($client))->from('t')->where('d', new DateTimeImmutable('2024-01-02 03:04:05.123456'));

        $this->assertSame("SELECT * FROM `t` WHERE `d` = '2024-01-02 03:04:05'", $query->toSql());
        $this->assertSame(
            "SELECT * FROM `r` WHERE `d` = '2024-01-02 03:04:05'",
            $query->newQuery()->from('r')->where('d', new DateTimeImmutable('2024-01-02 03:04:05.123456'))->toSql()
        );
    }

    public function test_follow_connection_options_refuses_an_invalid_precision(): void
    {
        [, , $client] = $this->connectionWithCannedClient();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[datetime_precision]');

        (new Builder($client))->followConnectionOptions($this->connection(['datetime_precision' => 'nanosecond']));
    }

    public function test_query_returns_laravels_builder_subclass_without_fix_default_query_builder(): void
    {
        $connection = $this->connection(['fix_default_query_builder' => false]);

        $query = $connection->query();

        $this->assertSame(QueryBuilder::class, get_class($query));
        $this->assertSame($connection, $query->getConnection());
        $this->assertSame($connection->getQueryGrammar(), $query->getGrammar());
        $this->assertSame($connection->getPostProcessor(), $query->getProcessor());
    }
}
