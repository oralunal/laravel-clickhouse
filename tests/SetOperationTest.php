<?php

namespace Tests;

use Closure;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Set operations on the server: ones whose last operand has set operations of its
 * own, with and without settings(), also as a sub-query, and INTERSECT and EXCEPT
 * read through from(), a withExpression() name or a join.
 *
 * The client adds FORMAT JSON to the end of a query without a FORMAT clause,
 * after SETTINGS, and ClickHouse 24.8 rejects FORMAT there after a parenthesized
 * last operand. get() therefore names the JSON format itself, before SETTINGS.
 */
class SetOperationTest extends TestCase
{
    /**
     * Select column x from the given UInt8 values.
     *
     * @param string $values For example: '1, 2'
     * @return Builder
     */
    private function values(string $values): Builder
    {
        return DB::connection('clickhouse')->query()->select('x')->from(new RawColumn("values('x UInt8', {$values})"));
    }

    /**
     * @return array<string, array{Closure(self): Builder, int[]}>
     */
    public static function nestedSetOperationProvider(): array
    {
        return [
            'union all of a union all' => [
                fn (self $test): Builder => $test->values('1, 2')->unionAll($test->values('1, 2')->unionAll($test->values('1, 2'))),
                [1, 1, 1, 2, 2, 2],
            ],
            'union all of a closure with its own union all' => [
                fn (self $test): Builder => $test->values('1, 2')->unionAll(
                    fn (Builder $query) => $query->select('x')->from(new RawColumn("values('x UInt8', 1, 2)"))->unionAll($test->values('1, 2'))
                ),
                [1, 1, 1, 2, 2, 2],
            ],
            'except of a union all' => [
                fn (self $test): Builder => $test->values('1, 2, 3, 3')->except($test->values('2, 3')->unionAll($test->values('3, 4'))),
                [1],
            ],
            'intersect of a union all' => [
                fn (self $test): Builder => $test->values('1, 2, 3, 3')->intersect($test->values('2, 3')->unionAll($test->values('3, 4'))),
                [2, 3, 3],
            ],
        ];
    }

    /**
     * @param Closure(self): Builder $query
     * @param int[] $values
     */
    #[DataProvider('nestedSetOperationProvider')]
    public function testNestedSetOperationReturnsTheSameRowsWithAndWithoutSettings(Closure $query, array $values): void
    {
        $this->assertSame($values, $this->sortedValues($query($this)->getRows()), 'without settings');
        $this->assertSame(
            $values,
            $this->sortedValues($query($this)->settings(['max_threads' => 1])->getRows()),
            'with settings'
        );
    }

    /**
     * @param Closure(self): Builder $query
     * @param int[] $values
     */
    #[DataProvider('nestedSetOperationProvider')]
    public function testCountPaginateAndExistsOfANestedSetOperationWithSettings(Closure $query, array $values): void
    {
        $builder = fn (): Builder => $query($this)->settings(['max_threads' => 1]);

        $this->assertCount(count($values), $builder()->getRows(), 'the query itself');
        $this->assertSame(count($values), $builder()->count());
        $this->assertSame(count($values), $builder()->paginate(2)->total());
        $this->assertTrue($builder()->exists());
    }

    public function testNestedSetOperationWithSettingsIsSentWithFormatJsonBeforeTheSettings(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $this->values('1, 2, 3, 3')
            ->except($this->values('2, 3')->unionAll($this->values('3, 4')))
            ->settings(['max_threads' => 1])
            ->getRows();

        $this->assertSame(
            ["SELECT `x` FROM values('x UInt8', 1, 2, 3, 3) EXCEPT (SELECT `x` FROM values('x UInt8', 2, 3)"
            . " UNION ALL SELECT `x` FROM values('x UInt8', 3, 4)) FORMAT JSON SETTINGS max_threads=1"],
            array_column($connection->getQueryLog(), 'query')
        );
    }

    /**
     * Select the columns g and n from the given values.
     *
     * @param string $rows For example: "('a', 1), ('b', 2)"
     * @return Builder
     */
    private function pairs(string $rows): Builder
    {
        return DB::connection('clickhouse')->query()->select('g', 'n')->from(new RawColumn("values('g String, n UInt8', {$rows})"));
    }

    /**
     * Two-column INTERSECT and EXCEPT whose rows differ from those of the same
     * operation on g alone or on n alone, as the README's paging recipe uses them.
     *
     * @return array<string, array{Closure(self): Builder, int}>
     */
    public static function subQueryWithIntersectOrExceptProvider(): array
    {
        return [
            'except with two rows' => [
                fn (self $test): Builder => $test->pairs("('a', 1), ('a', 2), ('b', 3)")->except($test->pairs("('a', 1), ('b', 2), ('c', 3)")),
                2,
            ],
            'intersect with two rows' => [
                fn (self $test): Builder => $test->pairs("('a', 1), ('b', 2), ('c', 9), ('d', 3)")
                    ->intersect($test->pairs("('a', 1), ('b', 2), ('c', 3), ('e', 9)")),
                2,
            ],
            'intersect without a row' => [
                fn (self $test): Builder => $test->pairs("('a', 1), ('b', 2)")->intersect($test->pairs("('a', 2), ('b', 1)")),
                0,
            ],
        ];
    }

