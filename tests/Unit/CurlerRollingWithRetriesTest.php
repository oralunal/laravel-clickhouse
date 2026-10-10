<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\CurlerRollingWithRetries;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The retry loop of CurlerRollingWithRetries and its retry_on policy.
 *
 * Requests to 127.0.0.1:1 are refused at once, so they never reach a server. The other attempts get canned
 * responses, so nothing is sent.
 */
class CurlerRollingWithRetriesTest extends TestCase
{
    public function test_the_policy_is_any_by_default(): void
    {
        $this->assertSame(CurlerRollingWithRetries::RETRY_ON_ANY, (new CurlerRollingWithRetries())->getRetryOn());
    }

    public function test_set_retry_on_takes_unsent_and_any(): void
    {
        $curler = new CurlerRollingWithRetries();

        $curler->setRetryOn('unsent');
        $this->assertSame(CurlerRollingWithRetries::RETRY_ON_UNSENT, $curler->getRetryOn());

        $curler->setRetryOn('any');
        $this->assertSame(CurlerRollingWithRetries::RETRY_ON_ANY, $curler->getRetryOn());
    }

    public function test_set_retry_on_refuses_other_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The retry policy must be 'any' or 'unsent', [bogus] given.");

        (new CurlerRollingWithRetries())->setRetryOn('bogus');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function policies(): array
    {
        return [
            'any' => [CurlerRollingWithRetries::RETRY_ON_ANY],
            'unsent' => [CurlerRollingWithRetries::RETRY_ON_UNSENT],
        ];
    }

    #[DataProvider('policies')]
    public function test_a_refused_connection_is_sent_again_under_both_policies(string $policy): void
    {
        $curler = $this->countingCurler(retries: 2, policy: $policy);

        $httpCode = $curler->execOne($this->requestToAClosedPort());

        $this->assertSame(0, $httpCode);
        $this->assertSame(3, $curler->attempts, 'A request that never reached the server makes 1 + retries attempts.');
    }

