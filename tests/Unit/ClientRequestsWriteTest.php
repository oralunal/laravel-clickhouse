<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Oralunal\LaravelClickHouse\ClientRequests;
use Oralunal\LaravelClickHouse\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The pretend-aware write() of ClientRequests, and the statement that pretend mode returns. Nothing is sent.
 */
class ClientRequestsWriteTest extends TestCase
{
    public function test_write_sends_through_client_write_when_not_pretending(): void
    {
        $statement = $this->createStub(Statement::class);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('write')
            ->with('ALTER TABLE `examples` DELETE WHERE `f_int` = :f', ['f' => 1])
            ->willReturn($statement);
        $connection = $this->connection();
        $connection->expects($this->never())->method('logQuery');

        $this->assertSame(
            $statement,
            ClientRequests::write($client, $connection, 'ALTER TABLE `examples` DELETE WHERE `f_int` = :f', ['f' => 1])
        );
    }

    public function test_write_while_pretending_logs_the_statement_and_sends_nothing(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('write');
        $client->expects($this->never())->method('transport');
        $connection = $this->connection(pretending: true);
        $connection->expects($this->once())
            ->method('logQuery')
            ->with('TRUNCATE TABLE `examples`', ['a' => 1], 0.0);

        $statement = ClientRequests::write($client, $connection, 'TRUNCATE TABLE `examples`', ['a' => 1]);

        $this->assertFalse($statement->isError());
        $this->assertSame('TRUNCATE TABLE `examples`', $statement->sql());
    }

    public function test_a_pretended_statement_is_a_successful_empty_write(): void
    {
        $statement = ClientRequests::pretendedStatement('OPTIMIZE TABLE `examples` FINAL');

        $this->assertFalse($statement->isError());
        $this->assertFalse($statement->error());
        $this->assertSame('OPTIMIZE TABLE `examples` FINAL', $statement->sql());
        $this->assertSame([], $statement->rows());
        $this->assertNull($statement->summary());
        $this->assertSame(200, $statement->responseInfo()['http_code']);
        $this->assertSame(0.0, $statement->totalTimeRequest());
        $this->assertSame('0 bytes', $statement->info()['size_upload']);
    }

    /**
     * @param bool $pretending
     * @return Connection&MockObject
     */
    private function connection(bool $pretending = false): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('pretending')->willReturn($pretending);

        return $connection;
    }
}
