<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\TestCase;

/**
 * The ClickHouse clauses of Laravel's query builder on a ClickHouse connection (fix_default_query_builder false):
 * FINAL, SAMPLE, ARRAY JOIN, the ClickHouse joins, PREWHERE, GLOBAL IN, empty(), LIMIT ... BY, SETTINGS, WITH,
 * INTERSECT and EXCEPT, and the ON CLUSTER, lightweight and IN PARTITION options of the mutations.
 */
class QueryBuilderClickHouseSqlTest extends TestCase
{
    /**
     * A query builder of the package on a stub ClickHouse connection with the given config.
     *
     * @param array<string, mixed> $config
     * @return QueryBuilder
     */
    private function query(array $config = []): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null) => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $connection->method('prepareBindings')->willReturnArgument(0);
        $connection->method('getDefaultCluster')->willReturn($config['default_cluster'] ?? null);
        $grammar = new QueryGrammar($connection);
        $connection->method('getQueryGrammar')->willReturn($grammar);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from('t');
    }

    public function test_final_and_sample_follow_the_table(): void
    {
        $this->assertSame('select * from "t" final', $this->query()->final()->toSql());
        $this->assertSame('select * from "t" as "x" final', $this->query()->from('t', 'x', true)->toSql());
        $this->assertSame('select * from "t"', $this->query()->final()->final(false)->toSql());
        $this->assertSame('select * from "t" sample 0.1', $this->query()->sample(0.1)->toSql());
        $this->assertSame('select * from "t" sample 1000000', $this->query()->sample(1_000_000)->toSql());
        $this->assertSame(
            'select * from "t" final sample 0.1 offset 0.5 where "a" = ?',
            $this->query()->final()->sample(0.1, 0.5)->where('a', 1)->toSql()
        );
    }

    public function test_final_of_a_sub_query_and_an_invalid_sample_throw(): void
    {
        $query = $this->query()->fromSub($this->query(), 's')->final();

        try {
            $query->toSql();
            $this->fail('FINAL of a sub-query must throw');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('FINAL applies to a table', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->query()->sample(0);
    }

    public function test_array_join(): void
    {
        $this->assertSame('select * from "t" array join "tags"', $this->query()->arrayJoin('tags')->toSql());
        $this->assertSame(
            'select "tag" from "t" array join "tags" as "tag"',
            $this->query()->select('tag')->arrayJoin('tags', 'tag')->toSql()
        );
        $this->assertSame(
            'select * from "t" array join "tags" as "tag", "scores"',
            $this->query()->arrayJoin(['tag' => 'tags', 'scores'])->toSql()
        );
        $this->assertSame(
            'select * from "t" left array join "tags"',
            $this->query()->leftArrayJoin('tags')->toSql()
        );
        $this->assertSame(
            'select * from "t" array join (select groupArray("id") from "u" where "a" = ?) as "ids" where "b" = ?',
            $this->query()->arrayJoinSub(
                $this->query()->from('u')->selectRaw('groupArray("id")')->where('a', 1),
                'ids'
            )->where('b', 2)->toSql()
        );
        $query = $this->query()->arrayJoinSub(fn ($q) => $q->from('u')->selectRaw('groupArray(id)')->where('a', 1), 'ids')
            ->join('v', 'v.id', '=', 't.id')
            ->where('b', 2);
        $this->assertSame([1, 2], $query->getBindings());
        $this->assertSame(
            'select * from "t" array join (select groupArray(id) from "u" where "a" = ?) as "ids" inner join "v" on "v"."id" = "t"."id" where "b" = ?',
            $query->toSql()
        );
    }

    public function test_clickhouse_joins(): void
    {
        $this->assertSame(
            'select * from "t" any left join "u" on "t"."uid" = "u"."id"',
            $this->query()->anyLeftJoin('u', 't.uid', '=', 'u.id')->toSql()
        );
        $cases = [
            'allLeftJoin' => 'all left', 'innerJoin' => 'inner', 'anyInnerJoin' => 'any inner', 'allInnerJoin' => 'all inner',
            'anyRightJoin' => 'any right', 'allRightJoin' => 'all right', 'fullJoin' => 'full', 'semiLeftJoin' => 'semi left',
            'semiRightJoin' => 'semi right', 'antiLeftJoin' => 'anti left', 'antiRightJoin' => 'anti right',
            'asofJoin' => 'asof', 'asofLeftJoin' => 'asof left',
        ];
        foreach ($cases as $method => $type) {
            $this->assertSame(
                'select * from "t" ' . $type . ' join "u" on "t"."uid" = "u"."id"',
                $this->query()->{$method}('u', 't.uid', '=', 'u.id')->toSql(),
                $method
            );
            $sub = $method . 'Sub';
            $this->assertSame(
                'select * from "t" ' . $type . ' join (select * from "u" where "a" = ?) as "s" on "t"."uid" = "s"."id"',
                $this->query()->{$sub}($this->query()->from('u')->where('a', 1), 's', 't.uid', '=', 's.id')->toSql(),
                $sub
            );
        }
        $this->assertSame(
            'select * from "t" asof left join "q" on "t"."sym" = "q"."sym" and "t"."ts" >= "q"."ts"',
            $this->query()->asofLeftJoin('q', function ($join) {
                $join->on('t.sym', '=', 'q.sym')->on('t.ts', '>=', 'q.ts');
            })->toSql()
        );
    }

    public function test_prewhere(): void
    {
        $query = $this->query()
            ->preWhere('a', 1)
            ->orPreWhere('b', '>', 2)
            ->preWhereIn('c', [3, 4])
            ->preWhereNull('d')
            ->preWhereRaw('e = ?', [5])
            ->preWhere(fn ($q) => $q->where('f', 6)->orWhere('g', 7))
            ->where('h', 8);

        $this->assertSame(
            'select * from "t" prewhere "a" = ? or "b" > ? and "c" in (?, ?) and "d" is null and e = ? and ("f" = ? or "g" = ?) where "h" = ?',
            $query->toSql()
        );
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $query->getBindings());
        $this->assertCount(1, $query->wheres);
        $this->assertCount(6, $query->prewheres);
        $this->assertSame(
            'select * from "t" prewhere "a" not in (?) or "b" is not null or "c" between ? and ? or e = ?',
            $this->query()->preWhereNotIn('a', [1])->orPreWhereNotNull('b')->orPreWhereBetween('c', [1, 2])
                ->orPreWhereRaw('e = ?', [3])->toSql()
        );
        $this->assertSame(
            'select * from "t" prewhere "a" in (select "id" from "u")',
            $this->query()->preWhereIn('a', fn ($q) => $q->from('u')->select('id'))->toSql()
        );
    }

    public function test_global_in_and_empty(): void
    {
        $query = $this->query()->whereGlobalIn('a', [1, 2])->orWhereGlobalNotIn('b', [3])
            ->whereGlobalIn('c', fn ($q) => $q->from('u')->select('id')->where('x', 4))
            ->whereGlobalIn('d', []);
        $this->assertSame(
            'select * from "t" where "a" global in (?, ?) or "b" global not in (?) and "c" global in (select "id" from "u" where "x" = ?) and 0 = 1',
            $query->toSql()
        );
        $this->assertSame([1, 2, 3, 4], $query->getBindings());

        $this->assertSame(
            'select * from "t" where empty("a") or notEmpty("b") and empty("c") and empty("d")',
            $this->query()->whereEmpty('a')->orWhereNotEmpty('b')->whereEmpty(['c', 'd'])->toSql()
        );
        $this->assertSame(
            'select "g" from "t" group by "g" having empty("g") or notEmpty("h")',
            $this->query()->select('g')->groupBy('g')->havingEmpty('g')->orHavingNotEmpty('h')->toSql()
        );
    }

    public function test_limit_by(): void
    {
        $this->assertSame(
            'select * from "t" order by "ts" desc limit 3 by "user_id" limit 100',
            $this->query()->orderByDesc('ts')->limitBy(3, 'user_id')->limit(100)->toSql()
        );
        $this->assertSame(
            'select * from "t" limit 1 offset 2 by "user_id", "day"',
            $this->query()->limitByWithOffset(1, 2, ['user_id', 'day'])->toSql()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->query()->limitBy(1);
    }

    public function test_settings(): void
    {
        $this->assertSame(
            'select * from "t" where "a" = ? settings max_threads=4, optimize_read_in_order=1, log_comment=\'it\\\'s\'',
            $this->query()->where('a', 1)->settings(['max_threads' => 2, 'optimize_read_in_order' => true])
                ->settings('max_threads', 4)->settings('log_comment', "it's")->toSql()
        );
        $this->assertSame('select * from "t"', $this->query()->settings('max_threads', 2)->settings('max_threads', null)->toSql());
        $this->assertSame(
            'select * from "t" settings max_execution_time = 5, max_threads=2',
            $this->query()->timeout(5)->settings('max_threads', 2)->toSql()
        );
        $this->assertSame(
            'select * from ((select * from "t") union all (select * from "u")) settings max_threads=2',
            $this->query()->unionAll($this->query()->from('u'))->settings('max_threads', 2)->toSql()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->query()->settings('max threads', 2)->toSql();
    }

    public function test_with(): void
    {
        $query = $this->query()->from('recent')
            ->withExpression('recent', fn ($q) => $q->from('events')->where('a', 1))
            ->withAlias('total', fn ($q) => $q->from('events')->selectRaw('count()'))
            ->withAlias('ids', [1, 2])
            ->withAlias('now_ts', new Expression('now()'))
            ->where('b', 2);

        $this->assertSame(
            'with "recent" as (select * from "events" where "a" = ?), (select count() from "events") as "total", [1, 2] as "ids", now() as "now_ts" select * from "recent" where "b" = ?',
            $query->toSql()
        );
        $this->assertSame([1, 2], $query->getBindings());
        $this->assertSame(
            'with recursive "r" as (select 1 as n union all select n + 1 from r where n < 3) select * from "r"',
            $this->query()->from('r')->withRecursiveExpression('r', 'select 1 as n union all select n + 1 from r where n < 3')->toSql()
        );
    }

    public function test_intersect_and_except(): void
    {
        $this->assertSame(
            '(select * from "t") intersect (select * from "u") except distinct (select * from "v")',
            $this->query()->intersect($this->query()->from('u'))->exceptDistinct(fn ($q) => $q->from('v'))->toSql()
        );
        $this->assertSame(
            '(select * from "t") intersect distinct (select * from "u") except (select * from "v") union distinct (select * from "w")',
            $this->query()->intersectDistinct($this->query()->from('u'))->except($this->query()->from('v'))
                ->unionDistinct($this->query()->from('w'))->toSql()
        );
    }

    public function test_mutation_options(): void
    {
        $delete = fn (QueryBuilder $query, mixed ...$arguments): string => $query->getGrammar()->compileDelete(
            tap($query, function (QueryBuilder $query) use ($arguments) {
                $query->lightweightDelete = $arguments[0] ?? null;
                $query->partition = $arguments[1] ?? null;
            })
        );

        $this->assertSame('alter table "t" delete where "a" = ?', $delete($this->query()->where('a', 1)));
        $this->assertSame('delete from "t" where "a" = ?', $delete($this->query()->where('a', 1), true));
        $this->assertSame('alter table "t" delete where "a" = ?', $delete($this->query(['use_lightweight_delete' => true])->where('a', 1), false));
        $this->assertSame(
            'alter table "t" delete in partition 202401 where "a" = ?',
            $delete($this->query()->where('a', 1), false, 202401)
        );
        $this->assertSame(
            'delete from "t" in partition \'202401\' where "a" = ?',
            $delete($this->query()->where('a', 1), true, '202401')
        );
        $this->assertSame(
            'alter table "t" on cluster \'c1\' delete in partition ID \'202401\' where "a" = ?',
            $delete($this->query()->onCluster('c1')->where('a', 1), false, new Expression("ID '202401'"))
        );
        $this->assertSame(
            'alter table "t" on cluster \'dflt\' delete where "a" = ?',
            $delete($this->query(['default_cluster' => 'dflt'])->where('a', 1))
        );
        $this->assertSame(
            'alter table "t" delete where "a" = ?',
            $delete($this->query(['default_cluster' => 'dflt'])->withoutOnCluster()->where('a', 1))
        );

        $update = $this->query()->onCluster('c1')->where('a', 1);
        $update->partition = 202401;
        $this->assertSame(
            'alter table "t" on cluster \'c1\' update "b" = ? in partition 202401 where "a" = ?',
            $update->getGrammar()->compileUpdate($update, ['b' => 2])
        );
        $this->assertSame(
            ['truncate table "t" on cluster \'c1\'' => []],
            $this->query()->onCluster('c1')->getGrammar()->compileTruncate($this->query()->onCluster('c1'))
        );
    }

    public function test_a_mutation_joins_the_prewhere_conditions_to_the_where_conditions(): void
    {
        $query = $this->query()->preWhere('a', 1)->where('b', 2);
        $this->assertSame('alter table "t" delete where ("a" = ?) and ("b" = ?)', $query->getGrammar()->compileDelete($query));
        $this->assertSame([1, 2], $query->getGrammar()->prepareBindingsForDelete($query->getRawBindings()));
        $this->assertSame('select * from "t" prewhere "a" = ? where "b" = ?', $query->toSql());

        $query = $this->query()->preWhere('a', 1);
        $this->assertSame(
            'alter table "t" update "c" = ? where "a" = ?',
            $query->getGrammar()->compileUpdate($query, ['c' => 3])
        );
        $this->assertSame([3, 1], $query->getGrammar()->prepareBindingsForUpdate($query->getRawBindings(), ['c' => 3]));
    }

    public function test_a_mutation_refuses_the_clickhouse_clauses_that_it_would_ignore(): void
    {
        $cases = [
            'FINAL' => fn (QueryBuilder $q) => $q->final(),
            'SAMPLE' => fn (QueryBuilder $q) => $q->sample(0.1),
            'ARRAY JOIN' => fn (QueryBuilder $q) => $q->arrayJoin('tags'),
            'LIMIT BY' => fn (QueryBuilder $q) => $q->limitBy(1, 'a'),
            'WITH' => fn (QueryBuilder $q) => $q->withAlias('x', 1),
            'SETTINGS' => fn (QueryBuilder $q) => $q->settings('max_threads', 1),
            'INTERSECT' => fn (QueryBuilder $q) => $q->intersect($this->query()),
            'EXCEPT DISTINCT' => fn (QueryBuilder $q) => $q->exceptDistinct($this->query()),
        ];

        foreach ($cases as $clause => $add) {
            $query = $add($this->query()->where('a', 1));

            try {
                $query->getGrammar()->compileDelete($query);
                $this->fail("A delete with {$clause} must throw");
            } catch (QueryException $exception) {
                $this->assertStringContainsString($clause, $exception->getMessage(), $clause);
            }
        }
    }

    public function test_count_counts_limit_by_and_array_join_in_a_sub_query(): void
    {
        $query = $this->query()->limitBy(1, 'a');
        $this->assertTrue((fn (): bool => $this->countsInASubquery())->call($query));
        $query = $this->query()->arrayJoin('tags');
        $this->assertTrue((fn (): bool => $this->countsInASubquery())->call($query));
        $query = $this->query()->final()->sample(0.1)->preWhere('a', 1)->settings('max_threads', 1);
        $this->assertFalse((fn (): bool => $this->countsInASubquery())->call($query));
    }
}
