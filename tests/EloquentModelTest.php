<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Oralunal\LaravelClickHouse\Eloquent\Builder;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\Parallel;
use Oralunal\LaravelClickHouse\QueryBuilder;

/**
 * An event, with a string key, timestamps and a user.
 *
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property int $amount
 */
class EloquentModelTestEvent extends Model
{
    protected $connection = 'clickhouse-eloquent';

    protected $table = 'em_events';

    protected $guarded = [];

    protected $casts = ['amount' => 'int', 'user_id' => 'int'];

    /**
     * @return BelongsTo<EloquentModelTestUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(EloquentModelTestUser::class, 'user_id');
    }
}

/**
 * A user, with an int key, no timestamps and events.
 */
class EloquentModelTestUser extends Model
{
    protected $connection = 'clickhouse-eloquent';

    protected $table = 'em_users';

    protected $keyType = 'int';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<EloquentModelTestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(EloquentModelTestEvent::class, 'user_id');
    }
}

/**
 * A ClickHouse model on a connection that is not a ClickHouse connection.
 */
class EloquentModelTestSqliteModel extends Model
{
    protected $connection = 'eloquent-sqlite';

    protected $table = 'rows';
}

/**
 * The Eloquent model of the package against the server: Eloquent's create(), find(), save(), timestamps, relations
 * and eager loads, the ClickHouse clauses of QueryBuilder on its queries, the options of its delete() and update(),
 * and Parallel::get() with Eloquent builders.
 *
 * The connection is a copy of the default connection with fix_default_query_builder on, as packaged, so that the
 * tests show that the model uses Laravel's query builder anyway, and mutations_sync = 2.
 */
class EloquentModelTest extends TestCase
{
    private const CONNECTION = 'clickhouse-eloquent';

    protected function setUp(): void
    {
        parent::setUp();

        $config = array_merge(config('database.connections.clickhouse'), ['fix_default_query_builder' => true]);
        $config['settings'] = array_merge($config['settings'] ?? [], ['mutations_sync' => 2]);
        config(['database.connections.' . self::CONNECTION => $config]);
        DB::purge(self::CONNECTION);

        $client = $this->client();
        $client->write('DROP TABLE IF EXISTS em_events SYNC');
        $client->write('DROP TABLE IF EXISTS em_users SYNC');
        $client->write(
            'CREATE TABLE em_events (id String, user_id UInt32, type String, amount UInt32, created_at DateTime,'
            . ' updated_at DateTime) ENGINE = ReplacingMergeTree PARTITION BY toYYYYMM(created_at) ORDER BY id'
        );
        $client->write('CREATE TABLE em_users (id UInt32, name String) ENGINE = MergeTree ORDER BY id');
        $client->write("INSERT INTO em_users VALUES (1, 'Ada'), (2, 'Linus')");
    }

