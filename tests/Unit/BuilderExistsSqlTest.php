<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BuilderExistsSqlTest extends TestCase
{
    private Client&MockObject $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = $this->createMock(Client::class);
    }

    /**
     * A builder on the mocked client whose query log goes to a stub connection, so get() runs without Laravel.
     *
     * @return Builder
     */
    private function builder(): Builder
    {
        $builder = new class($this->client) extends Builder {
            public static ?Connection $stubConnection = null;

            public function resolveConnection(): Connection
            {
                return static::$stubConnection;
            }
        };
        $builder::$stubConnection = $this->createStub(Connection::class);

        return $builder;
    }

    /**
     * Expect exactly one select() call with the given SQL, answering with the given rows.
     *
     * @param string $sql
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function expectSelect(string $sql, array $rows = [['1' => 1]]): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn($rows);

        $this->client->expects($this->once())
            ->method('select')
            ->with($sql)
            ->willReturn($statement);
    }

    public function test_plain_query_selects_a_constant_with_limit_one(): void
    {
        $this->expectSelect('SELECT 1 FROM `examples` WHERE `f_int` = 1 LIMIT 1');

        $this->assertTrue($this->builder()->from('examples')->where('f_int', 1)->exists());
    }

    public function test_returns_false_when_no_row_comes_back(): void
    {
        $this->expectSelect('SELECT 1 FROM `examples` WHERE `f_int` = 1 LIMIT 1', []);

        $this->assertFalse($this->builder()->from('examples')->where('f_int', 1)->exists());
    }

    public function test_doesnt_exist_is_the_opposite(): void
    {
        $this->expectSelect('SELECT 1 FROM `examples` WHERE `f_int` = 1 LIMIT 1', []);

        $this->assertTrue($this->builder()->from('examples')->where('f_int', 1)->doesntExist());
    }

    /**
     * The CSV format of the query is dropped. Because of the settings, get() then
     * names the JSON format itself, before them (see Builder::getQueryToSend()).
     */
    public function test_plain_query_drops_columns_order_and_format_and_keeps_settings_and_offset(): void
    {
        $this->expectSelect('SELECT 1 FROM `examples` WHERE `f_int` > 1 LIMIT 20, 1 FORMAT JSON SETTINGS max_threads=2');

        $this->builder()
            ->select(['f_int', 'f_string'])
            ->from('examples')
            ->where('f_int', '>', 1)
            ->orderBy('f_int', 'desc')
            ->limit(10, 20)
            ->format('CSV')
            ->settings(['max_threads' => 2])
            ->exists();
    }

    public function test_plain_query_keeps_a_zero_limit(): void
    {
        $this->expectSelect('SELECT 1 FROM `examples` LIMIT 0', []);

        $this->assertFalse($this->builder()->from('examples')->limit(0)->exists());
    }

    public function test_plain_query_keeps_final_sample_array_join_joins_and_prewhere(): void
    {
        $query = fn (Builder $builder): Builder => $builder
            ->from('examples', null, true)
            ->sample(0.5)
            ->arrayJoin('arr')
            ->anyLeftJoin('example_groups', ['f_string'])
            ->preWhere('f_string', 'a')
            ->where('f_int', 1);

        $expected = $query($this->builder())->select(new Expression('1'))->limit(1)->toSql();
        $this->assertSame(
            1,
            preg_match('/^SELECT 1 FROM `examples` FINAL SAMPLE 0\.5 ARRAY JOIN .+ PREWHERE .+ WHERE `f_int` = 1 LIMIT 1$/', $expected)
        );
        $this->expectSelect($expected);

        $query($this->builder())->exists();
    }

    public function test_aggregate_columns_are_wrapped_in_a_subquery(): void
    {
        $this->expectSelect('SELECT 1 FROM (SELECT count() AS `c` FROM `examples` WHERE `f_int` = 1) LIMIT 1');

        $this->builder()->select(new RawColumn('count()', 'c'))->from('examples')->where('f_int', 1)->exists();
    }

    public function test_aliased_columns_are_wrapped_because_conditions_may_use_the_alias(): void
    {
        $this->expectSelect('SELECT 1 FROM (SELECT `f_int` AS `x` FROM `examples` WHERE `x` = 1) LIMIT 1');

        $this->builder()->select('f_int as x')->from('examples')->where('x', 1)->exists();
    }

    public function test_group_by_and_having_are_wrapped_without_their_order_by(): void
    {
        $this->expectSelect(
            'SELECT 1 FROM (SELECT `f_string`, count() AS `c` FROM `examples` GROUP BY `f_string` HAVING `c` > 1) LIMIT 1'
        );

        $this->builder()
            ->select('f_string', new RawColumn('count()', 'c'))
            ->from('examples')
            ->groupBy('f_string')
            ->having('c', '>', 1)
            ->orderBy('f_string')
            ->exists();
    }

    public function test_union_is_wrapped(): void
    {
        $query = fn (Builder $builder): Builder => $builder
            ->from('examples')
            ->where('f_int', 1)
            ->unionAll(fn (Builder $union) => $union->from('examples2')->where('f_int', 2));

        $this->expectSelect('SELECT 1 FROM (' . $query($this->builder())->toSql() . ') LIMIT 1');

        $query($this->builder())->exists();
    }

    /**
     * Ordered by id, the LIMIT of the first query keeps the highest id, and that
     * row decides what EXCEPT or INTERSECT returns, so the wrapper keeps the ORDER BY.
     *
     * Around INTERSECT and EXCEPT, WHERE NOT ignore(*) keeps ClickHouse from
     * removing the columns that the wrapper does not read from the compared queries.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function setOperationProvider(): array
    {
        return [
            'except' => ['except', 'EXCEPT', ' WHERE NOT ignore(*)'],
            'except distinct' => ['exceptDistinct', 'EXCEPT DISTINCT', ' WHERE NOT ignore(*)'],
            'intersect' => ['intersect', 'INTERSECT', ' WHERE NOT ignore(*)'],
            'intersect distinct' => ['intersectDistinct', 'INTERSECT DISTINCT', ' WHERE NOT ignore(*)'],
            'union all' => ['unionAll', 'UNION ALL', ''],
            'union distinct' => ['unionDistinct', 'UNION DISTINCT', ''],
        ];
    }

    #[DataProvider('setOperationProvider')]
    public function test_set_operation_keeps_the_order_by_that_decides_the_limited_rows(
        string $method,
        string $keyword,
        string $readEveryColumn
    ): void {
        $this->expectSelect(
            "SELECT 1 FROM (SELECT `id` FROM `t` ORDER BY `id` DESC LIMIT 1 {$keyword} SELECT `id` FROM `r`)"
            . "{$readEveryColumn} LIMIT 1"
        );

        $this->builder()
            ->select('id')
            ->from('t')
            ->orderBy('id', 'desc')
            ->limit(1)
            ->{$method}(fn (Builder $query) => $query->select('id')->from('r'))
            ->exists();
    }

    public function test_except_in_a_union_all_part_also_reads_every_column(): void
    {
        $query = fn (Builder $builder): Builder => $builder
            ->select('id', 'v')
            ->from('t')
            ->unionAll(fn (Builder $part) => $part
                ->select('id', 'v')
                ->from('r')
                ->except(fn (Builder $except) => $except->select('id', 'v')->from('s')));

        $this->expectSelect('SELECT 1 FROM (' . $query($this->builder())->toSql() . ') WHERE NOT ignore(*) LIMIT 1');

        $query($this->builder())->exists();
    }

    /**
     * Build SELECT `g`, `n` FROM `a` <keyword> SELECT `g`, `n` FROM `b` on the client of the given builder.
     *
     * @param Builder $builder
     * @param string $method unionAll, intersect, except or another set operation method
     * @return Builder
     */
    private static function setOperationOf(Builder $builder, string $method): Builder
    {
        return $builder->newQuery()
            ->select('g', 'n')
            ->from('a')
            ->{$method}(fn (Builder $other) => $other->select('g', 'n')->from('b'));
    }

    /**
     * A query that reads rows from a sub-query that uses INTERSECT or EXCEPT, in
     * from(), a join or a withExpression(), reads every column of it with WHERE NOT
     * ignore(*), as get() does with *: ClickHouse would compare only the columns
     * that exists() reads.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function subqueryWithIntersectOrExceptProvider(): array
    {
        return [
            'except, checked in place' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'except'))->orderBy('g'),
                'SELECT 1 FROM (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`) WHERE NOT ignore(*) LIMIT 1',
            ],
            'intersect with a where condition and an offset, checked in place' => [
                fn (Builder $builder): Builder => $builder
                    ->from(self::setOperationOf($builder, 'intersect'))
                    ->where('g', 'x')
                    ->limit(10, 5),
                "SELECT 1 FROM (SELECT `g`, `n` FROM `a` INTERSECT SELECT `g`, `n` FROM `b`) WHERE `g` = 'x' AND NOT ignore(*)"
                . ' LIMIT 5, 1',
            ],
            'except two sub-queries deep, checked in place' => [
                fn (Builder $builder): Builder => $builder->from($builder->newQuery()->from(self::setOperationOf($builder, 'except'))),
                'SELECT 1 FROM (SELECT * FROM (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`)) WHERE NOT ignore(*) LIMIT 1',
            ],
            'except, wrapped' => [
                fn (Builder $builder): Builder => $builder
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from(self::setOperationOf($builder, 'except')),
                'SELECT 1 FROM (SELECT n + 1 AS `m` FROM (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`))'
                . ' WHERE NOT ignore(*) LIMIT 1',
            ],
            'union all, checked in place as it is' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'unionAll')),
                'SELECT 1 FROM (SELECT `g`, `n` FROM `a` UNION ALL SELECT `g`, `n` FROM `b`) LIMIT 1',
            ],
            'except in a with expression, checked in place' => [
                fn (Builder $builder): Builder => $builder->withExpression('w', self::setOperationOf($builder, 'except'))->from('w')->orderBy('g'),
                'WITH `w` AS (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`) SELECT 1 FROM `w` WHERE NOT ignore(*) LIMIT 1',
            ],
            'intersect in a recursive with expression, checked in place' => [
                fn (Builder $builder): Builder => $builder
                    ->withRecursiveExpression('w', self::setOperationOf($builder, 'intersect'))
                    ->from('w')
                    ->where('g', 'x'),
                "WITH RECURSIVE `w` AS (SELECT `g`, `n` FROM `a` INTERSECT SELECT `g`, `n` FROM `b`) SELECT 1 FROM `w`"
                . " WHERE `g` = 'x' AND NOT ignore(*) LIMIT 1",
            ],
            'except in a with expression, wrapped' => [
                fn (Builder $builder): Builder => $builder
                    ->withExpression('w', self::setOperationOf($builder, 'except'))
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from('w'),
                'SELECT 1 FROM (WITH `w` AS (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`) SELECT n + 1 AS `m` FROM `w`)'
                . ' WHERE NOT ignore(*) LIMIT 1',
            ],
            'except in a join, checked in place' => [
                fn (Builder $builder): Builder => $builder->from('t')->allInnerJoin(self::setOperationOf($builder, 'except'), ['g'], false, 's'),
                'SELECT 1 FROM `t` ALL INNER JOIN (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`) AS `s` USING `g`'
                . ' WHERE NOT ignore(*) LIMIT 1',
            ],
            'intersect in a join, wrapped' => [
                fn (Builder $builder): Builder => $builder
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from('t')
                    ->allInnerJoin(self::setOperationOf($builder, 'intersect'), ['g'], false, 's'),
                'SELECT 1 FROM (SELECT n + 1 AS `m` FROM `t` ALL INNER JOIN (SELECT `g`, `n` FROM `a` INTERSECT SELECT `g`, `n` FROM `b`)'
                . ' AS `s` USING `g`) WHERE NOT ignore(*) LIMIT 1',
            ],
            'union all in a join, checked in place as it is' => [
                fn (Builder $builder): Builder => $builder->from('t')->allInnerJoin(self::setOperationOf($builder, 'unionAll'), ['g'], false, 's'),
                'SELECT 1 FROM `t` ALL INNER JOIN (SELECT `g`, `n` FROM `a` UNION ALL SELECT `g`, `n` FROM `b`) AS `s` USING `g` LIMIT 1',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('subqueryWithIntersectOrExceptProvider')]
    public function test_a_sub_query_with_intersect_or_except_is_read_with_every_column(Closure $query, string $expectedSql): void
    {
        $this->expectSelect($expectedSql);

        $this->assertTrue($query($this->builder())->exists());
    }

    public function test_selected_with_alias_is_wrapped_because_it_may_be_an_aggregate(): void
    {
        $this->expectSelect('SELECT 1 FROM (WITH count() AS `c` SELECT `c` FROM `examples` WHERE `id` = 999) LIMIT 1');

        $this->builder()->withAlias('c', new Expression('count()'))->select('c')->from('examples')->where('id', 999)->exists();
    }

    public function test_order_by_a_with_alias_is_kept_because_it_may_be_an_array_join(): void
    {
        $this->expectSelect(
            'SELECT 1 FROM (WITH arrayJoin(tags) AS `tag` SELECT * FROM `examples` ORDER BY `tag` ASC) LIMIT 1'
        );

        $this->builder()->withAlias('tag', new Expression('arrayJoin(tags)'))->from('examples')->orderBy('tag')->exists();
    }

    public function test_with_alias_used_only_in_where_is_still_checked_in_place(): void
    {
        $this->expectSelect("WITH arrayJoin(tags) AS `tag` SELECT 1 FROM `examples` WHERE `tag` = 'a' LIMIT 1");

        $this->builder()
            ->withAlias('tag', new Expression('arrayJoin(tags)'))
            ->select('f_int')
            ->from('examples')
            ->where('tag', 'a')
            ->orderBy('f_int')
            ->exists();
    }

    /**
     * inRandomOrder() only sorts the rows, so exists() drops it and checks in place.
     */
    public function test_random_order_is_dropped_and_the_query_checked_in_place(): void
    {
        $this->expectSelect('SELECT 1 FROM `t` WHERE `a` = 3 LIMIT 1');

        $this->assertTrue($this->builder()->from('t')->where('a', 3)->inRandomOrder()->exists());
    }

    public function test_random_order_next_to_plain_columns_is_dropped(): void
    {
        $this->expectSelect('SELECT 1 FROM `t` WHERE `a` = 3 LIMIT 1');

        $this->builder()->select('a')->from('t')->where('a', 3)->orderBy('a')->inRandomOrder()->exists();
    }

    public function test_other_raw_sql_with_rand_is_kept_in_a_subquery(): void
    {
        $this->expectSelect('SELECT 1 FROM (SELECT * FROM `t` WHERE `a` = 3 ORDER BY rand() DESC) LIMIT 1');

        $this->builder()->from('t')->where('a', 3)->orderByRaw('rand() DESC')->exists();
    }

    public function test_get_query_for_exists_is_the_query_that_exists_sends(): void
    {
        $query = fn (Builder $builder): Builder => $builder->from('t')->where('a', 3)->inRandomOrder()->settings(['max_threads' => 2]);
        $sql = $query($this->builder())->getQueryForExists()->getQueryToSend()->toSql();
        $this->assertSame('SELECT 1 FROM `t` WHERE `a` = 3 LIMIT 1 FORMAT JSON SETTINGS max_threads=2', $sql);
        $this->expectSelect($sql);

        $query($this->builder())->exists();
    }

    public function test_limit_by_is_wrapped(): void
    {
        $this->expectSelect('SELECT 1 FROM (SELECT * FROM `examples` LIMIT 1 BY `f_string`) LIMIT 1');

        $this->builder()->from('examples')->limitBy(1, 'f_string')->exists();
    }

    public function test_raw_order_by_is_kept_because_with_fill_adds_rows(): void
    {
        $this->expectSelect(
            'SELECT 1 FROM (SELECT * FROM `examples` WHERE `f_int` = 999 ORDER BY f_int WITH FILL FROM 0 TO 3) LIMIT 1'
        );

        $this->builder()
            ->from('examples')
            ->where('f_int', 999)
            ->orderByRaw('f_int WITH FILL FROM 0 TO 3')
            ->exists();
    }

    public function test_wrapped_query_moves_settings_outside_and_drops_format(): void
    {
        $this->expectSelect(
            'SELECT 1 FROM (SELECT count() FROM `examples` LIMIT 5, 10) LIMIT 1 FORMAT JSON SETTINGS max_threads=2'
        );

        $this->builder()
            ->select(new RawColumn('count()'))
            ->from('examples')
            ->limit(10, 5)
            ->format('JSON')
            ->settings(['max_threads' => 2])
            ->exists();
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function unchangedBuilderProvider(): array
    {
        return [
            'plain' => [fn (Builder $builder): Builder => $builder
                ->select(['f_int'])
                ->from('examples')
                ->where('f_int', 1)
                ->orderBy('f_int')
                ->limit(5, 2)
                ->format('JSON')
                ->settings(['max_threads' => 2])],
            'wrapped' => [fn (Builder $builder): Builder => $builder
                ->select(new RawColumn('count()', 'c'))
                ->from('examples')
                ->groupBy('f_string')
                ->orderBy('f_string')
                ->format('JSON')
                ->settings(['max_threads' => 2])],
            'set operation and with alias' => [fn (Builder $builder): Builder => $builder
                ->withAlias('tag', new Expression('arrayJoin(tags)'))
                ->select(['f_int'])
                ->from('examples')
                ->orderBy('tag')
                ->limit(1)
                ->except(fn (Builder $query) => $query->select(['f_int'])->from('examples2'))
                ->settings(['max_threads' => 2])],
            'sub-query with except' => [fn (Builder $builder): Builder => $builder
                ->from(self::setOperationOf($builder, 'except'))
                ->where('g', 'x')
                ->orderBy('g')
                ->limit(5, 2)],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('unchangedBuilderProvider')]
    public function test_exists_leaves_the_builder_unchanged(Closure $query): void
    {
        $builder = $query($this->builder());
        $sqlBefore = $builder->toSql();
        $this->client->method('select')->willReturn($this->createStub(Statement::class));

        $builder->exists();

        $this->assertSame($sqlBefore, $builder->toSql());
    }
}
