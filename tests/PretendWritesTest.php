<?php

namespace Tests;

use ClickHouseDB\Client;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\Example;
use Tests\Models\ExampleJson;

/**
 * The writes of the models and of the query builder while the connection pretends (DB::pretend(), migrate
 * --pretend): nothing is sent, and each statement is logged once with the SQL it would send.
 */
class PretendWritesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Example::query()->truncate();
        ExampleJson::query()->truncate();
        Example::clearBuffer();
        ExampleJson::clearBuffer();
        Example::insert([['f_int' => 1, 'f_int2' => 1, 'f_string' => 'kept']]);
    }

    protected function tearDown(): void
    {
        Example::clearBuffer();
        ExampleJson::clearBuffer();

        parent::tearDown();
    }

    public function testModelWritesChangeNothingWhilePretending(): void
    {
        $results = [];

        $log = DB::connection('clickhouse')->pretend(function () use (&$results): void {
            $results[] = Example::insert([['f_int' => 2, 'f_int2' => 0, 'f_string' => "it's"]]);
            $results[] = Example::create(['f_int' => 5])->exists;
            $saved = new Example();
            $saved->f_int = 6;
            $results[] = $saved->save();
            Example::buffer([['f_int' => 7], ['f_int' => 8]]);
            $results[] = ExampleJson::query()->insert([['id' => 9]], 'JSONEachRow');
            $results[] = Example::optimize(true, 'x');
            Example::query()->truncate();
        });

        $this->assertSame(
            [
                "insert into \"examples\" (\"f_int\", \"f_int2\", \"f_string\") values (2, 0, 'it\\'s')",
                'insert into "examples" ("f_int") values (5)',
                'insert into "examples" ("f_int") values (6)',
                'insert into "examples" ("f_int") values (7), (8)',
                'insert into "json_examples" ("id") format JSONEachRow',
                'optimize table "examples" partition \'x\' final',
                'truncate table "examples"',
            ],
            array_column($log, 'query')
        );
        $this->assertSame([true, true, true, true, true], $results);
        $this->assertSame(0, Example::bufferCount(), 'The rows buffered while pretending were logged at once.');
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, $this->jsonExampleCount());
    }

    /**
     * The query builder's delete(), update(), truncate() and insert(), and a model query's mutations, are logged
     * once each and not sent, in both insert formats, with and without the default cluster of use_on_cluster. No
     * mutation reaches the server.
     */
    public function testBuilderWritesChangeNothingWhilePretending(): void
    {
        $this->app['config']->set('database.connections.clickhouse.use_on_cluster', true);
        $this->app['config']->set('database.connections.clickhouse.cluster_name', 'company_cluster');
        DB::purge('clickhouse');
        $connection = DB::connection('clickhouse');
        $mutationsBefore = $this->mutationCount();

        $log = $connection->pretend(function (Connection $connection): void {
            $connection->table('examples')->where('f_int', 1)->delete(lightweight: false);
            $connection->table('examples')->where('f_int', 1)->withoutOnCluster()->delete(lightweight: true);
            $connection->table('examples')
                ->whereExists(fn (QueryBuilder $sub) => $sub->from('examples2')->whereColumn('examples2.f_int', 'examples.f_int'))
                ->update(['f_string' => 'x']);
            $connection->withoutOnCluster(fn () => $connection->table('examples')->truncate());
            $connection->table('examples')->insert([['f_int' => 2, 'f_string' => 'a']]);
            $connection->table('examples')->insert([['f_int' => 3, 'f_string' => 'b']], 'JSONEachRow');
            Example::where('f_int', 1)->delete(false);
            Example::query()->truncate();
        });

        $this->assertSame(
            [
                "alter table \"examples\" on cluster 'company_cluster' delete where \"f_int\" = 1",
                'delete from "examples" where "f_int" = 1',
                "alter table \"examples\" on cluster 'company_cluster' update \"f_string\" = 'x'"
                    . ' where exists (select * from "examples2" where "examples2"."f_int" = "examples"."f_int")',
                'truncate table "examples"',
                "insert into \"examples\" (\"f_int\", \"f_string\") values (2, 'a')",
                'insert into "examples" ("f_int", "f_string") format JSONEachRow',
                "alter table \"examples\" on cluster 'company_cluster' delete where \"f_int\" = 1",
                "truncate table \"examples\" on cluster 'company_cluster'",
            ],
            array_column($log, 'query')
        );
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame($mutationsBefore, $this->mutationCount(), 'no mutation was sent');
    }

    /**
     * Rows buffered while pretending are logged at once and never sent, also not by a flush after pretend() ends,
     * such as the automatic one at the end of the request.
     */
    public function testRowsBufferedWhilePretendingAreNotSentLater(): void
    {
        $log = DB::connection('clickhouse')->pretend(function (): void {
            Example::buffer(['f_int' => 2, 'f_string' => 'pretended']);
            ExampleJson::buffer([['id' => 3, 'f_flag' => true], ['f_flag' => false, 'id' => 4]]);
        });

        Model::flushAllBuffers();
        $this->app->terminate();

        $this->assertSame(
            [
                "insert into \"examples\" (\"f_int\", \"f_string\") values (2, 'pretended')",
                'insert into "json_examples" ("f_flag", "id") values (1, 3), (0, 4)',
            ],
            array_column($log, 'query')
        );
        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(0, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, $this->jsonExampleCount());
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
            Model::flushAllBuffers();
        });

        $this->assertSame(
            [
                "insert into \"examples\" (\"f_int\", \"f_string\") values (2, 'before')",
                "insert into \"examples\" (\"f_int\", \"f_string\") values (2, 'before')",
                'insert into "json_examples" ("f_flag", "id") values (1, 3)',
            ],
            array_column($log, 'query')
        );
        $this->assertSame(1, Example::bufferCount());
        $this->assertSame(1, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept']], $this->examples());
        $this->assertSame(0, $this->jsonExampleCount());

        Model::flushAllBuffers();
        Model::flushAllBuffers();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(0, ExampleJson::bufferCount());
        $this->assertSame([[1, 'kept'], [2, 'before']], $this->examples());
        $this->assertSame(1, $this->jsonExampleCount());
    }

    /**
     * An insert that a check refuses before anything is sent, with the exception and the start of its message: in
     * JSONEachRow, rows whose keys differ, raw SQL or a string that is not valid UTF-8, since the rows are encoded
     * while pretending too.
     *
     * @return array<string, array{Closure(): mixed, class-string, string}>
     */
    public static function insertThatWouldFailProvider(): array
    {
        $json = fn (array $rows) => DB::connection('clickhouse')->table('json_examples')->insert($rows, 'JSONEachRow');

        return [
            'keys that differ' => [
                fn () => $json([['id' => 2, 'f_string' => 'a'], ['id' => 3]]),
                QueryException::class,
                'Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [f_string].',
            ],
            'raw SQL' => [
                fn () => $json([['id' => 2, 'f_string' => DB::raw("'x'")]]),
                InvalidArgumentException::class,
                'Cannot insert the value of column [f_string] in the row at index 0 as JSON: raw SQL',
            ],
            'invalid UTF-8' => [
                fn () => $json([['id' => 2, 'f_string' => "\xff"]]),
                InvalidArgumentException::class,
                'Cannot insert the value of column [f_string] in the row at index 0 as JSON: Malformed UTF-8 characters',
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
        } catch (QueryException|InvalidArgumentException $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringStartsWith($message, $thrown->getMessage());
        }

        $this->assertSame([], $logged);
        $this->assertSame(0, $this->jsonExampleCount());
    }

    /**
     * Outside pretend() the same writes run.
     */
    public function testTheWritesRunOutsidePretend(): void
    {
        Example::insert([['f_int' => 2, 'f_string' => 'a']]);
        Example::buffer(['f_int' => 3, 'f_string' => 'b']);
        Example::flushBuffer();
        $this->assertTrue(Example::optimize(true));

        $this->assertSame([[1, 'kept'], [2, 'a'], [3, 'b']], $this->examples());

        Example::query()->truncate();

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