    /**
     * Response info of an attempt that may have reached the server, and how often it is sent under each policy.
     *
     * @return array<string, array{array<string, int|float>, int, int}>
     */
    public static function failuresThatReachedTheServer(): array
    {
        return [
            'timed out after connecting' => [['http_code' => 0, 'connect_time' => 0.00018, 'size_upload' => 0], 3, 1],
            'timed out after sending the body' => [['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 47], 3, 1],
            'a server error' => [['http_code' => 500, 'connect_time' => 0.001, 'size_upload' => 47], 3, 1],
            'an answer without connect time' => [['http_code' => 503, 'connect_time' => 0.0, 'size_upload' => 0], 3, 1],
            'never reached the server' => [['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 0], 3, 3],
            'no info at all' => [[], 3, 3],
        ];
    }

    /**
     * @param array<string, int|float> $info
     */
    #[DataProvider('failuresThatReachedTheServer')]
    public function test_unsent_sends_again_only_what_never_reached_the_server(
        array $info,
        int $attemptsUnderAny,
        int $attemptsUnderUnsent
    ): void {
        $any = $this->cannedCurler([$info, $info, $info], retries: 2, policy: CurlerRollingWithRetries::RETRY_ON_ANY);
        $any->execOne(new CurlerRequest());
        $this->assertSame($attemptsUnderAny, $any->attempts, 'any');

        $unsent = $this->cannedCurler([$info, $info, $info], retries: 2, policy: CurlerRollingWithRetries::RETRY_ON_UNSENT);
        $unsent->execOne(new CurlerRequest());
        $this->assertSame($attemptsUnderUnsent, $unsent->attempts, 'unsent');
    }

    #[DataProvider('policies')]
    public function test_a_success_stops_the_loop(string $policy): void
    {
        $refused = ['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 0];
        $curler = $this->cannedCurler([$refused, ['http_code' => 200], $refused], retries: 2, policy: $policy);

        $this->assertSame(200, $curler->execOne(new CurlerRequest()));
        $this->assertSame(2, $curler->attempts);
    }

    public function test_unsent_stops_once_an_attempt_reached_the_server(): void
    {
        $refused = ['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 0];
        $timedOut = ['http_code' => 0, 'connect_time' => 0.002, 'size_upload' => 0];
        $curler = $this->cannedCurler([$refused, $timedOut, $refused], retries: 2, policy: CurlerRollingWithRetries::RETRY_ON_UNSENT);

        $this->assertSame(0, $curler->execOne(new CurlerRequest()));
        $this->assertSame(2, $curler->attempts);
    }

    public function test_without_retries_there_is_one_attempt(): void
    {
        $curler = $this->countingCurler(retries: 0, policy: CurlerRollingWithRetries::RETRY_ON_ANY);

        $curler->execOne($this->requestToAClosedPort());

        $this->assertSame(1, $curler->attempts);
    }

    public function test_may_retry_applies_the_policy_to_a_request_that_ran_elsewhere(): void
    {
        $sent = new CurlerRequest();
        $sentResponse = new CurlerResponse();
        $sentResponse->_info = ['http_code' => 0, 'connect_time' => 0.001, 'size_upload' => 47];
        $sent->setResponse($sentResponse);
        $refused = new CurlerRequest();
        $refusedResponse = new CurlerResponse();
        $refusedResponse->_info = ['http_code' => 0, 'connect_time' => 0.0, 'size_upload' => 0];
        $refused->setResponse($refusedResponse);

        $curler = new CurlerRollingWithRetries();
        $this->assertTrue($curler->mayRetry($sent));
        $this->assertTrue($curler->mayRetry($refused));

        $curler->setRetryOn(CurlerRollingWithRetries::RETRY_ON_UNSENT);
        $this->assertFalse($curler->mayRetry($sent));
        $this->assertTrue($curler->mayRetry($refused));
    }

    public function test_a_request_without_a_response_counts_as_reached(): void
    {
        $curler = new class extends CurlerRollingWithRetries {
            public int $attempts = 0;

            protected function attempt(CurlerRequest $request, bool $auto_close): int
            {
                $this->attempts++;

                return 0;
            }
        };
        $curler->setRetries(2);
        $curler->setRetryOn(CurlerRollingWithRetries::RETRY_ON_UNSENT);

        $curler->execOne(new CurlerRequest());

        $this->assertSame(1, $curler->attempts);
    }

    /**
     * A curler that sends for real and counts its attempts.
     *
     * @param int $retries
     * @param string $policy
     * @return CurlerRollingWithRetries&object{attempts: int}
     */
    private function countingCurler(int $retries, string $policy): CurlerRollingWithRetries
    {
        $curler = new class extends CurlerRollingWithRetries {
            public int $attempts = 0;

            protected function attempt(CurlerRequest $request, bool $auto_close): int
            {
                $this->attempts++;

                return parent::attempt($request, $auto_close);
            }
        };
        $curler->setRetries($retries);
        $curler->setRetryOn($policy);

        return $curler;
    }

    /**
     * A curler whose attempts get the given response info in turn, without sending anything.
     *
     * @param array<int, array<string, int|float>> $infos
     * @param int $retries
     * @param string $policy
     * @return CurlerRollingWithRetries&object{attempts: int}
     */
    private function cannedCurler(array $infos, int $retries, string $policy): CurlerRollingWithRetries
    {
        $curler = new class($infos) extends CurlerRollingWithRetries {
            public int $attempts = 0;

            /**
             * @param array<int, array<string, int|float>> $infos
             */
            public function __construct(private array $infos)
            {
            }

            protected function attempt(CurlerRequest $request, bool $auto_close): int
            {
                $response = new CurlerResponse();
                $response->_info = $this->infos[$this->attempts++];
                $request->setResponse($response);

                return (int) ($response->_info['http_code'] ?? 0);
            }
        };
        $curler->setRetries($retries);
        $curler->setRetryOn($policy);

        return $curler;
    }

    /**
     * A request that smi2 builds for a port that nothing listens on, so the connection is refused at once.
     *
     * @return CurlerRequest
     */
    private function requestToAClosedPort(): CurlerRequest
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->setConnectTimeOut(1.0);

        return $client->transport()->writeStreamData('SELECT 1');
    }
}