    protected function tearDown(): void
    {
        $this->client()->write('DROP TABLE IF EXISTS em_events SYNC');
        $this->client()->write('DROP TABLE IF EXISTS em_users SYNC');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_find_and_timestamps(): void
    {
        Carbon::setTestNow('2024-01-05 10:00:00');

        $event = EloquentModelTestEvent::create(['id' => 'e1', 'user_id' => 1, 'type' => 'click', 'amount' => 3]);
        $this->assertTrue($event->exists);
        $this->assertSame('e1', $event->getKey());

        $found = EloquentModelTestEvent::find('e1');
        $this->assertInstanceOf(EloquentModelTestEvent::class, $found);
        $this->assertSame(3, $found->amount);
        $this->assertSame('2024-01-05 10:00:00', $found->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-05 10:00:00', $found->updated_at->format('Y-m-d H:i:s'));
        $this->assertNull(EloquentModelTestEvent::find('missing'));
    }

    public function test_the_queries_use_laravels_query_builder_with_the_clickhouse_clauses(): void
    {
        $query = EloquentModelTestEvent::query();
        $this->assertInstanceOf(Builder::class, $query);
        $this->assertInstanceOf(QueryBuilder::class, $query->toBase());

        $this->insertEvents();

        $this->assertSame(
            ['e1', 'e2'],
            EloquentModelTestEvent::query()->final()->preWhere('user_id', 1)->settings('max_threads', 1)
                ->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame(
            'select * from "em_events" final prewhere "user_id" = ? where "type" = ? settings max_threads=1',
            EloquentModelTestEvent::query()->final()->preWhere('user_id', 1)->where('type', 'click')
                ->settings('max_threads', 1)->toSql()
        );
        $this->assertSame(3, EloquentModelTestEvent::query()->count());
        $this->assertSame(9, (int) EloquentModelTestEvent::query()->sum('amount'));
    }

    public function test_save_update_and_delete_send_mutations(): void
    {
        $this->insertEvents();

        $event = EloquentModelTestEvent::findOrFail('e1');
        $event->amount = 10;
        $event->save();
        $this->assertSame(10, EloquentModelTestEvent::findOrFail('e1')->amount);

        EloquentModelTestEvent::query()->where('user_id', 2)->update(['type' => 'view'], 202401);
        $this->assertSame('view', EloquentModelTestEvent::findOrFail('e3')->type);

        $event->delete();
        $this->assertNull(EloquentModelTestEvent::find('e1'));

        EloquentModelTestEvent::query()->where('id', 'e2')->delete(lightweight: true);
        $this->assertSame(['e3'], EloquentModelTestEvent::query()->pluck('id')->all());

        EloquentModelTestEvent::query()->where('id', 'e3')->forceDelete(false, 202401);
        $this->assertSame(0, EloquentModelTestEvent::query()->count());
    }

    public function test_relations_and_eager_loads(): void
    {
        $this->insertEvents();

        $user = EloquentModelTestUser::with('events')->findOrFail(1);
        $this->assertSame(['e1', 'e2'], $user->events->pluck('id')->sort()->values()->all());
        $this->assertSame(1, $user->events()->where('type', 'view')->count());

        $events = EloquentModelTestEvent::with('user')->orderBy('id')->get();
        $this->assertSame(['Ada', 'Ada', 'Linus'], $events->map(fn (EloquentModelTestEvent $event): string => $event->user->name)->all());
    }

    public function test_parallel_get_returns_models_with_their_eager_loads(): void
    {
        $this->insertEvents();

        $results = Parallel::getRows([
            'events' => EloquentModelTestEvent::with('user')->where('user_id', 1)->orderBy('id'),
            'users' => EloquentModelTestUser::query()->orderBy('id'),
            'count' => ['sql' => 'SELECT count() AS c FROM em_events', 'connection' => self::CONNECTION],
        ]);

        $this->assertInstanceOf(Collection::class, $results['events']);
        $this->assertSame(['e1', 'e2'], $results['events']->pluck('id')->all());
        $this->assertTrue($results['events']->first()->relationLoaded('user'));
        $this->assertSame('Ada', $results['events']->first()->user->name);
        $this->assertSame([1, 2], $results['users']->modelKeys());
        $this->assertSame(3, (int) $results['count'][0]['c']);
    }

    public function test_a_model_on_another_connection_is_refused(): void
    {
        config(['database.connections.eloquent-sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs a ClickHouse connection');

        EloquentModelTestSqliteModel::query();
    }

    /**
     * Insert e1 (user 1, click), e2 (user 1, view) and e3 (user 2, click), all in January 2024.
     */
    private function insertEvents(): void
    {
        EloquentModelTestEvent::query()->insert([
            ['id' => 'e1', 'user_id' => 1, 'type' => 'click', 'amount' => 2, 'created_at' => '2024-01-05 00:00:00', 'updated_at' => '2024-01-05 00:00:00'],
            ['id' => 'e2', 'user_id' => 1, 'type' => 'view', 'amount' => 3, 'created_at' => '2024-01-06 00:00:00', 'updated_at' => '2024-01-06 00:00:00'],
            ['id' => 'e3', 'user_id' => 2, 'type' => 'click', 'amount' => 4, 'created_at' => '2024-01-07 00:00:00', 'updated_at' => '2024-01-07 00:00:00'],
        ], 'JSONEachRow');
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }
}
