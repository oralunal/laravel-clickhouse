<?php

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\TransportException;
use ClickHouseDB\Statement;
use InvalidArgumentException;
use LogicException;

class Cluster
{
    /**
     * The seconds that timeout_query and timeout_connect default to when a connection leaves them out, as in
     * the packaged config/clickhouse.php.
     */
    protected const DEFAULT_TIMEOUT = 2.0;

    /**
     * @var Client[]
     */
    protected array $nodes;
    protected int $activeNodeIndex;

    /**
     * How many callers pinned the active node: slideNode() throws while this is above 0.
     */
    protected int $activeNodePins = 0;

    public function __construct(
        protected array $nodeConfigs
    ) {
        foreach ($this->nodeConfigs as $index => $nodeConfig) {
            try {
                $this->nodes[$index] = static::createClient($nodeConfig);
                $this->nodes[$index]->ping(true);
                $this->activeNodeIndex = $index;
                break;
            } catch (TransportException $e) {
            }
        }
        if (!isset($this->activeNodeIndex)) {
            throw $e ?? new TransportException('No nodes are available');
        }
    }

    public function write(string $sql, array $bindings = [], bool $exception = true): ?Statement
    {
        foreach ($this->nodeConfigs as $index => $config) {
            if (empty($this->nodes[$index])) {
                $this->nodes[$index] = static::createClient($config);
            }
            $statement = $this->nodes[$index]->write($sql, $bindings, $exception);
        }
        return $statement ?? null;
    }

    public function getActiveNode(): Client
    {
        return $this->nodes[$this->activeNodeIndex];
    }

    /**
     * Pin the active node, so that slideNode() throws until unpinActiveNode() has been called as often.
     *
     * Used by Connection::session(): a ClickHouse session and its temporary tables only exist on the node that
     * opened the session, so the connection must not move to another node while the session runs. The pins
     * are counted, so that nested sessions work.
     *
     * @return void
     */
    public function pinActiveNode(): void
    {
        $this->activeNodePins++;
    }

    /**
     * Take back one pin of pinActiveNode(). The count never goes below 0.
     *
     * Used by Connection::session() when its callback ends.
     *
     * @return void
     */
    public function unpinActiveNode(): void
    {
        $this->activeNodePins = max(0, $this->activeNodePins - 1);
    }

    /**
     * Determine if the active node is pinned, as it is while Connection::session() runs.
     *
     * @return bool
     */
    public function isActiveNodePinned(): bool
    {
        return $this->activeNodePins > 0;
    }

    /**
     * Switch the active node to the next node that answers a ping.
     *
     * Each node after the active one is tried in turn, wrapping around, until one answers, so a node that is
     * down is skipped and the node after it is reached. When no other node answers, the active node is kept.
     *
     * While the active node is pinned, it throws before any node is pinged, also on a connection with one node,
     * so that code tested on one node fails the same way on a cluster.
     *
     * @return void
     * @throws LogicException While the active node is pinned (see pinActiveNode())
     */
    public function slideNode(): void
    {
        if ($this->isActiveNodePinned()) {
            $node = $this->getActiveNode();

            throw new LogicException(sprintf(
                'Cannot switch to another node while the active node %s:%s is pinned, as it is while session() runs'
                . ' on this connection: a session and its temporary tables only exist on the node that opened it.',
                $node->getConnectHost(),
                $node->getConnectPort()
            ));
        }

        $configCount = count($this->nodeConfigs);
        if ($configCount < 2) {
            return;
        }
        for ($i = 1; $i < $configCount; $i++) {
            $nextIndex = ($this->activeNodeIndex + $i) % $configCount;
            try {
                $this->nodes[$nextIndex] ??= static::createClient($this->nodeConfigs[$nextIndex]);
                $this->nodes[$nextIndex]->ping(true);
                $this->activeNodeIndex = $nextIndex;
                break;
            } catch (TransportException) {
            }
        }
    }

