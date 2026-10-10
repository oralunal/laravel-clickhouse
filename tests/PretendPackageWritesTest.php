<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\Example;
use Tests\Models\ExampleJson;

/**
 * A model of the examples table that chooses its connection at runtime, as a multi-tenant app may do, by overriding
 * resolveConnection(). The connection that its $connection names stays the default one.
 */
class PretendTenantExample extends BaseModel
{
    public const CONNECTION = 'clickhouse-pretend-tenant';

    protected $table = 'examples';

    public function resolveConnection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }
}

/**
 * The writes of the models and of the package builder while the connection pretends (DB::pretend(), migrate
 * --pretend): nothing is sent, and each statement is logged once with the SQL it would send.
 */
class PretendPackageWritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Example::truncate();
        ExampleJson::truncate();
        Example::clearBuffer();
        ExampleJson::clearBuffer();
        Example::insertAssoc([['f_int' => 1, 'f_int2' => 1, 'f_string' => 'kept']]);
    }

    protected function tearDown(): void
    {
        Example::clearBuffer();
        ExampleJson::clearBuffer();
        PretendTenantExample::clearBuffer();

        parent::tearDown();
    }

    public function testModelWritesChangeNothingWhilePretending(): void
    {
        $results = [];
        $flushed = false;

        $log = DB::connection('clickhouse')->pretend(function () use (&$results, &$flushed): void {
            $results[] = Example::insertAssoc([['f_int' => 2, 'f_int2' => 0, 'f_string' => "it's"]]);
            $results[] = Example::insertBulk([[3, 'b']], ['f_int', 'f_string']);
            $results[] = Example::prepareAndInsertAssoc([['f_int' => 4]]);
            $model = Example::create(['f_int' => 5]);
            $results[] = $model->exists;
            $saved = new Example();
            $saved->f_int = 6;
            $results[] = $saved->save();
            Example::buffer([['f_int' => 7], ['f_int' => 8]]);
            $flushed = Example::flushBuffer();
            $results[] = ExampleJson::insertAssoc([['id' => 9]]);
            $results[] = ExampleJson::insertBulk([[10]], ['id']);
            $results[] = Example::optimize(true, 'x');
            $results[] = Example::truncate();
        });

        $this->assertSame(
            [
                "INSERT INTO `examples` (`f_int`,`f_int2`,`f_string`)  VALUES  (2,0,'it\\'s')",
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (3,'b')",
                'INSERT INTO `examples` (`f_int`)  VALUES  (4)',
                'INSERT INTO `examples` (`f_int`)  VALUES  (5)',
                'INSERT INTO `examples` (`f_int`)  VALUES  (6)',
                'INSERT INTO `examples` (`f_int`)  VALUES  (7),  (8)',
                'INSERT INTO `json_examples` (`id`) FORMAT JSONEachRow',
                'INSERT INTO `json_examples` (`id`) FORMAT JSONCompactEachRow',
                "OPTIMIZE TABLE examples PARTITION 'x' FINAL",
                'TRUNCATE TABLE examples',
            ],
            array_column($log, 'query')
        );
        foreach ($results as $result) {
            $this->assertTrue($result instanceof Statement ? !$result->isError() : $result === true);
        }
        $this->assertNull($flushed, 'The rows buffered while pretending were logged at once, so the buffer is empty.');
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, (int) $this->client()->select('SELECT count() AS c FROM json_examples')->fetchOne('c'));
    }

    /**
     * The package builder's delete(), update(), truncate() and insert(), and a model query's mutations, are logged
     * once each and not sent, in both insert formats, with and without the default cluster of use_on_cluster. No
     * EXPLAIN is sent for a sub-query, and no mutation reaches the server.
     */
    public function testBuilderWritesChangeNothingWhilePretending(): void
    {
        $this->app['config']->set('database.connections.clickhouse.use_on_cluster', true);
        $this->app['config']->set('database.connections.clickhouse.cluster_name', 'company_cluster');
        DB::purge('clickhouse');
        $connection = DB::connection('clickhouse');
        $mutationsBefore = $this->mutationCount();
        $results = [];

        $log = $connection->pretend(function (Connection $connection) use (&$results): void {
            $results[] = $connection->table('examples')->where('f_int', 1)->delete(false);
            $results[] = $connection->table('examples')->where('f_int', 1)->withoutOnCluster()->delete(true);
            $results[] = $connection->table('examples')
                ->whereExists(fn (Builder $sub) => $sub->from('examples2')->whereColumn('examples2.f_int', 'examples.f_int'))
                ->update(['f_string' => 'x']);
            $results[] = $connection->withoutOnCluster(fn () => $connection->table('examples')->truncate());
            $results[] = $connection->table('examples')->insert([['f_int' => 2, 'f_string' => 'a']]);
            $results[] = $connection->table('examples')->insert([['f_int' => 3, 'f_string' => 'b']], Format::JSON_EACH_ROW);
            $results[] = Example::where('f_int', 1)->delete(false);
            $results[] = Example::truncate();
        });

        $this->assertSame(
            [
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1",
                'DELETE FROM `examples` WHERE `f_int` = 1',
                "ALTER TABLE `examples` ON CLUSTER 'company_cluster' UPDATE `f_string` = 'x'"
                    . ' WHERE EXISTS (SELECT * FROM `examples2` WHERE `examples2`.`f_int` = `examples`.`f_int`)',
                'TRUNCATE TABLE `examples`',
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (2,'a')",
                'INSERT INTO `examples` (`f_int`, `f_string`) FORMAT JSONEachRow',
                "ALTER TABLE examples ON CLUSTER 'company_cluster' DELETE WHERE `f_int` = 1",
                "TRUNCATE TABLE examples ON CLUSTER 'company_cluster'",
            ],
            array_column($log, 'query')
        );
        foreach ($results as $result) {
            $this->assertFalse($result->isError());
        }
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame($mutationsBefore, $this->mutationCount(), 'no mutation was sent');
    }

    /**
     * Rows buffered while pretending are never sent, also not by a flush after pretend() ends, such as the
     * automatic one at the end of the request. A JSONEachRow model logs the head of its insert.
     */
    public function testRowsBufferedWhilePretendingAreNotSentLater(): void
    {
        $log = DB::connection('clickhouse')->pretend(function (): void {
            Example::buffer(['f_int' => 2, 'f_string' => 'pretended']);
            ExampleJson::buffer([['id' => 3, 'f_flag' => true], ['f_flag' => false, 'id' => 4]]);
        });

        BaseModel::flushAllBuffers();
        $this->app->terminate();

        $this->assertSame(
            [
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (2,'pretended')",
                'INSERT INTO `json_examples` (`id`, `f_flag`) FORMAT JSONEachRow',
            ],
            array_column($log, 'query')
        );
        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(0, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, (int) $this->client()->select('SELECT count() AS c FROM json_examples')->fetchOne('c'));
    }

    /**
     * Rows buffered before pretend() and flushed inside it, by flushBuffer() or flushAllBuffers(), are logged and not
     * sent, and stay buffered: the first flush after pretend() ends sends them, once. Pretending loses no rows.
     */
    public function testAFlushWhilePretendingKeepsTheRowsBufferedBeforeIt(): void
    {
        Example::buffer(['f_int' => 2, 'f_string' => 'before']);
        ExampleJson::buffer(['id' => 3, 'f_flag' => true]);

        $log = DB::connection('clickhouse')->pretend(function (): void {
            Example::flushBuffer();
            BaseModel::flushAllBuffers();
        });

        $this->assertSame(
            [
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (2,'before')",
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (2,'before')",
                'INSERT INTO `json_examples` (`id`, `f_flag`) FORMAT JSONEachRow',
            ],
            array_column($log, 'query')
        );
        $this->assertSame(1, Example::bufferCount());
        $this->assertSame(1, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, $this->jsonExampleCount());

        BaseModel::flushAllBuffers();
        BaseModel::flushAllBuffers();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(0, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept'], [2, 'before']], $this->examples());
        $this->assertSame(1, $this->jsonExampleCount());
    }

    /**
     * A model that overrides resolveConnection() buffers and flushes by the connection that it returns, which its
     * inserts go through, and not by the connection that its $connection names. While the returned connection
     * pretends, rows buffered before are kept, and sent once afterwards, and rows buffered inside are logged and never
     * sent; the first used to be dropped, and the second sent by the next flush. While only the named connection
     * pretends, a flush sends the rows once; it used to keep them after sending them, so the next flush sent them
     * again.
     */
    public function testAModelThatResolvesItsConnectionPretendsWithThatConnection(): void
    {
        config(['database.connections.' . PretendTenantExample::CONNECTION => config('database.connections.clickhouse')]);
        DB::purge(PretendTenantExample::CONNECTION);

        PretendTenantExample::buffer(['f_int' => 2, 'f_string' => 'before']);
        $log = DB::connection(PretendTenantExample::CONNECTION)->pretend(function (): void {
            PretendTenantExample::flushBuffer();
            PretendTenantExample::buffer(['f_int' => 3, 'f_string' => 'inside']);
        });

        $this->assertSame(
            [
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (2,'before')",
                "INSERT INTO `examples` (`f_int`,`f_string`)  VALUES  (3,'inside')",
            ],
            array_column($log, 'query')
        );
        $this->assertSame([['f_int' => 2, 'f_string' => 'before']], PretendTenantExample::getBufferedRows());
        $this->assertSame([[1, 'kept']], $this->examples());

        PretendTenantExample::flushBuffer();
        BaseModel::flushAllBuffers();

        $this->assertSame([[1, 'kept'], [2, 'before']], $this->examples());

        PretendTenantExample::buffer(['f_int' => 4, 'f_string' => 'named']);
        $log = DB::connection('clickhouse')->pretend(fn () => PretendTenantExample::flushBuffer());
        BaseModel::flushAllBuffers();

        $this->assertSame([], $log);
        $this->assertSame(0, PretendTenantExample::bufferCount());
        $this->assertSame([[1, 'kept'], [2, 'before'], [4, 'named']], $this->examples());
    }

    /**
     * An insert that a check refuses before anything is sent, on each path, with the exception and the start of its
     * message: rows whose keys differ in Values, and in JSONEachRow and JSONCompactEachRow a value that JSON cannot
     * hold, raw SQL or a string that is not valid UTF-8, since the rows are encoded while pretending too.
     *
     * @return array<string, array{Closure(): mixed, class-string, string}>
     */
    public static function insertThatWouldFailProvider(): array
    {
        $utf8 = 'Cannot insert the value of column [f_string] in the row at index 0 as JSON: Malformed UTF-8 characters';

        return [
            'insertAssoc(), keys that differ, as Values' => [
                fn () => Example::insertAssoc([['f_int' => 2, 'f_string' => 'a'], ['f_int' => 3]]),
                ClientQueryException::class,
                'Fields not match: f_int and f_int,f_string on element 1',
            ],
            "the builder's insert(), raw SQL, as JSONEachRow" => [
                fn () => DB::connection('clickhouse')->table('json_examples')
                    ->insert([['id' => 2, 'f_string' => DB::raw("'x'")]], Format::JSON_EACH_ROW),
                InvalidArgumentException::class,
                'Cannot insert the value of column [f_string] in the row at index 0 as JSON: raw SQL',
            ],
            'insertAssoc(), invalid UTF-8, as JSONEachRow' => [
                fn () => ExampleJson::insertAssoc([['id' => 2, 'f_string' => "\xff"]]),
                InvalidArgumentException::class,
                $utf8,
            ],
            'insertBulk(), invalid UTF-8, as JSONCompactEachRow' => [
                fn () => ExampleJson::insertBulk([[2, "\xff"]], ['id', 'f_string']),
                InvalidArgumentException::class,
                'Cannot insert the value of column [1] in the row at index 0 as JSON: Malformed UTF-8 characters',
            ],
            'buffer(), invalid UTF-8, as JSONEachRow' => [
                fn () => ExampleJson::buffer(['id' => 2, 'f_string' => "\xff"]),
                InvalidArgumentException::class,
                $utf8,
            ],
        ];
    }

    /**
     * A check that fails before anything is sent still throws while pretending, and logs nothing.
     *
     * @param Closure(): mixed $insert
     * @param class-string $exception
     */
    #[DataProvider('insertThatWouldFailProvider')]
    public function testAnInsertThatWouldFailStillThrowsWhilePretending(Closure $insert, string $exception, string $message): void
    {
        $logged = [];
        DB::listen(function (QueryExecuted $query) use (&$logged): void {
            $logged[] = $query->sql;
        });

        try {
            DB::connection('clickhouse')->pretend($insert);
            $this->fail('The insert did not throw.');
        } catch (ClientQueryException|InvalidArgumentException $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringStartsWith($message, $thrown->getMessage());
        }

        $this->assertSame([], $logged);
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, $this->jsonExampleCount());
        $this->assertSame(0, ExampleJson::bufferCount());
    }

    /**
     * Outside pretend() the same writes run.
     */
    public function testTheWritesRunOutsidePretend(): void
    {
        Example::insertAssoc([['f_int' => 2, 'f_string' => 'a']]);
        Example::buffer(['f_int' => 3, 'f_string' => 'b']);
        Example::flushBuffer();
        Example::optimize(true);

        $this->assertSame([[1, 'kept'], [2, 'a'], [3, 'b']], $this->examples());

        Example::truncate();

        $this->assertSame([], $this->examples());
    }

    /**
     * @return list<array{int, string}>
     */
    private function examples(): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['f_int'], $row['f_string']],
            $this->client()->select('SELECT f_int, f_string FROM examples ORDER BY f_int')->rows()
        );
    }

    private function jsonExampleCount(): int
    {
        return (int) $this->client()->select('SELECT count() AS c FROM json_examples')->fetchOne('c');
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    /**
     * Count the mutations that ClickHouse has registered on the examples table.
     *
     * @return int
     */
    private function mutationCount(): int
    {
        return (int) $this->client()->select(
            "SELECT count() AS c FROM system.mutations WHERE database = currentDatabase() AND table = 'examples'"
        )->fetchOne('c');
    }
}
