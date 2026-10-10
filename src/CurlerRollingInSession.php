<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Transport\CurlerRequest;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;

/**
 * The curler of a client while Connection::session() runs.
 *
 * It sends a failed request again only when the request never reached the server (the RETRY_ON_UNSENT
 * policy), whatever the connection's retry_on says. ClickHouse runs one query of a session at a time, and a
 * query that failed on the client may still run on the server: a resent query would then fail with
 * SESSION_IS_LOCKED and hide the first error, and a write could run twice (ClickHouse 24.8 checked).
 *
 * It refuses asynchronous requests before anything is sent. smi2/phpclickhouse 1.26 marks every request of
 * a client that has a session_id as persistent, and its CurlerRolling never takes a persistent request off the
 * queue, so selectAsync() with executeAsync() would send the query again and again and never return.
 */
class CurlerRollingInSession extends CurlerRollingWithRetries
{
    /**
     * @param int $retries How often a request that never reached the server is sent again, as the
     *                     connection's retries option says
     */
    public function __construct(int $retries = 0)
    {
        $this->setRetries($retries);
        $this->retryOn = self::RETRY_ON_UNSENT;
    }

    /**
     * The policy of a session curler is always RETRY_ON_UNSENT.
     *
     * @param string $retryOn
     * @return void
     * @throws InvalidArgumentException For any other value than RETRY_ON_UNSENT
     */
    public function setRetryOn(string $retryOn): void
    {
        if ($retryOn !== self::RETRY_ON_UNSENT) {
            throw new InvalidArgumentException(sprintf(
                "Inside a ClickHouse session the retry policy is always '%s', [%s] given: ClickHouse runs one query"
                . ' of a session at a time, so a request that reached the server is not sent again.',
                self::RETRY_ON_UNSENT,
                $retryOn
            ));
        }

        parent::setRetryOn($retryOn);
    }

    /**
     * Refuse to queue an asynchronous request, before anything is sent.
     *
     * @param CurlerRequest $req
     * @param bool $checkMultiAdd
     * @param bool $force
     * @return bool
     * @throws QueryException Always
     */
    public function addQueLoop(CurlerRequest $req, bool $checkMultiAdd = true, bool $force = false): bool
    {
        throw QueryException::cannotRunAsynchronouslyInSession();
    }
}
