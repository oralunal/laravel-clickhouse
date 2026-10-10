<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Statement;
use Illuminate\Database\Migrations\Migration as BaseMigration;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;

class Migration extends BaseMigration
{

    use WithClient;

    protected $connection = Connection::DEFAULT_NAME;

    /**
     * Send a statement to every node of the migration's connection, or, while the connection pretends, log it and
     * send nothing.
     *
     * php artisan migrate --pretend and DB::connection(...)->pretend() make the connection pretend, so the
     * statement shows up in the list of statements that they print or return, and the returned statement is a
     * successful empty one (see ClientRequests::pretendedStatement()). Otherwise each node gets the statement
     * through Cluster::write(), which throws at the first node that fails.
     *
     * @param string $sql
     * @param array<int|string, mixed> $bindings Bindings for smi2's :name and {name} placeholders
     * @return Statement
     */
    protected static function write(string $sql, array $bindings = []): Statement
    {
        $connection = (new static())->resolveConnection();
        if ($connection->pretending()) {
            $connection->logQuery($sql, $bindings, 0.0);

            return ClientRequests::pretendedStatement($sql);
        }

        return $connection->getCluster()->write($sql, $bindings);
    }

    /**
     * Create a MergeTree table in the database of the migration's connection, which the callback defines, and send
     * its CREATE TABLE through write().
     *
     * The table is created ON CLUSTER the connection's cluster_name, when it has one (see
     * Connection::getClusterName()), and gets a replicated engine when the connection lists its nodes in the cluster
     * option (see Connection::hasClusterNodes()).
     *
     * @param string $tableName
     * @param callable(MergeTree): mixed $callback
     * @return Statement
     */
    protected static function createMergeTree(string $tableName, callable $callback): Statement
    {
        $instance = new static();
        $connection = $instance->resolveConnection();
        $table = (new MergeTree($tableName))
            ->dbName($connection->getDatabaseName())
            ->onCluster($connection->getClusterName());
        if ($connection->hasClusterNodes()) {
            $table->getEngine()->replicated();
        }
        $callback($table);
        return static::write($table->compile());
    }
}
