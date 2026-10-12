<?php

namespace Tests;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A user with posts, roles, children and the comments of its posts.
 */
class RelationExistenceUser extends Model
{
    protected $table = 're_users';

    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * @return HasMany<RelationExistencePost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(RelationExistencePost::class, 'user_id');
    }

    /**
     * @return BelongsToMany<RelationExistenceRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(RelationExistenceRole::class, 're_role_user', 'user_id', 'role_id');
    }

    /**
     * @return HasMany<RelationExistenceUser, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasManyThrough<RelationExistenceComment, RelationExistencePost, $this>
     */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(RelationExistenceComment::class, RelationExistencePost::class, 'user_id', 'post_id');
    }
}

/**
 * A post, whose user_id may be null, with its user and comments.
 */
class RelationExistencePost extends Model
{
    protected $table = 're_posts';

    protected $keyType = 'int';

    public $timestamps = false;

    /**
     * @return BelongsTo<RelationExistenceUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(RelationExistenceUser::class, 'user_id');
    }

    /**
     * @return HasMany<RelationExistenceComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RelationExistenceComment::class, 'post_id');
    }
}

class RelationExistenceRole extends Model
{
    protected $table = 're_roles';

    protected $keyType = 'int';

    public $timestamps = false;
}

class RelationExistenceComment extends Model
{
    protected $table = 're_comments';

    protected $keyType = 'int';

    public $timestamps = false;
}

/**
 * has(), whereHas(), doesntHave() and their variants check a relation with an IN sub-query, which ClickHouse 24.8
 * runs, as 26.3 and 26.8 do; the correlated EXISTS of Laravel fails on 24.8. A relation query that reads the outer
 * table keeps Laravel's EXISTS.
 *
 * Users 1 and 2 have posts, 1 has two; post 13 has no user. User 1 has both roles and 3 has one. Users 2 and 3 are
 * children of 1, and 4 of 2. Post 10 of user 1 has a comment, and post 12 of user 2 has two.
 */
class RelationExistenceTest extends TestCase
{
    private const TABLES = ['re_users', 're_posts', 're_roles', 're_role_user', 're_comments'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        $write = fn (string $sql) => DB::connection('clickhouse')->getClient()->write($sql);
        $write('CREATE TABLE re_users (id UInt32, name String, parent_id Nullable(UInt32)) ENGINE = MergeTree ORDER BY id');
        $write('CREATE TABLE re_posts (id UInt32, user_id Nullable(UInt32), title String) ENGINE = MergeTree ORDER BY id');
        $write('CREATE TABLE re_roles (id UInt32, name String) ENGINE = MergeTree ORDER BY id');
        $write('CREATE TABLE re_role_user (user_id UInt32, role_id UInt32) ENGINE = MergeTree ORDER BY user_id');
        $write('CREATE TABLE re_comments (id UInt32, post_id UInt32) ENGINE = MergeTree ORDER BY id');
        $write("INSERT INTO re_users VALUES (1, 'a', NULL), (2, 'b', 1), (3, 'c', 1), (4, 'd', 2)");
        $write("INSERT INTO re_posts VALUES (10, 1, 'x'), (11, 1, 'y'), (12, 2, 'x'), (13, NULL, 'z')");
        $write("INSERT INTO re_roles VALUES (100, 'admin'), (101, 'editor')");
        $write('INSERT INTO re_role_user VALUES (1, 100), (1, 101), (3, 101)');
        $write('INSERT INTO re_comments VALUES (1000, 10), (1001, 12), (1002, 12)');
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    /**
     * @return array<string, array{Closure(): EloquentBuilder<Model>, list<int>, string}>
     */
    public static function relationQueries(): array
    {
        $users = 'select * from "re_users" where ';
        $posts = 'select "re_posts"."user_id" from "re_posts"';
        $roles = 'select "re_role_user"."user_id" from "re_roles" inner join "re_role_user" on "re_roles"."id" = "re_role_user"."role_id"';
        $comments = 'select "re_posts"."user_id" from "re_comments" inner join "re_posts" on "re_posts"."id" = "re_comments"."post_id"';

        return [
            'has' => [fn () => RelationExistenceUser::has('posts'), [1, 2], "{$users}\"re_users\".\"id\" in ({$posts})"],
            'doesntHave' => [
                fn () => RelationExistenceUser::doesntHave('posts'),
                [3, 4],
                "{$users}(\"re_users\".\"id\" not in ({$posts}) or \"re_users\".\"id\" is null)",
            ],
            'whereHas' => [
                fn () => RelationExistenceUser::whereHas('posts', fn (EloquentBuilder $query) => $query->where('title', 'y')),
                [1],
                "{$users}\"re_users\".\"id\" in ({$posts} where \"title\" = ?)",
            ],
            'orWhereHas' => [
                fn () => RelationExistenceUser::where('id', 4)->orWhereHas('posts'),
                [1, 2, 4],
                "{$users}\"id\" = ? or \"re_users\".\"id\" in ({$posts})",
            ],
            'at least 2' => [
                fn () => RelationExistenceUser::has('posts', '>=', 2),
                [1],
                "{$users}\"re_users\".\"id\" in ({$posts} group by \"re_posts\".\"user_id\" having count(*) >= 2)",
            ],
            'fewer than 2' => [
                fn () => RelationExistenceUser::has('posts', '<', 2),
                [2, 3, 4],
                "{$users}(\"re_users\".\"id\" not in ({$posts} group by \"re_posts\".\"user_id\" having count(*) >= 2)"
                    . ' or "re_users"."id" is null)',
            ],
            'exactly 0' => [fn () => RelationExistenceUser::has('posts', '=', 0), [3, 4], ''],
            'exactly 1' => [fn () => RelationExistenceUser::has('posts', '=', 1), [2], ''],
            'not 1' => [fn () => RelationExistenceUser::has('posts', '!=', 1), [1, 3, 4], ''],
            'more than 0' => [fn () => RelationExistenceUser::has('posts', '>', 0), [1, 2], ''],
            'at most 1' => [fn () => RelationExistenceUser::has('posts', '<=', 1), [2, 3, 4], ''],
            'belongsTo' => [
                fn () => RelationExistencePost::has('user'),
                [10, 11, 12],
                'select * from "re_posts" where "re_posts"."user_id" in (select "re_users"."id" from "re_users")',
            ],
            'belongsTo with a null key' => [fn () => RelationExistencePost::doesntHave('user'), [13], ''],
            'whereRelation' => [fn () => RelationExistencePost::whereRelation('user', 'name', 'b'), [12], ''],
            'belongsToMany' => [
                fn () => RelationExistenceUser::whereHas('roles', fn (EloquentBuilder $query) => $query->where('name', 'editor')),
                [1, 3],
                "{$users}\"re_users\".\"id\" in ({$roles} where \"name\" = ?)",
            ],
            'belongsToMany at least 2' => [fn () => RelationExistenceUser::has('roles', '>=', 2), [1], ''],
            'a relation to the same table' => [
                fn () => RelationExistenceUser::has('children'),
                [1, 2],
                "{$users}\"re_users\".\"id\" in (select \"laravel_reserved_0\".\"parent_id\" from \"re_users\" as \"laravel_reserved_0\")",
            ],
            'no relation to the same table' => [fn () => RelationExistenceUser::doesntHave('children'), [3, 4], ''],
            'hasManyThrough' => [
                fn () => RelationExistenceUser::has('comments', '>=', 2),
                [2],
                "{$users}\"re_users\".\"id\" in ({$comments} group by \"re_posts\".\"user_id\" having count(*) >= 2)",
            ],
            'nested' => [
                fn () => RelationExistenceUser::has('posts.comments'),
                [1, 2],
                "{$users}\"re_users\".\"id\" in ({$posts} where \"re_posts\".\"id\" in"
                    . ' (select "re_comments"."post_id" from "re_comments"))',
            ],
        ];
    }

    /**
     * @param Closure(): EloquentBuilder<Model> $query
     * @param list<int> $ids
     */
    #[DataProvider('relationQueries')]
    public function testARelationIsCheckedWithAnInSubQuery(Closure $query, array $ids, string $sql): void
    {
        $builder = $query();

        if ($sql !== '') {
            $this->assertSame($sql, $builder->toSql());
        } else {
            $this->assertStringContainsString(' in (select ', $builder->toSql());
        }
        $this->assertSame($ids, $this->ids($builder));
    }

    /**
     * A relation query that reads the outer table, or that has a limit, keeps Laravel's correlated EXISTS, which
     * ClickHouse 26.3 and 26.8 run and 24.8 refuses.
     */
    public function testARelationQueryThatReadsTheOuterTableKeepsExists(): void
    {
        $queries = [
            RelationExistenceUser::whereHas(
                'posts',
                fn (EloquentBuilder $query) => $query->whereColumn('re_posts.id', '>', 're_users.id')
            ),
            RelationExistenceUser::whereHas('posts', fn (EloquentBuilder $query) => $query->whereRaw('re_users.name = title')),
            RelationExistenceUser::whereHas('posts', fn (EloquentBuilder $query) => $query->limit(1)),
        ];

        foreach ($queries as $query) {
            $this->assertStringStartsWith('select * from "re_users" where exists (', $query->toSql());

            try {
                $ids = $this->ids($query);
                $this->assertContains($ids, [[1, 2], []]);
            } catch (\Illuminate\Database\QueryException|\ClickHouseDB\Exception\DatabaseException $exception) {
                $this->assertStringStartsWith('24.8', DB::connection('clickhouse')->getServerVersion());
            }
        }
    }

    /**
     * The IN sub-query runs in a mutation too, where ClickHouse refuses a correlated sub-query on every version.
     */
    public function testADeleteOfTheRowsWithoutARelation(): void
    {
        RelationExistenceUser::doesntHave('posts')->settings('mutations_sync', 2)->delete(false);

        $this->assertSame([1, 2], $this->ids(RelationExistenceUser::query()));
    }

    /**
     * @param EloquentBuilder<Model> $query
     * @return list<int>
     */
    private function ids(EloquentBuilder $query): array
    {
        $key = $query->getModel()->getQualifiedKeyName();

        return array_map('intval', $query->orderBy($key)->pluck($key)->all());
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            DB::connection('clickhouse')->getClient()->write("DROP TABLE IF EXISTS {$table} SYNC");
        }
    }
}
