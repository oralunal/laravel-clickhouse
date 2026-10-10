<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;

/**
 * An Eloquent model on the connection with Laravel's query builder, whose $incrementing stays true.
 */
class LaravelQueryBuilderRow extends Model
{
    protected $connection = 'clickhouse-laravel-builder';
    protected $table = 'lqb_rows';
    public $timestamps = false;
    protected $guarded = [];
}

/**
 * Laravel's query builder on a ClickHouse connection, as fix_default_query_builder = false hands it out: the
 * package's QueryBuilder with the package's QueryGrammar, against the server.
 *
 * The connection is a copy of the default connection (database and nodes), with mutations_sync = 2 so that a
 * mutation has changed the rows when it returns.
 */
class LaravelQueryBuilderTest extends TestCase
{
    private const CONNECTION = 'clickhouse-laravel-builder';

    private const ROWS = 'lqb_rows';

    private const DATES = 'lqb_dates';

    private const FLOATS = 'lqb_floats';

    private const NAMES = 'lqb_names';

    private const TABLES = [self::ROWS, self::DATES, self::FLOATS, self::NAMES];

    /**
     * Create ROWS: id 1 to 4 with mixed-case names, flags 1, 256, 257 and 2, a Nullable note that is NULL for 1 and 3,
     * and tags whose array is empty for 2.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection();
        $client = $this->client();
        foreach (self::TABLES as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
        }
        $client->write(
            'CREATE TABLE ' . self::ROWS . ' (id UInt32, name String, flag UInt32, note Nullable(String),'
            . ' tags Array(String), created_at DateTime DEFAULT \'2024-01-01 00:00:00\','
            . ' INDEX idx_name name TYPE bloom_filter GRANULARITY 1)'
            . ' ENGINE = MergeTree ORDER BY id SETTINGS index_granularity = 1'
        );
        $client->write(
            'INSERT INTO ' . self::ROWS . ' (id, name, flag, note, tags) VALUES'
            . " (1, 'a', 1, NULL, ['x']), (2, 'A', 256, 'n2', []), (3, 'c', 257, NULL, ['x', 'y']), (4, 'd', 2, 'n4', ['z'])"
        );
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->client()->write("DROP TABLE IF EXISTS {$table} SYNC");
        }
        DB::purge(self::CONNECTION);
        parent::tearDown();
    }

    public function testTheConnectionHandsOutThePackageQueryBuilder(): void
    {
        $query = $this->connection()->table(self::ROWS);

        $this->assertInstanceOf(QueryBuilder::class, $query);
        $this->assertInstanceOf(LaravelBuilder::class, $query);
        $this->assertInstanceOf(QueryBuilder::class, LaravelQueryBuilderRow::query()->toBase());
    }

    // Placeholders and bindings

    public function testQuestionMarkPlaceholdersOfRawSqlAreWrittenIntoTheSql(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        $this->assertSame([['id' => 2]], $connection->select('select id from ' . self::ROWS . ' where id = ?', [2]));
        $this->assertSame(
            [['id' => 2]],
            $connection->select('select id from ' . self::ROWS . ' where id = ? and name = ?', [2, 'A'])
        );
        $this->assertSame(
            [['query' => 'select id from ' . self::ROWS . ' where id = ?', 'bindings' => [2]]],
            array_map(fn (array $entry): array => ['query' => $entry['query'], 'bindings' => $entry['bindings']], array_slice($connection->getQueryLog(), 0, 1)),
            'the query log keeps "?" and the bindings'
        );
    }

    public function testRawSqlWithTheTernaryOperatorLiteralsHeredocsAndComments(): void
    {
        $connection = $this->connection();

        $this->assertSame([['t' => 10]], $connection->select('select ? > 1 ?? 10 : 20 as t', [5]));
        $this->assertSame([['t' => 10]], $connection->select('select 5 > 1 ? 10 : 20 as t'));
        $this->assertSame([['q?' => '?', 'b' => 7]], $connection->select("select '?' as \"q?\", -- why?\n ? as b", [7]));
        $this->assertSame([['h' => 'a?b', 'v' => 1]], $connection->select('select $$a?b$$ as h, ? as v', [1]));
    }

    public function testSmi2PlaceholdersAndQueryParametersWorkAsBefore(): void
    {
        $connection = $this->connection();

        $this->assertSame([['id' => 3]], $connection->select('select id from ' . self::ROWS . ' where id = :id', ['id' => 3]));
        $this->assertSame([['id' => 3]], $connection->select('select id from {table} where id = 3', ['table' => self::ROWS]));
        $this->assertSame([['v' => 5]], $connection->select('select {p:UInt8} + 1 as v', ['p' => 4]));
    }

    public function testACountMismatchThrowsBeforeAnythingIsSent(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        try {
            $connection->select('select ? as a', [1, 2]);
            $this->fail('A count mismatch should throw');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The query has 1 "?" placeholder, but 2 bindings were given. Write a literal "?" as "??".',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
    }

    public function testBuilderBindingsAreWrittenAsLiterals(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame([3], $this->ids($query()->whereRaw('id > ?', [1])->where('name', 'c')));
        $this->assertSame([1, 3], $this->ids($query()->where('flag', '!=', false)->where('id', '!=', 2)->whereIn('id', [1, 3])));
        $this->assertSame([1, 3], $this->ids($query()->whereIn('note', [null])->orWhereNull('note')));
        $this->assertSame([1], $this->ids($query()->whereIn('id', range(1, 41))->where('name', 'a')->orWhereRaw("name = 'a:30'")));
    }

    /**
     * Str::of('0 or 1 = 1') was written unquoted, so the condition matched every row.
     */
    public function testAStringableValueIsQuoted(): void
    {
        $query = $this->connection()->table(self::ROWS)->where('name', Str::of("x' or 1 = 1 or name = '"));
        $this->assertSame([], $this->ids($query));

        $this->expectException(DatabaseException::class);
        $this->connection()->table(self::ROWS)->where('id', Str::of('0 or 1 = 1'))->get();
    }

