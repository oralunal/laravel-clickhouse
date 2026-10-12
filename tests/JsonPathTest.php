<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * Laravel's JSON selectors, whereJsonContains() and its variants, and the updates of JSON paths against ClickHouse,
 * on a String column that holds JSON documents, as json() creates it. Also whereFullText() and the vector queries.
 * tests/Unit/QueryBuilderJsonSqlTest.php checks their SQL.
 */
class JsonPathTest extends TestCase
{
    private const TABLE = 'json_path_rows';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');
        $this->client()->write(
            'CREATE TABLE ' . self::TABLE . ' (id UInt32, payload String, note Nullable(String), body String,'
            . ' embedding Array(Float32)) ENGINE = MergeTree ORDER BY id'
        );
        $this->table()->insert([
            [
                'id' => 1,
                'payload' => '{"color":"red","size":3,"active":true,"tags":["en","de"],"nums":[1,2.5],'
                    . '"user":{"name":"Ann","a b":"x"},"n":null}',
                'note' => '{"a":1}',
                'body' => 'The quick brown fox',
                'embedding' => [1.0, 0.0, 0.0],
            ],
            [
                'id' => 2,
                'payload' => '{"color":"blue","size":"large","active":false,"tags":["fr"],"nums":[3],"user":{"name":"Bob"}}',
                'note' => null,
                'body' => 'Lazy dogs sleep',
                'embedding' => [0.0, 1.0, 0.0],
            ],
            ['id' => 3, 'payload' => '', 'note' => 'not json', 'body' => 'A quick dog', 'embedding' => [0.9, 0.1, 0.0]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS ' . self::TABLE . ' SYNC');

        parent::tearDown();
    }

    public function testConditionsReadTheValueOfAPath(): void
    {
        $this->assertSame([1], $this->ids($this->table()->where('payload->color', 'red')));
        $this->assertSame([2], $this->ids($this->table()->where('payload->user->name', 'Bob')));
        $this->assertSame([1], $this->ids($this->table()->where('payload->user->a b', 'x')));
        $this->assertSame([2], $this->ids($this->table()->where('payload->tags[0]', 'fr')));
        $this->assertSame([1], $this->ids($this->table()->where('payload->tags[-1]', 'de')));
        $this->assertSame([1, 2], $this->ids($this->table()->whereLike('payload->color', '%e%')));
    }

    /**
     * A number is compared with the value read as a float, and a value that is no number, such as "large", or a
     * document that is no JSON, is null: no comparison holds for it, != and not in included, and the query does not
     * fail.
     */
    public function testNumbersCompareWithTheValueAsAFloat(): void
    {
        $this->assertSame([1], $this->ids($this->table()->where('payload->size', '>', 2)));
        $this->assertSame([1], $this->ids($this->table()->where('payload->size', 3.0)));
        $this->assertSame([1], $this->ids($this->table()->whereIn('payload->size', [3, 4])));
        $this->assertSame([1], $this->ids($this->table()->whereNotIn('payload->size', [4])));
        $this->assertSame([], $this->ids($this->table()->where('payload->size', '!=', 3)));
        $this->assertSame([1], $this->ids($this->table()->whereBetween('payload->size', [1, 5])));
        $this->assertSame([2], $this->ids($this->table()->where('payload->size', 'large')));
    }

    public function testBooleansAndNulls(): void
    {
        $this->assertSame([1], $this->ids($this->table()->where('payload->active', true)));
        $this->assertSame([2], $this->ids($this->table()->where('payload->active', false)));
        $this->assertSame([1, 2, 3], $this->ids($this->table()->whereNull('payload->n')), 'null, a missing key and no JSON');
        $this->assertSame([1, 2], $this->ids($this->table()->whereNotNull('payload->color')));
    }

    public function testJsonContainsOverlapsContainsKeyAndLength(): void
    {
        $this->assertSame([1], $this->ids($this->table()->whereJsonContains('payload->tags', 'en')));
        $this->assertSame([1], $this->ids($this->table()->whereJsonContains('payload->tags', ['de', 'en'])));
        $this->assertSame([2, 3], $this->ids($this->table()->whereJsonDoesntContain('payload->tags', 'en')));
        $this->assertSame([1], $this->ids($this->table()->whereJsonContains('payload->nums', 2.5)));
        $this->assertSame([1], $this->ids($this->table()->whereJsonContains('payload->nums', [1, 2.5])));
        $this->assertSame([], $this->ids($this->table()->whereJsonContains('payload->nums', [1, 'x'])));
        $this->assertSame([1, 2], $this->ids($this->table()->whereJsonOverlaps('payload->tags', ['fr', 'de'])));
        $this->assertSame([1], $this->ids($this->table()->whereJsonContainsKey('payload->user->a b')));
        $this->assertSame([1], $this->ids($this->table()->whereJsonContainsKey('payload->n')), 'a key with null');
        $this->assertSame([2, 3], $this->ids($this->table()->whereJsonDoesntContainKey('payload->tags[1]')));
        $this->assertSame([1], $this->ids($this->table()->whereJsonLength('payload->tags', 2)));
        $this->assertSame([2, 3], $this->ids($this->table()->whereJsonLength('payload->tags', '<', 2)));
    }

    public function testASelectorInTheSelectAndTheOrder(): void
    {
        $this->assertSame(
            [
                ['id' => 3, 'user' => '', 'size' => '', 'tags' => ''],
                ['id' => 2, 'user' => '{"name":"Bob"}', 'size' => 'large', 'tags' => '["fr"]'],
                ['id' => 1, 'user' => '{"name":"Ann","a b":"x"}', 'size' => '3', 'tags' => '["en","de"]'],
            ],
            $this->table()
                ->select('id', 'payload->user as user', 'payload->size as size', 'payload->tags as tags')
                ->orderBy('payload->color')
                ->get()
                ->map(fn (object|array $row): array => (array) $row)
                ->all()
        );
    }

    /**
     * An update of JSON paths merges the values into the document of each row that holds a JSON object. A row whose
     * column holds '', null or text that is no JSON keeps it, and the mutation finishes.
     */
    public function testAnUpdateMergesTheValuesOfJsonPaths(): void
    {
        $this->table()->where('id', '>', 0)->settings('mutations_sync', 2)->update([
            'payload->color' => 'green',
            'payload->user->age' => 30,
            'payload->obj' => ['k' => 1],
            'note->b' => 2,
        ]);

        $this->assertSame(
            [
                [
                    1,
                    '{"color":"green","size":3,"active":true,"tags":["en","de"],"nums":[1,2.5],'
                        . '"user":{"name":"Ann","a b":"x","age":30},"n":null,"obj":{"k":1}}',
                    '{"a":1,"b":2}',
                ],
                [
                    2,
                    '{"color":"green","size":"large","active":false,"tags":["fr"],"nums":[3],"user":{"name":"Bob","age":30},'
                        . '"obj":{"k":1}}',
                    null,
                ],
                [3, '', 'not json'],
            ],
            $this->rows()
        );

        $text = "it's \"quoted\"\\\nnew line, é";
        $this->table()->where('id', 1)->settings('mutations_sync', 2)->update(['payload->obj' => ['z' => 2], 'payload->n' => $text]);

        $document = json_decode($this->rows()[0][1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['z' => 2], $document['obj'], 'An object replaces the object at its path.');
        $this->assertSame($text, $document['n']);
        $this->assertSame('green', $document['color']);
        $this->assertSame(
            0,
            (int) $this->client()->select(
                "SELECT count() AS c FROM system.mutations WHERE database = currentDatabase() AND table = '"
                . self::TABLE . "' AND NOT is_done"
            )->fetchOne('c')
        );
    }

    public function testAnInsertIntoAJsonPathIsRefusedBeforeAnythingIsSent(): void
    {
        foreach (['Values', 'JSONEachRow'] as $format) {
            try {
                $this->table()->insert(['id' => 9, 'payload->a' => 1], $format);
                $this->fail("The insert in {$format} should throw");
            } catch (QueryException $exception) {
                $this->assertStringStartsWith('Cannot insert into the JSON path [payload->a]', $exception->getMessage());
            }
        }

        $this->assertSame([1, 2, 3], $this->ids($this->table()));
    }

    /**
     * Laravel's vector queries use cosineDistance(), which ClickHouse 24.8 has too, with or without a vector
     * similarity index.
     */
    public function testVectorQueries(): void
    {
        $this->assertSame([1, 3], $this->table()->whereVectorSimilarTo('embedding', [1, 0, 0], 0.8)->pluck('id')->all());
        $this->assertSame(
            [1, 3],
            $this->table()->orderByVectorDistance('embedding', [1.0, 0.0, 0.0])->limit(2)->pluck('id')->all()
        );

        $distances = $this->table()->select('id')->selectVectorDistance('embedding', [0, 1, 0], 'd')->orderBy('id')->get();
        $this->assertEqualsWithDelta([1.0, 0.0, 0.8895], $distances->pluck('d')->map(fn (mixed $d): float => (float) $d)->all(), 0.0001);
    }

    /**
     * whereFullText() uses hasAllTokens() and hasAnyTokens(), which ClickHouse 26.3 and 26.8 have and 24.8 does not.
     * Tokens compare with their letter case.
     */
    public function testWhereFullText(): void
    {
        try {
            $this->assertSame([1], $this->ids($this->table()->whereFullText('body', 'quick fox')));
            $this->assertSame([1, 3], $this->ids($this->table()->whereFullText('body', 'fox dog', ['mode' => 'any'])));
            $this->assertSame([2], $this->ids($this->table()->whereFullText(['note', 'body'], 'Lazy')->where('id', '>', 0)));
            $this->assertSame([], $this->ids($this->table()->whereFullText('body', 'lazy')));
        } catch (DatabaseException $exception) {
            $this->assertStringStartsWith('24.8', $this->connection()->getServerVersion(), $exception->getMessage());
            $this->assertStringContainsString('UNKNOWN_FUNCTION', $exception->getMessage());
        }
    }

    /**
     * @param QueryBuilder $query
     * @return list<int>
     */
    private function ids(QueryBuilder $query): array
    {
        return array_map('intval', $query->orderBy('id')->pluck('id')->all());
    }

    /**
     * @return list<array{int, string, string|null}>
     */
    private function rows(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['id'], $row['payload'], $row['note']],
            $this->client()->select('SELECT id, payload, note FROM ' . self::TABLE . ' ORDER BY id')->rows()
        );
    }

    private function table(): QueryBuilder
    {
        $query = $this->connection()->table(self::TABLE);
        $this->assertInstanceOf(QueryBuilder::class, $query);

        return $query;
    }

    private function connection(): Connection
    {
        $connection = DB::connection('clickhouse');
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function client(): Client
    {
        return $this->connection()->getClient();
    }
}