    /**
     * ClickHouse compares only the columns that the outer query reads, so count()
     * and exists() read every column of the sub-query, as get() does with *.
     *
     * @param Closure(self): Builder $setOperation
     * @param int $rows
     */
    #[DataProvider('subQueryWithIntersectOrExceptProvider')]
    public function testCountPaginateAndExistsOfASubQueryWithIntersectOrExceptMatchItsRows(Closure $setOperation, int $rows): void
    {
        $connection = DB::connection('clickhouse');
        $orders = [
            'without an order' => fn (Builder $query): Builder => $query,
            'ordered' => fn (Builder $query): Builder => $query->orderBy('g'),
            'ordered by raw sql' => fn (Builder $query): Builder => $query->orderByRaw('n DESC'),
        ];

        foreach ($orders as $label => $order) {
            $builder = fn (): Builder => $order($connection->table($setOperation($this)));

            $this->assertCount($rows, $builder()->getRows(), "the query itself, {$label}");
            $this->assertSame($rows, $builder()->count(), "count(), {$label}");
            $this->assertSame($rows, $builder()->paginate(10)->total(), "paginate()->total(), {$label}");
            $this->assertCount($rows, $builder()->paginate(10)->items(), "paginate()->items(), {$label}");
            $this->assertSame($rows > 0, $builder()->exists(), "exists(), {$label}");
        }
    }

    /**
     * The same when the rows come through a withExpression() name or a join, with
     * ClickHouse's analyzer on, the 24.8 default, and off. The joined table holds
     * each g of the sub-query's rows once, so the join returns as many rows.
     *
     * @param Closure(self): Builder $setOperation
     * @param int $rows
     */
    #[DataProvider('subQueryWithIntersectOrExceptProvider')]
    public function testCountPaginateAndExistsOfAWithExpressionOrAJoinWithIntersectOrExceptMatchItsRows(Closure $setOperation, int $rows): void
    {
        $connection = DB::connection('clickhouse');
        $forms = [
            'with expression' => fn (): Builder => $connection->query()->withExpression('w', $setOperation($this))->from('w'),
            'recursive with expression' => fn (): Builder => $connection->query()->withRecursiveExpression('w', $setOperation($this))->from('w'),
            'join' => fn (): Builder => $connection->query()
                ->from(new RawColumn("values('g String', 'a', 'b', 'c', 'd', 'e')"), 't')
                ->allInnerJoin($setOperation($this), ['g'], false, 's'),
        ];

        foreach ($forms as $form => $query) {
            foreach (['analyzer on' => 1, 'analyzer off' => 0] as $analyzer => $enableAnalyzer) {
                if ($form === 'recursive with expression' && $enableAnalyzer === 0) {
                    continue;
                }
                $builder = fn (): Builder => $query()->settings(['enable_analyzer' => $enableAnalyzer]);
                $label = "{$form}, {$analyzer}";

                $this->assertCount($rows, $builder()->getRows(), "the query itself, {$label}");
                $this->assertSame($rows, $builder()->count(), "count(), {$label}");
                $this->assertSame($rows, $builder()->paginate(10)->total(), "paginate()->total(), {$label}");
                $this->assertCount($rows, $builder()->paginate(10)->items(), "paginate()->items(), {$label}");
                $this->assertSame($rows > 0, $builder()->exists(), "exists(), {$label}");
            }
        }
    }

    /**
     * A UNION ALL whose last operand is a UNION ALL compiles flat, so with settings()
     * it also works as a sub-query: in table() and in whereIn().
     */
    public function testNestedUnionAllWithSettingsWorksAsASubQuery(): void
    {
        $connection = DB::connection('clickhouse');
        $nested = fn (): Builder => $this->values('1, 2')
            ->unionAll($this->values('3')->unionAll($this->values('4')))
            ->settings(['max_threads' => 1]);

        foreach (['analyzer on' => 1, 'analyzer off' => 0] as $analyzer => $enableAnalyzer) {
            $this->assertSame(
                [1, 2, 3, 4],
                array_column($connection->table($nested())->orderBy('x')->settings(['enable_analyzer' => $enableAnalyzer])->getRows(), 'x'),
                "table(), {$analyzer}"
            );
            $this->assertSame(
                [1, 3],
                array_column($this->values('1, 3, 5')->whereIn('x', $nested())->orderBy('x')->settings(['enable_analyzer' => $enableAnalyzer])->getRows(), 'x'),
                "whereIn(), {$analyzer}"
            );
        }
    }