    /**
     * smi2 read :30 in 'a:30' as the binding of :30 once there were 31 bindings.
     */
    public function testAColonInAStringLiteralIsKept(): void
    {
        $query = $this->connection()->table(self::ROWS)->whereIn('id', range(100, 140))->orWhereRaw("name = 'a:30'");
        $this->client()->write('INSERT INTO ' . self::ROWS . " (id, name, flag, tags) VALUES (5, 'a:30', 0, [])");

        $this->assertSame([5], $this->ids($query));
    }

    public function testAValueThatNamesAFormatReturnsItsRow(): void
    {
        $this->client()->write('INSERT INTO ' . self::ROWS . " (id, name, flag, tags) VALUES (6, 'a FORMAT CSV', 0, [])");

        $this->assertSame([6], $this->ids($this->connection()->table(self::ROWS)->where('name', 'a FORMAT CSV')));
    }

    public function testInsertWritesFalseNullArraysDatesAndEnums(): void
    {
        $this->connection()->table(self::ROWS)->insert([
            ['id' => 10, 'name' => StringBackedEnumFixture::Active, 'flag' => false, 'note' => null, 'tags' => ['p', "q'"], 'created_at' => Carbon::parse('2024-05-06 07:08:09')],
            ['id' => 11, 'name' => 'e', 'flag' => IntBackedEnumFixture::High, 'note' => 'x', 'tags' => [], 'created_at' => Carbon::parse('2024-05-06 07:08:10')],
        ]);

        $this->assertSame(
            [
                ['id' => 10, 'name' => 'active', 'flag' => 0, 'note' => null, 'tags' => ['p', "q'"], 'created_at' => '2024-05-06 07:08:09'],
                ['id' => 11, 'name' => 'e', 'flag' => 3, 'note' => 'x', 'tags' => [], 'created_at' => '2024-05-06 07:08:10'],
            ],
            $this->connection()->table(self::ROWS)->where('id', '>=', 10)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()
        );
    }

