<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Query\Degeneration\Bindings;
use ClickHouseDB\Query\Query;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\Http;
use Closure;
use RuntimeException;

/**
 * A smi2 client for unit tests whose requests get canned responses, so no server is needed.
 *
 * Its transport builds every read and write request as smi2 builds it, with the same URL and extended info, as a
 * request that keeps a canned response: when a curler runs the request, the request goes to 127.0.0.1:1, which
 * refuses it at once, and the response that the curler sets is replaced with the next canned response. So the
 * synchronous curler, CurlerRollingInSession and the batch curler of Parallel all run the request as usual, and
 * each attempt is counted. A request that a curler runs more than MAX_ATTEMPTS times throws a RuntimeException, so
 * a test whose request would be run again and again fails at once instead of hanging: smi2's CurlerRolling keeps a
 * request with a session id in its queue and runs it again and again, so its loop never ends.
 *
 * The responder gets the SQL of each request and the request, and returns its response, or a list of responses for
 * its attempts in turn (the last one is kept for any later attempt). Without a responder, every request gets a JSON
 * result with one row, ['x' => '1'].
 */
final class CannedAsyncClient extends Client
{
    /**
     * How often a curler may run one request, far more than the retries of any test.
     */
    public const MAX_ATTEMPTS = 10;

    /**
     * Every request that the transport built, in order.
     *
     * @var list<CurlerRequest>
     */
    public array $requests = [];

    private Http $cannedTransport;

    /**
     * @var Closure(string, CurlerRequest): (CurlerResponse|array<int, CurlerResponse>)
     */
    private Closure $responder;

    /**
     * @param (Closure(string, CurlerRequest): (CurlerResponse|array<int, CurlerResponse>))|null $responder
     */
    public function __construct(?Closure $responder = null)
    {
        $this->responder = $responder ?? fn (): CurlerResponse => self::json([['x' => '1']]);
        $this->cannedTransport = new class ('127.0.0.1', 1, 'default', '', $this->cannedRequest(...)) extends Http {
            /**
             * @param Closure(CurlerRequest): CurlerRequest $canned
             */
            public function __construct(string $host, int $port, string $username, string $password, private Closure $canned)
            {
                parent::__construct($host, $port, $username, $password);
            }

            /**
             * @param array<string, mixed> $querySettings
             */
            public function getRequestRead(Query $query, $whereInFile = null, $writeToFile = null, array $querySettings = []): CurlerRequest
            {
                return ($this->canned)(parent::getRequestRead($query, $whereInFile, $writeToFile, $querySettings));
            }

            /**
             * @param array<string, mixed> $querySettings
             */
            public function getRequestWrite(Query $query, array $querySettings = []): CurlerRequest
            {
                return ($this->canned)(parent::getRequestWrite($query, $querySettings));
            }
        };

        parent::__construct(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $this->cannedTransport->addQueryDegeneration(new Bindings());
    }

    public function transport(): Http
    {
        return $this->cannedTransport;
    }

    /**
     * The SQL of each request, in order.
     *
     * @return list<string>
     */
    public function sentSql(): array
    {
        return array_map(fn (CurlerRequest $request): string => (string) $request->getRequestExtendedInfo('sql'), $this->requests);
    }

    /**
     * How often a curler ran a request.
     *
     * @param CurlerRequest $request
     * @return int
     */
    public static function attemptsOf(CurlerRequest $request): int
    {
        return property_exists($request, 'attempts') ? $request->attempts : 0;
    }

    /**
     * A JSON result, as ClickHouse sends it for FORMAT JSON or default_format=JSON.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param float $totalTime The seconds that curl reports for the request
     * @return CurlerResponse
     */
    public static function json(array $rows, float $totalTime = 0.0): CurlerResponse
    {
        $meta = array_map(
            fn (string $name): array => ['name' => $name, 'type' => 'String'],
            array_keys($rows[0] ?? ['x' => '1'])
        );

        return self::response(
            200,
            (string) json_encode(['meta' => $meta, 'data' => $rows, 'rows' => count($rows)]),
            ['X-ClickHouse-Format' => 'JSON'],
            'application/json; charset=UTF-8',
            $totalTime
        );
    }

    /**
     * A ClickHouse error, as the server sends it: Code: <code>. DB::Exception: <message>. (<NAME>) (version ...).
     *
     * @param int $httpCode
     * @param int $code
     * @param string $message
     * @param string $name
     * @param float $totalTime
     * @return CurlerResponse
     */
    public static function error(int $httpCode, int $code, string $message, string $name, float $totalTime = 0.0): CurlerResponse
    {
        return self::response(
            $httpCode,
            "Code: {$code}. DB::Exception: {$message}. ({$name}) (version 24.8.14.39 (official build))\n",
            ['X-ClickHouse-Exception-Code' => (string) $code],
            'text/plain; charset=UTF-8',
            $totalTime
        );
    }

    /**
     * A response that never reached the server: curl could not connect (error 7, http_code 0, no connect time).
     *
     * @return CurlerResponse
     */
    public static function unreachable(): CurlerResponse
    {
        $response = self::response(0, '', [], null, 0.0);
        $response->_errorNo = 7;
        $response->_error = 'Failed to connect to 127.0.0.1 port 1: Connection refused';

        return $response;
    }

    /**
     * @param int $httpCode
     * @param string $body
     * @param array<string, string> $headers
     * @param string|null $contentType
     * @param float $totalTime
     * @return CurlerResponse
     */
    public static function response(
        int $httpCode,
        string $body,
        array $headers = [],
        ?string $contentType = 'text/plain; charset=UTF-8',
        float $totalTime = 0.0
    ): CurlerResponse {
        $response = new CurlerResponse();
        $response->_info = [
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'total_time' => $totalTime,
            'connect_time' => $httpCode === 0 ? 0.0 : 0.001,
            'size_upload' => 0,
        ];
        $response->_headers = $headers;
        $response->_body = $body;

        return $response;
    }

    /**
     * Turn a request that smi2 built into one that keeps its canned responses, and record it.
     *
     * @param CurlerRequest $built
     * @return CurlerRequest
     */
    private function cannedRequest(CurlerRequest $built): CurlerRequest
    {
        $sql = (string) $built->getRequestExtendedInfo('sql');
        $responses = ($this->responder)($sql, $built);
        $request = new class (is_array($responses) ? array_values($responses) : [$responses], $sql) extends CurlerRequest {
            public int $attempts = 0;

            /**
             * @param list<CurlerResponse> $responses
             * @param string $sql
             */
            public function __construct(private array $responses, private string $sql)
            {
                parent::__construct();
            }

            /**
             * @param CurlerResponse $response
             * @return void
             * @throws RuntimeException When a curler runs the request more than CannedAsyncClient::MAX_ATTEMPTS times
             */
            public function setResponse(CurlerResponse $response): void
            {
                $this->attempts++;
                if ($this->attempts > CannedAsyncClient::MAX_ATTEMPTS) {
                    throw new RuntimeException(sprintf(
                        'A curler ran the request [%s] %d times, more than the %d that a test allows. smi2\'s'
                        . ' CurlerRolling runs a request with a session id again and again, and its execLoopWait()'
                        . ' never returns.',
                        $this->sql,
                        $this->attempts,
                        CannedAsyncClient::MAX_ATTEMPTS
                    ));
                }

                parent::setResponse(count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0]);
            }
        };

        $request->url($built->getUrl())->verbose(false);
        $request->setRequestExtendedInfo($built->getRequestExtendedInfo());
        if ($built->isPersistent()) {
            $request->persistent();
        }
        $this->requests[] = $request;

        return $request;
    }
}
