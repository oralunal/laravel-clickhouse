<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * count() and the paginate() total of the package's QueryBuilder: a query whose rows the select list, the order,
 * DISTINCT, GROUP BY or a group limit changes is counted in a sub-query, so that the count matches get().
 */
class QueryBuilderCountTest extends TestCase
{
    /**
     * A query of the package's QueryBuilder on a stub connection that records each select with its bindings and
     * answers with one row whose aggregate is the given value.
     *
     * @param list<array{string, list<mixed>}> $selects The recorded selects
     * @param list<array<string, mixed>> $rows The rows each select returns
     * @return QueryBuilder
     */
    private function query(array &$selects, array $rows = [['aggregate' => '3']]): QueryBuilder
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturn(null);
        $connection->method('getTablePrefix')->willReturn('');
        $connection->method('newBuilderGrammar')->willReturnCallback(fn (): Grammar => new Grammar());
        $connection->method('select')->willReturnCallback(function (string $sql, array $bindings = []) use (&$selects, $rows): array {
            $selects[] = [$sql, $bindings];

            return $rows;
        });
        $grammar = new QueryGrammar($connection);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from('t7');
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string, list<mixed>}>
     */
    public static function countedInASubqueryProvider(): array
    {
        $count = 'select count(*) as "aggregate" from (%s) as "aggregate_table"';

        return [
            'a select alias that a condition reads' => [
                fn (QueryBuilder $query) => $query->select('id as x')->where('x', 3),
                sprintf($count, 'select "id" as "x" from "t7" where "x" = ?'),
                [3],
            ],
            'selectRaw() with arrayJoin()' => [
                fn (QueryBuilder $query) => $query->selectRaw('arrayJoin(tags) as tag'),
                sprintf($count, 'select arrayJoin(tags) as tag from "t7"'),
                [],
            ],
            'selectRaw() with a binding' => [
                fn (QueryBuilder $query) => $query->selectRaw('id + ? as y', [1])->where('id', '>', 2),
                sprintf($count, 'select id + ? as y from "t7" where "id" > ?'),
                [1, 2],
            ],
            'selectSub()' => [
                fn (QueryBuilder $query) => $query->select('id')->selectSub(fn (Builder $sub) => $sub->from('u')->selectRaw('1'), 'one'),
                sprintf($count, 'select "id", (select 1 from "u") as "one" from "t7"'),
                [],
            ],
            'an order that defines an alias' => [
                fn (QueryBuilder $query) => $query->select('id')->orderByRaw('id * 2 as d')->where('d', '>', 2),
                sprintf($count, 'select "id" from "t7" where "d" > ? order by id * 2 as d'),
                [2],
            ],
            'an order that calls arrayJoin()' => [
                fn (QueryBuilder $query) => $query->orderBy(new Expression('arrayJoin(tags)')),
                sprintf($count, 'select * from "t7" order by arrayJoin(tags) asc'),
                [],
            ],
            'an order WITH FILL' => [
                fn (QueryBuilder $query) => $query->select('id')->where('id', 999)->orderByRaw('id with fill from 0 to 3'),
                sprintf($count, 'select "id" from "t7" where "id" = ? order by id with fill from 0 to 3'),
                [999],
            ],
            'distinct()' => [
                fn (QueryBuilder $query) => $query->distinct()->select('flag'),
                sprintf($count, 'select distinct "flag" from "t7"'),
                [],
            ],
            'groupBy()' => [
                fn (QueryBuilder $query) => $query->select('flag')->groupBy('flag'),
                sprintf($count, 'select "flag" from "t7" group by "flag"'),
                [],
            ],
            'groupBy() without a select list, which counts the groups' => [
                fn (QueryBuilder $query) => $query->groupBy('flag'),
                sprintf($count, 'select 1 from "t7" group by "flag"'),
                [],
            ],
            'groupBy() with select(*)' => [
                fn (QueryBuilder $query) => $query->select('*')->groupBy('flag')->orderBy('flag'),
                sprintf($count, 'select 1 from "t7" group by "flag" order by "flag" asc'),
                [],
            ],
            'distinct() and groupBy() without a select list' => [
                fn (QueryBuilder $query) => $query->distinct()->groupBy('flag'),
                sprintf($count, 'select 1 from "t7" group by "flag"'),
                [],
            ],
            'groupByRaw() with a binding, without a select list' => [
                fn (QueryBuilder $query) => $query->where('id', '>', 0)->groupByRaw('flag % ?', [2]),
                sprintf($count, 'select 1 from "t7" where "id" > ? group by flag % ?'),
                [0, 2],
            ],
            'groupBy() and a having clause without a select list' => [
                fn (QueryBuilder $query) => $query->groupBy('flag')->havingRaw('count() > ?', [1]),
                sprintf($count, 'select 1 from "t7" group by "flag" having count() > ?'),
                [1],
            ],
            'an order with LIMIT ... BY' => [
                fn (QueryBuilder $query) => $query->select('id')->where('id', '>', 0)->orderByRaw('ts desc limit 1 by user'),
                sprintf($count, 'select "id" from "t7" where "id" > ? order by ts desc limit 1 by user'),
                [0],
            ],
            'an order with LIMIT, without the limit of the query' => [
                fn (QueryBuilder $query) => $query->orderByRaw('id LIMIT 2')->limit(5),
                sprintf($count, 'select * from "t7" order by id LIMIT 2'),
                [],
            ],
            'an order with OFFSET and FETCH' => [
                fn (QueryBuilder $query) => $query->orderByRaw('id offset 1 rows fetch first 2 rows only'),
                sprintf($count, 'select * from "t7" order by id offset 1 rows fetch first 2 rows only'),
                [],
            ],
            'a group limit' => [
                fn (QueryBuilder $query) => $query->orderBy('id')->groupLimit(1, 'flag'),
                sprintf(
                    $count,
                    'select * from (select *, row_number() over (partition by "flag" order by "id" asc) as "laravel_row"'
                    . ' from "t7") as "laravel_table" where "laravel_row" <= 1 order by "laravel_row"'
                ),
                [],
            ],
            'an alias, without the limit and the offset' => [
                fn (QueryBuilder $query) => $query->select('id as x')->limit(1)->offset(2),
                sprintf($count, 'select "id" as "x" from "t7"'),
                [],
            ],
            'a timeout and an index hint, on the outer query' => [
                fn (QueryBuilder $query) => $query->select('id as x')->timeout(5)->forceIndex('idx_name'),
                sprintf($count, 'select "id" as "x" from "t7"') . " settings max_execution_time = 5, force_data_skipping_indices = 'idx_name'",
                [],
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     * @param list<mixed> $bindings
     */
    #[DataProvider('countedInASubqueryProvider')]
    public function test_count_counts_the_rows_that_get_returns_in_a_subquery(Closure $build, string $sql, array $bindings): void
    {
        $selects = [];
        $query = $build($this->query($selects));

        $this->assertSame(3, $query->count());
        $this->assertSame([[$sql, $bindings]], $selects);
    }

    #[DataProvider('countedInASubqueryProvider')]
    public function test_the_paginate_total_counts_the_same_subquery(Closure $build, string $sql, array $bindings): void
    {
        $selects = [];
        $query = $build($this->query($selects));

        $this->assertSame(3, $query->getCountForPagination());
        $this->assertSame([[$sql, $bindings]], $selects);
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): mixed, string}>
     */
    public static function countedAsLaravelCountsProvider(): array
    {
        return [
            'a plain query' => [fn (QueryBuilder $query) => $query->where('id', '>', 1)->count(), 'select count(*) as "aggregate" from "t7" where "id" > ?'],
            'a plain select list and order' => [
                fn (QueryBuilder $query) => $query->select('id', 'name')->orderBy('id')->count(),
                'select count(*) as "aggregate" from "t7"',
            ],
            'a limit' => [fn (QueryBuilder $query) => $query->limit(1)->count(), 'select count(*) as "aggregate" from "t7" limit 1'],
            'count($column)' => [fn (QueryBuilder $query) => $query->select('id as x')->count('id'), 'select count("id") as "aggregate" from "t7"'],
            'distinct()->count($column)' => [
                fn (QueryBuilder $query) => $query->distinct()->count('flag'),
                'select count(distinct "flag") as "aggregate" from "t7"',
            ],
            'distinct($column)' => [
                fn (QueryBuilder $query) => $query->distinct('flag')->count(),
                'select count(distinct "flag") as "aggregate" from "t7"',
            ],
            'distinct($column) with a plain select list' => [
                fn (QueryBuilder $query) => $query->select('id', 'flag')->distinct('flag')->where('id', '>', 1)->count(),
                'select count(distinct "flag") as "aggregate" from "t7" where "id" > ?',
            ],
            'an order by a sub-query with a limit, which limits only the sub-query' => [
                fn (QueryBuilder $query) => $query->orderBy(fn (Builder $sub) => $sub->from('u')->selectRaw('max(id)')->limit(1))->count(),
                'select count(*) as "aggregate" from "t7"',
            ],
            'a raw order with limit only in quotes' => [
                fn (QueryBuilder $query) => $query->orderByRaw("\"limit\" desc, `offset`, s = 'fetch (' desc")->count(),
                'select count(*) as "aggregate" from "t7"',
            ],
            'a groupBy() without a select list, with a having clause and a union' => [
                fn (QueryBuilder $query) => $query->groupBy('flag')->havingRaw('count() > 1')
                    ->unionAll($query->newQuery()->from('u')->select('flag'))->count(),
                'select count(*) as "aggregate" from ((select * from "t7" group by "flag" having count() > 1) union all (select "flag" from "u")) as "temp_table"',
            ],
            'a having clause' => [
                fn (QueryBuilder $query) => $query->select('flag')->groupBy('flag')->havingRaw('count() > 1')->count(),
                'select count(*) as "aggregate" from (select "flag" from "t7" group by "flag" having count() > 1) as "temp_table"',
            ],
            'a union' => [
                fn (QueryBuilder $query) => $query->select('id')->unionAll($query->newQuery()->from('u')->select('id'))->count(),
                'select count(*) as "aggregate" from ((select "id" from "t7") union all (select "id" from "u")) as "temp_table"',
            ],
            'max()' => [fn (QueryBuilder $query) => $query->select('id as x')->max('id'), 'select max("id") as "aggregate" from "t7"'],
            'a timeout' => [
                fn (QueryBuilder $query) => $query->timeout(2)->count(),
                'select count(*) as "aggregate" from "t7" settings max_execution_time = 2',
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): mixed $aggregate
     */
    #[DataProvider('countedAsLaravelCountsProvider')]
    public function test_other_aggregates_are_laravels(Closure $aggregate, string $sql): void
    {
        $selects = [];
        $aggregate($this->query($selects));

        $this->assertSame($sql, $selects[0][0]);
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string}>
     */
    public static function paginationCountProvider(): array
    {
        return [
            'a having clause without groups' => [
                fn (QueryBuilder $query) => $query->select('flag')->havingRaw('flag > 1')->orderBy('flag'),
                'select count(*) as "aggregate" from (select "flag" from "t7" having flag > 1 order by "flag" asc) as "aggregate_table"',
            ],
            'a union' => [
                fn (QueryBuilder $query) => $query->select('id')->unionAll($query->newQuery()->from('u')->select('id'))->orderBy('id'),
                'select count(*) as "aggregate" from ((select "id" from "t7") union all (select "id" from "u")) as "temp_table"',
            ],
            'a plain query' => [
                fn (QueryBuilder $query) => $query->select('id')->where('id', '>', 1)->orderBy('id'),
                'select count(*) as "aggregate" from "t7" where "id" > ?',
            ],
            'distinct($column)' => [
                fn (QueryBuilder $query) => $query->distinct('flag')->orderBy('id'),
                'select count(distinct "flag") as "aggregate" from "t7"',
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     */
    #[DataProvider('paginationCountProvider')]
    public function test_the_paginate_total_of_havings_unions_and_plain_queries(Closure $build, string $sql): void
    {
        $selects = [];

        $this->assertSame(3, $build($this->query($selects))->getCountForPagination());
        $this->assertSame($sql, $selects[0][0]);
    }

    public function test_a_count_without_a_row_is_zero(): void
    {
        $selects = [];

        $this->assertSame(0, $this->query($selects, [])->select('id as x')->count());
        $this->assertSame(0, $this->query($selects, [])->select('id as x')->getCountForPagination());
    }

    public function test_the_counted_query_keeps_its_limit_offset_and_timeout(): void
    {
        $selects = [];
        $query = $this->query($selects)->select('id as x')->limit(1)->offset(2)->timeout(5);

        $query->count();

        $this->assertSame(1, $query->limit);
        $this->assertSame(2, $query->offset);
        $this->assertSame(5, $query->timeout);
        $this->assertSame('select "id" as "x" from "t7" limit 1 offset 2 settings max_execution_time = 5', $query->toSql());
    }
}