    /**
     * insert() writes a collection value as an array literal, as update() does, also as the first value of a row.
     */
    public function testInsertWritesACollectionAsAnArray(): void
    {
        $this->connection()->table(self::ROWS)->insert(['tags' => collect(['p', 'q']), 'id' => 10, 'name' => 'e', 'flag' => 0]);
        $this->connection()->table(self::ROWS)->insert([
            ['id' => 11, 'name' => 'f', 'flag' => 0, 'tags' => LazyCollection::make(fn () => yield from ['r'])],
        ]);

        $this->assertSame(
            [[10, ['p', 'q']], [11, ['r']]],
            $this->connection()->table(self::ROWS)->where('id', '>=', 10)->orderBy('id')->get(['id', 'tags'])
                ->map(fn ($row) => array_values((array) $row))->all()
        );
    }

    /**
     * smi2 replaced {0} in a column name with the first binding.
     */
    public function testAColumnNameWithBracesIsKept(): void
    {
        $this->client()->write('CREATE TABLE ' . self::NAMES . ' (id UInt8, `{0}` String, `na\\\\me` String) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::NAMES . " VALUES (1, 'a', 'b')");

        $this->connection()->table(self::NAMES)->where('id', 1)->update(['{0}' => 'ABC']);

        $this->assertSame([['{0}' => 'ABC']], $this->connection()->table(self::NAMES)->select('{0}')->get()->map(fn ($row) => (array) $row)->all());
    }

    /**
     * ClickHouse reads backslash escapes inside double-quoted identifiers, so the backslash of a name is doubled.
     */
    public function testANameWithABackslashIsEscaped(): void
    {
        $this->client()->write('CREATE TABLE ' . self::NAMES . ' (id UInt8, `{0}` String, `na\\\\me` String) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::NAMES . " VALUES (1, 'a', 'b'), (2, 'c', 'd')");

        $query = $this->connection()->table(self::NAMES)->select('na\\me')->where('na\\me', 'd');

        $this->assertSame('select "na\\\\me" from "lqb_names" where "na\\\\me" = ?', $query->toSql());
        $this->assertSame([['na\\me' => 'd']], $query->get()->map(fn ($row) => (array) $row)->all());
    }

    public function testToSqlShowsPlaceholdersAndToRawSqlTheValues(): void
    {
        $query = $this->connection()->table(self::ROWS)->where('name', "it's")->where('id', 1);

        $this->assertSame('select * from "lqb_rows" where "name" = ? and "id" = ?', $query->toSql());
        $this->assertSame("select * from \"lqb_rows\" where \"name\" = 'it\\'s' and \"id\" = 1", $query->toRawSql());
    }

    /**
     * Bindings are written with every digit, so a computed float matches the value ClickHouse computed, and an
     * update stores the exact double.
     */
    public function testFloatBindingsAreExact(): void
    {
        $this->client()->write('CREATE TABLE ' . self::FLOATS . ' (id UInt8, f Float64) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO ' . self::FLOATS . ' SELECT 1, 0.1 + 0.2 UNION ALL SELECT 2, 0.3');
        $query = fn (): QueryBuilder => $this->connection()->table(self::FLOATS);

        $this->assertSame([1], $this->ids($query()->where('f', 0.1 + 0.2)));
        $this->assertSame([2], $this->ids($query()->where('f', 0.3)));

        $query()->where('id', 2)->update(['f' => 1 / 3]);

        $this->assertSame(1 / 3, $query()->where('id', 2)->value('f'));
        $this->assertSame([2], $this->ids($query()->where('f', 1 / 3)));
    }

    // count() and paginate()

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, int}>
     */
    public static function countProvider(): array
    {
        return [
            'a select alias that a condition reads' => [fn (QueryBuilder $query) => $query->select('id as x')->where('x', 3), 1],
            'WITH FILL' => [fn (QueryBuilder $query) => $query->select('id')->where('id', 999)->orderByRaw('id WITH FILL FROM 0 TO 3'), 3],
            'arrayJoin()' => [fn (QueryBuilder $query) => $query->selectRaw('arrayJoin(tags) as tag'), 4],
            'an order that calls arrayJoin()' => [fn (QueryBuilder $query) => $query->select('id')->orderByRaw('arrayJoin(tags)'), 4],
            'distinct()' => [fn (QueryBuilder $query) => $query->distinct()->select('note'), 3],
            'groupBy()' => [fn (QueryBuilder $query) => $query->select('note')->groupBy('note'), 3],
            'selectRaw() of an aggregate' => [fn (QueryBuilder $query) => $query->selectRaw('count() as c'), 1],
            'a group limit' => [fn (QueryBuilder $query) => $query->orderBy('id')->groupLimit(1, 'name'), 4],
            'an alias and a limit' => [fn (QueryBuilder $query) => $query->select('id as x')->where('x', '>', 1)->limit(1), 3],
            'an order with LIMIT ... BY' => [fn (QueryBuilder $query) => $query->where('id', '>', 0)->orderByRaw('id desc limit 1 by flag % 2'), 2],
            'a plain query' => [fn (QueryBuilder $query) => $query->where('id', '>', 1), 3],
        ];
    }

    /**
     * count() and the paginate() total count the rows that get() returns, without its limit.
     *
     * @param Closure(QueryBuilder): QueryBuilder $build
     */
    #[DataProvider('countProvider')]
    public function testCountAndThePaginateTotalCountTheRowsGetReturns(Closure $build, int $count): void
    {
        $query = fn (): QueryBuilder => $build($this->connection()->table(self::ROWS));

        $this->assertSame($count, $query()->count());
        $this->assertSame($count, $query()->paginate(2)->total());
        $this->assertSame($count, $query()->limit(null)->get()->count(), 'get() without the limit returns as many rows');
    }

    public function testCountOfAColumnUnionsAndHavingsAreLaravels(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame(2, $query()->count('note'));
        $this->assertSame(2, $query()->distinct()->count('note'));
        $this->assertSame(2, $query()->distinct('note')->count());
        $this->assertSame(2, $query()->distinct('note')->paginate(5)->total());
        $this->assertSame(1, $query()->select('note')->groupBy('note')->havingRaw('count() > 1')->count());
        $this->assertSame(1, $query()->select('note')->groupBy('note')->havingRaw('count() > 1')->paginate(5)->total());
        $this->assertSame(8, $query()->select('id')->unionAll($query()->select('id'))->count());
        $this->assertSame(8, $query()->select('id')->unionAll($query()->select('id'))->paginate(3)->total());
    }

    /**
     * A grouped query without a select list would select * from the groups, which ClickHouse rejects, so count() and
     * the paginate() total count its groups: the notes NULL, n2 and n4.
     */
    public function testCountOfAGroupedQueryWithoutASelectListCountsTheGroups(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame(3, $query()->groupBy('note')->count());
        $this->assertSame(3, $query()->groupBy('note')->getCountForPagination());
        $this->assertSame(2, $query()->where('id', '>', 0)->groupByRaw('flag % ?', [2])->count());
        $this->assertSame(1, $query()->groupBy('note')->havingRaw('count() > ?', [1])->count());
        $this->assertSame(1, $query()->groupBy('note')->havingRaw('count() > ?', [1])->getCountForPagination());
        $this->assertSame(3, LaravelQueryBuilderRow::query()->groupBy('note')->count());
    }

    public function testEloquentCountAndPaginateCountTheRowsGetReturns(): void
    {
        $this->assertSame(3, LaravelQueryBuilderRow::query()->select('id as k')->where('k', '>', 1)->count());
        $this->assertSame(4, LaravelQueryBuilderRow::query()->selectRaw('arrayJoin(tags) as tag')->paginate(10)->total());
    }

    // Conditions

    public function testDateConditions(): void
    {
        $this->client()->write(
            'CREATE TABLE ' . self::DATES . " (id UInt8, dt DateTime, tokyo DateTime('Asia/Tokyo'), dt64 DateTime64(3),"
            . ' d Date, d32 Date32) ENGINE = MergeTree ORDER BY id'
        );
        $this->client()->write(
            'INSERT INTO ' . self::DATES . ' VALUES'
            . " (1, '2024-01-05 10:30:00', '2024-01-05 10:30:00', '2024-01-05 10:30:00.123', '2024-01-05', '1960-05-05'),"
            . " (2, '2024-02-10 23:59:59', '2024-02-10 23:59:59', '2024-02-10 23:59:59.999', '2024-02-10', '2200-05-05'),"
            . " (3, '2025-03-01 09:05:00', '2025-03-01 09:05:00', '2025-03-01 09:05:00.000', '2025-03-01', '2025-03-01')"
        );
        $query = fn (): QueryBuilder => $this->connection()->table(self::DATES);

        $this->assertSame([2, 3], $this->ids($query()->whereDate('dt', '>=', '2024-02-10')));
        $this->assertSame([2, 3], $this->ids($query()->whereDate('dt64', '>=', Carbon::parse('2024-02-10 23:00:00'))));
        $this->assertSame([1], $this->ids($query()->whereDate('d32', '1960-05-05')), 'toDate32() keeps dates before 1970');
        $this->assertSame([2], $this->ids($query()->whereDate('d32', '>', '2100-01-01')));
        $this->assertSame([1, 2], $this->ids($query()->whereTime('tokyo', '>=', '10:00')), 'the column\'s own time zone');
        $this->assertSame([3], $this->ids($query()->whereTime('dt', '9:05')));
        $this->assertSame([3], $this->ids($query()->whereTime('dt', '9:5')), 'a one-digit minute is padded');
        $this->assertSame([1, 2], $this->ids($query()->whereTime('dt64', '>=', '10:30:0')), 'a one-digit second is padded');
        $this->assertSame([1], $this->ids($query()->whereTime('dt64', '<', '10:30:01')->whereTime('dt64', '>', '10:00')));
        $this->assertSame([1], $this->ids($query()->whereDay('d', 5)));
        $this->assertSame([1], $this->ids($query()->whereDay('dt', '05')));
        $this->assertSame([2], $this->ids($query()->whereMonth('dt64', '02')));
        $this->assertSame([1, 3], $this->ids($query()->whereMonth('tokyo', '!=', 2)));
        $this->assertSame([3], $this->ids($query()->whereYear('dt', '>=', 2025)));
        $this->assertSame([1, 2], $this->ids($query()->whereYear('d', Carbon::parse('2024-06-01'))));
        $this->assertSame([1, 2], $this->ids($query()->whereYear('d', '2024.0')));
        $this->assertSame([], $this->ids($query()->whereYear('dt', 2024.5)), 'a year with a fraction is not truncated');

        $query()->whereYear('dt', '2024.5')->delete();
        $this->assertSame([1, 2, 3], $this->ids($query()), 'a delete by a year with a fraction changes no row');
    }

    public function testInRandomOrderReturnsEveryRow(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->ids($this->connection()->table(self::ROWS)->inRandomOrder()));
    }

