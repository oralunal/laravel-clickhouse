<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Cluster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The timeouts that Cluster::createClient() gives the client of a node, read back from the curl options and the
 * URL of the requests that the client builds. Nothing is sent.
 */
class ClusterTimeoutTest extends TestCase
{
    /**
     * Timeout options, and the connect timeout (ms), the HTTP timeout (ms) and max_execution_time (s) they give.
     *
     * @return array<string, array{mixed, mixed, int, int, int}>
     */
    public static function timeouts(): array
    {
        return [
            'whole seconds as strings' => ['2', '2', 2000, 2000, 2],
            'whole seconds as integers' => [3, 10, 3000, 10000, 10],
            'half a second' => [0.5, 0.5, 500, 1000, 1],
            'fractions as strings' => ['1.5', '1.5', 1500, 2000, 2],
            'a tiny query timeout is one second' => [2, 0.001, 2000, 1000, 1],
            'a tiny connect timeout is one millisecond' => [0.0001, 2, 1, 2000, 2],
            'zero query is no limit and zero connect is curls default' => [0, 0, 0, 0, 0],
            'zero as a string' => ['0', '0.0', 0, 0, 0],
            'a blank string is zero' => ['', ' ', 0, 0, 0],
            'null is zero' => [null, null, 0, 0, 0],
            'numeric strings with spaces and exponents' => [' 1.25 ', '1e1', 1250, 10000, 10],
        ];
    }

    #[DataProvider('timeouts')]
    public function test_timeouts_become_curl_options_and_max_execution_time(
        mixed $connect,
        mixed $query,
        int $connectTimeoutMs,
        int $timeoutMs,
        int $maxExecutionTime
    ): void {
        $client = $this->createClient(['timeout_connect' => $connect, 'timeout_query' => $query]);

        $this->assertSame(
            ['connect_ms' => $connectTimeoutMs, 'timeout_ms' => $timeoutMs, 'max_execution_time' => $maxExecutionTime],
            $this->timeoutsOf($client->transport()->writeStreamData('SELECT 1'))
        );
    }

    public function test_missing_options_mean_two_seconds(): void
    {
        $client = $this->createClient([]);

        $this->assertSame(
            ['connect_ms' => 2000, 'timeout_ms' => 2000, 'max_execution_time' => 2],
            $this->timeoutsOf($client->transport()->writeStreamData('SELECT 1'))
        );
    }

    public function test_the_failover_ping_uses_the_same_timeouts(): void
    {
        $client = $this->createClient(['timeout_connect' => 0.5, 'timeout_query' => 1.5]);
        $curler = new class extends CurlerRolling {
            public ?CurlerRequest $request = null;

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->request = $request;
                $response = new CurlerResponse();
                $response->_info = ['http_code' => 200];
                $response->_body = "Ok.\n";
                $request->setResponse($response);

                return 200;
            }
        };
        $client->transport()->setDirtyCurler($curler);

        $this->assertTrue($client->ping(true));
        $options = (new ReflectionProperty(CurlerRequest::class, 'options'))->getValue($curler->request);
        $this->assertSame(500, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertSame(2000, $options[CURLOPT_TIMEOUT_MS]);
    }

    public function test_select_and_write_requests_use_the_timeouts_too(): void
    {
        $client = $this->createClient(['timeout_connect' => 0.25, 'timeout_query' => 0.5]);
        $curler = new class extends CurlerRolling {
            /** @var array<int, CurlerRequest> */
            public array $requests = [];

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->requests[] = $request;
                $response = new CurlerResponse();
                $response->_info = ['http_code' => 200, 'content_type' => 'text/plain'];
                $request->setResponse($response);

                return 200;
            }
        };
        $client->transport()->setDirtyCurler($curler);

        $client->select('SELECT 1');
        $client->write('SELECT 1');

        $this->assertCount(2, $curler->requests);
        foreach ($curler->requests as $request) {
            $this->assertSame(
                ['connect_ms' => 250, 'timeout_ms' => 1000, 'max_execution_time' => 1],
                $this->timeoutsOf($request)
            );
        }
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidTimeouts(): array
    {
        return [
            'a word' => ['timeout_query', 'abc', 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [abc] given.'],
            'a negative integer' => ['timeout_query', -1, 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [-1] given.'],
            'a negative string' => ['timeout_connect', '-0.5', 'The [timeout_connect] option of the ClickHouse connection must be a number of seconds, [-0.5] given.'],
            'a negative float' => ['timeout_connect', -0.5, 'The [timeout_connect] option of the ClickHouse connection must be a number of seconds, [-0.5] given.'],
            'infinity' => ['timeout_query', INF, 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [INF] given.'],
            'not a number' => ['timeout_query', NAN, 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [NAN] given.'],
            'an overflowing string' => ['timeout_query', '1e400', 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [1e400] given.'],
            'a hexadecimal string' => ['timeout_connect', '0x10', 'The [timeout_connect] option of the ClickHouse connection must be a number of seconds, [0x10] given.'],
            'a boolean' => ['timeout_query', true, 'The [timeout_query] option of the ClickHouse connection must be a number of seconds, [true] given.'],
            'an array' => ['timeout_connect', [2], 'The [timeout_connect] option of the ClickHouse connection must be a number of seconds, [array] given.'],
        ];
    }

    #[DataProvider('invalidTimeouts')]
    public function test_an_invalid_timeout_throws_before_the_client_is_created(string $key, mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->createClient([$key => $value]);
    }

    /**
     * Create a node's client as the Cluster does, for a host that nothing listens on.
     *
     * @param array<string, mixed> $options
     * @return Client
     */
    private function createClient(array $options): Client
    {
        $config = $options + [
            'host' => '127.0.0.1',
            'port' => '1',
            'username' => 'default',
            'password' => '',
            'database' => 'default',
            'settings' => [],
        ];

        return (new ReflectionMethod(Cluster::class, 'createClient'))->invoke(null, $config);
    }

    /**
     * @param CurlerRequest $request
     * @return array{connect_ms: int|null, timeout_ms: int|null, max_execution_time: int|null}
     */
    private function timeoutsOf(CurlerRequest $request): array
    {
        $options = (new ReflectionProperty(CurlerRequest::class, 'options'))->getValue($request);
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

        return [
            'connect_ms' => $options[CURLOPT_CONNECTTIMEOUT_MS] ?? null,
            'timeout_ms' => $options[CURLOPT_TIMEOUT_MS] ?? null,
            'max_execution_time' => isset($query['max_execution_time']) ? (int) $query['max_execution_time'] : null,
        ];
    }
}