    /**
     * Create the client of one node.
     *
     * timeout_query and timeout_connect are seconds and default to 2 when the config leaves them out.
     * timeout_connect is kept with its fraction (0.5 is 500 ms). smi2 applies timeout_query in whole seconds,
     * as the server's max_execution_time and as the HTTP timeout, so a fraction is rounded up: 0.5 becomes 1
     * second instead of 0, which means no HTTP timeout and no max_execution_time. timeout_query 0 means no
     * limit; timeout_connect 0 is handed to curl, which reads it as its own default of 300 seconds, not as no
     * limit. A client with retries gets a CurlerRollingWithRetries with the retry_on policy.
     *
     * @param array<string, mixed> $config
     * @return Client
     * @throws InvalidArgumentException When a timeout or retry_on has an invalid value
     */
    protected static function createClient(array $config): Client
    {
        $queryTimeout = static::parseTimeout($config, 'timeout_query') ?? static::DEFAULT_TIMEOUT;
        $connectTimeout = static::parseTimeout($config, 'timeout_connect') ?? static::DEFAULT_TIMEOUT;
        $retryOn = static::parseRetryOn($config);

        // smi2 hands curl whole milliseconds, and curl reads a connect timeout of 0 as its default of 300 seconds.
        if ($connectTimeout > 0 && $connectTimeout < 0.001) {
            $connectTimeout = 0.001;
        }

        $client = new Client($config);
        $client->database($config['database']);
        $client->setTimeout((int) ceil($queryTimeout));
        $client->setConnectTimeOut($connectTimeout);
        if ($configSettings =& $config['settings']) {
            $settings = $client->settings();
            foreach ($configSettings as $sName => $sValue) {
                $settings->set($sName, $sValue);
            }
        }
        if ($retries = (int)($config['retries'] ?? null)) {
            $curler = new CurlerRollingWithRetries();
            $curler->setRetries($retries);
            $curler->setRetryOn($retryOn);
            $client->transport()->setDirtyCurler($curler);
        }
        return $client;
    }

    /**
     * Read a timeout option as a number of seconds.
     *
     * An int, a float or a numeric string is taken as given, fraction included. A null value or a blank string
     * means 0, as in 3.0.0: for timeout_query that is no limit, while curl reads a connect timeout of 0 as its
     * own default of 300 seconds (see createClient()).
     *
     * @param array<string, mixed> $config
     * @param string $key
     * @return float|null The seconds, or null when the config has no such option
     * @throws InvalidArgumentException For a negative, infinite or non-numeric value, naming the option and the value
     */
    protected static function parseTimeout(array $config, string $key): ?float
    {
        if (!array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return 0.0;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $seconds = (float) $value;
            if (is_finite($seconds) && $seconds >= 0) {
                return $seconds;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'The [%s] option of the ClickHouse connection must be a number of seconds, [%s] given.',
            $key,
            static::describeOptionValue($value)
        ));
    }

    /**
     * Read the retry_on option: which failed requests a client with retries sends again.
     *
     * A missing option, null or a blank string means 'any', which sends every failed request again, as in
     * 3.0.0. 'unsent' sends only requests that never reached the server. Letter case and surrounding spaces do
     * not matter. The option is checked also when retries is 0, so that a typo shows at once.
     *
     * @param array<string, mixed> $config
     * @return string CurlerRollingWithRetries::RETRY_ON_ANY or CurlerRollingWithRetries::RETRY_ON_UNSENT
     * @throws InvalidArgumentException For any other value
     */
    protected static function parseRetryOn(array $config): string
    {
        $value = $config['retry_on'] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return CurlerRollingWithRetries::RETRY_ON_ANY;
        }

        $policies = [CurlerRollingWithRetries::RETRY_ON_ANY, CurlerRollingWithRetries::RETRY_ON_UNSENT];
        $retryOn = is_string($value) ? strtolower(trim($value)) : null;
        if (in_array($retryOn, $policies, true)) {
            return $retryOn;
        }

        throw new InvalidArgumentException(sprintf(
            "The [retry_on] option of the ClickHouse connection must be '%s' or '%s', [%s] given.",
            CurlerRollingWithRetries::RETRY_ON_ANY,
            CurlerRollingWithRetries::RETRY_ON_UNSENT,
            static::describeOptionValue($value)
        ));
    }

    /**
     * Describe an option value for an exception message: a string as given, a number as PHP writes it, and
     * any other value by its type.
     *
     * @param mixed $value
     * @return string
     */
    protected static function describeOptionValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
