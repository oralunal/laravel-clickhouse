<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Concerns\HasBufferedInserts;
use PHPUnit\Framework\Attributes\DataProvider;

class InsertColumnNameRow extends BaseModel
{
    protected $table = 'insert_column_names';
}

/**
 * Uses HasBufferedInserts again, so its flushBuffer() is a copy of the trait's, in which self is this class.
 */
class InsertColumnNameRowReusingBufferedInserts extends BaseModel
{
    use HasBufferedInserts;

    protected $table = 'insert_column_names';
}

/**
 * smi2's client writes the column names of an insert between backticks as given, so
 * every insert path escapes the backticks and backslashes in each name first.
 */
class InsertColumnNameTest extends TestCase
{
    private const TABLE = 'insert_column_names';

    protected function setUp(): void
    {
        parent::setUp();

        InsertColumnNameRow::clearBuffer();
        InsertColumnNameRowReusingBufferedInserts::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, `tick``name` String, `ends\\\\` String, n Nested(a UInt8))'
            . ' ENGINE = MergeTree ORDER BY id'
        );
    }

    protected function tearDown(): void
    {
        InsertColumnNameRow::clearBuffer();
        InsertColumnNameRowReusingBufferedInserts::clearBuffer();
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        parent::tearDown();
    }

    /**
     * Each insert path, given associative rows.
     *
     * @return array<string, array{Closure(array<int, array<string, mixed>>): mixed}>
     */
    public static function insertPathProvider(): array
    {
        $columns = fn (array $rows): array => array_keys($rows[0]);
        $values = fn (array $rows): array => array_map('array_values', $rows);

        return [
            'query builder insert()' => [fn (array $rows) => DB::connection('clickhouse')->table(self::TABLE)->insert($rows)],
            'insertAssoc()' => [fn (array $rows) => InsertColumnNameRow::insertAssoc($rows)],
            'prepareAndInsertAssoc()' => [fn (array $rows) => InsertColumnNameRow::prepareAndInsertAssoc($rows)],
            'insertBulk()' => [fn (array $rows) => InsertColumnNameRow::insertBulk($values($rows), $columns($rows))],
            'prepareAndInsertBulk()' => [fn (array $rows) => InsertColumnNameRow::prepareAndInsertBulk($values($rows), $columns($rows))],
            'prepareAndInsert()' => [fn (array $rows) => InsertColumnNameRow::prepareAndInsert($values($rows), $columns($rows))],
            'create()' => [fn (array $rows) => array_map(fn (array $row) => InsertColumnNameRow::create($row), $rows)],
            'buffer() and flushBuffer()' => [function (array $rows) {
                InsertColumnNameRow::buffer($rows);

                return InsertColumnNameRow::flushBuffer();
            }],
            'buffer() and flushBuffer() of a model that uses the trait again' => [function (array $rows) {
                InsertColumnNameRowReusingBufferedInserts::buffer($rows);

                return InsertColumnNameRowReusingBufferedInserts::flushBuffer();
            }],
        ];
    }

    /**
     * @param Closure(array<int, array<string, mixed>>): mixed $insert
     */
    #[DataProvider('insertPathProvider')]
    public function testANameWithABacktickOrATrailingBackslashGetsItsValues(Closure $insert): void
    {
        $insert([
            ['id' => 1, 'tick`name' => 'a', 'ends\\' => 'b', 'n.a' => [1, 2]],
            ['id' => 2, 'tick`name' => 'c', 'ends\\' => 'd', 'n.a' => []],
        ]);

        $this->assertSame([[1, 'a', 'b', [1, 2]], [2, 'c', 'd', []]], $this->rows());
    }

    /**
     * Plain names and Nested names such as n.a are sent as before.
     *
     * @param Closure(array<int, array<string, mixed>>): mixed $insert
     */
    #[DataProvider('insertPathProvider')]
    public function testPlainAndNestedNamesGetTheirValues(Closure $insert): void
    {
        $insert([['id' => 1, 'n.a' => [1, 2]], ['id' => 2, 'n.a' => [3]]]);

        $this->assertSame([[1, '', '', [1, 2]], [2, '', '', [3]]], $this->rows());
    }

    /**
     * A column name with a backtick and a parenthesis that tries to close the column
     * list stays one name, which the table does not have: the insert fails and
     * writes no row.
     *
     * @param Closure(array<int, array<string, mixed>>): mixed $insert
     */
    #[DataProvider('insertPathProvider')]
    public function testANameThatTriesToCloseTheColumnListStaysOneName(Closure $insert): void
    {
        $name = "tick``name`) VALUES (2, 'forged') -- ";

        try {
            $insert([['id' => 1, $name => 'a']]);
            $this->fail('The insert should fail');
        } catch (DatabaseException $exception) {
            $this->assertSame('NO_SUCH_COLUMN_IN_TABLE', $exception->getClickHouseExceptionName());
            $this->assertStringContainsString("No such column {$name} in table", $exception->getMessage());
        }

        $this->assertSame([], $this->rows());
    }

    /**
     * No insert path raises a deprecation. Laravel's handler only logs one, so the test
     * turns that handling off: prepareAndInsertAssoc() used the callable
     * 'static::prepareAssocFromRequest', which PHP 8.5 deprecates.
     *
     * @param Closure(array<int, array<string, mixed>>): mixed $insert
     */
    #[DataProvider('insertPathProvider')]
    public function testAnInsertPathRaisesNoDeprecation(Closure $insert): void
    {
        $this->withoutDeprecationHandling();

        $insert([['id' => 1, 'tick`name' => 'a'], ['id' => 2, 'tick`name' => 'b']]);

        $this->assertSame([[1, 'a', '', []], [2, 'b', '', []]], $this->rows());
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    /**
     * @return array<int, array{int, string, string, int[]}>
     */
    private function rows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['id'], $row['tick`name'], $row['ends\\'], array_map('intval', $row['n.a'])],
            $this->client()->select('SELECT id, `tick``name`, `ends\\\\`, `n.a` FROM ' . self::TABLE . ' ORDER BY id')->rows()
        );
    }
}
