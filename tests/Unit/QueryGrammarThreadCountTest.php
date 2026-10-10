<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection as LaravelConnection;
use Oralunal\LaravelClickHouse\QueryGrammar;
use PHPUnit\Framework\TestCase;

/**
 * The query behind Connection::threadCount(), which `php artisan db:show` prints as Open Connections.
 */
class QueryGrammarThreadCountTest extends TestCase
{
    public function test_the_thread_count_sums_the_client_connections_from_system_metrics(): void
    {
        $grammar = new QueryGrammar($this->createStub(LaravelConnection::class));

        $this->assertSame(
            'SELECT toUInt32(sum(value)) AS `Value` FROM system.metrics'
            . " WHERE metric IN ('TCPConnection', 'HTTPConnection', 'MySQLConnection', 'PostgreSQLConnection')",
            $grammar->compileThreadCount()
        );
    }

    /**
     * Connections between replicas, which fetch parts, are not clients.
     */
    public function test_the_thread_count_leaves_out_interserver_connections(): void
    {
        $grammar = new QueryGrammar($this->createStub(LaravelConnection::class));

        $this->assertStringNotContainsString('Interserver', $grammar->compileThreadCount());
    }
}
