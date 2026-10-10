<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Connection::select() through ClientRequests::select(), against the server: a SELECT whose SQL names a format in a
 * string literal, or ends in a comment, returns its rows, where smi2's own format search returned none or threw, and
 * a SELECT that fails after its first result block throws the server's error.
 *
 * A query that fails, through any of the connection's methods, throws smi2's DatabaseException with the server's
 * error code, as in 3.0.0, not Laravel's QueryException, and is neither logged nor reported to DB::listen().
 */
class ConnectionSelectTest extends TestCase
{
    private const TABLE = 'connection_select_rows';

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $client->write('CREATE TABLE ' . self::TABLE . ' (id UInt32, note String) ENGINE = MergeTree ORDER BY id');
        $client->write(
            'INSERT INTO ' . self::TABLE
            . " VALUES (1, 'export as format csv please'), (2, 'format JSON x'), (3, 'plain')"
        );
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    public function test_a_literal_that_names_a_format_returns_its_rows(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertSame(
            [['id' => 1]],
            $connection->select('SELECT id FROM ' . self::TABLE . " WHERE note = 'export as format csv please'")
        );
        $this->assertSame(
            [['id' => 1], ['id' => 3]],
            $connection->select('SELECT id FROM ' . self::TABLE . " WHERE note != 'format JSON x' ORDER BY id")
        );
    }

    public function test_a_binding_that_names_a_format_returns_its_rows(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertSame(
            [['id' => 1]],
            $connection->select('SELECT id FROM ' . self::TABLE . ' WHERE note = ?', ['export as format csv please'])
        );
        $this->assertSame(
            [['id' => 2]],
            $connection->select('SELECT id FROM ' . self::TABLE . ' WHERE note = :note', ['note' => 'format JSON x'])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trailingComments(): array
    {
        return [
            'a -- comment' => ['-- only these rows'],
            'a // comment' => ['// only these rows'],
            'a # comment' => ['# only these rows'],
            'a -- comment that names a format' => ['-- format CSV'],
            'a block comment that names a format' => ['/* format CSV */'],
        ];
    }

    #[DataProvider('trailingComments')]
    public function test_a_select_that_ends_in_a_comment_returns_its_rows(string $comment): void
    {
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            DB::connection('clickhouse')->select('SELECT id FROM ' . self::TABLE . ' WHERE id < ? ORDER BY id ' . $comment, [3])
        );
    }

    /**
     * ClickHouse writes the error into the JSON result when the query fails after its first block. The first
     * query goes through smi2's Client::select(), the second, whose literal names a format, through the
     * transport.
     *
     * @return array<string, array{string}>
     */
    public static function queriesThatFailAfterTheFirstBlock(): array
    {
        return [
            'through Client::select()' => [
                'SELECT number, throwIf(number = 5) AS failed FROM numbers(10) SETTINGS max_block_size = 1',
            ],
            'through the transport' => [
                "SELECT number, throwIf(number = 5) AS failed, 'format csv' AS note FROM numbers(10)"
                . ' SETTINGS max_block_size = 1',
            ],
        ];
    }

    #[DataProvider('queriesThatFailAfterTheFirstBlock')]
    public function test_a_select_that_fails_after_its_first_block_throws(string $sql): void
    {
        try {
            DB::connection('clickhouse')->select($sql);
            $this->fail('The failed select returned rows.');
        } catch (DatabaseException $exception) {
            $this->assertSame(395, $exception->getCode());
            $this->assertSame('FUNCTION_THROW_IF_VALUE_IS_NON_ZERO', $exception->getClickHouseExceptionName());
        }
    }

    public function test_a_failed_select_is_not_logged(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        try {
            $connection->select('SELECT * FROM ' . self::TABLE . '_missing WHERE id = ?', [1]);
            $this->fail('The select of a missing table returned rows.');
        } catch (DatabaseException $exception) {
            $this->assertSame(60, $exception->getCode());
        }

        $this->assertSame([], $connection->getQueryLog());
    }

    /**
     * @return array<string, array{callable(Connection): mixed, int, string}>
     */
    public static function failingQueries(): array
    {
        $missing = self::TABLE . '_missing';

        return [
            'statement() of a table that exists' => [
                fn (Connection $connection) => $connection->statement('CREATE TABLE ' . self::TABLE . ' (id UInt8) ENGINE = Memory'),
                57,
                'TABLE_ALREADY_EXISTS',
            ],
            'insert() into an unknown column' => [
                fn (Connection $connection) => $connection->insert('INSERT INTO ' . self::TABLE . ' (nope) VALUES (?)', [1]),
                16,
                'NO_SUCH_COLUMN_IN_TABLE',
            ],
            'affectingStatement() on a missing table' => [
                fn (Connection $connection) => $connection->affectingStatement("ALTER TABLE {$missing} DELETE WHERE id = ?", [1]),
                60,
                'UNKNOWN_TABLE',
            ],
            'update() of a missing table' => [
                fn (Connection $connection) => $connection->update("ALTER TABLE {$missing} UPDATE note = ? WHERE id = ?", ['x', 1]),
                60,
                'UNKNOWN_TABLE',
            ],
            'delete() from a missing table' => [
                fn (Connection $connection) => $connection->delete("DELETE FROM {$missing} WHERE id = ?", [1]),
                60,
                'UNKNOWN_TABLE',
            ],
            'cursor() over a missing table' => [
                fn (Connection $connection) => iterator_to_array($connection->cursor("SELECT * FROM {$missing} WHERE id = ?", [1])),
                60,
                'UNKNOWN_TABLE',
            ],
            'unprepared() with a syntax error' => [
                fn (Connection $connection) => $connection->unprepared('SELEC 1'),
                62,
                'SYNTAX_ERROR',
            ],
            'statementOnEveryNode() of a table that exists' => [
                fn (Connection $connection) => $connection->statementOnEveryNode('CREATE TABLE ' . self::TABLE . ' (id UInt8) ENGINE = Memory'),
                57,
                'TABLE_ALREADY_EXISTS',
            ],
        ];
    }

    /**
     * @param callable(Connection): mixed $run
     */
    #[DataProvider('failingQueries')]
    public function test_a_failed_query_throws_the_server_error_and_is_not_logged(callable $run, int $code, string $name): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();
        $events = 0;
        DB::listen(function () use (&$events): void {
            $events++;
        });

        try {
            $run($connection);
            $this->fail('The failed query did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame($code, $exception->getCode());
            $this->assertSame($name, $exception->getClickHouseExceptionName());
        }

        $this->assertSame([], $connection->getQueryLog());
        $this->assertSame(0, $events);
    }
}
