<?php

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\QueryBuilder as Builder;

class BufferKeyOrder extends Model
{
    protected $table = 'regression_buffer';
}

class ReadRow extends Model
{
    protected $table = 'regression_reads';

    public $timestamps = false;
}

class RegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = DB::connection('clickhouse')->getClient();
        $client->write('CREATE TABLE IF NOT EXISTS regression_buffer (k1 String, k2 String) ENGINE = MergeTree() ORDER BY (k1)');
        $client->write('TRUNCATE TABLE regression_buffer');
        $client->write('DROP TABLE IF EXISTS regression_reads SYNC');
        $client->write('DROP TABLE IF EXISTS regression_reads_other SYNC');
        $client->write('DROP TABLE IF EXISTS regression_reads_nested SYNC');
        $client->write(
            'CREATE TABLE regression_reads (id UInt32, a Int32, s String, grp String, a2 Int64 ALIAS a * 2)'
            . ' ENGINE = MergeTree ORDER BY id'
        );
        $client->write('CREATE TABLE regression_reads_other (id UInt32, s String) ENGINE = MergeTree ORDER BY id');
        $client->write('CREATE TABLE regression_reads_nested (id UInt32, n Nested(k String, v UInt32)) ENGINE = MergeTree ORDER BY id');
        $client->write("INSERT INTO regression_reads (id, a, s, grp) VALUES (1, 1, 'x', 'g1'), (2, 2, 'y', 'g1'), (3, 5, 'z', 'g2')");
        $client->write("INSERT INTO regression_reads_other VALUES (2, 'q'), (10, 'p'), (11, 'r')");
        $client->write("INSERT INTO regression_reads_nested VALUES (1, ['k1'], [1]), (2, ['k2', 'k3'], [2, 3])");
    }

    protected function tearDown(): void
    {
        BufferKeyOrder::clearBuffer();
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS regression_reads SYNC');
        $client->write('DROP TABLE IF EXISTS regression_reads_other SYNC');
        $client->write('DROP TABLE IF EXISTS regression_reads_nested SYNC');
        parent::tearDown();
    }

    private function reads(string $table = 'regression_reads'): Builder
    {
        return DB::connection('clickhouse')->table($table);
    }

    /**
     * An aggregate of a union reads the rows of the whole union.
     */
    public function testAggregatesOfAUnionReadTheRowsOfTheUnion(): void
    {
        $union = fn (): Builder => $this->reads()->select('id')->unionAll($this->reads('regression_reads_other')->select('id'));

        $this->assertSame(11, $union()->max('id'));
        $this->assertEquals(29, $union()->sum('id'));
        $this->assertSame(3, $this->reads()->max('regression_reads.id'));
        $this->assertNull($this->reads()->where('id', 999)->avg('a'));
        $this->assertSame(0, $this->reads()->where('id', 999)->sum('a'));
    }

    /**
     * WITH FILL adds rows in each group of the ORDER BY entries before it: a, filled for each grp, is 1 and 2 for g1
     * and 5 for g2. count(), the paginate() total and the aggregates keep those entries in their subquery; without
     * them, a was filled once over every row (1 to 5), so count() was 5 and sum('a') 15.
     */
    public function testCountAndAggregatesKeepTheOrderInWhoseGroupsWithFillAddsRows(): void
    {
        $query = fn (): Builder => $this->reads()->select('grp', 'a')->orderBy('grp')->orderByRaw('a WITH FILL');

        $this->assertSame([['g1', 1], ['g1', 2], ['g2', 5]], array_map('array_values', $query()->get()->all()));
        $this->assertSame(3, $query()->count());
        $this->assertSame(3, $query()->paginate(10)->total());
        $this->assertEquals(8, $query()->sum('a'));
        $this->assertSame(5, $query()->max('a'));
    }

    /**
     * ClickHouse reads LIMIT BY, LIMIT, OFFSET and FETCH in the raw SQL of an ORDER BY entry as clauses of the
     * select, so they leave rows out of what get() returns. count(), the paginate() total and the aggregates read
     * those rows in a subquery that keeps the whole ORDER BY: it decides which rows are kept, and with WITH TIES how
     * many. A LIMIT inside a sub-query of an entry is not looked at.
     */
    public function testCountKeepsAnOrderThatLimitsTheRows(): void
    {
        $connection = DB::connection('clickhouse');
        $limitBy = fn (): Builder => $this->reads()->select('id', 'grp', 'a')->orderBy('grp')->orderByRaw('id DESC LIMIT 1 BY grp');

        $this->assertSame([[2, 'g1', 2], [3, 'g2', 5]], array_map('array_values', $limitBy()->get()->all()));
        $connection->enableQueryLog();
        $this->assertSame(2, $limitBy()->count());
        $this->assertSame(
            ['select count(*) as "aggregate" from (select "id", "grp", "a" from "regression_reads" order by "grp" asc, id DESC LIMIT 1 BY grp) as "aggregate_table"'],
            array_column($connection->getQueryLog(), 'query')
        );
        $connection->disableQueryLog();
        $this->assertSame(2, $limitBy()->paginate(10)->total());

        $withTies = fn (): Builder => $this->reads()->select('id', 'a')->orderBy('a', 'desc')->orderByRaw('grp LIMIT 1 WITH TIES');
        $this->assertSame([[3, 5]], array_map('array_values', $withTies()->get()->all()));
        $this->assertSame(1, $withTies()->count());

        $limit = fn (): Builder => $this->reads()->select('id', 'a')->orderBy('a')->orderByRaw('id DESC LIMIT 2');
        $this->assertSame([[1, 1], [2, 2]], array_map('array_values', $limit()->get()->all()));
        $this->assertSame(2, $limit()->count());

        $this->assertSame(1, $this->reads()->orderByRaw('id OFFSET 2 ROWS')->count());
        $this->assertSame(3, $this->reads()->orderByRaw('id IN (SELECT id FROM regression_reads ORDER BY id LIMIT 1)')->count());
    }

    /**
     * A parametric aggregate takes its parameters in the function name.
     */
    public function testParametricAggregatesTakeTheirParametersInTheName(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->assertEquals(2, $this->reads()->aggregate('quantile(0.5)', ['a']));
        $this->assertSame(['select quantile(0.5)("a") as "aggregate" from "regression_reads"'], array_column($connection->getQueryLog(), 'query'));
        $this->assertEquals([2, 4.4], $this->reads()->aggregate('quantiles(0.5, 0.9)', ['a']));
        $this->assertSame(['g1', 'g2'], $this->reads()->aggregate('topK(2)', ['grp']));
    }

    /**
     * A string column of an aggregate is quoted as a name. Raw SQL is passed as DB::raw().
     */
    public function testAggregatesQuoteAStringColumnAndTakeRawSql(): void
    {
        $this->assertEquals(20, $this->reads()->sum(DB::raw('a * id')));
        $this->assertEquals(2, $this->reads()->aggregate('uniqExact', ['grp']));

        foreach (['a * id', '(SELECT name FROM system.users LIMIT 1)'] as $column) {
            try {
                $this->reads()->max($column);
                $this->fail("max('{$column}') did not throw.");
            } catch (DatabaseException $exception) {
                $this->assertSame('UNKNOWN_IDENTIFIER', $exception->getClickHouseExceptionName(), $column);
            }
        }
    }

    public function testFirstAndValueReadOneRow(): void
    {
        $query = fn (): Builder => $this->reads()->select('id', 's')->orderBy('id', 'desc');

        $this->assertSame(['id' => 3, 's' => 'z'], $query()->first());
        $this->assertNull($this->reads()->where('id', 999)->first());
        $this->assertSame(['id' => 2], $this->reads()->select('id')->orderBy('id')->limit(10)->offset(1)->first());
        $this->assertSame(['id' => 1, 's' => 'x'], $this->reads()->orderBy('id')->first(['id', 's']));
        $this->assertSame('z', $this->reads()->orderBy('id', 'desc')->value('s'));
        $this->assertEquals(2, $this->reads()->orderBy('id')->value(DB::raw('a + 1')));
        $this->assertSame(5, $this->reads()->value(DB::raw('max(a)')));
        $this->assertSame(5, $this->reads()->select('a as x')->orderBy('id', 'desc')->value('x'));
        $this->assertSame('y', ReadRow::where('id', 2)->value('s'));
        $this->assertNull($this->reads()->where('id', 999)->value('s'));
    }

    /**
     * pluck() selects only its columns from a query without a select list, so aliases, expressions, ALIAS columns
     * and a joined table's column work.
     */
    public function testPluckSelectsOnlyItsColumns(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->assertSame([1, 2, 3], $this->reads()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['select "id" from "regression_reads" order by "id" asc'], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame([1, 2, 5], $this->reads()->orderBy('id')->pluck('a as x')->all());
        $this->assertEquals([2, 3, 6], $this->reads()->orderBy('id')->pluck(DB::raw('a + 1 AS y'))->all());
        $this->assertEquals([2, 4, 10], $this->reads()->orderBy('id')->pluck('a2')->all());
        $this->assertSame([1 => 'x', 2 => 'y', 3 => 'z'], $this->reads()->pluck('s', 'id')->sortKeys()->all());
        $this->assertSame([1 => 1, 2 => 2, 3 => 3], $this->reads()->pluck('id', 'id')->sortKeys()->all());
        $this->assertSame(['q'], $this->joinedOnId()->pluck('regression_reads_other.s')->all());
        $this->assertSame([2, 3], ReadRow::where('a', '>', 1)->pluck('id')->sort()->values()->all());
        $this->assertSame([1, 2, 5], $this->reads()->select('a')->orderBy('id')->pluck('a')->all());
    }

    /**
     * A query with a select list is read by the names that ClickHouse gives its columns: a column of a joined table
     * keeps its table name, while a column of the query's own table does not.
     */
    public function testPluckReadsAJoinedColumnOfTheSelectListByItsName(): void
    {
        $this->assertSame([2 => 'q'], $this->joinedReads()->pluck('regression_reads_other.s', 'regression_reads.id')->all());
        $this->assertSame(['y'], $this->joinedReads()->pluck('regression_reads.s')->all());
    }

    private function joinedReads(): Builder
    {
        return $this->joinedOnId()->select('regression_reads.id', 'regression_reads.s', 'regression_reads_other.s');
    }

    /**
     * The rows of regression_reads joined to those of regression_reads_other with the same id.
     *
     * @return Builder
     */
    private function joinedOnId(): Builder
    {
        return $this->reads()->allInnerJoin('regression_reads_other', 'regression_reads.id', '=', 'regression_reads_other.id');
    }

    /**
     * chunk() calls back only for pages with rows, stops when the callback returns false, passes the page number
     * and reads within the query's own LIMIT and OFFSET.
     */
    public function testChunkFollowsLaravel(): void
    {
        $pages = [];
        $this->reads()->select('id')->orderBy('id')->chunk(2, function (Collection $rows, int $page) use (&$pages): void {
            $pages[$page] = $rows->pluck('id')->all();
        });
        $this->assertSame([1 => [1, 2], 2 => [3]], $pages);

        $pages = [];
        $this->reads()->select('id')->orderBy('id')->chunk(1, function (Collection $rows) use (&$pages): bool {
            $pages[] = $rows->pluck('id')->all();

            return false;
        });
        $this->assertSame([[1]], $pages);

        $pages = [];
        $this->reads()->select('id')->orderBy('id')->limit(2)->offset(1)->chunk(1, function (Collection $rows) use (&$pages): void {
            $pages[] = $rows->pluck('id')->all();
        });
        $this->assertSame([[2], [3]], $pages);

        $pages = [];
        ReadRow::where('id', '>', 0)->orderBy('id')->chunk(10, function (Collection $rows) use (&$pages): void {
            $pages[] = count($rows);
        });
        $this->assertSame([3], $pages);
    }

    /**
     * Rows buffered one at a time can have their keys in another order; the flush inserts them by name.
     */
    public function testFlushBufferNormalizesKeyOrderAcrossCalls(): void
    {
        BufferKeyOrder::clearBuffer();
        BufferKeyOrder::buffer(['k1' => 'a', 'k2' => 'b']);
        BufferKeyOrder::buffer(['k2' => 'y', 'k1' => 'x']);

        BufferKeyOrder::flushBuffer();

        $this->assertSame(
            [['k1' => 'a', 'k2' => 'b'], ['k1' => 'x', 'k2' => 'y']],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT k1, k2 FROM regression_buffer ORDER BY k1')->rows()
        );
    }

    /**
     * A buffered row that omits a column is refused before it is buffered, so the rows buffered before it are flushed
     * as they were.
     */
    public function testBufferStillFailsLoudlyOnMismatchedKeySets(): void
    {
        BufferKeyOrder::clearBuffer();
        BufferKeyOrder::buffer(['k1' => 'a', 'k2' => 'b']);

        try {
            BufferKeyOrder::buffer(['k1' => 'only-k1']);
            $this->fail('buffer() must not take a row without a value for the column k2');
        } catch (\Throwable $e) {
            $this->assertStringStartsWith(
                'Cannot buffer the rows of the model [' . BufferKeyOrder::class . ']: the row at index 0 has the keys [k1]',
                $e->getMessage()
            );
        }

        try {
            BufferKeyOrder::flushBuffer();
        } finally {
            BufferKeyOrder::clearBuffer();
        }

        $this->assertSame(
            [['k1' => 'a', 'k2' => 'b']],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT k1, k2 FROM regression_buffer')->rows()
        );
    }

    /** A published entry only has to name the keys it changes. */
    public function testPublishedConfigMergesPerKeyWithPackagedDefaults(): void
    {
        config()->set('database.connections.clickhouse', []);
        config()->set('clickhouse', ['clickhouse' => ['host' => 'partial-host']]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('partial-host', config('database.connections.clickhouse.host'));
        $this->assertSame('clickhouse', config('database.connections.clickhouse.driver'));
        $this->assertNotNull(config('database.connections.clickhouse.database'));
    }

    /** A published config/clickhouse.php must be able to add a connection. */
    public function testPublishedConfigCanAddConnection(): void
    {
        config()->set('clickhouse', [
            'clickhouse-published' => [
                'driver' => 'clickhouse', 'host' => '127.0.0.1', 'port' => '18124',
                'database' => 'default', 'username' => 'default', 'password' => '',
            ],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('18124', config('database.connections.clickhouse-published.port'));
    }

    /** A published config/clickhouse.php must be able to override a packaged default. */
    public function testPublishedConfigOverridesPackagedDefault(): void
    {
        config()->set('database.connections.clickhouse', []);
        config()->set('clickhouse', [
            'clickhouse' => [
                'driver' => 'clickhouse', 'host' => 'published-host', 'port' => '8123',
                'database' => 'default', 'username' => 'default', 'password' => '',
            ],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('published-host', config('database.connections.clickhouse.host'));
    }

    /** config/database.php still outranks a published config/clickhouse.php. */
    public function testDatabaseConfigStillWinsOverPublishedConfig(): void
    {
        config()->set('database.connections.clickhouse', ['host' => 'from-database-php']);
        config()->set('clickhouse', [
            'clickhouse' => ['driver' => 'clickhouse', 'host' => 'from-published', 'port' => '8123'],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('from-database-php', config('database.connections.clickhouse.host'));
    }

    /** With nothing published, the packaged defaults still register. */
    public function testPackagedDefaultsApplyWithoutPublishedConfig(): void
    {
        config()->set('clickhouse', null);
        config()->set('database.connections.clickhouse', []);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('clickhouse', config('database.connections.clickhouse.driver'));
    }
}
