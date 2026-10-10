<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Shaping of system.columns and index rows into Laravel's column and index arrays, insertGetId(), which ClickHouse
 * cannot answer with a generated ID, and the insert() of collection values.
 */
class QueryProcessorTest extends TestCase
{
    public function testOrdinaryColumn(): void
    {
        $this->assertSame([[
            'name' => 'id',
            'type_name' => 'UInt64',
            'type' => 'UInt64',
            'collation' => null,
            'nullable' => false,
            'default' => null,
            'auto_increment' => false,
            'comment' => null,
            'generation' => null,
        ]], (new QueryProcessor())->processColumns([$this->column('id', 'UInt64')]));
    }

    public function testColumnKinds(): void
    {
        $columns = (new QueryProcessor())->processColumns([
            $this->column('name', 'String', 'DEFAULT', "'it\\'s'", 'Event name'),
            $this->column('name_length', 'UInt64', 'MATERIALIZED', 'length(name)'),
            $this->column('name_upper', 'String', 'ALIAS', 'upper(name)'),
            $this->column('raw', 'String', 'EPHEMERAL', "''"),
        ]);

        $this->assertSame(
            [
                ['name', "'it\\'s'", null, 'Event name'],
                ['name_length', null, ['type' => 'stored', 'expression' => 'length(name)'], null],
                ['name_upper', null, ['type' => 'virtual', 'expression' => 'upper(name)'], null],
                ['raw', "''", null, null],
            ],
            array_map(
                fn (array $column): array => [$column['name'], $column['default'], $column['generation'], $column['comment']],
                $columns
            )
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function nullableTypes(): array
    {
        return [
            'Nullable' => ['Nullable(String)', true],
            'Nullable with arguments' => ["Nullable(DateTime64(3, 'UTC'))", true],
            'LowCardinality(Nullable)' => ['LowCardinality(Nullable(String))', true],
            'LowCardinality' => ['LowCardinality(String)', false],
            'Array(Nullable)' => ['Array(Nullable(String))', false],
            'Map with Nullable values' => ['Map(String, Nullable(String))', false],
            'Tuple with a Nullable element' => ['Tuple(a Nullable(Int32), b String)', false],
            'plain' => ['String', false],
            'Variant' => ['Variant(String, UInt64)', true],
            'Dynamic' => ['Dynamic', true],
            'Dynamic with max_types' => ['Dynamic(max_types=10)', true],
            'Array(Variant)' => ['Array(Variant(String, UInt64))', false],
            'Array(Dynamic)' => ['Array(Dynamic)', false],
            'SimpleAggregateFunction over Nullable' => ['SimpleAggregateFunction(anyLast, Nullable(String))', true],
            'SimpleAggregateFunction over LowCardinality(Nullable)' => [
                'SimpleAggregateFunction(any, LowCardinality(Nullable(String)))',
                true,
            ],
            'SimpleAggregateFunction over Variant' => ['SimpleAggregateFunction(anyLast, Variant(String, UInt64))', true],
            'SimpleAggregateFunction' => ['SimpleAggregateFunction(sum, UInt64)', false],
            'SimpleAggregateFunction with a parameter' => ['SimpleAggregateFunction(groupArrayArray(10), Array(String))', false],
            'SimpleAggregateFunction over Array(Nullable)' => [
                'SimpleAggregateFunction(groupUniqArrayArray, Array(Nullable(String)))',
                false,
            ],
        ];
    }

    #[DataProvider('nullableTypes')]
    public function testNullable(string $type, bool $nullable): void
    {
        $column = (new QueryProcessor())->processColumns([$this->column('c', $type)])[0];

        $this->assertSame($nullable, $column['nullable']);
        $this->assertSame($type, $column['type']);
        $this->assertSame($type, $column['type_name']);
    }

    /**
     * The schema grammar applies the same rule to the types it compiles.
     */
    #[DataProvider('nullableTypes')]
    public function testTypeAcceptsNullIsAPublicStaticRule(string $type, bool $nullable): void
    {
        $this->assertSame($nullable, QueryProcessor::typeAcceptsNull($type));
    }

    /**
     * The primary key's type is null, as on Laravel's SQLite driver, so that
     * db:table lists it once as primary.
     */
    public function testPrimaryKeyAndDataSkippingIndices(): void
    {
        $this->assertSame(
            [
                ['name' => 'primary', 'columns' => ['id', 'intHash32(id)'], 'type' => null, 'unique' => false, 'primary' => true],
                ['name' => 'idx_name', 'columns' => ['name'], 'type' => 'bloom_filter', 'unique' => false, 'primary' => false],
                ['name' => 'idx_pair', 'columns' => ['id', 'name_length'], 'type' => 'minmax', 'unique' => false, 'primary' => false],
            ],
            (new QueryProcessor())->processIndexes([
                ['name' => 'primary', 'expression' => 'id, intHash32(id)', 'type' => 'primary', 'is_primary' => 1],
                ['name' => 'idx_name', 'expression' => 'name', 'type' => 'bloom_filter', 'is_primary' => 0],
                ['name' => 'idx_pair', 'expression' => 'id, name_length', 'type' => 'minmax', 'is_primary' => '0'],
            ])
        );
    }

    public function testIndexNamesAreLowercased(): void
    {
        $index = (new QueryProcessor())->processIndexes([
            ['name' => 'Idx_Name', 'expression' => 'name', 'type' => 'set', 'is_primary' => 0],
        ])[0];

        $this->assertSame('idx_name', $index['name']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function expressionLists(): array
    {
        return [
            'single column' => ['id', ['id']],
            'function arguments' => ['a, cityHash64(b, c), d', ['a', 'cityHash64(b, c)', 'd']],
            'nested parentheses' => ['f(g(a, b), c), (x, y).1', ['f(g(a, b), c)', '(x, y).1']],
            'array and map literals' => ["has([1, 2], a), m{'k'}", ['has([1, 2], a)', "m{'k'}"]],
            'comma in a string' => ["concat(s, ', ('), t", ["concat(s, ', (')", 't']],
            'escaped quote in a string' => ["concat(s, '\\', ('), t", ["concat(s, '\\', (')", 't']],
            'doubled quote in a string' => ["concat(s, 'it''s, ok'), t", ["concat(s, 'it''s, ok')", 't']],
            'quoted identifiers are unquoted' => ['`my col`, select, `1abc`', ['my col', 'select', '1abc']],
            'escaped backtick' => ['`back\\`tick`, `a,b`', ['back`tick', 'a,b']],
            'quotes inside a quoted identifier' => ["`we\"ird`, `it's`", ['we"ird', "it's"]],
            'double-quoted identifier' => ['"my col", b', ['my col', 'b']],
            'quoted identifier inside an expression' => ['intHash32(`my col`)', ['intHash32(`my col`)']],
            'extra whitespace' => ["  a ,\n b  ", ['a', 'b']],
        ];
    }

    /**
     * @param list<string> $columns
     */
    #[DataProvider('expressionLists')]
    public function testIndexColumnsSplitTheExpressionAtTopLevelCommas(string $expression, array $columns): void
    {
        $index = (new QueryProcessor())->processIndexes([
            ['name' => 'primary', 'expression' => $expression, 'type' => 'primary', 'is_primary' => 1],
        ])[0];

        $this->assertSame($columns, $index['columns']);
    }

    /**
     * @return array{name: string, type: string, default_kind: string, default_expression: string, comment: string}
     */
    private function column(
        string $name,
        string $type,
        string $defaultKind = '',
        string $defaultExpression = '',
        string $comment = ''
    ): array {
        return [
            'name' => $name,
            'type' => $type,
            'default_kind' => $defaultKind,
            'default_expression' => $defaultExpression,
            'comment' => $comment,
        ];
    }

    /**
     * Laravel's base builder hands the processor the row's values without their column names, and its own
     * processor inserts the row before it reads the ID from PDO, which the connection does not have; the
     * processor refuses before anything is sent.
     */
    public function testProcessInsertGetIdRefusesBeforeAnythingIsSent(): void
    {
        $inserts = [];
        $connection = $this->recordingConnection($inserts);
        $query = (new Builder($connection, new QueryGrammar($connection), new QueryProcessor()))->from('t');

        try {
            $query->insertGetId(['id' => 5, 'name' => 'a'], 'id');
            $this->fail('insertGetId() of the base builder should throw');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'ClickHouse has no auto-increment columns, so insertGetId() cannot get the ID of the inserted row. Set'
                . ' the [id] value before inserting, for example with Str::uuid(), and call insert(), or use the query'
                . " builder of the ClickHouse connection, whose insertGetId() returns the key the row carries. On an"
                . ' Eloquent model, set $incrementing to false.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $inserts, 'nothing is sent');
    }

    public function testProcessInsertGetIdDeclaresThatItNeverReturns(): void
    {
        $this->assertSame('never', (string) (new ReflectionMethod(QueryProcessor::class, 'processInsertGetId'))->getReturnType());
    }

    /**
     * insert() writes a collection value as an array, as update() does, so that the connection writes it as an
     * array literal; it would refuse the collection itself.
     *
     * @return array<string, array{Closure(QueryBuilder): mixed, string, list<mixed>}>
     */
    public static function insertOfACollectionProvider(): array
    {
        return [
            'one row whose first value is a collection' => [
                fn (QueryBuilder $query) => $query->insert(['tags' => collect(['a', 'b']), 'id' => 1]),
                'insert into "t" ("id", "tags") values (?, ?)',
                [1, ['a', 'b']],
            ],
            'rows with a lazy collection and a nested collection' => [
                fn (QueryBuilder $query) => $query->insert([
                    ['id' => 2, 'tags' => LazyCollection::make(fn () => yield from ['p'])],
                    ['id' => 3, 'tags' => collect([collect([1]), [2]])],
                ]),
                'insert into "t" ("id", "tags") values (?, ?), (?, ?)',
                [2, ['p'], 3, [[1], [2]]],
            ],
            'insertGetId()' => [
                fn (QueryBuilder $query) => $query->insertGetId(['id' => 5, 'tags' => collect(['q'])]),
                'insert into "t" ("id", "tags") values (?, ?)',
                [5, ['q']],
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): mixed $insert
     * @param list<mixed> $bindings
     */
    #[DataProvider('insertOfACollectionProvider')]
    public function testInsertWritesACollectionValueAsAnArray(Closure $insert, string $sql, array $bindings): void
    {
        $inserts = [];
        $insert($this->recordingQuery($inserts));

        $this->assertSame([[$sql, $bindings]], $inserts);
    }

    public function testInsertGetIdInsertsTheRowAndReturnsItsKey(): void
    {
        $inserts = [];
        $query = $this->recordingQuery($inserts);

        $this->assertSame(51, $query->insertGetId(['id' => 51, 'name' => 'gid']));
        $this->assertSame([['insert into "t" ("id", "name") values (?, ?)', [51, 'gid']]], $inserts);
    }

    public function testInsertGetIdReturnsTheKeyOfTheSequenceColumnAsGiven(): void
    {
        $inserts = [];
        $uuid = '0190a6c4-6f2a-7d4e-9b1a-1c2d3e4f5a6b';

        $this->assertSame($uuid, $this->recordingQuery($inserts)->insertGetId(['name' => 'a', 'uuid' => $uuid], 'uuid'));
        $this->assertSame([['insert into "t" ("name", "uuid") values (?, ?)', ['a', $uuid]]], $inserts);
    }

    /**
     * @return array<string, array{array<string, mixed>, string|null, string}>
     */
    public static function rowWithoutItsKeyProvider(): array
    {
        return [
            'no id' => [['name' => 'a'], null, 'id'],
            'a null id' => [['id' => null, 'name' => 'a'], null, 'id'],
            'no value for the sequence column' => [['id' => 1, 'name' => 'a'], 'uuid', 'uuid'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('rowWithoutItsKeyProvider')]
    public function testInsertGetIdRefusesARowWithoutItsKeyBeforeAnythingIsSent(array $values, ?string $sequence, string $key): void
    {
        $inserts = [];

        try {
            $this->recordingQuery($inserts)->insertGetId($values, $sequence);
            $this->fail('insertGetId() should throw');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "ClickHouse has no auto-increment columns, so insertGetId() cannot get the ID of a row without a [{$key}]"
                . ' value. Set the key before inserting, for example with Str::uuid(), and call insert(). On an'
                . ' Eloquent model, set $incrementing to false.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $inserts, 'nothing is sent');
    }

    /**
     * A stub ClickHouse connection that records the inserts it is given, with their bindings, instead of sending them.
     *
     * @param list<array{string, list<mixed>}> $inserts
     * @return Connection
     */
    private function recordingConnection(array &$inserts): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $connection->method('insert')->willReturnCallback(function (string $sql, array $bindings = []) use (&$inserts): bool {
            $inserts[] = [$sql, $bindings];

            return true;
        });

        return $connection;
    }

    /**
     * @param list<array{string, list<mixed>}> $inserts
     * @return QueryBuilder
     */
    private function recordingQuery(array &$inserts): QueryBuilder
    {
        $connection = $this->recordingConnection($inserts);

        return (new QueryBuilder($connection, new QueryGrammar($connection), new QueryProcessor()))->from('t');
    }
}