    public function testUnionRemovesDuplicatesAndUnionAllKeepsThem(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS)->select('id')->where('id', 1);

        $this->assertSame([1], $this->ids($query()->union($query())));
        $this->assertSame([1, 1], $this->ids($query()->unionAll($query())));
    }

    /**
     * With union_default_mode set, union() sends a bare union, which follows that mode.
     */
    public function testUnionFollowsTheUnionDefaultModeOfTheConnection(): void
    {
        $query = fn (): QueryBuilder => $this->connection(['settings' => ['union_default_mode' => 'ALL']])
            ->table(self::ROWS)->select('id')->where('id', 1);

        $this->assertSame([1, 1], $this->ids($query()->union($query())));
    }

    /**
     * ClickHouse rejects an ORDER BY or a LIMIT after a parenthesized union, which union()->orderBy(), first() and
     * paginate() write.
     */
    public function testTheOrderAndLimitOfAUnionApplyToTheWholeUnion(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS)->select('id');
        $union = fn (): QueryBuilder => $query()->where('id', '<', 3)->unionAll($query()->where('id', '>', 1));

        $this->assertSame([4, 3], $union()->orderBy('id', 'desc')->limit(2)->pluck('id')->all());
        $this->assertSame(2, ((array) $union()->orderBy('id')->offset(1)->first())['id']);
        $this->assertSame([2, 3], $union()->orderBy('id')->paginate(2, page: 2)->pluck('id')->all());
    }

    public function testWhereLikeIgnoresCaseUnlessItIsCaseSensitive(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame([1, 2], $this->ids($query()->whereLike('name', 'a')));
        $this->assertSame([1], $this->ids($query()->whereLike('name', 'a', caseSensitive: true)));
        $this->assertSame([3, 4], $this->ids($query()->whereNotLike('name', 'A')));
        $this->assertSame([1, 3, 4], $this->ids($query()->whereNotLike('name', 'A', caseSensitive: true)));
        $this->assertSame([2], $this->ids($query()->where('name', 'like', 'A')));
    }

    public function testNullSafeEquality(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame([1, 3], $this->ids($query()->whereNullSafeEquals('note', null)));
        $this->assertSame([2], $this->ids($query()->whereNullSafeEquals('note', 'n2')));
        $this->assertSame([1, 3], $this->ids($query()->whereNullSafeEquals('note', DB::raw('nullIf(name, name)'))));
        $this->assertSame([2, 4], $this->ids($query()->whereNullSafeEquals('note', DB::raw("concat('n', toString(id))"))));
        $this->assertSame([4], $this->ids($query()->where('note', '<=>', 'n4')));
    }

    public function testBitwiseConditions(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame([1, 3], $this->ids($query()->where('flag', '&', 1)));
        $this->assertSame([2, 3, 4], $this->ids($query()->where('flag', '&~', 1)), 'bits above the eighth count');
        $this->assertSame([2, 3, 4], $this->ids($query()->where('flag', '>>', 1)));
        $this->assertSame([1, 4], $this->ids($query()->where('flag', '^', 256)->where('flag', '<', 256)));
        $this->assertSame([256, 257], $query()->select('flag')->groupBy('flag')->having('flag', '&', 256)->orderBy('flag')->pluck('flag')->all());
    }

    // Mutations

    public function testUpdateWritesArraysAndCollectionsAsArrayLiterals(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $query()->where('id', 3)->update(['tags' => ['a', "b'c"], 'name' => 'c2']);
        $query()->where('id', 2)->update(['tags' => collect(['x', 'y'])]);

        $this->assertSame(
            [[1, 'a', ['x']], [2, 'A', ['x', 'y']], [3, 'c2', ['a', "b'c"]], [4, 'd', ['z']]],
            $query()->orderBy('id')->get(['id', 'name', 'tags'])->map(fn ($row) => array_values((array) $row))->all()
        );
    }

    public function testDeleteByIdAndQualifiedColumnsInMutations(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        $connection->table(self::ROWS)->delete(1);
        $connection->table(self::ROWS)->where(self::ROWS . '.id', 3)->update([self::ROWS . '.name' => 'cc']);
        $connection->table($this->database() . '.' . self::ROWS)->where($this->database() . '.' . self::ROWS . '.id', 4)
            ->update(['flag' => 9]);
        $connection->table(self::ROWS . ' as r')->where('r.id', 2)->update(['r.note' => 'z']);

        $this->assertSame([
            'alter table "lqb_rows" delete where "id" = ?',
            'alter table "lqb_rows" update "name" = ? where "id" = ?',
            'alter table "' . $this->database() . '"."lqb_rows" update "flag" = ? where "id" = ?',
            'alter table "lqb_rows" update "note" = ? where "id" = ?',
        ], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame(
            [[2, 'A', 256, 'z'], [3, 'cc', 257, null], [4, 'd', 9, 'n4']],
            $connection->table(self::ROWS)->orderBy('id')->get(['id', 'name', 'flag', 'note'])->map(fn ($row) => array_values((array) $row))->all()
        );
    }

    public function testEloquentDeletesAndUpdatesByItsQualifiedKey(): void
    {
        LaravelQueryBuilderRow::query()->whereKey(1)->delete();
        LaravelQueryBuilderRow::query()->whereIn((new LaravelQueryBuilderRow())->getQualifiedKeyName(), [2, 3])->update(['name' => 'q']);

        $this->assertSame([[2, 'q'], [3, 'q'], [4, 'd']], $this->rows());
    }

    public function testAMutationWithAUnionIsStillRefused(): void
    {
        $query = $this->connection()->table(self::ROWS)->where('id', 1)->union($this->connection()->table(self::ROWS)->where('id', 2));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete with a query that uses UNION: ');

        $query->delete();
    }

    // Inserts with a key

    public function testInsertGetIdReturnsTheKeyOfTheRow(): void
    {
        $id = $this->connection()->table(self::ROWS)->insertGetId(['id' => 51, 'name' => 'gid', 'flag' => 0, 'tags' => []]);

        $this->assertSame(51, $id);
        $this->assertSame([1, 2, 3, 4, 51], $this->ids($this->connection()->table(self::ROWS)));
    }

    public function testInsertGetIdRefusesARowWithoutItsKeyBeforeAnythingIsSent(): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        try {
            $connection->table(self::ROWS)->insertGetId(['name' => 'gid', 'flag' => 0, 'tags' => []]);
            $this->fail('insertGetId() without the key should throw');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                'ClickHouse has no auto-increment columns, so insertGetId() cannot get the ID of a row without a [id] value.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
        $this->assertSame([1, 2, 3, 4], $this->ids($connection->table(self::ROWS)));
    }

    public function testEloquentCreateOfAnIncrementingModel(): void
    {
        $row = LaravelQueryBuilderRow::create(['id' => 9, 'name' => 'n', 'flag' => 0, 'tags' => ['q']]);

        $this->assertSame(9, $row->getKey());
        $this->assertTrue($row->exists);
        $this->assertSame([1, 2, 3, 4, 9], $this->ids($this->connection()->table(self::ROWS)));

        try {
            LaravelQueryBuilderRow::create(['name' => 'n', 'flag' => 0, 'tags' => []]);
            $this->fail('create() without the key should throw');
        } catch (RuntimeException $exception) {
            $this->assertStringEndsWith('On an Eloquent model, set $incrementing to false.', $exception->getMessage());
        }

        $this->assertSame([1, 2, 3, 4, 9], $this->ids($this->connection()->table(self::ROWS)), 'nothing is inserted');
    }

    // Settings of the outermost query

    public function testTimeoutLimitsTheExecutionTimeOnTheServer(): void
    {
        $query = $this->connection()->query()->fromRaw('numbers(10)')->selectRaw('sleepEachRow(0.25) as s')->timeout(1);

        try {
            $query->get();
            $this->fail('The query should time out');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('TIMEOUT_EXCEEDED', $exception->getMessage());
            $this->assertStringContainsString('settings max_execution_time = 1', $exception->getMessage());
        }
    }

    /**
     * The client's timeout_query still ends the request first when it is shorter than timeout(); the server then
     * keeps running the query until it ends.
     */
    public function testTheClientsTimeoutQueryCutsALongerTimeoutShort(): void
    {
        $query = $this->connection(['timeout_query' => 1])->query()->fromRaw('numbers(4)')->selectRaw('sleepEachRow(0.5) as s')->timeout(10);

        try {
            $query->get();
            $this->fail('The request should time out on the client');
        } catch (ClientQueryException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertStringNotContainsString('TIMEOUT_EXCEEDED', $exception->getMessage());
        }
    }

    public function testForceIndexRequiresTheIndexAndIgnoreIndexSkipsIt(): void
    {
        $query = fn (): QueryBuilder => $this->connection()->table(self::ROWS);

        $this->assertSame([3], $this->ids($query()->where('name', 'c')->forceIndex('idx_name')));
        $this->assertSame([3], $this->ids($query()->where('name', 'c')->ignoreIndex('idx_name')));
        $this->assertSame(1, $query()->select('id as x')->where('name', 'c')->forceIndex('idx_name')->timeout(5)->count());

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('INDEX_NOT_USED');
        $query()->where('id', 3)->forceIndex('idx_name')->get();
    }

    // Methods that ClickHouse cannot run

    /**
     * @return array<string, array{Closure(QueryBuilder): mixed, string}>
     */
    public static function unsupportedMethodProvider(): array
    {
        return [
            'upsert()' => [
                fn (QueryBuilder $query) => $query->upsert([['id' => 9, 'name' => 'u']], ['id'], ['name']),
                'This database engine does not support upserts.',
            ],
            'insertOrIgnore()' => [
                fn (QueryBuilder $query) => $query->insertOrIgnore([['id' => 9, 'name' => 'u']]),
                'This database engine does not support inserting while ignoring errors.',
            ],
            'whereJsonContains()' => [
                fn (QueryBuilder $query) => $query->whereJsonContains('note', 'x')->get(),
                'This database engine does not support JSON contains operations.',
            ],
            'a JSON selector' => [fn (QueryBuilder $query) => $query->where('note->a', 'x')->get(), 'This database engine does not support JSON operations.'],
            'whereFullText()' => [
                fn (QueryBuilder $query) => $query->whereFullText('name', 'x')->get(),
                'This database engine does not support fulltext search operations.',
            ],
            'useIndex()' => [
                fn (QueryBuilder $query) => $query->useIndex('idx_name')->get(),
                'ClickHouse chooses data-skipping indexes itself: use forceIndex() to require one or ignoreIndex() to skip one.',
            ],
            'inRandomOrder() with a seed' => [
                fn (QueryBuilder $query) => $query->inRandomOrder(42)->get(),
                'ClickHouse has no seeded random order: rand() ignores its argument.',
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): mixed $call
     */
    #[DataProvider('unsupportedMethodProvider')]
    public function testAMethodThatClickHouseCannotRunThrowsBeforeAnythingIsSent(Closure $call, string $message): void
    {
        $connection = $this->connection();
        $connection->enableQueryLog();

        try {
            $call($connection->table(self::ROWS));
            $this->fail('The call should throw');
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $this->assertStringStartsWith($message, $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog(), 'nothing is sent');
    }

    public function testLocksAreIgnored(): void
    {
        $this->assertSame([1, 2, 3, 4], $this->ids($this->connection()->table(self::ROWS)->lockForUpdate()));
        $this->assertSame([1, 2, 3, 4], $this->ids($this->connection()->table(self::ROWS)->sharedLock()));
    }

    // Connection introspection

    public function testThreadCountAndDbShowReportTheOpenConnections(): void
    {
        $count = $this->connection()->threadCount();
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(1, $count);

        $this->assertSame(0, Artisan::call('db:show', ['--database' => 'clickhouse', '--json' => true]));
        $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsInt($data['platform']['open_connections']);
        $this->assertGreaterThanOrEqual(1, $data['platform']['open_connections']);
    }

    /**
     * A copy of the default connection that hands out Laravel's query builder.
     *
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        $wanted = array_merge(
            config('database.connections.clickhouse'),
            ['fix_default_query_builder' => false],
            $config
        );
        $wanted['settings'] = array_merge($wanted['settings'] ?? [], ['mutations_sync' => 2], $config['settings'] ?? []);

        if (config('database.connections.' . self::CONNECTION) !== $wanted) {
            config(['database.connections.' . self::CONNECTION => $wanted]);
            DB::purge(self::CONNECTION);
        }

        return DB::connection(self::CONNECTION);
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    private function database(): string
    {
        return (string) config('database.connections.clickhouse.database');
    }

    /**
     * @param LaravelBuilder $query
     * @return list<int>
     */
    private function ids(LaravelBuilder $query): array
    {
        $ids = array_map(fn ($row): int => (int) ((array) $row)['id'], $query->get()->all());
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{int, string}>
     */
    private function rows(): array
    {
        return $this->connection()->table(self::ROWS)->orderBy('id')->get(['id', 'name'])
            ->map(fn ($row): array => array_values((array) $row))->all();
    }
}
