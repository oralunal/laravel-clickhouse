<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\Schema\Blueprint;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * Temporary tables in Laravel's schema builder, without a server: Schema::create() with temporary() inside and
 * outside the connection's session, Schema::dropTemporary(), dropTemporaryIfExists() and hasTemporaryTable().
 * tests/SchemaTemporaryTableTest.php runs them against ClickHouse.
 */
class SchemaBuilderTemporaryTest extends TestCase
{
    /**
     * The statements that the mocked connection received through statement().
     *
     * @var list<string>
     */
    private array $statements = [];

    public function test_a_temporary_table_outside_a_session_throws_and_sends_nothing(): void
    {
        $connection = $this->connection(inSession: false);

        try {
            $this->schema($connection)->create('tmp_ids', function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
            $this->fail('Schema::create() should refuse a temporary table outside a session.');
        } catch (QueryException $exception) {
            $this->assertSame(
                'Cannot create the temporary table tmp_ids outside a session: ClickHouse drops a temporary table at the'
                . ' end of the query that creates it, unless the query runs in a session. Create and use the table'
                . " inside the connection's session() callback.",
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->statements);
    }

    public function test_a_temporary_table_inside_a_session_is_created(): void
    {
        $this->schema($this->connection(inSession: true))->create('tmp_ids', function (SchemaBlueprint $table) {
            $table->temporary();
            $table->integer('id');
            $table->string('name');
        });

        $this->assertSame(['CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32, `name` String)'], $this->statements);
    }

    public function test_the_table_prefix_names_the_temporary_table(): void
    {
        $this->schema($this->connection(inSession: true, prefix: 'pre_'))->create('tmp_ids', function (SchemaBlueprint $table) {
            $table->temporary();
            $table->engine('Memory');
            $table->integer('id');
        });

        $this->assertSame(['CREATE TEMPORARY TABLE `pre_tmp_ids` (`id` Int32) ENGINE = Memory'], $this->statements);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot create the temporary table pre_tmp_ids outside a session');

        $this->schema($this->connection(inSession: false, prefix: 'pre_'))->create('tmp_ids', function (Blueprint $table) {
            $table->temporary();
            $table->integer('id');
        });
    }

    public function test_a_blueprint_from_a_resolver_is_checked_too(): void
    {
        $connection = $this->connection(inSession: false);
        $schema = $this->schema($connection);
        $schema->blueprintResolver(fn (BaseConnection $connection, string $table, ?\Closure $callback): Blueprint => new Blueprint($connection, $table, $callback));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot create the temporary table tmp_ids outside a session');

        try {
            $schema->create('tmp_ids', function (Blueprint $table) {
                $table->temporary();
                $table->integer('id');
            });
        } finally {
            $this->assertSame([], $this->statements);
        }
    }

    /**
     * Outside a session, a temporary table is neither created nor changed: an ALTER TABLE of a temporary() blueprint
     * would change the table of the database instead. DROP TEMPORARY TABLE only ever drops a temporary table, so it
     * is sent, and ClickHouse treats DROP TEMPORARY TABLE IF EXISTS as a no-op.
     */
    public function test_outside_a_session_only_a_temporary_drop_is_sent(): void
    {
        $schema = $this->schema($this->connection(inSession: false));

        $schema->create('events', fn (SchemaBlueprint $table) => $table->integer('id'));

        try {
            $schema->table('tmp_ids', function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('a');
            });
            $this->fail('Schema::table() should refuse a temporary table outside a session.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith(
                'Cannot send ALTER TABLE for the temporary table tmp_ids outside a session',
                $exception->getMessage()
            );
        }

        $schema->dropTemporaryIfExists('tmp_ids');

        $this->assertSame(
            [
                'CREATE TABLE `events` (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`)',
                'DROP TEMPORARY TABLE IF EXISTS `tmp_ids`',
            ],
            $this->statements
        );
    }

    public function test_drop_temporary_and_drop_temporary_if_exists(): void
    {
        $schema = $this->schema($this->connection(inSession: true, prefix: 'pre_'));

        $schema->dropTemporary('tmp_ids');
        $schema->dropTemporaryIfExists('tmp_ids');

        $this->assertSame(['DROP TEMPORARY TABLE `pre_tmp_ids`', 'DROP TEMPORARY TABLE IF EXISTS `pre_tmp_ids`'], $this->statements);
    }

    public function test_has_temporary_table_asks_the_connection(): void
    {
        $connection = $this->connection(inSession: true, prefix: 'pre_');
        $connection->expects($this->exactly(2))
            ->method('hasTemporaryTable')
            ->willReturnCallback(fn (string $table): bool => $table === 'pre_tmp_ids');

        $this->assertTrue($this->schema($connection)->hasTemporaryTable('tmp_ids'));
        $this->assertFalse($this->schema($connection)->hasTemporaryTable('missing'));
    }

    private function schema(Connection $connection): SchemaBuilder
    {
        return new SchemaBuilder($connection);
    }

    /**
     * A mocked connection with a real SchemaGrammar, the database `analytics` and no options, which records the
     * statements it is given. inSession() answers as given.
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function connection(bool $inSession, string $prefix = ''): Connection
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn($prefix);
        $connection->method('getDatabaseName')->willReturn('analytics');
        $connection->method('inSession')->willReturn($inSession);
        $connection->method('statement')->willReturnCallback(function (string $query): bool {
            $this->statements[] = $query;

            return true;
        });

        return $connection;
    }
}