    /**
     * chunk() reads a set operation as a whole, SELECT * FROM (<query>) LIMIT <offset>, <count>, so it ends and reads
     * every row once. It used to add its LIMIT to the first query: on UNION ALL it never ended, and on EXCEPT it
     * stopped after an empty first page. With one thread, ClickHouse returns the rows in the same order on every run.
     *
     * @return array<string, array{Closure(self): Builder, int[]}>
     */
    public static function chunkedSetOperationProvider(): array
    {
        return [
            'union all' => [
                fn (self $test): Builder => $test->values('1, 2, 3')->unionAll($test->values('10, 11')),
                [1, 2, 3, 10, 11],
            ],
            'except' => [
                fn (self $test): Builder => $test->values('1, 2, 3, 4, 5')->except($test->values('2, 4')),
                [1, 3, 5],
            ],
            'intersect of a union all' => [
                fn (self $test): Builder => $test->values('1, 2, 3, 3')->intersect($test->values('2, 3')->unionAll($test->values('3, 4'))),
                [2, 3, 3],
            ],
        ];
    }

    /**
     * @param Closure(self): Builder $query
     * @param int[] $values
     */
    #[DataProvider('chunkedSetOperationProvider')]
    public function testChunkOfASetOperationEndsAndReadsEveryRowOnce(Closure $query, array $values): void
    {
        foreach ([1, 2, 10] as $count) {
            $pages = [];
            $builder = $query($this)->settings(['max_threads' => 1]);
            $sqlBefore = $builder->toSql();

            $builder->chunk($count, function (array $rows, int $page) use (&$pages): void {
                $pages[$page] = array_column($rows, 'x');
            });

            $read = array_merge(...array_values($pages));
            sort($read);
            $this->assertSame($values, $read, "chunk({$count})");
            $this->assertSame(range(1, (int) ceil(count($values) / $count)), array_keys($pages), "pages of chunk({$count})");
            $this->assertSame($sqlBefore, $builder->toSql(), "chunk({$count}) leaves the builder unchanged");
            $this->assertSame(count($values), $query($this)->count());
        }
    }

    /**
     * @param Closure(self): Builder $query
     * @param int[] $values
     */
    #[DataProvider('chunkedSetOperationProvider')]
    public function testPaginateOfASetOperationPagesTheWholeResult(Closure $query, array $values): void
    {
        $pages = [];
        foreach (range(1, (int) ceil(count($values) / 2)) as $page) {
            $paginator = $query($this)->settings(['max_threads' => 1])->paginate(2, ['*'], 'page', $page);
            $this->assertSame(count($values), $paginator->total(), "total of page {$page}");
            $pages[] = array_column($paginator->items(), 'x');
        }

        $this->assertSame(array_map('count', array_chunk($values, 2)), array_map('count', $pages));
        $read = array_merge(...$pages);
        sort($read);
        $this->assertSame($values, $read);
        $this->assertCount(2, $query($this)->simplePaginate(2)->items());
    }

    /**
     * The LIMIT of a set operation belongs to its first query: count(), the total of paginate() and the aggregates
     * count with it, as get() returns the rows.
     */
    public function testCountPaginateAndAggregatesKeepTheLimitOfTheFirstQuery(): void
    {
        $query = fn (): Builder => $this->values('3, 1, 2')->orderBy('x')->limit(1)->unionAll($this->values('10, 11'));

        $this->assertSame([1, 10, 11], $this->sortedValues($query()->getRows()));
        $this->assertSame(3, $query()->count());
        $this->assertSame(3, $query()->paginate(10)->total());
        $this->assertSame([1, 10, 11], $this->sortedValues($query()->paginate(10)->items()));
        $this->assertEquals(22, $query()->sum('x'));
        $this->assertSame(1, $query()->min('x'));
        $this->assertSame(['x' => 1], $this->values('3, 1, 2')->orderBy('x')->limit(1)->unionAll($this->values('10, 11'))
            ->settings(['max_threads' => 1])->first());
    }

    /**
     * An aggregate of a set operation reads the rows of the whole query. It used to replace the columns of the first
     * query only, which ClickHouse rejected for a different number of columns, or aggregated the first query alone.
     */
    public function testAggregatesOfASetOperationReadItsRows(): void
    {
        $union = fn (): Builder => $this->values('1, 2, 3')->unionAll($this->values('10, 11'));
        $except = fn (): Builder => $this->values('1, 2, 3, 4, 5')->except($this->values('2, 4'));

        $this->assertSame(11, $union()->max('x'));
        $this->assertEquals(27, $union()->sum('x'));
        $this->assertSame(5, $except()->max('x'));
        $this->assertEquals(3, $except()->avg('x'));
        $this->assertSame(11, $union()->settings(['max_threads' => 1])->max('x'));
        $this->assertSame([1, 2, 3, 10, 11], $union()->pluck('x')->sort()->values()->all());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return int[]
     */
    private function sortedValues(array $rows): array
    {
        $values = array_column($rows, 'x');
        sort($values);

        return $values;
    }
}
