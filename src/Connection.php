<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

use ClickHouseDB\Client;
use Closure;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Filesystem\Filesystem;

class Connection extends BaseConnection
{
    public const DEFAULT_NAME = 'clickhouse';

    protected Cluster $cluster;

    public function getCluster(): Cluster
    {
        return $this->cluster;
    }

    public function getClient(): Client
    {
        return $this->cluster->getActiveNode();
    }

    /**
     * @param array $config
     * @return static
     */
    public static function createWithClient(array $config): self
    {
        $conn = new static(null, $config['database'], '', $config);
        $nodeConfigs = [];
        if ($cluster = $config['cluster'] ?? null) {
            foreach ($cluster as $node) {
                $nodeConfigs[] = $node + $config;
            }
        } else {
            $nodeConfigs[] = $config;
        }
        $conn->cluster = new Cluster($nodeConfigs);
        return $conn;
    }

    /** @inheritDoc */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /** @inheritDoc */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /** @inheritDoc */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Get the schema state for the connection, used by `schema:dump` and `migrate`.
     *
     * @param Filesystem|null $files
     * @param callable|null $processFactory
     * @return SchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): SchemaState
    {
        return new SchemaState($this, $files, $processFactory);
    }

    /** @inheritDoc */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $query = QueryGrammar::prepareParameters($query);
        return $this->run($query, $bindings, function ($query, $bindings) {
            return $this->cluster->getActiveNode()->select($query, $bindings)->rows();
        });
    }

    /** @inheritDoc */
    public function statement($query, $bindings = []): bool
    {
        $query = QueryGrammar::prepareParameters($query);
        return $this->run($query, $bindings, function ($query, $bindings) {
            return !$this->cluster->getActiveNode()->write($query, $bindings)->isError();
        });
    }

    /** @inheritDoc */
    public function affectingStatement($query, $bindings = []): int
    {
        $query = QueryGrammar::prepareParameters($query);
        return (int)$this->statement($query, $bindings);
    }

    /** @inheritDoc */
    protected function run($query, $bindings, Closure $callback)
    {
        foreach ($this->beforeExecutingCallbacks as $beforeExecutingCallback) {
            $beforeExecutingCallback($query, $bindings, $this);
        }

        $start = microtime(true);

        $result = $callback($query, $bindings);

        $this->logQuery($query, $bindings, $this->getElapsedTime($start));

        return $result;
    }

    /**
     * Get a new query builder instance.
     *
     * @return Builder|\Illuminate\Database\Query\Builder
     */
    public function query()
    {
        if ($this->config['fix_default_query_builder'] ?? false) {
            return new Builder($this->getClient(), $this->getName());
        }
        return parent::query();
    }
}
