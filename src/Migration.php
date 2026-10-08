<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use Illuminate\Database\Migrations\Migration as BaseMigration;
use PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree;
use Symfony\Component\Console\Output\ConsoleOutput;

class Migration extends BaseMigration
{

    use WithClient;

    protected $connection = Connection::DEFAULT_NAME;

    /**
     * @param string $sql
     * @param array $bindings
     * @return Statement
     */
    protected static function write(string $sql, array $bindings = []): Statement
    {
        $instance = new static();
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if ($trace['function'] == 'pretend') {
                $name = static::class;
                (new ConsoleOutput())->writeln(
                    "<comment>Clickhouse</comment> <info>$name on connection $instance->connection:</info> $sql"
                );
                return new Statement(new CurlerRequest());
            }
        }
        return $instance->resolveConnection()->getCluster()->write($sql, $bindings);
    }

    protected static function createMergeTree(string $tableName, callable $callback): Statement
    {
        $instance = new static();
        $connection = $instance->resolveConnection();
        $table = (new MergeTree($tableName))
            ->dbName($connection->getDatabaseName())
            ->onCluster($connection->getConfig('cluster_name'));
        if ($connection->getConfig('cluster')) {
            $table->getEngine()->replicated();
        }
        $callback($table);
        return static::write($table->compile());
    }
}
