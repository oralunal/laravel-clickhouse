<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Client;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * The ClickHouse clauses of Laravel's query builder (fix_default_query_builder = false) against the server: the SQL
 * that QueryBuilderClickHouseSqlTest checks is accepted, and returns or changes the expected rows.
 */
class LaravelQueryBuilderClickHouseSqlTest extends TestCase
{
    private const CONNECTION = 'clickhouse-laravel-builder-ch';

    private const EVENTS = 'lqbch_events';

    private const VERSIONS = 'lqbch_versions';

    private const USERS = 'lqbch_users';

    private const QUOTES = 'lqbch_quotes';

    private const TABLES = [self::EVENTS, self::VERSIONS, self::USERS, self::QUOTES];

    /**
     * Create EVENTS (id 1 to 6, user 1 or 2, tags, partitioned by month), VERSIONS (a ReplacingMergeTree with two
     * versions of id 1), USERS (1 and 3) and QUOTES (prices of user 1 at two times).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $client = $this->client();
        foreach (self::TABLES as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
        }

        $client->write(
            'CREATE TABLE ' . self::EVENTS . ' (id UInt32, user_id UInt32, name String, tags Array(String),'
            . ' ts DateTime) ENGINE = MergeTree PARTITION BY toYYYYMM(ts) ORDER BY (user_id, intHash32(id))'
            . ' SAMPLE BY intHash32(id)'
        );
        $client->write(
            'INSERT INTO ' . self::EVENTS . " VALUES (1, 1, 'a', ['x', 'y'], '2024-01-05 00:00:00'),"
            . " (2, 1, '', [], '2024-01-06 00:00:00'), (3, 1, 'c', ['z'], '2024-02-01 00:00:00'),"
            . " (4, 2, 'd', ['x'], '2024-01-07 00:00:00'), (5, 2, '', ['y'], '2024-02-02 00:00:00'),"
            . " (6, 2, 'f', [], '2024-02-03 00:00:00')"
        );
        $client->write('CREATE TABLE ' . self::VERSIONS . ' (id UInt32, v UInt32, name String) ENGINE = ReplacingMergeTree(v) ORDER BY id');
        $client->write('INSERT INTO ' . self::VERSIONS . " VALUES (1, 1, 'old')");
        $client->write('INSERT INTO ' . self::VERSIONS . " VALUES (1, 2, 'new')");
        $client->write('CREATE TABLE ' . self::USERS . ' (id UInt32, country String) ENGINE = MergeTree ORDER BY id');
        $client->write('INSERT INTO ' . self::USERS . " VALUES (1, 'DE'), (3, 'FR')");
        $client->write('CREATE TABLE ' . self::QUOTES . ' (user_id UInt32, ts DateTime, price UInt32) ENGINE = MergeTree ORDER BY (user_id, ts)');
        $client->write('INSERT INTO ' . self::QUOTES . " VALUES (1, '2024-01-01 00:00:00', 10), (1, '2024-01-06 00:00:00', 20)");
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            $this->client()->write("DROP TABLE IF EXISTS {$table} SYNC");
        }

        parent::tearDown();
    }

    public function test_final_and_sample(): void
    {
        $rows = $this->table(self::VERSIONS)->final()->get()->all();
        $this->assertCount(1, $rows);
        $this->assertSame('new', ((array) $rows[0])['name']);
        $this->assertCount(2, $this->table(self::VERSIONS)->get());
        $this->assertCount(1, $this->connection()->table(self::VERSIONS, null, true)->get());

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->ids($this->table(self::EVENTS)->sample(1)));
        $this->assertSame(6, $this->table(self::EVENTS)->sample(1.0)->count());
    }

    public function test_array_join(): void
    {
        $tags = $this->table(self::EVENTS)->select('id', 'tag')->arrayJoin('tags', 'tag')->orderBy('id')->orderBy('tag')->get()
            ->map(fn ($row): string => ((array) $row)['id'] . ((array) $row)['tag'])->all();
        $this->assertSame(['1x', '1y', '3z', '4x', '5y'], $tags);

        $left = $this->table(self::EVENTS)->select('id', 'tag')->leftArrayJoin(['tag' => 'tags'])->where('id', 2)->get()->all();
        $this->assertCount(1, $left);
        $this->assertSame('', ((array) $left[0])['tag']);

        $this->assertSame(5, $this->table(self::EVENTS)->select('id', 'tag')->arrayJoin('tags', 'tag')->count());

        $ids = $this->table(self::EVENTS)->select('n')
            ->arrayJoinSub(fn ($q) => $q->from(self::USERS)->selectRaw('groupArray(id)')->where('country', 'DE'), 'n')
            ->where('id', 1)->get()->pluck('n')->all();
        $this->assertSame([1], array_map('intval', $ids));
    }

    public function test_clickhouse_joins(): void
    {
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->table(self::EVENTS)->select(self::EVENTS . '.id')
                ->semiLeftJoin(self::USERS, self::EVENTS . '.user_id', '=', self::USERS . '.id'))
        );
        $this->assertSame(
            [4, 5, 6],
            $this->ids($this->table(self::EVENTS)->select(self::EVENTS . '.id')
                ->antiLeftJoin(self::USERS, self::EVENTS . '.user_id', '=', self::USERS . '.id'))
        );
        $countries = $this->table(self::EVENTS)->select(self::EVENTS . '.id', 'u.country')
            ->anyLeftJoinSub($this->table(self::USERS), 'u', self::EVENTS . '.user_id', '=', 'u.id')
            ->orderBy(self::EVENTS . '.id')->pluck('country')->all();
        $this->assertSame(['DE', 'DE', 'DE', '', '', ''], $countries);

        $prices = $this->table(self::EVENTS)->select(self::EVENTS . '.id', 'q.price')
            ->asofJoin(self::QUOTES . ' as q', function ($join) {
                $join->on(self::EVENTS . '.user_id', '=', 'q.user_id')->on(self::EVENTS . '.ts', '>=', 'q.ts');
            })
            ->orderBy(self::EVENTS . '.id')->pluck('price')->map(fn ($price): int => (int) $price)->all();
        $this->assertSame([10, 20, 20], $prices);
    }

    public function test_prewhere(): void
    {
        $query = $this->table(self::EVENTS)->preWhere('user_id', 1)->preWhereIn('id', [1, 2, 4])->where('name', '!=', '');
        $this->assertSame([1], $this->ids($query));
        $this->assertSame(1, $query->count());
        $this->assertTrue($query->exists());

        $this->assertSame(
            [1, 3],
            $this->ids($this->table(self::EVENTS)->preWhere(fn ($q) => $q->where('id', 1)->orWhere('id', 3)))
        );
        $this->assertSame(
            [2, 3],
            $this->ids($this->table(self::EVENTS)->preWhereRaw('id between ? and ?', [2, 3]))
        );
    }

    public function test_global_in_and_empty(): void
    {
        $this->assertSame(
            [1, 2, 3],
            $this->ids($this->table(self::EVENTS)->whereGlobalIn('user_id', fn ($q) => $q->from(self::USERS)->select('id')))
        );
        $this->assertSame([4, 5, 6], $this->ids($this->table(self::EVENTS)->whereGlobalNotIn('user_id', [1])));
        $this->assertSame([2, 5], $this->ids($this->table(self::EVENTS)->whereEmpty('name')));
        $this->assertSame([2, 6], $this->ids($this->table(self::EVENTS)->whereEmpty('tags')));
        $this->assertSame([1, 3, 4], $this->ids($this->table(self::EVENTS)->whereNotEmpty('name')->whereNotEmpty('tags')));

        $users = $this->table(self::EVENTS)->select('user_id')->selectRaw('groupArrayIf(name, name = \'\') as blanks')
            ->groupBy('user_id')->havingNotEmpty('blanks')->orderBy('user_id')->pluck('user_id')->all();
        $this->assertSame([1, 2], array_map('intval', $users));
    }

    public function test_limit_by(): void
    {
        $rows = $this->table(self::EVENTS)->select('id', 'user_id')->orderByDesc('id')->limitBy(1, 'user_id')->get();
        $this->assertSame([6, 3], $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, $this->table(self::EVENTS)->limitBy(1, 'user_id')->count());
        $this->assertSame(
            [5, 2],
            $this->table(self::EVENTS)->select('id')->orderByDesc('id')->limitByWithOffset(1, 1, 'user_id')->pluck('id')
                ->map(fn ($id): int => (int) $id)->all()
        );
    }

    public function test_settings_and_with(): void
    {
        $rows = $this->table(self::EVENTS)->where('id', 1)->settings(['max_threads' => 1, 'log_comment' => "it's"])->get();
        $this->assertCount(1, $rows);
        $this->assertSame(6, $this->table(self::EVENTS)->settings('max_threads', 1)->count());

        $rows = $this->connection()->query()->from('recent')
            ->withExpression('recent', fn ($q) => $q->from(self::EVENTS)->where('user_id', 2))
            ->withAlias('limit_id', 5)
            ->whereRaw('id < limit_id')
            ->get();
        $this->assertSame([4], $rows->pluck('id')->map(fn ($id): int => (int) $id)->all());

        $numbers = $this->connection()->query()->from('r')
            ->withRecursiveExpression('r', 'select 1 as n union all select n + 1 from r where n < 3')
            ->orderBy('n')->pluck('n')->map(fn ($n): int => (int) $n)->all();
        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_intersect_and_except(): void
    {
        $january = $this->table(self::EVENTS)->select('user_id')->where('ts', '<', '2024-02-01 00:00:00');
        $february = $this->table(self::EVENTS)->select('user_id')->where('ts', '>=', '2024-02-01 00:00:00');

        $this->assertSame([1, 2], $this->userIds((clone $january)->intersectDistinct(clone $february)));
        $this->assertSame([], $this->userIds((clone $january)->exceptDistinct(clone $february)));
        $this->assertSame(
            [1, 2],
            $this->userIds($this->table(self::EVENTS)->select('user_id')->where('id', '>', 3)->exceptDistinct(
                $this->table(self::EVENTS)->select('user_id')->where('id', '>', 10)
            )->unionAll($this->table(self::EVENTS)->select('user_id')->where('id', 1)))
        );
    }

    public function test_mutations(): void
    {
        $this->table(self::EVENTS)->preWhere('user_id', 2)->where('id', '>', 4)->delete();
        $this->assertSame([1, 2, 3, 4], $this->ids($this->table(self::EVENTS)));

        $this->table(self::EVENTS)->where('user_id', 1)->delete(null, false, '202402');
        $this->assertSame([1, 2, 4], $this->ids($this->table(self::EVENTS)));

        $this->table(self::EVENTS)->where('id', '>', 0)->update(['name' => 'jan'], 202401);
        $this->assertSame(['jan', 'jan', 'jan'], $this->table(self::EVENTS)->orderBy('id')->pluck('name')->all());

        $this->table(self::EVENTS)->where('id', 4)->delete(lightweight: true);
        $this->assertSame([1, 2], $this->ids($this->table(self::EVENTS)));
    }

    private function table(string $table): QueryBuilder
    {
        $query = $this->connection()->table($table);
        $this->assertInstanceOf(QueryBuilder::class, $query);

        return $query;
    }

    /**
     * A copy of the default connection with Laravel's query builder and mutations_sync = 2, so that a mutation has
     * changed the rows when it returns.
     */
    private function connection(): Connection
    {
        $wanted = array_merge(config('database.connections.clickhouse'), ['fix_default_query_builder' => false]);
        $wanted['settings'] = array_merge($wanted['settings'] ?? [], ['mutations_sync' => 2]);

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

    /**
     * @return list<int>
     */
    private function ids(LaravelBuilder $query): array
    {
        $ids = array_map(fn ($row): int => (int) ((array) $row)['id'], $query->get()->all());
        sort($ids);

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function userIds(LaravelBuilder $query): array
    {
        $ids = array_map(fn ($row): int => (int) ((array) $row)['user_id'], $query->get()->all());
        sort($ids);

        return $ids;
    }
}
