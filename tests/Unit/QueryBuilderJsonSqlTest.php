<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\LiteralGrammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SQL of Laravel's JSON selectors, such as payload->a, of whereJsonContains() and its variants, of an update of
 * JSON paths, of whereFullText() and of the vector queries on a ClickHouse connection. tests/JsonPathTest.php runs
 * them against ClickHouse.
 */
class QueryBuilderJsonSqlTest extends TestCase
{
    /**
     * A query builder of the package on a stub ClickHouse connection, which records the updates and deletes it is
     * given, with their bindings, instead of sending them.
     *
     * @param list<array{string, list<mixed>}> $statements The recorded statements
     * @return QueryBuilder
     */
    private function query(array &$statements = []): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newLiteralGrammar')->willReturnCallback(fn (): LiteralGrammar => new LiteralGrammar());
        $record = function (string $sql, array $bindings = []) use (&$statements): int {
            $statements[] = [$sql, $bindings];

            return 1;
        };
        $connection->method('update')->willReturnCallback($record);
        $connection->method('delete')->willReturnCallback($record);
        $grammar = new QueryGrammar($connection);
        $connection->method('getQueryGrammar')->willReturn($grammar);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from('t');
    }

    public function test_a_json_selector_reads_its_value_as_a_string(): void
    {
        $this->assertSame(
            'select JSONExtractString("payload", \'user\', \'name\') as "name" from "t"'
                . ' order by JSONExtractString("payload", \'size\') desc',
            $this->query()->select('payload->user->name as name')->orderByDesc('payload->size')->toSql()
        );
        $this->assertSame(
            'select * from "t" where JSONExtractString("t"."payload", \'color\') = ?'
                . ' group by JSONExtractString("payload", \'color\')',
            $this->query()->where('t.payload->color', 'red')->groupBy('payload->color')->toSql()
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function jsonPaths(): array
    {
        return [
            'array index from 0' => ['payload->tags[0]', '"payload", \'tags\', 1'],
            'nested indexes' => ['payload->m[1][2]->a', '"payload", \'m\', 2, 3, \'a\''],
            'negative index from the end' => ['payload->tags[-1]', '"payload", \'tags\', -1'],
            'an index first' => ['payload->[0]', '"payload", 1'],
            'brackets without a number are part of the key' => ['payload->a[b]', '"payload", \'a[b]\''],
            'a space and a dot' => ['payload->a b.c', '"payload", \'a b.c\''],
            'a quote and a backslash' => ["payload->it's\\", '"payload", \'it\\\'s\\\\\''],
            'a key that is a number' => ['payload->0', '"payload", \'0\''],
        ];
    }

    #[DataProvider('jsonPaths')]
    public function test_the_keys_of_a_path_are_string_literals_and_indexes_count_from_1(string $selector, string $arguments): void
    {
        $this->assertSame("select JSONExtractString({$arguments}) from \"t\"", $this->query()->select($selector)->toSql());
    }

    public function test_a_number_is_compared_with_the_value_read_as_a_float(): void
    {
        $number = 'JSONExtract("payload", \'size\', \'Nullable(Float64)\')';
        $string = 'JSONExtractString("payload", \'size\')';

        $this->assertSame("select * from \"t\" where {$number} > ?", $this->query()->where('payload->size', '>', 2)->toSql());
        $this->assertSame("select * from \"t\" where {$number} = ?", $this->query()->where('payload->size', 2.5)->toSql());
        $this->assertSame("select * from \"t\" where {$string} = ?", $this->query()->where('payload->size', '2')->toSql());
        $this->assertSame("select * from \"t\" where {$number} in (?, ?)", $this->query()->whereIn('payload->size', [1, 2.5])->toSql());
        $this->assertSame("select * from \"t\" where {$string} in (?, ?)", $this->query()->whereIn('payload->size', [1, 'l'])->toSql());
        $this->assertSame("select * from \"t\" where {$number} not in (?)", $this->query()->whereNotIn('payload->size', [1])->toSql());
        $this->assertSame("select * from \"t\" where {$number} in (1, 2)", $this->query()->whereIntegerInRaw('payload->size', [1, 2])->toSql());
        $this->assertSame("select * from \"t\" where {$number} not in (1)", $this->query()->whereIntegerNotInRaw('payload->size', [1])->toSql());
        $this->assertSame("select * from \"t\" where {$number} between ? and ?", $this->query()->whereBetween('payload->size', [1, 5])->toSql());
        $this->assertSame("select * from \"t\" where {$string} not between ? and ?", $this->query()->whereNotBetween('payload->size', ['a', 'c'])->toSql());
        $this->assertSame("select * from \"t\" where {$string} ilike ?", $this->query()->whereLike('payload->size', 'l%')->toSql());
    }

    public function test_a_boolean_is_compared_with_the_value_read_as_a_bool_and_null_with_its_json_type(): void
    {
        $this->assertSame(
            'select * from "t" where JSONExtract("payload", \'active\', \'Nullable(Bool)\') = true'
                . ' or JSONExtract("payload", \'gone\', \'Nullable(Bool)\') = false',
            $this->query()->where('payload->active', true)->orWhere('payload->gone', false)->toSql()
        );
        $this->assertSame(
            'select * from "t" where JSONType("payload", \'a\') = \'Null\' and JSONType("payload", \'b\', 1) != \'Null\'',
            $this->query()->whereNull('payload->a')->whereNotNull('payload->b[0]')->toSql()
        );
    }

    public function test_json_contains_overlaps_contains_key_and_length(): void
    {
        $query = $this->query()
            ->whereJsonContains('payload->tags', 'en')
            ->whereJsonDoesntContain('payload->nums', [1, 2.5])
            ->orWhereJsonContains('payload->flags', [true])
            ->whereJsonOverlaps('payload->tags', ['fr', 'de'])
            ->whereJsonContains('payload->mixed', [1, 'a', null])
            ->whereJsonContains('payload->list', [])
            ->whereJsonContainsKey('payload->user->a b')
            ->whereJsonDoesntContainKey('payload->tags[1]')
            ->whereJsonLength('payload->tags', '>', 1);

        $this->assertSame(
            'select * from "t" where hasAll(JSONExtract("payload", \'tags\', \'Array(String)\'), JSONExtract(?, \'Array(String)\'))'
                . ' and not hasAll(JSONExtract("payload", \'nums\', \'Array(Float64)\'), JSONExtract(?, \'Array(Float64)\'))'
                . ' or hasAll(JSONExtract("payload", \'flags\', \'Array(Bool)\'), JSONExtract(?, \'Array(Bool)\'))'
                . ' and hasAny(JSONExtract("payload", \'tags\', \'Array(String)\'), JSONExtract(?, \'Array(String)\'))'
                . ' and hasAll(JSONExtractArrayRaw("payload", \'mixed\'), JSONExtractArrayRaw(?))'
                . ' and hasAll(JSONExtract("payload", \'list\', \'Array(String)\'), JSONExtract(?, \'Array(String)\'))'
                . ' and JSONHas("payload", \'user\', \'a b\') and not JSONHas("payload", \'tags\', 2)'
                . ' and JSONLength("payload", \'tags\') > ?',
            $query->toSql()
        );
        $this->assertSame(
            ['["en"]', '[1,2.5]', '[true]', '["fr","de"]', '[1,"a",null]', '[]', 1],
            $query->getBindings()
        );
        $this->assertSame(
            'select * from "t" where hasAll(JSONExtractArrayRaw("payload"), JSONExtractArrayRaw(json_array))',
            $this->query()->whereJsonContains('payload', new Expression('json_array'))->toSql()
        );
    }

    public function test_json_contains_refuses_an_object(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'whereJsonContains() and whereJsonOverlaps() take a value or a list of values on ClickHouse, not an array'
            . ' with keys, which is a JSON object'
        );

        $this->query()->whereJsonContains('payload', ['a' => 1]);
    }

    public function test_an_update_merges_the_values_of_json_paths_into_their_column(): void
    {
        $statements = [];
        $this->query($statements)->where('id', 7)->update([
            'name' => 'x',
            'payload->a' => 1,
            'tags' => ['p', 'q'],
            'payload->b->c' => "it's",
            'meta->0' => null,
            'score' => 2,
        ]);

        $patched = fn (string $column, string $patches): string => "if(JSONType(\"{$column}\") = 'Object',"
            . " jsonMergePatch(if(JSONType(\"{$column}\") = 'Object', \"{$column}\", '{}'), {$patches}), \"{$column}\")";
        $this->assertSame(
            [[
                'alter table "t" update "name" = ?, "tags" = [\'p\', \'q\'], "score" = ?, "payload" = '
                    . $patched('payload', '\'{"a":1,"b":{"c":"it\\\'s"}}\'') . ', "meta" = '
                    . $patched('meta', '\'{"0":null}\'') . ' where "id" = ?',
                ['x', 2, 7],
            ]],
            $statements
        );
    }

    public function test_an_object_value_replaces_the_object_at_its_path(): void
    {
        $statements = [];
        $this->query($statements)->where('id', 7)->update(['payload->o' => ['k' => 1.0, 'd' => [1, 2]], 'payload->n' => "é/\"q\n"]);

        $this->assertSame(
            'alter table "t" update "payload" = if(JSONType("payload") = \'Object\', jsonMergePatch(if(JSONType("payload")'
                . ' = \'Object\', "payload", \'{}\'), \'{"o":null}\', \'{"o":{"k":1.0,"d":[1,2]},"n":"é/\\\\"q\\\\n"}\'),'
                . ' "payload")'
                . ' where "id" = ?',
            $statements[0][0]
        );
        $this->assertSame([7], $statements[0][1]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function jsonPathsAnUpdateCannotSet(): array
    {
        return [
            'an array element' => [
                ['payload->tags[0]' => 'x'],
                'Cannot update the JSON path [payload->tags[0]]: jsonMergePatch() sets the keys of objects, not the'
                . ' elements of arrays. Update the whole column.',
            ],
            'SQL' => [
                ['payload->n' => new Expression('n + 1')],
                'Cannot update the JSON path [payload->n]: the value is SQL, and the package writes the values of JSON'
                . ' paths into a JSON patch. Update the whole column, such as with DB::raw("jsonMergePatch(...)").',
            ],
            'the column too' => [
                ['payload' => '{}', 'payload->a' => 1],
                'Cannot update the JSON path [payload->a]: the update also sets the column [payload].',
            ],
            'a value that is no JSON' => [
                ['payload->a' => NAN],
                'Cannot update the JSON path [payload->a]: a value cannot be written as JSON: Inf and NaN cannot be'
                . ' JSON encoded.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('jsonPathsAnUpdateCannotSet')]
    public function test_an_update_refuses_a_json_path_that_it_cannot_set(array $values, string $message): void
    {
        $statements = [];

        try {
            $this->query($statements)->where('id', 7)->update($values);
            $this->fail('The update should throw');
        } catch (QueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame([], $statements);
    }

    public function test_an_insert_refuses_a_json_path_as_a_column(): void
    {
        $query = $this->query();
        $grammar = $query->getGrammar();
        $this->assertInstanceOf(QueryGrammar::class, $grammar);
        $message = 'Cannot insert into the JSON path [payload->a]: an insert writes whole columns. Insert the JSON'
            . " document into the column [payload], such as json_encode(['a' => \$value]).";

        foreach ([
            fn () => $grammar->compileInsert($query, [['id' => 1, 'payload->a' => 1]]),
            fn () => $grammar->compileInsert($query, ['payload->a' => 1]),
            fn () => $grammar->compileInsertUsing($query, ['id', 'payload->a'], 'select 1, 2'),
        ] as $compile) {
            try {
                $compile();
                $this->fail('The insert should throw');
            } catch (QueryException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_a_delete_reads_json_paths_without_the_table_qualifier(): void
    {
        $statements = [];
        $this->query($statements)->where('t.payload->a', 1)->delete(lightweight: false);

        $this->assertSame(
            [['alter table "t" delete where JSONExtract("payload", \'a\', \'Nullable(Float64)\') = ?', [1]]],
            $statements
        );
    }

    public function test_where_full_text_uses_the_token_functions(): void
    {
        $query = $this->query()->whereFullText('body', 'quick fox')->orWhereFullText(['title', 'body'], 'dog', ['mode' => 'any']);

        $this->assertSame(
            'select * from "t" where hasAllTokens("body", ?) or (hasAnyTokens("title", ?) or hasAnyTokens("body", ?))',
            $query->toSql()
        );
        $this->assertSame(['quick fox', 'dog', 'dog'], $query->getBindings());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("whereFullText() takes the mode 'all' or 'any' on ClickHouse, [boolean] given.");

        $this->query()->whereFullText('body', 'x', ['mode' => 'boolean']);
    }

    public function test_the_vector_queries_use_the_cosine_distance(): void
    {
        $query = $this->query()
            ->select('id')
            ->selectVectorDistance('embedding', [1, 0.5], 'd')
            ->whereVectorSimilarTo('embedding', [1, 0.5], 0.75);

        $distance = 'cosineDistance("embedding", CAST(? AS Array(Float32)))';
        $this->assertSame(
            "select \"id\", {$distance} as \"d\" from \"t\" where {$distance} <= ? order by {$distance} asc",
            $query->toSql()
        );
        $this->assertSame(['[1,0.5]', '[1,0.5]', 0.25, '[1,0.5]'], $query->getBindings());
    }
}
