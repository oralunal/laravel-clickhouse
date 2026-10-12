<?php

namespace Tests;

use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\QueryBuilder as Builder;
use PHPUnit\Framework\Attributes\DataProvider;

class ExistsRow extends Model
{
    protected $table = 'exists_rows';

    public $timestamps = false;
}

class ExistsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS exists_rows SYNC');
        $client->write('DROP TABLE IF EXISTS exists_groups SYNC');
        $client->write(
            'CREATE TABLE exists_rows (id UInt32, grp String, arr Array(UInt8), version UInt32 DEFAULT 1)'
            . ' ENGINE = ReplacingMergeTree(version) ORDER BY (id, intHash32(id)) SAMPLE BY intHash32(id)'
        );
        $client->write('CREATE TABLE exists_groups (grp String, label String) ENGINE = MergeTree ORDER BY grp');
        $client->write("INSERT INTO exists_rows (id, grp, arr) VALUES (1, 'a', [1, 2]), (2, 'a', []), (3, 'b', [3])");
        $client->write("INSERT INTO exists_groups VALUES ('a', 'Group A')");
    }

    protected function tearDown(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS exists_rows SYNC');
        $client->write('DROP TABLE IF EXISTS exists_groups SYNC');
        parent::tearDown();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, bool}>
     */
    public static function queryProvider(): array
    {
        return [
            'matching where' => [fn (Builder $q) => $q->where('id', 1), true],
            'no matching row' => [fn (Builder $q) => $q->where('id', 999), false],
            'columns and order by' => [fn (Builder $q) => $q->select(['id', 'grp'])->where('grp', 'b')->orderBy('id'), true],
            'join with a match' => [
                fn (Builder $q) => $q->anyInnerJoin('exists_groups', 'exists_rows.grp', '=', 'exists_groups.grp')->where('label', 'Group A'),
                true,
            ],
            'join without a match' => [
                fn (Builder $q) => $q->anyInnerJoin('exists_groups', 'exists_rows.grp', '=', 'exists_groups.grp')->where('id', 3),
                false,
            ],
            'prewhere with a match' => [fn (Builder $q) => $q->preWhere('grp', 'b'), true],
            'prewhere without a match' => [fn (Builder $q) => $q->preWhere('grp', 'z'), false],
            'having with a match' => [
                fn (Builder $q) => $q->select('grp', DB::raw('count() as c'))->groupBy('grp')->having('c', '>', 1),
                true,
            ],
            'having without a match' => [
                fn (Builder $q) => $q->select('grp', DB::raw('count() as c'))->groupBy('grp')->having('c', '>', 5),
                false,
            ],
            'aggregate without group by still returns a row' => [
                fn (Builder $q) => $q->select(DB::raw('count() as c'))->where('id', 999),
                true,
            ],
            'alias used in where' => [fn (Builder $q) => $q->select('id as x')->where('x', 3), true],
            'union with a match in the second query' => [
                fn (Builder $q) => $q->where('id', 999)->unionAll(fn (Builder $u) => $u->from('exists_rows')->where('id', 1)),
                true,
            ],
            'union without a match' => [
                fn (Builder $q) => $q->where('id', 999)->unionAll(fn (Builder $u) => $u->from('exists_rows')->where('id', 998)),
                false,
            ],
            'final with a match' => [fn (Builder $q) => $q->final()->where('id', 1), true],
            'final without a match' => [fn (Builder $q) => $q->final()->where('id', 999), false],
            'sample' => [fn (Builder $q) => $q->sample(1)->where('id', 1), true],
            'offset before the last row' => [fn (Builder $q) => $q->limit(10)->offset(2), true],
            'offset past the last row' => [fn (Builder $q) => $q->limit(10)->offset(3), false],
            'array join of a non-empty array' => [fn (Builder $q) => $q->arrayJoin('arr')->where('id', 1), true],
            'array join of an empty array' => [fn (Builder $q) => $q->arrayJoin('arr')->where('id', 2), false],
            'limit by' => [fn (Builder $q) => $q->limitBy(1, 'grp')->where('grp', 'a'), true],
            'with fill adds rows' => [
                fn (Builder $q) => $q->select(['id'])->where('id', 999)->orderByRaw('id WITH FILL FROM 0 TO 3'),
                true,
            ],
            'settings' => [
                fn (Builder $q) => $q->where('id', 1)->settings(['max_threads' => 1, 'use_uncompressed_cache' => false]),
                true,
            ],
            'aggregate with alias without a matching row still returns a row' => [
                fn (Builder $q) => $q->withAlias('c', DB::raw('count()'))->select('c')->where('id', 999),
                true,
            ],
            'array join with alias of an empty array' => [
                fn (Builder $q) => $q->withAlias('item', DB::raw('arrayJoin(arr)'))->select('item')->where('id', 2),
                false,
            ],
            'raw array join of an empty array' => [
                fn (Builder $q) => $q->select(DB::raw('arrayJoin(arr) as item'))->where('id', 2),
                false,
            ],
            'raw array join of a non-empty array' => [
                fn (Builder $q) => $q->select(DB::raw('arrayJoin(arr) as item'))->where('id', 1),
                true,
            ],
            'array join with alias of an empty array, only in order by' => [
                fn (Builder $q) => $q->withAlias('item', DB::raw('arrayJoin(arr)'))->where('id', 2)->orderBy('item'),
                false,
            ],
            'except of the highest id' => [
                fn (Builder $q) => $q->select('id')->orderBy('id', 'desc')->limit(1)
                    ->except(fn (Builder $e) => $e->select('id')->from('exists_rows')->where('id', '<', 3)),
                true,
            ],
            'intersect of the highest id' => [
                fn (Builder $q) => $q->select('id')->orderBy('id', 'desc')->limit(1)
                    ->intersect(fn (Builder $i) => $i->select('id')->from('exists_rows')->where('id', '<', 3)),
                false,
            ],
            'except on two columns that each match on their own' => [
                fn (Builder $q) => $q->select('grp', 'id')
                    ->except(fn (Builder $e) => $e->select(DB::raw("if(grp = 'a', 'b', 'a')"), 'id')->from('exists_rows')),
                true,
            ],
            'intersect on two columns that each match on their own' => [
                fn (Builder $q) => $q->select('grp', 'id')
                    ->intersect(fn (Builder $i) => $i->select(DB::raw("if(grp = 'a', 'b', 'a')"), 'id')->from('exists_rows')),
                false,
            ],
            'random order with a match' => [fn (Builder $q) => $q->where('grp', 'b')->inRandomOrder(), true],
            'random order without a match' => [fn (Builder $q) => $q->where('grp', 'z')->inRandomOrder(), false],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param bool $expected
     */
    #[DataProvider('queryProvider')]
    public function testExistsMatchesWhetherTheQueryReturnsARow(Closure $query, bool $expected): void
    {
        $builder = fn (): Builder => $query(DB::connection('clickhouse')->table('exists_rows'));

        $this->assertSame($expected, $builder()->get()->isNotEmpty(), 'the query itself');
        $this->assertSame($expected, $builder()->exists());
        $this->assertSame(!$expected, $builder()->doesntExist());
    }

    public function testExistsAsksClickHouseWhetherTheQueryReturnsARow(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $connection->table('exists_rows')->where('id', '>', 0)->exists();

        $this->assertSame(
            ['select exists(select * from "exists_rows" where "id" > ?) as "exists"'],
            array_column($connection->getQueryLog(), 'query')
        );
    }

    public function testEmptyTable(): void
    {
        DB::connection('clickhouse')->table('exists_rows')->truncate();

        $this->assertFalse(DB::connection('clickhouse')->table('exists_rows')->exists());
        $this->assertTrue(DB::connection('clickhouse')->table('exists_rows')->doesntExist());
    }

    public function testSchemaQualifiedTable(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertTrue($connection->table($connection->getDatabaseName() . '.exists_rows')->exists());
    }

    public function testModel(): void
    {
        $this->assertTrue(ExistsRow::where('id', 1)->exists());
        $this->assertTrue(ExistsRow::where('id', 999)->doesntExist());
        $this->assertTrue(ExistsRow::select(['id'])->exists());
    }

    /**
     * Queries whose rows count() and paginate() cannot count by replacing the
     * columns with count(). The set operations compare two columns, and each
     * column on its own would match differently. An arrayJoin() or an aggregate
     * in the select list decides the number of rows, and an alias can be used
     * by a condition, also an alias that an ORDER BY entry defines: b < 5 reads
     * the alias b of the column a, which matches three rows, while the column b
     * matches one. WITH FILL in the ORDER BY adds rows, in each group of the
     * entries before it, an arrayJoin() in it multiplies them, and LIMIT BY in
     * its raw SQL leaves rows out; an ORDER BY that only sorts is counted in
     * place.
     *
     * @return array<string, array{Closure(Builder): Builder, int}>
     */
    public static function countProvider(): array
    {
        $first = "values('g String, n UInt8', ('a', 1), ('a', 2), ('b', 3), ('b', 3))";
        $second = "values('g String, n UInt8', ('a', 1), ('b', 2), ('c', 3))";
        $setOperation = fn (string $method): Closure => fn (Builder $q): Builder => $q
            ->select('g', 'n')
            ->fromRaw($first)
            ->{$method}(fn (Builder $other) => $other->select('g', 'n')->fromRaw($second));
        $tags = "values('id UInt64, tags Array(String)', (1, ['a', 'b', 'c']), (2, []), (3, ['d']))";
        $orderAliasRows = fn (Builder $q): Builder => $q
            ->select('a')
            ->fromRaw("values('a UInt32, b UInt32', (1, 10), (2, 20), (3, 30), (10, 1))")
            ->where('b', '<', 5);

        return [
            'union all' => [$setOperation('unionAll'), 7],
            'union distinct' => [$setOperation('unionDistinct'), 5],
            'intersect' => [$setOperation('intersect'), 1],
            'intersect distinct' => [$setOperation('intersectDistinct'), 1],
            'except' => [$setOperation('except'), 3],
            'except distinct' => [$setOperation('exceptDistinct'), 2],
            'group by with several groups of two rows' => [
                fn (Builder $q): Builder => $q
                    ->select('g', DB::raw('count() as c'))
                    ->fromRaw("values('g String, n UInt8', ('a', 1), ('a', 2), ('b', 3), ('b', 4), ('c', 5), ('c', 6), ('d', 7), ('d', 8))")
                    ->groupBy('g')
                    ->orderBy('g'),
                4,
            ],
            'having' => [
                fn (Builder $q): Builder => $q
                    ->select('g', DB::raw('count() as c'))
                    ->fromRaw("values('g String, n UInt8', ('a', 1), ('b', 2), ('b', 3), ('c', 4), ('c', 5), ('d', 6), ('d', 7), ('d', 8))")
                    ->groupBy('g')
                    ->having('c', '>', 1),
                3,
            ],
            'with alias array join' => [
                fn (Builder $q): Builder => $q->withAlias('tag', DB::raw('arrayJoin(tags)'))->select('tag')->fromRaw($tags),
                4,
            ],
            'with alias aggregate without a matching row' => [
                fn (Builder $q): Builder => $q->withAlias('c', DB::raw('count()'))->select('c')->fromRaw($tags)->where('id', 999),
                1,
            ],
            'raw array join column' => [
                fn (Builder $q): Builder => $q->select(DB::raw('arrayJoin(tags) AS tag'))->fromRaw($tags),
                4,
            ],
            'raw aggregate column without a matching row' => [
                fn (Builder $q): Builder => $q->select(DB::raw('count() AS c'))->fromRaw($tags)->where('id', 999),
                1,
            ],
            'alias used in where' => [
                fn (Builder $q): Builder => $q->select('id as x')->fromRaw($tags)->where('x', 3),
                1,
            ],
            'with fill adds rows' => [
                fn (Builder $q): Builder => $q->select('id')->fromRaw($tags)->where('id', 999)->orderByRaw('id WITH FILL FROM 0 TO 3'),
                3,
            ],
            'with fill adds rows between the matching ones' => [
                fn (Builder $q): Builder => $q->select('id')->fromRaw($tags)->orderByRaw('id with fill FROM 0 TO 10'),
                10,
            ],
            'with fill after a plain order by fills each group of it' => [
                fn (Builder $q): Builder => $q
                    ->select('g', 'n')
                    ->fromRaw("values('g String, n UInt8', ('a', 1), ('a', 3), ('b', 1), ('b', 3))")
                    ->orderBy('g')
                    ->orderByRaw('n WITH FILL'),
                6,
            ],
            'raw order by with limit by keeps some rows of each group' => [
                fn (Builder $q): Builder => $q
                    ->select('g', 'n')
                    ->fromRaw("values('g String, n UInt8', ('a', 1), ('a', 2), ('a', 3), ('b', 4), ('c', 5))")
                    ->orderBy('g')
                    ->orderByRaw('n DESC LIMIT 2 BY g'),
                4,
            ],
            'order by a with alias array join' => [
                fn (Builder $q): Builder => $q->withAlias('tag', DB::raw('arrayJoin(tags)'))->select('id')->fromRaw($tags)->orderBy('tag'),
                4,
            ],
            'raw order by an array join' => [
                fn (Builder $q): Builder => $q->select('id')->fromRaw($tags)->orderByRaw('arrayJoin(tags)'),
                4,
            ],
            'raw order by that mentions a with alias array join' => [
                fn (Builder $q): Builder => $q->withAlias('tag', DB::raw('arrayJoin(tags)'))->fromRaw($tags)->orderByRaw('length(tag) DESC'),
                4,
            ],
            'raw order by that only sorts' => [
                fn (Builder $q): Builder => $q->select('id')->fromRaw($tags)->where('id', '>', 1)->orderByRaw('id DESC'),
                2,
            ],
            'order by a name with as, whose alias the where condition reads' => [
                fn (Builder $q): Builder => $orderAliasRows($q)->orderBy('a as b'),
                3,
            ],
            'raw order by with AS, whose alias the where condition reads' => [
                fn (Builder $q): Builder => $orderAliasRows($q)->orderByRaw('a AS b'),
                3,
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param int $rows
     */
    #[DataProvider('countProvider')]
    public function testCountAndPaginateMatchTheRowsOfTheQuery(Closure $query, int $rows): void
    {
        $builder = fn (): Builder => $query(DB::connection('clickhouse')->query());

        $this->assertCount($rows, $builder()->get(), 'the query itself');
        $this->assertSame($rows, $builder()->count());
        $this->assertSame($rows, $builder()->paginate(2)->total());
        $this->assertSame($rows > 0, $builder()->exists());
    }
}
