<?php

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;
use Oralunal\LaravelClickHouse\RawColumn;

use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

class CastFirstColumn extends BaseModel
{
    protected $table = 'regression_cast';
    protected $casts = ['b' => 'boolean'];
}

class BufferKeyOrder extends BaseModel
{
    protected $table = 'regression_buffer';
}

class ReadRow extends BaseModel
{
    protected $table = 'regression_reads';
}

class RegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = DB::connection('clickhouse')->getClient();
        $client->write('CREATE TABLE IF NOT EXISTS regression_cast (b UInt8, p Int64) ENGINE = MergeTree() ORDER BY (p)');
        $client->write('CREATE TABLE IF NOT EXISTS regression_buffer (k1 String, k2 String) ENGINE = MergeTree() ORDER BY (k1)');
        $client->write('TRUNCATE TABLE regression_cast');
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
     * count(), min(), max(), sum(), avg() and average() leave out the format of the query. With a format other than
     * JSON they used to throw 'Can`t find meta'.
     */
    public function testCountAndAggregatesLeaveOutTheFormat(): void
    {
        $this->assertSame(3, $this->reads()->format('CSV')->count());
        $this->assertEquals(8, $this->reads()->format('CSV')->sum('a'));
        $this->assertSame(5, $this->reads()->format('TSV')->max('a'));
        $this->assertSame(3, ReadRow::select()->format('CSV')->count());
        $this->assertEquals(8, ReadRow::select()->format('CSV')->sum('a'));
        $this->assertSame(3, $this->reads()->format('JSON')->settings(['max_threads' => 1])->count());
    }

    /**
     * An aggregate reads the rows that the query returns: a set operation, a grouped query or one whose condition
     * reads an alias is aggregated in a subquery, and the ORDER BY and LIMIT of any other query are left out.
     */
    public function testAggregatesReadTheRowsThatTheQueryReturns(): void
    {
        $union = fn (): Builder => $this->reads()->select('id')->unionAll($this->reads('regression_reads_other')->select('id'));

        $this->assertSame(11, $union()->max('id'));
        $this->assertEquals(29, $union()->sum('id'));
        $this->assertSame(5, $this->reads()->select('a')->groupBy('a')->max('a'));
        $this->assertSame(2, $this->reads()->select('grp', new RawColumn('max(id)', 'id'))->groupBy('grp')->min('id'));
        $this->assertSame(5, $this->reads()->select('a as x')->where('x', '>', 1)->max('x'));
        $this->assertSame(1, $this->reads()->orderBy('id')->limit(1, 1)->min('id'));
        $this->assertSame(2, $this->reads()->select('id', 'grp')->orderBy('id', 'desc')->limitBy(1, 'grp')->min('id'));
        $this->assertSame(3, $this->reads()->select('id', 'grp')->orderBy('id')->limitBy(1, 'grp')->max('id'));
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

        $this->assertSame([['g1', 1], ['g1', 2], ['g2', 5]], array_map('array_values', $query()->getRows()));
        $this->assertSame(3, $query()->count());
        $this->assertSame(3, $query()->paginate(10)->total());
        $this->assertEquals(8, $query()->sum('a'));
        $this->assertSame(5, $query()->max('a'));
    }

    /**
     * ClickHouse reads LIMIT BY, LIMIT, OFFSET and FETCH in the raw SQL of an ORDER BY entry as clauses of the
     * select, so they leave rows out of what get() returns. count(), the paginate() total and the aggregates read
     * those rows in a subquery that keeps the whole ORDER BY: it decides which rows are kept, and with WITH TIES how
     * many. They used to drop the ORDER BY: count() was 3 for each query, min('id') of the LIMIT BY query was 1, and
     * 3.0.0 failed with NOT_AN_AGGREGATE for an aggregate. A LIMIT inside a sub-query of an entry is not looked at.
     */
    public function testCountAndAggregatesKeepAnOrderThatLimitsTheRows(): void
    {
        $connection = DB::connection('clickhouse');
        $limitBy = fn (): Builder => $this->reads()->select('id', 'grp', 'a')->orderBy('grp')->orderByRaw('id DESC LIMIT 1 BY grp');

        $this->assertSame([[2, 'g1', 2], [3, 'g2', 5]], array_map('array_values', $limitBy()->getRows()));
        $connection->enableQueryLog();
        $this->assertSame(2, $limitBy()->count());
        $this->assertSame(
            ['SELECT count() AS `count` FROM (SELECT `id`, `grp`, `a` FROM `regression_reads` ORDER BY `grp` ASC, id DESC LIMIT 1 BY grp)'],
            array_column($connection->getQueryLog(), 'query')
        );
        $connection->disableQueryLog();
        $this->assertSame(2, $limitBy()->paginate(10)->total());
        $this->assertSame(2, $limitBy()->min('id'));
        $this->assertEquals(7, $limitBy()->sum('a'));

        $withTies = fn (): Builder => $this->reads()->select('id', 'a')->orderBy('a', 'desc')->orderByRaw('grp LIMIT 1 WITH TIES');
        $this->assertSame([[3, 5]], array_map('array_values', $withTies()->getRows()));
        $this->assertSame(1, $withTies()->count());
        $this->assertSame(5, $withTies()->min('a'));

        $limit = fn (): Builder => $this->reads()->select('id', 'a')->orderBy('a')->orderByRaw('id DESC LIMIT 2');
        $this->assertSame([[1, 1], [2, 2]], array_map('array_values', $limit()->getRows()));
        $this->assertSame(2, $limit()->count());
        $this->assertSame(2, $limit()->max('id'));
        $this->assertEquals(3, $limit()->sum('a'));

        $this->assertSame(1, $this->reads()->orderByRaw('id OFFSET 2 ROWS')->count());
        $this->assertSame(3, $this->reads()->orderByRaw('id IN (SELECT id FROM regression_reads ORDER BY id LIMIT 1)')->count());
    }

    /**
     * A parametric aggregate takes a list of numbers as its parameters, as it did in 3.0.0; any other SQL in the
     * function name is still refused before anything is sent.
     */
    public function testParametricAggregatesTakeNumbersAsParameters(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->assertEquals(2, $this->reads()->aggregate('quantile(0.5)', ['a']));
        $this->assertSame(['SELECT quantile(0.5)(`a`) AS `aggregate` FROM `regression_reads`'], array_column($connection->getQueryLog(), 'query'));
        $this->assertEquals([2, 4.4], $this->reads()->aggregate('quantiles(0.5, 0.9)', ['a']));
        $this->assertSame(['g1', 'g2'], $this->reads()->aggregate('topK(2)', 'grp'));
        $this->assertSame(5, $this->reads()->select('grp', 'a')->groupBy('grp', 'a')->aggregate('quantileExact(1)', ['a']));

        foreach (["quantile('0.5')", 'quantile(0.5)(id) --', 'topK(a)'] as $function) {
            try {
                $this->reads()->aggregate($function, ['a']);
                $this->fail("aggregate('{$function}') did not throw.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringStartsWith("Invalid aggregate function name [{$function}]", $exception->getMessage());
            }
        }
    }

    /**
     * A builder made with only a client follows no connection, so a Laravel database expression takes the grammar of
     * the connection of its name. When the application does not configure that name, the exception says that the
     * expression cannot be written, instead of only that the connection is not configured.
     */
    public function testALaravelExpressionOfABuilderWhoseConnectionIsNotConfiguredSaysWhy(): void
    {
        $builder = new Builder(DB::connection('clickhouse')->getClient(), 'not_configured');

        try {
            $builder->from('regression_reads')->where(DB::raw('a + 1'), 2)->toSql();
            $this->fail('toSql() did not throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringStartsWith(
                'Cannot write a Laravel database expression without the grammar of a database connection: the builder'
                . ' follows no connection, and its connection [not_configured] cannot be resolved (Database connection'
                . ' [not_configured] not configured.).',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            'SELECT * FROM `regression_reads` WHERE a + 1 = 2',
            (new Builder(DB::connection('clickhouse')->getClient()))->from('regression_reads')->where(DB::raw('a + 1'), 2)->toSql()
        );
    }

    /**
     * A string column of an aggregate is quoted as a name, so it can no longer add SQL that reads another table.
     * Raw SQL is passed as raw(), a RawColumn or DB::raw().
     */
    public function testAggregatesQuoteAStringColumnAndTakeRawSql(): void
    {
        $this->assertEquals(20, $this->reads()->sum(raw('a * id')));
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

        $this->expectException(InvalidArgumentException::class);
        $this->reads()->aggregate('max(id)) --', ['id']);
    }

    public function testFirstAndValueReadOneRowOfACopy(): void
    {
        $query = fn (): Builder => $this->reads()->select('id', 's')->orderBy('id', 'desc');

        $this->assertSame(['id' => 3, 's' => 'z'], $query()->first());
        $this->assertNull($this->reads()->where('id', 999)->first());
        $this->assertSame(['id' => 2], $this->reads()->select('id')->orderBy('id')->limit(10, 1)->first());
        $this->assertSame(['id' => 1, 's' => 'x'], $this->reads()->orderBy('id')->first(['id', 's']));
        $this->assertSame('z', $this->reads()->orderBy('id', 'desc')->value('s'));
        $this->assertEquals(2, $this->reads()->orderBy('id')->value(raw('a + 1')));
        $this->assertSame(5, $this->reads()->value(DB::raw('max(a)')));
        $this->assertSame(5, $this->reads()->select('a as x')->orderBy('id', 'desc')->value('x'));
        $this->assertSame('y', ReadRow::where('id', 2)->value('s'));
        $this->assertNull($this->reads()->where('id', 999)->value('s'));
        $this->assertSame('q', $this->joinedReads()->value('regression_reads_other.s'), 'a column of a joined table');
        $this->assertSame('y', $this->joinedReads()->value('regression_reads.s'), 'the column of that name of the own table');
        $this->assertSame(['k2', 'k3'], $this->reads('regression_reads_nested')->select('n.k')->orderBy('id', 'desc')->value('n.k'));

        $builder = $query();
        $builder->first();
        $this->assertSame('SELECT `id`, `s` FROM `regression_reads` ORDER BY `id` DESC', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The query does not select the column [b] that value() reads.');
        $this->reads()->select('a')->value('b');
    }

    /**
     * pluck() selects only its columns from a query that selects every column, so aliases, expressions, ALIAS
     * columns, a joined table's column and a key equal to the column work. pluck(DB::raw(...)) used to throw an
     * ArgumentCountError.
     */
    public function testPluckSelectsOnlyItsColumns(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->assertSame([1, 2, 3], $this->reads()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['SELECT `id` FROM `regression_reads` ORDER BY `id` ASC'], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame([1, 2, 5], $this->reads()->orderBy('id')->pluck('a as x')->all());
        $this->assertEquals([2, 3, 6], $this->reads()->orderBy('id')->pluck(DB::raw('a + 1 AS y'))->all());
        $this->assertEquals([2, 3, 6], $this->reads()->orderBy('id')->pluck(raw('a + 1'))->all());
        $this->assertEquals([2, 4, 10], $this->reads()->orderBy('id')->pluck('a2')->all());
        $this->assertSame([1 => 'x', 2 => 'y', 3 => 'z'], $this->reads()->pluck('s', 'id')->sortKeys()->all());
        $this->assertSame([1 => 1, 2 => 2, 3 => 3], $this->reads()->pluck('id', 'id')->sortKeys()->all());
        $this->assertSame(['q'], $this->reads()->allInnerJoin('regression_reads_other', ['id'])->pluck('regression_reads_other.s')->all());
        $this->assertSame([2, 3], ReadRow::where('a', '>', 1)->pluck('id')->sort()->values()->all());
        $this->assertSame([1, 2, 5], $this->reads()->select('a')->orderBy('id')->pluck('a')->all());
    }

    /**
     * A query with a select list is read by the names that ClickHouse gives its columns: a column of a joined table
     * and a column of a Nested structure keep their table or structure name, while a column of the query's own
     * table does not. pluck() with such a select list threw 'Undefined array key'.
     */
    public function testPluckReadsAJoinedOrNestedColumnOfTheSelectListByItsName(): void
    {
        $this->assertSame([2 => 'q'], $this->joinedReads()->pluck('regression_reads_other.s', 'regression_reads.id')->all());
        $this->assertSame(['y'], $this->joinedReads()->pluck('regression_reads.s')->all());
        $this->assertSame(
            [1 => ['k1'], 2 => ['k2', 'k3']],
            $this->reads('regression_reads_nested')->select('id', 'n.k')->pluck('`n`.`k`', 'id')->sortKeys()->all()
        );
    }

    /**
     * The rows of regression_reads joined to those of regression_reads_other with the same id (id 2), with a select
     * list that holds the column s of both tables.
     *
     * @return Builder
     */
    private function joinedReads(): Builder
    {
        return $this->reads()
            ->allInnerJoin('regression_reads_other', ['id'])
            ->select('regression_reads.id', 'regression_reads.s', 'regression_reads_other.s');
    }

    public function testGetCollectionReturnsTheRowsInACollection(): void
    {
        $collection = $this->reads()->select('id')->orderBy('id')->getCollection();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame([1, 2, 3], $collection->pluck('id')->all());
    }

    /**
     * chunk() calls back only for pages with rows, stops when the callback returns false, passes the page number
     * and reads within the query's own LIMIT and OFFSET, on a copy of the query.
     */
    public function testChunkFollowsLaravel(): void
    {
        $pages = [];
        $query = $this->reads()->select('id')->orderBy('id');
        $query->chunk(2, function (array $rows, int $page) use (&$pages): void {
            $pages[$page] = array_column($rows, 'id');
        });
        $this->assertSame([1 => [1, 2], 2 => [3]], $pages);
        $this->assertSame('SELECT `id` FROM `regression_reads` ORDER BY `id` ASC', $query->toSql());

        $pages = [];
        $this->reads()->select('id')->orderBy('id')->chunk(1, function (array $rows) use (&$pages): bool {
            $pages[] = array_column($rows, 'id');

            return false;
        });
        $this->assertSame([[1]], $pages);

        $pages = [];
        $this->reads()->select('id')->orderBy('id')->limit(2, 1)->chunk(1, function (array $rows) use (&$pages): void {
            $pages[] = array_column($rows, 'id');
        });
        $this->assertSame([[2], [3]], $pages);

        $pages = [];
        ReadRow::where('id', '>', 0)->chunk(10, function (array $rows) use (&$pages): void {
            $pages[] = count($rows);
        });
        $this->assertSame([3], $pages);
    }

    /**
     * Read the rows of regression_cast, with the Int64 column p as an int: ClickHouse 24.8 quotes 64-bit integers
     * in JSON, 25.8 and later do not (output_format_json_quote_64bit_integers is 0), so the column comes back as a
     * string or as an int depending on the server.
     *
     * @return list<array{p: int, b: int}>
     */
    private function castRows(): array
    {
        return array_map(
            fn (array $row): array => ['p' => (int) $row['p'], 'b' => $row['b']],
            DB::connection('clickhouse')->getClient()->select('SELECT p, b FROM regression_cast ORDER BY p')->rows()
        );
    }

    /**
     * insertBulk() used to drop the cast when the cast column was the first one:
     * array_search() returns index 0, which the truthiness check treated as "not found".
     */
    public function testInsertBulkCastsFirstColumn(): void
    {
        CastFirstColumn::insertBulk([[false, 1]], ['b', 'p']);

        $this->assertSame(
            [['p' => 1, 'b' => 0]],
            $this->castRows(),
            'boolean cast must apply to the first column too'
        );
    }

    /** The same cast in a non-zero position must keep working. */
    public function testInsertBulkCastsLaterColumn(): void
    {
        CastFirstColumn::insertBulk([[2, false]], ['p', 'b']);

        $this->assertSame([['p' => 2, 'b' => 0]], $this->castRows());
    }

    /** A column with no cast configured must be left alone. */
    public function testInsertBulkLeavesUncastColumnsAlone(): void
    {
        CastFirstColumn::insertBulk([[1, 3]], ['b', 'p']);

        $this->assertSame([['p' => 3, 'b' => 1]], $this->castRows());
    }

    /**
     * Rows buffered one at a time are prepared in isolation, so their key order
     * is only comparable at flush time. Without normalization there, flushing a
     * buffer whose rows carry the same keys in a different order threw
     * "Fields not match".
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
     * A buffered row that omits a column must NOT be silently completed with
     * fabricated values — it has to fail loudly. buffer() refuses it before it
     * is buffered, so the rows buffered before it are flushed as they were.
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
