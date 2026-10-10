<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerRolling;
use InvalidArgumentException;

/**
 * The curler of a client whose connection sets retries: a request that does not get HTTP 200 is sent again.
 *
 * The retry_on policy decides which failed requests are sent again. With 'any', every one is, as in 3.0.0. A
 * request that reached the server may still run there after it failed on the client, for example an INSERT
 * that timed out on the client, so a retry can write its rows twice (ClickHouse 24.8 checked: with retries 2,
 * one INSERT that timed out stored its rows three times). With 'unsent', only requests that never reached
 * the server are sent again: refused connections, failed DNS lookups and connect timeouts.
 */
class CurlerRollingWithRetries extends CurlerRolling
{
    /**
     * Send every request that does not get HTTP 200 again.
     */
    public const RETRY_ON_ANY = 'any';

    /**
     * Send a request again only when it never reached the server.
     */
    public const RETRY_ON_UNSENT = 'unsent';

    /**
     * @var int 0 mean only one attempt, 1 mean one attempt + 1 retry while error (2 total attempts)
     */
    protected $retries = 0;

    /**
     * Which failed requests are sent again: RETRY_ON_ANY or RETRY_ON_UNSENT.
     *
     * @var string
     */
    protected string $retryOn = self::RETRY_ON_ANY;

    /** @inheritDoc */
    public function execOne(CurlerRequest $request, bool $auto_close = false): int
    {
        $attempts = 1 + max(0, $this->retries);
        $httpCode = 0;
        while ($attempts-- && 200 !== $httpCode) {
            $httpCode = $this->attempt($request, $auto_close);
            if (200 !== $httpCode && !$this->mayRetry($request)) {
                break;
            }
        }

        return $httpCode;
    }

    /**
     * @return int
     */
    public function getRetries(): int
    {
        return $this->retries;
    }

    /**
     * @param int $retries
     */
    public function setRetries(int $retries): void
    {
        $this->retries = $retries;
    }

    /**
     * Get which failed requests are sent again.
     *
     * @return string RETRY_ON_ANY or RETRY_ON_UNSENT
     */
    public function getRetryOn(): string
    {
        return $this->retryOn;
    }

    /**
     * Set which failed requests are sent again: RETRY_ON_ANY (every one) or RETRY_ON_UNSENT (only requests
     * that never reached the server).
     *
     * @param string $retryOn
     * @return void
     * @throws InvalidArgumentException For any other value
     */
    public function setRetryOn(string $retryOn): void
    {
        if (!in_array($retryOn, [self::RETRY_ON_ANY, self::RETRY_ON_UNSENT], true)) {
            throw new InvalidArgumentException(sprintf(
                "The retry policy must be '%s' or '%s', [%s] given.",
                self::RETRY_ON_ANY,
                self::RETRY_ON_UNSENT,
                $retryOn
            ));
        }

        $this->retryOn = $retryOn;
    }

    /**
     * Send the request once and return its HTTP code, 0 when no response came back.
     *
     * @param CurlerRequest $request
     * @param bool $auto_close
     * @return int
     */
    protected function attempt(CurlerRequest $request, bool $auto_close): int
    {
        return parent::execOne($request, $auto_close);
    }

    /**
     * Determine if a request that failed may be sent again under the retry policy.
     *
     * Public so that code which runs requests on a curler of its own, as parallel queries do, applies the
     * client's policy in the same way.
     *
     * @param CurlerRequest $request
     * @return bool
     */
    public function mayRetry(CurlerRequest $request): bool
    {
        return $this->getRetryOn() === self::RETRY_ON_ANY || !$this->reachedTheServer($request);
    }

    /**
     * Determine if the last attempt of a request may have reached the server, so that the server may run it.
     *
     * That is the case when a response came back, when the connection was made, or when any of the body was
     * sent. smi2 forbids reusing connections, so each attempt makes its own connection, and a connect time of 0
     * means that this attempt never connected: the connection was refused, the host name was not found or the
     * connect timeout ran out. A request without a response is taken to have reached the server, so that it
     * is not sent twice.
     *
     * @param CurlerRequest $request
     * @return bool
     */
    protected function reachedTheServer(CurlerRequest $request): bool
    {
        if (!$request->isResponseExists()) {
            return true;
        }

        $info = $request->response()->info();

        return (int) ($info['http_code'] ?? 0) !== 0
            || (float) ($info['connect_time'] ?? 0) > 0
            || (float) ($info['size_upload'] ?? 0) > 0;
    }
}
