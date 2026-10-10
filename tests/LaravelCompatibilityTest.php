<?php

declare(strict_types=1);

namespace Tests;

use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Laravel query methods of the bundled builder, run on the server: operators and booleans in lower case, groups
 * that add no condition, BETWEEN lists, whereColumn(), whereExists(), whereAll(), whereAny(), whereNone(), the date
 * part methods, inRandomOrder(), latest(), oldest(), when(), the whereLike() family and Laravel database
 * expressions.
 */
class LaravelCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS lc_events SYNC');
        $client->write('DROP TABLE IF EXISTS lc_tags SYNC');
        $client->write(
            'CREATE TABLE lc_events (id UInt32, a Int32, b Int32, s String, nd Nullable(DateTime), created_at DateTime,'
            . " at_tokyo DateTime('Asia/Tokyo'), at64 DateTime64(3), d32 Date32, d Date) ENGINE = MergeTree ORDER BY id"
        );
        $client->write('CREATE TABLE lc_tags (v String, w Int32) ENGINE = MergeTree ORDER BY v');
        $client->write(
            'INSERT INTO lc_events VALUES'
            . " (1, 1, 2, 'xa', NULL, '2024-01-05 10:20:30', '2024-01-05 10:20:30', '2024-01-05 10:20:30.123', '1960-05-05', '2024-01-05'),"
            . " (2, 3, 3, 'Xb', '2024-02-15 00:00:00', '2024-02-15 23:00:00', '2024-02-16 08:00:00', '2200-05-05 10:00:00.000', '2024-02-15', '2024-02-15'),"
            . " (3, 5, 4, 'yc', NULL, '2024-03-01 09:05:00', '2024-03-01 09:05:00', '2024-03-01 09:05:00.000', '2200-05-05', '2024-03-01')"
        );
        $client->write("INSERT INTO lc_tags VALUES ('p', 1), ('q', 2)");
    }

    protected function tearDown(): void
    {
        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS lc_events SYNC');
        $client->write('DROP TABLE IF EXISTS lc_tags SYNC');

        parent::tearDown();
    }

    /**
     * A query on the events table, from the connection as Laravel code starts one.
     *
     * @return Builder
     */
    private function events(): Builder
    {
        return DB::connection('clickhouse')->table('lc_events');
    }

    /**
     * Get the ids of the rows the query returns, in order.
     *
     * @param Builder $query
     * @return array<int, int>
     */
    private function ids(Builder $query): array
    {
        return array_map(fn (array $row): int => (int) $row['id'], $query->select('id')->orderBy('id')->getRows());
    }

    /**
     * A sub-query of the EXISTS tests: SELECT `a`, `b` FROM `lc_events` WHERE `id` = 1, combined with a copy whose
     * b is 100 higher, so EXCEPT returns its row and INTERSECT returns none.
     *
     * @param BaseBuilder $query
     * @param string $operator except or intersect
     * @return BaseBuilder
     */
    private function firstRowCombinedWithAnother(BaseBuilder $query, string $operator): BaseBuilder
    {
        return $query->select('a', 'b')->from('lc_events')->where('id', 1)->{$operator}(
            fn (BaseBuilder $other) => $other->select('a', new Expression('b + 100'))->from('lc_events')->where('id', 1)
        );
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, array<int, int>}>
     */
    public static function conditionProvider(): array
    {
        return [
            'like' => [fn (Builder $q) => $q->where('s', 'like', 'x%'), [1]],
            'not like' => [fn (Builder $q) => $q->where('s', 'not like', 'x%'), [2, 3]],
            'ilike' => [fn (Builder $q) => $q->where('s', 'ilike', 'x%'), [1, 2]],
            'not ilike' => [fn (Builder $q) => $q->where('s', 'Not ILike', 'x%'), [3]],
            'in' => [fn (Builder $q) => $q->where('id', 'in', [1, 2]), [1, 2]],
            'between' => [fn (Builder $q) => $q->where('id', 'between', [2, 3]), [2, 3]],
            'is null' => [fn (Builder $q) => $q->where('nd', 'is null', null), [1, 3]],
            'or as a boolean' => [fn (Builder $q) => $q->where('id', 1)->whereIn('id', [3], 'or'), [1, 3]],
            'a group that adds no condition' => [fn (Builder $q) => $q->where('a', '>', 1)->where(fn (Builder $group) => $group), [2, 3]],
            'whereBetween with a keyed list' => [fn (Builder $q) => $q->whereBetween('id', ['from' => 1, 'to' => 2]), [1, 2]],
            'whereBetween with three values' => [fn (Builder $q) => $q->whereBetween('id', [2, 3, 1]), [2, 3]],
            'whereBetweenColumns with a keyed list' => [fn (Builder $q) => $q->whereBetweenColumns('a', ['min' => 'a', 'max' => 'b']), [1, 2]],
            'whereColumn' => [fn (Builder $q) => $q->whereColumn('a', 'b'), [2]],
            'whereColumn with an operator' => [fn (Builder $q) => $q->whereColumn('a', '<', 'b'), [1]],
            'whereColumn with qualified names' => [fn (Builder $q) => $q->whereColumn('lc_events.a', '>', 'lc_events.b'), [3]],
            'whereColumn with a list' => [fn (Builder $q) => $q->whereColumn([['a', 'b'], ['a', '<=', 'b']]), [2]],
            'orWhereColumn' => [fn (Builder $q) => $q->where('id', 3)->orWhereColumn('a', 'b'), [2, 3]],
            'orWhereColumn with a list' => [fn (Builder $q) => $q->where('id', 3)->orWhereColumn([['a', 'b'], ['b', '<', 'a']]), [2, 3]],
            'orWhereColumn with pairs' => [fn (Builder $q) => $q->where('id', 3)->orWhereColumn(['a' => 'b', 'id' => 'a']), [1, 2, 3]],
            'where with an array' => [fn (Builder $q) => $q->where(['a' => 3, 'b' => 3]), [2]],
            'orWhere with an array' => [fn (Builder $q) => $q->where('id', 1)->orWhere([['a', '>', 4], ['s', 'Xb']]), [1, 2, 3]],
            'whereBetween with a null bound' => [fn (Builder $q) => $q->whereBetween('a', [null, 5]), []],
            'whereNotBetween with a null bound' => [fn (Builder $q) => $q->whereNotBetween('a', [1, null]), []],
            'preWhereColumn' => [fn (Builder $q) => $q->preWhereColumn('a', '<=', 'b'), [1, 2]],
            'whereExists' => [fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->from('lc_tags')->where('v', 'p')), [1, 2, 3]],
            'whereExists without a row' => [fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->from('lc_tags')->where('v', 'z')), []],
            'whereNotExists' => [fn (Builder $q) => $q->whereNotExists(fn (Builder $s) => $s->from('lc_tags')->where('v', 'p')), []],
            'orWhereExists without a row' => [fn (Builder $q) => $q->where('a', 5)->orWhereExists(fn (Builder $s) => $s->from('lc_tags')->where('v', 'z')), [3]],
            'whereAll' => [fn (Builder $q) => $q->whereAll(['a', 'b'], '>', 1), [2, 3]],
            'whereAny' => [fn (Builder $q) => $q->whereAny(['a', 'b'], 3), [2]],
            'whereNone' => [fn (Builder $q) => $q->whereNone(['a', 'b'], 1), [2, 3]],
            'orWhereNone' => [fn (Builder $q) => $q->where('id', 1)->orWhereNone(['a', 'b'], '>', 1), [1]],
            'whereNone on a nullable column' => [fn (Builder $q) => $q->whereNone(['nd'], null), [2]],
            'preWhereAny' => [fn (Builder $q) => $q->preWhereAny(['a', 'b'], 5), [3]],
            'whereDate' => [fn (Builder $q) => $q->whereDate('created_at', '2024-01-05'), [1]],
            'whereDate with a date' => [fn (Builder $q) => $q->whereDate('created_at', '>=', Carbon::parse('2024-02-15 23:00:00')), [2, 3]],
            'whereDate on a time zone column' => [fn (Builder $q) => $q->whereDate('at_tokyo', '2024-02-16'), [2]],
            'whereDate on a DateTime64 after 2149' => [fn (Builder $q) => $q->whereDate('at64', '2200-05-05'), [2]],
            'whereDate on a Date32 before 1970' => [fn (Builder $q) => $q->whereDate('d32', '1960-05-05'), [1]],
            'whereDate on a Date' => [fn (Builder $q) => $q->whereDate('d', 'between', ['2024-01-01', '2024-02-15']), [1, 2]],
            'whereDate on a nullable column' => [fn (Builder $q) => $q->whereDate('nd', null), [1, 3]],
            'whereTime' => [fn (Builder $q) => $q->whereTime('created_at', '>=', '10:20'), [1, 2]],
            'whereTime on a time zone column' => [fn (Builder $q) => $q->whereTime('at_tokyo', '10:20:30'), [1]],
            'whereTime with padding' => [fn (Builder $q) => $q->whereTime('created_at', '9:05'), [3]],
            'whereTime on a DateTime64' => [fn (Builder $q) => $q->whereTime('at64', Carbon::parse('2000-01-01 10:20:30.999')), [1]],
            'whereDay' => [fn (Builder $q) => $q->whereDay('created_at', '05'), [1]],
            'whereDay on a DateTime64' => [fn (Builder $q) => $q->whereDay('at64', 5), [1, 2]],
            'whereMonth' => [fn (Builder $q) => $q->whereMonth('created_at', '>', '01'), [2, 3]],
            'orWhereMonth with a list' => [fn (Builder $q) => $q->where('id', 3)->orWhereMonth('created_at', 'in', [1, '02']), [1, 2, 3]],
            'whereYear' => [fn (Builder $q) => $q->whereYear('created_at', Carbon::parse('2024-06-01')), [1, 2, 3]],
            'whereYear on a Date32' => [fn (Builder $q) => $q->whereYear('d32', 1960), [1]],
            'preWhereDate' => [fn (Builder $q) => $q->preWhereDate('created_at', '2024-01-05'), [1]],
            'whereLike' => [fn (Builder $q) => $q->whereLike('s', 'X%'), [1, 2]],
            'whereLike, case-sensitive' => [fn (Builder $q) => $q->whereLike('s', 'X%', true), [2]],
            'whereNotLike' => [fn (Builder $q) => $q->whereNotLike('s', 'X%'), [3]],
            'orWhereNotLike, case-sensitive' => [fn (Builder $q) => $q->where('id', 1)->orWhereNotLike('s', 'x%', true), [1, 2, 3]],
            'when' => [fn (Builder $q) => $q->when(false, fn (Builder $w) => $w->where('id', 1), fn (Builder $w) => $w->where('id', 2)), [2]],
            'unless' => [fn (Builder $q) => $q->unless(false, fn (Builder $w) => $w->where('id', 3)), [3]],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     * @param array<int, int> $expectedIds
     */
    #[DataProvider('conditionProvider')]
    public function test_a_condition_returns_the_rows_it_selects(Closure $condition, array $expectedIds): void
    {
        $this->assertSame($expectedIds, $this->ids($condition($this->events())));
    }

    /**
     * ClickHouse leaves columns out of the queries that INTERSECT and EXCEPT compare inside EXISTS; whereExists()
     * makes it compare all of them. EXCEPT returns the first row, INTERSECT returns none.
     *
     * @return array<string, array{Closure(LaravelCompatibilityTest, BaseBuilder): BaseBuilder, bool}>
     */
    public static function intersectOrExceptProvider(): array
    {
        return [
            'except' => [fn (self $test, BaseBuilder $s) => $test->firstRowCombinedWithAnother($s, 'except'), true],
            'intersect' => [fn (self $test, BaseBuilder $s) => $test->firstRowCombinedWithAnother($s, 'intersect'), false],
            'except in from()' => [
                fn (self $test, BaseBuilder $s) => $s->select('a')->from($test->firstRowCombinedWithAnother($s->newQuery(), 'except')),
                true,
            ],
            'intersect in from()' => [
                fn (self $test, BaseBuilder $s) => $s->select('a')->from($test->firstRowCombinedWithAnother($s->newQuery(), 'intersect')),
                false,
            ],
            'except in a join' => [
                fn (self $test, BaseBuilder $s) => $s->select('v')->from('lc_tags')
                    ->crossJoin($test->firstRowCombinedWithAnother($s->newQuery(), 'except'), false, 'x'),
                true,
            ],
            'intersect in a join' => [
                fn (self $test, BaseBuilder $s) => $s->select('v')->from('lc_tags')
                    ->crossJoin($test->firstRowCombinedWithAnother($s->newQuery(), 'intersect'), false, 'x'),
                false,
            ],
            'except in a withExpression()' => [
                fn (self $test, BaseBuilder $s) => $s->withExpression('w', $test->firstRowCombinedWithAnother($s->newQuery(), 'except'))
                    ->select('a')->from('w'),
                true,
            ],
            'except as an operand of union all' => [
                fn (self $test, BaseBuilder $s) => $s->select('a', 'b')->from('lc_events')->where('id', 99)
                    ->unionAll($test->firstRowCombinedWithAnother($s->newQuery(), 'except')),
                true,
            ],
        ];
    }

    /**
     * @param Closure(LaravelCompatibilityTest, BaseBuilder): BaseBuilder $subQuery
     */
    #[DataProvider('intersectOrExceptProvider')]
    public function test_where_exists_compares_every_column_of_intersect_and_except(Closure $subQuery, bool $returnsARow): void
    {
        $query = fn (BaseBuilder $s): BaseBuilder => $subQuery($this, $s);

        $this->assertSame($returnsARow ? [1, 2, 3] : [], $this->ids($this->events()->whereExists($query)));
        $this->assertSame($returnsARow ? [] : [1, 2, 3], $this->ids($this->events()->whereNotExists($query)));
    }

    /**
     * The same for a withExpression() of the outer query that the sub-query reads by its name, also when the
     * sub-query is in a group or nested in another sub-query.
     */
    public function test_where_exists_compares_every_column_of_an_outer_intersect_or_except_read_by_its_name(): void
    {
        $fromW = fn (BaseBuilder $sub): BaseBuilder => $sub->from('w');
        $forms = [
            'whereExists' => fn (Builder $query, bool $not): Builder => $query->whereExists($fromW, 'and', $not),
            'in a group' => fn (Builder $query, bool $not): Builder => $query->where(
                fn (BaseBuilder $group) => $group->whereExists($fromW, 'and', $not)
            ),
            'in a nested sub-query' => fn (Builder $query, bool $not): Builder => $query->whereExists(
                fn (BaseBuilder $sub) => $sub->from('lc_tags')->whereExists($fromW, 'and', $not)
            ),
        ];

        foreach (['except' => true, 'intersect' => false] as $operator => $returnsARow) {
            foreach ($forms as $form => $condition) {
                foreach ([false, true] as $not) {
                    $query = $this->events()->withExpression(
                        'w',
                        $this->firstRowCombinedWithAnother($this->events()->newQuery(), $operator)
                    );

                    $this->assertSame(
                        $returnsARow !== $not ? [1, 2, 3] : [],
                        $this->ids($condition($query, $not)),
                        "{$operator}, {$form}, " . ($not ? 'NOT EXISTS' : 'EXISTS')
                    );
                }
            }
        }
    }

    public function test_the_operators_and_helpers_that_only_order_rows(): void
    {
        $this->assertEqualsCanonicalizing([1, 2, 3], array_column($this->events()->select('id')->inRandomOrder()->getRows(), 'id'));
        $this->assertSame([3, 2, 1], array_map('intval', array_column($this->events()->select('id')->latest()->getRows(), 'id')));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($this->events()->select('id')->oldest('d')->getRows(), 'id')));

        $this->expectException(InvalidArgumentException::class);

        $this->events()->inRandomOrder(42);
    }

    /**
     * A lightweight delete with each condition removes exactly the rows a select with it returns.
     *
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function mutationConditionProvider(): array
    {
        return [
            'whereColumn' => [fn (Builder $q) => $q->whereColumn('a', '<', 'b')],
            'whereNone' => [fn (Builder $q) => $q->whereNone(['a', 'b'], 1)],
            'whereAny with a lower-case operator' => [fn (Builder $q) => $q->whereAny(['s'], 'ilike', 'x%')],
            'whereExists' => [fn (Builder $q) => $q->where('a', 5)->whereExists(fn (Builder $s) => $s->from('lc_tags')->where('v', 'p'))],
            'whereDate' => [fn (Builder $q) => $q->whereDate('created_at', '2024-01-05')],
            'whereTime' => [fn (Builder $q) => $q->whereTime('created_at', '>=', '10:00')],
            'whereMonth' => [fn (Builder $q) => $q->whereMonth('created_at', 2)],
            'preWhereDate and a where' => [fn (Builder $q) => $q->preWhereDate('created_at', '>=', '2024-02-01')->where('a', '>', 0)],
            'whereLike' => [fn (Builder $q) => $q->whereLike('s', 'X%')],
            'whereBetween with a keyed list' => [fn (Builder $q) => $q->whereBetween('id', ['from' => 2, 'to' => 3])],
            'orWhereColumn with pairs' => [fn (Builder $q) => $q->where('id', 1)->orWhereColumn(['a' => 'b', 'b' => 'id'])],
            'where with an array' => [fn (Builder $q) => $q->where([['a', '>', 1], 's' => 'yc'])],
            'a group that adds no condition next to a where' => [fn (Builder $q) => $q->where('id', 2)->where(fn (Builder $group) => $group)],
            'inRandomOrder' => [fn (Builder $q) => $q->where('id', 1)->inRandomOrder()],
        ];
    }

    /**
     * @param Closure(Builder): Builder $condition
     */
    #[DataProvider('mutationConditionProvider')]
    public function test_a_lightweight_delete_removes_the_rows_the_condition_selects(Closure $condition): void
    {
        $selected = $this->ids($condition($this->events()));
        $this->assertNotSame([], $selected);

        $condition($this->events())->delete(true);

        $this->assertSame(array_values(array_diff([1, 2, 3], $selected)), $this->ids($this->events()));
    }

    /**
     * The builder writes a Laravel database expression, such as DB::raw(), as it writes raw(), with the query grammar
     * of the connection it follows. The count is selected as a string, since ClickHouse 24.8 quotes a UInt64 in JSON
     * and 25.8 and later do not.
     */
    public function test_a_laravel_expression_is_written_as_raw_sql(): void
    {
        $this->assertSame([['c' => '3']], $this->events()->select(DB::raw('toString(count()) AS c'))->getRows());
        $this->assertSame([1], $this->ids($this->events()->where(DB::raw('a + 1'), 2)));
        $this->assertSame([2], $this->ids($this->events()->where('a', DB::raw('b'))));
        $this->assertSame([1, 3], $this->ids($this->events()->where(DB::raw('nd'), null)));
        $this->assertSame([1, 2], $this->ids($this->events()->whereColumn('a', '<', DB::raw('b + 1'))));
        $this->assertSame(
            [1],
            $this->ids($this->events()->where('id', 1)->settings('max_threads', DB::raw('2')))
        );
        $this->assertSame(
            [['x' => 7]],
            $this->events()->withExpression('w', DB::raw('SELECT 7 AS x'))->select('x')->from('w')->getRows()
        );
    }
}
