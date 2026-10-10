<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayIterator;
use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Closure;
use Generator;
use Illuminate\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use IteratorAggregate;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\UnitEnumFixture;

use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

class BuilderSqlTest extends TestCase
{
    private Client $mockClient;

    /**
     * The files that temporaryFile() made, which tearDown() deletes.
     *
     * @var array<int, string>
     */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockClient = $this->createMock(Client::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->temporaryFiles = [];

        parent::tearDown();
    }

    /**
     * Make an empty readable file in the system's temporary directory, which tearDown() deletes.
     *
     * @return string The path of the file
     */
    private function temporaryFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phpch-builder-sql-');
        $this->assertIsString($file);
        $this->temporaryFiles[] = $file;

        return $file;
    }

    public function test_where_in_generates_in_clause(): void
    {
        $sql = (new Builder($this->mockClient))
            ->from('examples')
            ->whereIn('f_string', ['zz'])
            ->toSql();

        $this->assertEquals(
            "SELECT * FROM `examples` WHERE `f_string` IN ('zz')",
            $sql
        );
    }

    public function test_where_between_generates_between_clause(): void
    {
        $sql = (new Builder($this->mockClient))
            ->from('examples')
            ->whereBetween('f_int', [1, 2])
            ->toSql();

        $this->assertEquals(
            'SELECT * FROM `examples` WHERE `f_int` BETWEEN 1 AND 2',
            $sql
        );
    }

    public function test_where_in_and_between_combine_with_and(): void
    {
        $sql = (new Builder($this->mockClient))
            ->from('examples')
            ->whereIn('f_string', ['zz'])
            ->whereBetween('f_int', [1, 2])
            ->toSql();

        $this->assertEquals(
            "SELECT * FROM `examples` WHERE `f_string` IN ('zz') AND `f_int` BETWEEN 1 AND 2",
            $sql
        );
    }

    public function test_or_where_groups_with_parentheses(): void
    {
        $sql = (new Builder($this->mockClient))
            ->from('examples')
            ->where(function (Builder $q) {
                $q->where('f_int', 1)->orWhere('f_int', 2);
            })
            ->toSql();

        $this->assertEquals(
            'SELECT * FROM `examples` WHERE (`f_int` = 1 OR `f_int` = 2)',
            $sql
        );
    }

    public function test_simple_where_equality(): void
    {
        $sql = (new Builder($this->mockClient))
            ->from('examples')
            ->where('f_int', 1)
            ->toSql();

        $this->assertEquals(
            'SELECT * FROM `examples` WHERE `f_int` = 1',
            $sql
        );
    }

    private function examples(): Builder
    {
        return (new Builder($this->mockClient))->from('examples');
    }

    public function test_settings_keep_the_existing_output_for_ints_and_plain_strings(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS max_threads=3, a='b'",
            $this->examples()->settings(['max_threads' => 3, 'a' => 'b'])->toSql()
        );
    }

    public function test_settings_render_booleans_as_one_and_zero(): void
    {
        $this->assertSame(
            'SELECT * FROM `examples` SETTINGS optimize_move_to_prewhere=1, use_uncompressed_cache=0',
            $this->examples()->settings(['optimize_move_to_prewhere' => true, 'use_uncompressed_cache' => false])->toSql()
        );
    }

    public function test_settings_render_floats_as_numbers(): void
    {
        $this->assertSame(
            'SELECT * FROM `examples` SETTINGS totals_auto_threshold=0.5, max_execution_time=1.0, x=1.0E-7, y=-INF',
            $this->examples()->settings([
                'totals_auto_threshold' => 0.5,
                'max_execution_time' => 1.0,
                'x' => 1.0E-7,
                'y' => -INF,
            ])->toSql()
        );
    }

    public function test_settings_escape_strings(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS log_comment='it\\'s a \\\\ test'",
            $this->examples()->settings(['log_comment' => "it's a \\ test"])->toSql()
        );
    }

    public function test_settings_render_expressions_as_written(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS additional_table_filters={'examples': 'f_int > 1'}",
            $this->examples()->settings(['additional_table_filters' => new RawColumn("{'examples': 'f_int > 1'}")])->toSql()
        );
    }

    public function test_settings_merge_and_later_values_win(): void
    {
        $builder = $this->examples()
            ->settings(['max_threads' => 3, 'a' => 'b'])
            ->settings(['max_threads' => 4, 'log_comment' => 'c']);

        $this->assertSame(['max_threads' => 4, 'a' => 'b', 'log_comment' => 'c'], $builder->getSettings());
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS max_threads=4, a='b', log_comment='c'",
            $builder->toSql()
        );
    }

    public function test_settings_accept_a_single_name_and_value(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS max_threads=4, log_comment='x'",
            $this->examples()->settings('max_threads', 4)->settings('log_comment', 'x')->toSql()
        );
    }

    public function test_settings_null_removes_a_setting(): void
    {
        $builder = $this->examples()
            ->settings(['max_threads' => 3, 'a' => 'b'])
            ->settings('max_threads', null);

        $this->assertSame(['a' => 'b'], $builder->getSettings());
        $this->assertSame("SELECT * FROM `examples` SETTINGS a='b'", $builder->toSql());

        $builder->settings(['a' => null]);

        $this->assertSame('SELECT * FROM `examples`', $builder->toSql());
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function invalidSettingNameProvider(): array
    {
        return [
            'space' => [['max threads' => 1]],
            'injection' => [['max_threads=1 FORMAT CSV --' => 1]],
            'trailing newline' => [["max_threads\n" => 1]],
            'leading digit' => [['1max_threads' => 1]],
            'list key' => [[3]],
            'empty name' => [['' => 1]],
        ];
    }

    /**
     * @param array<int|string, mixed> $settings
     */
    #[DataProvider('invalidSettingNameProvider')]
    public function test_settings_reject_names_that_are_not_plain_identifiers(array $settings): void
    {
        $builder = $this->examples()->settings($settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse setting name');

        $builder->toSql();
    }

    public function test_settings_reject_unsupported_values(): void
    {
        $builder = $this->examples()->settings(['max_threads' => [1, 2]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for ClickHouse setting [max_threads]');

        $builder->toSql();
    }

    public function test_settings_render_stringable_objects_as_strings(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS log_comment='it\\'s', a='2026-01-01 00:00:00'",
            $this->examples()->settings([
                'log_comment' => Str::of("it's"),
                'a' => Carbon::parse('2026-01-01 00:00:00'),
            ])->toSql()
        );
    }

    public function test_settings_render_backed_enums_as_their_values(): void
    {
        $this->assertSame(
            "SELECT * FROM `examples` SETTINGS max_threads=3, log_comment='active'",
            $this->examples()->settings([
                'max_threads' => IntBackedEnumFixture::High,
                'log_comment' => StringBackedEnumFixture::Active,
            ])->toSql()
        );
    }

    public function test_settings_reject_pure_enums(): void
    {
        $builder = $this->examples()->settings(['log_comment' => UnitEnumFixture::Hearts]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for ClickHouse setting [log_comment]');

        $builder->toSql();
    }

    public function test_settings_with_an_empty_array_keep_the_earlier_settings(): void
    {
        $this->assertSame(
            'SELECT * FROM `examples` SETTINGS max_threads=2',
            $this->examples()->settings(['max_threads' => 2])->settings([])->toSql()
        );
    }

    /**
     * Collections are Stringable, as their JSON, so they would otherwise pass as strings.
     *
     * @return array<string, array{object}>
     */
    public static function arrayableOrTraversableSettingValueProvider(): array
    {
        return [
            'collection' => [collect([1, 2])],
            'empty collection' => [collect()],
            'lazy collection' => [LazyCollection::make([1, 2])],
            'array iterator' => [new ArrayIterator([1, 2])],
            'generator' => [(fn (): Generator => yield 1)()],
            'stringable arrayable' => [new class implements Arrayable, Stringable {
                public function toArray(): array
                {
                    return [1, 2];
                }

                public function __toString(): string
                {
                    return '[1,2]';
                }
            }],
            'stringable iterator aggregate' => [new class implements IteratorAggregate, Stringable {
                public function getIterator(): ArrayIterator
                {
                    return new ArrayIterator([1, 2]);
                }

                public function __toString(): string
                {
                    return '[1,2]';
                }
            }],
        ];
    }

    #[DataProvider('arrayableOrTraversableSettingValueProvider')]
    public function test_settings_reject_arrayable_and_traversable_values(object $value): void
    {
        $builder = $this->examples()->settings(['log_comment' => $value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            '/^Invalid value for ClickHouse setting \[log_comment\]: expected .+, got ' . preg_quote(get_debug_type($value), '/') . '\. /'
        );

        $builder->toSql();
    }

    public function test_settings_explain_how_to_pass_a_list(): void
    {
        $builder = $this->examples()->settings(['log_comment' => collect([1, 2])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Invalid value for ClickHouse setting [log_comment]: expected bool, int, float, string, '
            . 'Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression, a backed enum or a Stringable object other'
            . ' than a collection or another Arrayable or Traversable value, got Illuminate\Support\Collection.'
            . ' For a map or an array, pass the SQL as an Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression.'
        );

        $builder->toSql();
    }

    /**
     * A builder on a mocked client, whose query log goes to a stub connection so
     * that get() runs without Laravel, expecting exactly one select() with the
     * given SQL that answers with the given rows.
     *
     * @param string $sql
     * @param array<int, array<string, mixed>> $rows
     * @return Builder
     */
    private function builderExpectingSelect(string $sql, array $rows): Builder
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn($rows);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('select')
            ->with($sql)
            ->willReturn($statement);

        return $this->builderOn($client);
    }

    /**
     * A builder on a stubbed client that adds the SQL of every select() to
     * $sentSql and answers the selects with the given rows, in call order
     * (no rows once they run out). Its query log goes to a stub connection.
     *
     * @param string[] $sentSql
     * @param array<int, array<int, array<string, mixed>>> $rowsPerSelect
     * @return Builder
     */
    private function builderRecordingSelects(array &$sentSql, array $rowsPerSelect = []): Builder
    {
        $client = $this->createStub(Client::class);
        $client->method('select')->willReturnCallback(
            function (string $sql) use (&$sentSql, &$rowsPerSelect): Statement {
                $sentSql[] = $sql;
                $statement = $this->createStub(Statement::class);
                $statement->method('rows')->willReturn(array_shift($rowsPerSelect) ?? []);

                return $statement;
            }
        );

        return $this->builderOn($client);
    }

    /**
     * A builder on the given client whose query log goes to a stub connection, so get() runs without Laravel.
     *
     * @param Client $client
     * @return Builder
     */
    private function builderOn(Client $client): Builder
    {
        $builder = new class($client) extends Builder {
            public static ?Connection $stubConnection = null;

            public function resolveConnection(): Connection
            {
                return static::$stubConnection;
            }
        };
        $builder::$stubConnection = $this->createStub(Connection::class);

        return $builder;
    }

    public function test_get_names_the_json_format_before_the_settings_of_a_query_without_a_format(): void
    {
        $builder = $this->builderExpectingSelect(
            'SELECT `f_int` FROM `examples` WHERE `f_int` = 1 FORMAT JSON SETTINGS max_threads=2',
            [['f_int' => 1]]
        );
        $builder->select('f_int')->from('examples')->where('f_int', 1)->settings(['max_threads' => 2]);

        $this->assertSame([['f_int' => 1]], $builder->getRows());
        $this->assertSame(
            'SELECT `f_int` FROM `examples` WHERE `f_int` = 1 SETTINGS max_threads=2',
            $builder->toSql(),
            'toSql() and the builder are unchanged'
        );
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function querySentAsCompiledProvider(): array
    {
        return [
            'no settings' => [
                fn (Builder $builder): Builder => $builder->from('examples')->where('f_int', 1),
                'SELECT * FROM `examples` WHERE `f_int` = 1',
            ],
            'settings removed again' => [
                fn (Builder $builder): Builder => $builder->from('examples')->settings(['max_threads' => 2])->settings('max_threads', null),
                'SELECT * FROM `examples`',
            ],
            'explicit format' => [
                fn (Builder $builder): Builder => $builder->from('examples')->format('CSV')->settings(['max_threads' => 2]),
                'SELECT * FROM `examples` FORMAT CSV SETTINGS max_threads=2',
            ],
            'explicit json format' => [
                fn (Builder $builder): Builder => $builder->from('examples')->format('JSON')->settings(['max_threads' => 2]),
                'SELECT * FROM `examples` FORMAT JSON SETTINGS max_threads=2',
            ],
            'explicit format without settings' => [
                fn (Builder $builder): Builder => $builder->from('examples')->format('TSV'),
                'SELECT * FROM `examples` FORMAT TSV',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('querySentAsCompiledProvider')]
    public function test_get_sends_a_query_with_a_format_or_without_settings_as_compiled(Closure $query, string $sql): void
    {
        $builder = $query($this->builderExpectingSelect($sql, []));

        $this->assertSame([], $builder->getRows());
        $this->assertSame($sql, $builder->toSql());
    }

    public function test_get_names_the_format_only_on_the_outer_query_of_a_nested_set_operation(): void
    {
        $part = fn (string $table): Builder => (new Builder($this->mockClient))->select('a')->from($table);
        $builder = $this->builderExpectingSelect(
            'SELECT `a` FROM `t` EXCEPT (SELECT `a` FROM `r` UNION ALL SELECT `a` FROM `s`)'
            . ' FORMAT JSON SETTINGS max_threads=1',
            []
        );

        $builder->select('a')->from('t')->except($part('r')->unionAll($part('s')))->settings(['max_threads' => 1])->getRows();
    }

    public function test_get_names_the_format_only_on_the_outer_query_of_sub_queries(): void
    {
        $subquery = fn (): Builder => (new Builder($this->mockClient))->select('id')->from('r');
        $builder = $this->builderExpectingSelect(
            'WITH `w` AS (SELECT `id` FROM `r`) SELECT * FROM (SELECT `id` FROM `r`)'
            . ' WHERE `id` IN (SELECT `id` FROM `r`) FORMAT JSON SETTINGS max_threads=1',
            []
        );

        $builder->withExpression('w', $subquery())
            ->from($subquery())
            ->whereIn('id', $subquery())
            ->settings(['max_threads' => 1])
            ->getRows();
    }

    public function test_the_query_log_records_the_sql_that_get_sends(): void
    {
        $sql = 'SELECT * FROM `examples` FORMAT JSON SETTINGS max_threads=2';
        $builder = $this->builderExpectingSelect($sql, []);
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('logQuery')->with($sql, [], $this->isFloat());
        $builder::$stubConnection = $connection;

        $builder->from('examples')->settings(['max_threads' => 2])->getRows();
    }

    /**
     * Every method that runs a SELECT goes through get().
     *
     * @return array<string, array{Closure(Builder): mixed, array<int, array<int, array<string, mixed>>>, string[]}>
     */
    public static function selectingMethodProvider(): array
    {
        return [
            'count' => [
                fn (Builder $builder) => $builder->count(),
                [[['count' => '3']]],
                ['SELECT count() as `count` FROM `examples` FORMAT JSON SETTINGS max_threads=2'],
            ],
            'paginate' => [
                fn (Builder $builder) => $builder->paginate(2),
                [[['count' => '3']], [['f_int' => 1], ['f_int' => 2]]],
                [
                    'SELECT count() as `count` FROM `examples` FORMAT JSON SETTINGS max_threads=2',
                    'SELECT * FROM `examples` LIMIT 0, 2 FORMAT JSON SETTINGS max_threads=2',
                ],
            ],
            'simple paginate' => [
                fn (Builder $builder) => $builder->simplePaginate(2),
                [],
                ['SELECT * FROM `examples` LIMIT 0, 3 FORMAT JSON SETTINGS max_threads=2'],
            ],
            'chunk' => [
                fn (Builder $builder) => $builder->chunk(2, fn (array $rows) => null),
                [[['f_int' => 1], ['f_int' => 2]]],
                [
                    'SELECT * FROM `examples` LIMIT 0, 2 FORMAT JSON SETTINGS max_threads=2',
                    'SELECT * FROM `examples` LIMIT 2, 2 FORMAT JSON SETTINGS max_threads=2',
                ],
            ],
            'exists' => [
                fn (Builder $builder) => $builder->exists(),
                [],
                ['SELECT 1 FROM `examples` LIMIT 1 FORMAT JSON SETTINGS max_threads=2'],
            ],
            'pluck' => [
                fn (Builder $builder) => $builder->pluck('f_int'),
                [[['f_int' => 1]]],
                ['SELECT `f_int` FROM `examples` FORMAT JSON SETTINGS max_threads=2'],
            ],
            'aggregate' => [
                fn (Builder $builder) => $builder->max('f_int'),
                [[['aggregate' => 1]]],
                ['SELECT max(`f_int`) AS `aggregate` FROM `examples` FORMAT JSON SETTINGS max_threads=2'],
            ],
        ];
    }

    /**
     * The paginators read the current page and path through static resolvers. A
     * Laravel application that an earlier test booted leaves them bound to its
     * flushed container, so this test sets resolvers that need no application.
     *
     * @param Closure(Builder): mixed $run
     * @param array<int, array<int, array<string, mixed>>> $rowsPerSelect
     * @param string[] $expectedSql
     */
    #[DataProvider('selectingMethodProvider')]
    public function test_every_select_names_the_json_format_before_the_settings(
        Closure $run,
        array $rowsPerSelect,
        array $expectedSql
    ): void {
        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
        $sentSql = [];
        $builder = $this->builderRecordingSelects($sentSql, $rowsPerSelect)->from('examples')->settings(['max_threads' => 2]);

        $run($builder);

        $this->assertSame($expectedSql, $sentSql);
        $this->assertStringNotContainsString('FORMAT', $builder->toSql(), 'the builder gets no format');
    }

    public function test_count_replaces_the_columns_of_a_plain_query(): void
    {
        $builder = $this->builderExpectingSelect(
            'SELECT count() as `count` FROM `examples` WHERE `f_int` = 1',
            [['count' => '3']]
        );

        $this->assertSame(3, $builder->select('f_int')->from('examples')->where('f_int', 1)->orderBy('f_int')->limit(2, 4)->count());
    }

    public function test_count_is_zero_when_no_row_comes_back(): void
    {
        $builder = $this->builderExpectingSelect(
            'SELECT count() AS `count` FROM (SELECT * FROM `a` UNION ALL SELECT * FROM `b`)',
            []
        );

        $this->assertSame(0, $builder->from('a')->unionAll(fn (Builder $query) => $query->from('b'))->count());
    }

    /**
     * Around INTERSECT and EXCEPT, WHERE NOT ignore(*) keeps ClickHouse from
     * removing the columns that count() does not read from the compared queries.
     * The LIMIT and ORDER BY of the first query stay in the subquery, so count()
     * counts the rows that get() returns.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function setOperationProvider(): array
    {
        return [
            'union all' => ['unionAll', 'UNION ALL', ''],
            'union distinct' => ['unionDistinct', 'UNION DISTINCT', ''],
            'intersect' => ['intersect', 'INTERSECT', ' WHERE NOT ignore(*)'],
            'intersect distinct' => ['intersectDistinct', 'INTERSECT DISTINCT', ' WHERE NOT ignore(*)'],
            'except' => ['except', 'EXCEPT', ' WHERE NOT ignore(*)'],
            'except distinct' => ['exceptDistinct', 'EXCEPT DISTINCT', ' WHERE NOT ignore(*)'],
        ];
    }

    #[DataProvider('setOperationProvider')]
    public function test_count_of_a_set_operation_counts_in_a_subquery(string $method, string $keyword, string $readEveryColumn): void
    {
        $builder = $this->builderExpectingSelect(
            "SELECT count() AS `count` FROM (SELECT `a`, `b` FROM `t` ORDER BY `a` ASC LIMIT 5, 10 {$keyword} SELECT `a`, `b` FROM `r`)"
            . "{$readEveryColumn} FORMAT JSON SETTINGS max_threads=2",
            [['count' => '1']]
        );

        $this->assertSame(1, $builder
            ->select('a', 'b')
            ->from('t')
            ->orderBy('a')
            ->limit(10, 5)
            ->{$method}(fn (Builder $query) => $query->select('a', 'b')->from('r'))
            ->format('JSON')
            ->settings(['max_threads' => 2])
            ->count());
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function subqueryCountProvider(): array
    {
        return [
            'group by' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_string', new RawColumn('count()', 'c'))
                    ->from('examples')
                    ->groupBy('f_string')
                    ->orderBy('f_string')
                    ->limit(10, 20),
                'SELECT count() AS `count` FROM (SELECT `f_string`, count() AS `c` FROM `examples` GROUP BY `f_string`)',
            ],
            'having' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_string', new RawColumn('count()', 'c'))
                    ->from('examples')
                    ->groupBy('f_string')
                    ->having('c', '>', 1),
                'SELECT count() AS `count` FROM'
                . ' (SELECT `f_string`, count() AS `c` FROM `examples` GROUP BY `f_string` HAVING `c` > 1)',
            ],
            'limit by' => [
                fn (Builder $builder): Builder => $builder->from('examples')->orderBy('f_int')->limitBy(1, 'f_string'),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` LIMIT 1 BY `f_string`)',
            ],
            'distinct column' => [
                fn (Builder $builder): Builder => $builder
                    ->select(fn (Column $column) => $column->name('f_string')->distinct())
                    ->from('examples'),
                'SELECT count() AS `count` FROM (SELECT DISTINCT `f_string` FROM `examples`)',
            ],
            'raw distinct column' => [
                fn (Builder $builder): Builder => $builder->select(new RawColumn('DISTINCT f_string, f_int'))->from('examples'),
                'SELECT count() AS `count` FROM (SELECT DISTINCT f_string, f_int FROM `examples`)',
            ],
            'the whole order by is kept because with fill adds rows in the groups of the entries before it' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_string', 'f_int')
                    ->from('examples')
                    ->groupBy('f_string', 'f_int')
                    ->orderBy('f_string', 'desc')
                    ->orderByRaw('f_int WITH FILL FROM 0 TO 10'),
                'SELECT count() AS `count` FROM (SELECT `f_string`, `f_int` FROM `examples` GROUP BY `f_string`, `f_int`'
                . ' ORDER BY `f_string` DESC, f_int WITH FILL FROM 0 TO 10)',
            ],
            'set operation with limit by keeps the order by that decides the kept rows' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_int', 'f_string')
                    ->from('a')
                    ->orderBy('f_int', 'desc')
                    ->limitBy(1, 'f_string')
                    ->except(fn (Builder $query) => $query->select('f_int', 'f_string')->from('b')),
                'SELECT count() AS `count` FROM (SELECT `f_int`, `f_string` FROM `a` ORDER BY `f_int` DESC'
                . ' LIMIT 1 BY `f_string` EXCEPT SELECT `f_int`, `f_string` FROM `b`) WHERE NOT ignore(*)',
            ],
            'with alias array join' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('tag', new RawColumn('arrayJoin(tags)'))
                    ->select('tag')
                    ->from('examples')
                    ->orderBy('f_int')
                    ->limit(2, 4),
                'SELECT count() AS `count` FROM (WITH arrayJoin(tags) AS `tag` SELECT `tag` FROM `examples`)',
            ],
            'with alias aggregate' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('c', new RawColumn('count()'))
                    ->select('c')
                    ->from('examples')
                    ->where('f_int', 999),
                'SELECT count() AS `count` FROM (WITH count() AS `c` SELECT `c` FROM `examples` WHERE `f_int` = 999)',
            ],
            'raw array join column' => [
                fn (Builder $builder): Builder => $builder->select(new RawColumn('arrayJoin(tags) AS tag'))->from('examples'),
                'SELECT count() AS `count` FROM (SELECT arrayJoin(tags) AS tag FROM `examples`)',
            ],
            'raw aggregate column' => [
                fn (Builder $builder): Builder => $builder
                    ->select(new RawColumn('count() AS c'))
                    ->from('examples')
                    ->where('f_int', 999),
                'SELECT count() AS `count` FROM (SELECT count() AS c FROM `examples` WHERE `f_int` = 999)',
            ],
            'aggregate function of a column' => [
                fn (Builder $builder): Builder => $builder->select(fn (Column $column) => $column->name('f_int')->sum())->from('examples'),
                'SELECT count() AS `count` FROM (SELECT sum(`f_int`) FROM `examples`)',
            ],
            'alias used in where' => [
                fn (Builder $builder): Builder => $builder->select('f_int as x')->from('examples')->where('x', 1),
                'SELECT count() AS `count` FROM (SELECT `f_int` AS `x` FROM `examples` WHERE `x` = 1)',
            ],
            'one plain column next to an expression' => [
                fn (Builder $builder): Builder => $builder->select('f_int', new RawColumn('arrayJoin(tags)'))->from('examples'),
                'SELECT count() AS `count` FROM (SELECT `f_int`, arrayJoin(tags) FROM `examples`)',
            ],
            'raw order by with fill adds rows' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_int')
                    ->from('examples')
                    ->where('f_int', 999)
                    ->orderByRaw('f_int WITH FILL FROM 0 TO 3')
                    ->limit(2),
                'SELECT count() AS `count` FROM'
                . ' (SELECT `f_int` FROM `examples` WHERE `f_int` = 999 ORDER BY f_int WITH FILL FROM 0 TO 3)',
            ],
            'with fill in lowercase, after a plain order by that is kept' => [
                fn (Builder $builder): Builder => $builder->from('examples')->orderBy('f_string')->orderByRaw('f_int with  fill STEP 2'),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` ORDER BY `f_string` ASC, f_int with  fill STEP 2)',
            ],
            'an array join order next to a plain order by, which is left out' => [
                fn (Builder $builder): Builder => $builder->select('f_int')->from('examples')->orderBy('f_int')->orderByRaw('arrayJoin(tags)'),
                'SELECT count() AS `count` FROM (SELECT `f_int` FROM `examples` ORDER BY arrayJoin(tags))',
            ],
            'raw order by an array join multiplies rows' => [
                fn (Builder $builder): Builder => $builder->select('f_int')->from('examples')->orderByRaw('arrayJoin(tags)'),
                'SELECT count() AS `count` FROM (SELECT `f_int` FROM `examples` ORDER BY arrayJoin(tags))',
            ],
            'order by a with alias array join multiplies rows' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('tag', new RawColumn('arrayJoin(tags)'))
                    ->select('f_int')
                    ->from('examples')
                    ->orderBy('tag'),
                'SELECT count() AS `count` FROM (WITH arrayJoin(tags) AS `tag` SELECT `f_int` FROM `examples` ORDER BY `tag` ASC)',
            ],
            'raw order by that mentions a with alias' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('tag', new RawColumn('arrayJoin(tags)'))
                    ->from('examples')
                    ->orderByRaw('length(tag) DESC'),
                'SELECT count() AS `count` FROM (WITH arrayJoin(tags) AS `tag` SELECT * FROM `examples` ORDER BY length(tag) DESC)',
            ],
            'order by a nested column, which is neither a name nor raw sql' => [
                fn (Builder $builder): Builder => $builder
                    ->from('examples')
                    ->where('f_int', 999)
                    ->orderBy(fn (Column $column) => $column->name(fn (Column $inner) => $inner->name('f_int'))),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` WHERE `f_int` = 999 ORDER BY (`f_int`) ASC)',
            ],
            'order by a name with as, whose alias the where condition reads' => [
                fn (Builder $builder): Builder => $builder->select('a')->from('examples')->where('b', '<', 5)->orderBy('a as b')->limit(10),
                'SELECT count() AS `count` FROM (SELECT `a` FROM `examples` WHERE `b` < 5 ORDER BY `a` AS `b` ASC)',
            ],
            'raw order by with AS, whose alias the where condition reads' => [
                fn (Builder $builder): Builder => $builder->select('a')->from('examples')->where('b', '<', 5)->orderByRaw('a AS b'),
                'SELECT count() AS `count` FROM (SELECT `a` FROM `examples` WHERE `b` < 5 ORDER BY a AS b)',
            ],
            'order by a column with an alias, after a plain order by that is left out' => [
                fn (Builder $builder): Builder => $builder
                    ->select('a')
                    ->from('examples')
                    ->where('b', '<', 5)
                    ->orderBy('a')
                    ->orderBy(fn (Column $column) => $column->name('a')->as('b')),
                'SELECT count() AS `count` FROM (SELECT `a` FROM `examples` WHERE `b` < 5 ORDER BY `a` AS `b` ASC)',
            ],
            'raw order by with limit by, after a plain order by that is kept with the limit of the builder left out' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_int', 'f_string')
                    ->from('examples')
                    ->orderBy('f_string')
                    ->orderByRaw('f_int DESC LIMIT 1 BY f_string')
                    ->limit(2, 4),
                'SELECT count() AS `count` FROM (SELECT `f_int`, `f_string` FROM `examples`'
                . ' ORDER BY `f_string` ASC, f_int DESC LIMIT 1 BY f_string)',
            ],
            'raw order by with limit, in lowercase' => [
                fn (Builder $builder): Builder => $builder->from('examples')->where('f_int', '>', 1)->orderByRaw('f_int desc limit 2'),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` WHERE `f_int` > 1 ORDER BY f_int desc limit 2)',
            ],
            'raw order by with offset' => [
                fn (Builder $builder): Builder => $builder->from('examples')->orderByRaw('f_int OFFSET 3 ROWS'),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` ORDER BY f_int OFFSET 3 ROWS)',
            ],
            'raw order by with fetch' => [
                fn (Builder $builder): Builder => $builder->from('examples')->orderByRaw('f_int OFFSET 1 ROW FETCH FIRST 2 ROWS ONLY'),
                'SELECT count() AS `count` FROM (SELECT * FROM `examples` ORDER BY f_int OFFSET 1 ROW FETCH FIRST 2 ROWS ONLY)',
            ],
            'with ties after a plain order by, which decides how many rows are kept' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_int')
                    ->from('examples')
                    ->orderBy('f_int', 'desc')
                    ->orderByRaw('f_string LIMIT 1 WITH TIES'),
                'SELECT count() AS `count` FROM (SELECT `f_int` FROM `examples` ORDER BY `f_int` DESC, f_string LIMIT 1 WITH TIES)',
            ],
        ];
    }

    /**
     * Queries that select only plain columns: no columns, *, column names, or
     * a withAlias() name that only a condition uses. An ORDER BY entry that only
     * sorts the rows is dropped, raw or not, such as a name with "as" only inside
     * a word, which defines no alias.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function inPlaceCountProvider(): array
    {
        return [
            'raw order by that only sorts' => [
                fn (Builder $builder): Builder => $builder->from('examples')->where('f_int', 1)->orderByRaw('created_at DESC')->limit(10),
                'SELECT count() as `count` FROM `examples` WHERE `f_int` = 1',
            ],
            'order by names and raw sql with as only inside a word' => [
                fn (Builder $builder): Builder => $builder
                    ->from('examples')
                    ->where('f_int', 1)
                    ->orderBy('alias')
                    ->orderBy('gas')
                    ->orderBy('a_as_b')
                    ->orderByRaw('has_items DESC')
                    ->orderByRaw('a ASC'),
                'SELECT count() as `count` FROM `examples` WHERE `f_int` = 1',
            ],
            'order by a function of a column' => [
                fn (Builder $builder): Builder => $builder->from('examples')->orderBy(fn (Column $column) => $column->name('f_int')->plus(1)),
                'SELECT count() as `count` FROM `examples`',
            ],
            'raw sql with limit, offset or fetch only in a word, a string, a quoted name or a sub-query' => [
                fn (Builder $builder): Builder => $builder
                    ->from('examples')
                    ->orderByRaw('limits, fetched_at DESC')
                    ->orderByRaw("f_string = 'limit 1' DESC")
                    ->orderByRaw('`offset`, "fetch" ASC')
                    ->orderByRaw('f_int IN (SELECT f_int FROM examples ORDER BY f_int LIMIT 1 BY f_string LIMIT 2)'),
                'SELECT count() as `count` FROM `examples`',
            ],
            'raw order by that does not mention the with alias' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('tag', new RawColumn('arrayJoin(tags)'))
                    ->from('examples')
                    ->where('tag', 'a')
                    ->orderByRaw('f_int DESC'),
                "WITH arrayJoin(tags) AS `tag` SELECT count() as `count` FROM `examples` WHERE `tag` = 'a'",
            ],
            'no columns' => [
                fn (Builder $builder): Builder => $builder->from('examples')->where('f_int', 1)->orderBy('f_int')->limit(2),
                'SELECT count() as `count` FROM `examples` WHERE `f_int` = 1',
            ],
            'star' => [
                fn (Builder $builder): Builder => $builder->select('*')->from('examples'),
                'SELECT count() as `count` FROM `examples`',
            ],
            'column names' => [
                fn (Builder $builder): Builder => $builder->select('f_int', 'examples.f_string')->from('examples'),
                'SELECT count() as `count` FROM `examples`',
            ],
            'with alias used only in where' => [
                fn (Builder $builder): Builder => $builder
                    ->withAlias('tag', new RawColumn('arrayJoin(tags)'))
                    ->select('f_int')
                    ->from('examples')
                    ->where('tag', 'a'),
                "WITH arrayJoin(tags) AS `tag` SELECT count() as `count` FROM `examples` WHERE `tag` = 'a'",
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('inPlaceCountProvider')]
    public function test_count_of_a_query_that_selects_only_plain_columns_replaces_the_columns(Closure $query, string $expectedSql): void
    {
        $builder = $query($this->builderExpectingSelect($expectedSql, [['count' => '2']]));
        $sqlBefore = $builder->toSql();

        $this->assertSame(2, $builder->count());
        $this->assertSame($sqlBefore, $builder->toSql(), 'count() leaves the builder unchanged');
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('subqueryCountProvider')]
    public function test_count_of_a_query_whose_columns_decide_the_rows_counts_in_a_subquery(Closure $query, string $expectedSql): void
    {
        $builder = $query($this->builderExpectingSelect($expectedSql, [['count' => '2']]));
        $sqlBefore = $builder->toSql();

        $this->assertSame(2, $builder->count());
        $this->assertSame($sqlBefore, $builder->toSql(), 'count() leaves the builder unchanged');
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
     * that the count reads. Raw SQL is not looked into.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function countOfASubqueryWithIntersectOrExceptProvider(): array
    {
        $except = '(SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`)';

        return [
            'except' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'except')),
                "SELECT count() as `count` FROM {$except} WHERE NOT ignore(*)",
            ],
            'except distinct' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'exceptDistinct')),
                'SELECT count() as `count` FROM (SELECT `g`, `n` FROM `a` EXCEPT DISTINCT SELECT `g`, `n` FROM `b`) WHERE NOT ignore(*)',
            ],
            'intersect, ordered and limited' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'intersect'))->orderBy('g')->limit(10, 20),
                'SELECT count() as `count` FROM (SELECT `g`, `n` FROM `a` INTERSECT SELECT `g`, `n` FROM `b`) WHERE NOT ignore(*)',
            ],
            'intersect distinct' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'intersectDistinct')),
                'SELECT count() as `count` FROM (SELECT `g`, `n` FROM `a` INTERSECT DISTINCT SELECT `g`, `n` FROM `b`)'
                . ' WHERE NOT ignore(*)',
            ],
            'except with a where condition' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'except'))->where('g', 'x'),
                "SELECT count() as `count` FROM {$except} WHERE `g` = 'x' AND NOT ignore(*)",
            ],
            'except given to from() in a closure' => [
                fn (Builder $builder): Builder => $builder->from(fn (From $from) => $from->query()
                    ->select('g', 'n')
                    ->from('a')
                    ->except(fn (Builder $other) => $other->select('g', 'n')->from('b'))),
                "SELECT count() as `count` FROM {$except} WHERE NOT ignore(*)",
            ],
            'except two sub-queries deep' => [
                fn (Builder $builder): Builder => $builder->from($builder->newQuery()->from(self::setOperationOf($builder, 'except'))),
                "SELECT count() as `count` FROM (SELECT * FROM {$except}) WHERE NOT ignore(*)",
            ],
            'except counted in a subquery' => [
                fn (Builder $builder): Builder => $builder
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from(self::setOperationOf($builder, 'except')),
                "SELECT count() AS `count` FROM (SELECT n + 1 AS `m` FROM {$except}) WHERE NOT ignore(*)",
            ],
            'except in the from() of a union all operand' => [
                fn (Builder $builder): Builder => $builder
                    ->select('g', 'n')
                    ->from('t')
                    ->unionAll(fn (Builder $other) => $other->select('g', 'n')->from(self::setOperationOf($builder, 'except'))),
                "SELECT count() AS `count` FROM (SELECT `g`, `n` FROM `t` UNION ALL SELECT `g`, `n` FROM {$except})"
                . ' WHERE NOT ignore(*)',
            ],
            'union all is counted as it is' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'unionAll')),
                'SELECT count() as `count` FROM (SELECT `g`, `n` FROM `a` UNION ALL SELECT `g`, `n` FROM `b`)',
            ],
            'except in a with expression' => [
                fn (Builder $builder): Builder => $builder->withExpression('w', self::setOperationOf($builder, 'except'))->from('w'),
                "WITH `w` AS {$except} SELECT count() as `count` FROM `w` WHERE NOT ignore(*)",
            ],
            'intersect in a with expression given as a closure' => [
                fn (Builder $builder): Builder => $builder
                    ->withExpression('w', fn (Builder $w) => $w
                        ->select('g', 'n')
                        ->from('a')
                        ->intersect(fn (Builder $other) => $other->select('g', 'n')->from('b')))
                    ->from('w'),
                'WITH `w` AS (SELECT `g`, `n` FROM `a` INTERSECT SELECT `g`, `n` FROM `b`) SELECT count() as `count` FROM `w`'
                . ' WHERE NOT ignore(*)',
            ],
            'except in a recursive with expression' => [
                fn (Builder $builder): Builder => $builder->withRecursiveExpression('w', self::setOperationOf($builder, 'except'))->from('w'),
                "WITH RECURSIVE `w` AS {$except} SELECT count() as `count` FROM `w` WHERE NOT ignore(*)",
            ],
            'except in a with expression, counted in a subquery' => [
                fn (Builder $builder): Builder => $builder
                    ->withExpression('w', self::setOperationOf($builder, 'except'))
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from('w'),
                "SELECT count() AS `count` FROM (WITH `w` AS {$except} SELECT n + 1 AS `m` FROM `w`) WHERE NOT ignore(*)",
            ],
            'except in a join' => [
                fn (Builder $builder): Builder => $builder->from('t')->allInnerJoin(self::setOperationOf($builder, 'except'), ['g'], false, 's'),
                "SELECT count() as `count` FROM `t` ALL INNER JOIN {$except} AS `s` USING `g` WHERE NOT ignore(*)",
            ],
            'except in a join given as a closure' => [
                fn (Builder $builder): Builder => $builder
                    ->from('t')
                    ->join(fn (JoinClause $join) => $join->query(self::setOperationOf($builder, 'except'))->as('s')->all()->inner()->using('g')),
                "SELECT count() as `count` FROM `t` ALL INNER JOIN {$except} AS `s` USING `g` WHERE NOT ignore(*)",
            ],
            'except in a join, counted in a subquery' => [
                fn (Builder $builder): Builder => $builder
                    ->select(new RawColumn('n + 1', 'm'))
                    ->from('t')
                    ->allInnerJoin(self::setOperationOf($builder, 'except'), ['g'], false, 's'),
                "SELECT count() AS `count` FROM (SELECT n + 1 AS `m` FROM `t` ALL INNER JOIN {$except} AS `s` USING `g`)"
                . ' WHERE NOT ignore(*)',
            ],
            'union all in a join is counted as it is' => [
                fn (Builder $builder): Builder => $builder->from('t')->allInnerJoin(self::setOperationOf($builder, 'unionAll'), ['g'], false, 's'),
                'SELECT count() as `count` FROM `t` ALL INNER JOIN (SELECT `g`, `n` FROM `a` UNION ALL SELECT `g`, `n` FROM `b`)'
                . ' AS `s` USING `g`',
            ],
            'with expression of raw sql is counted as it is' => [
                fn (Builder $builder): Builder => $builder->withExpression('w', 'SELECT 1 AS x EXCEPT SELECT 2')->from('w'),
                'WITH `w` AS (SELECT 1 AS x EXCEPT SELECT 2) SELECT count() as `count` FROM `w`',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('countOfASubqueryWithIntersectOrExceptProvider')]
    public function test_count_of_a_query_on_a_sub_query_with_intersect_or_except_reads_every_column(
        Closure $query,
        string $expectedSql
    ): void {
        $builder = $query($this->builderExpectingSelect($expectedSql, [['count' => '2']]));
        $sqlBefore = $builder->toSql();

        $this->assertSame(2, $builder->count());
        $this->assertSame($sqlBefore, $builder->toSql(), 'count() leaves the builder unchanged');
    }

    /**
     * An ORDER BY entry that is neither a name nor raw SQL, such as a nested Column,
     * counts as one that can add or multiply rows, so the total is counted in a subquery.
     */
    public function test_paginate_of_a_query_ordered_by_a_nested_column_counts_its_total_in_a_subquery(): void
    {
        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
        $sentSql = [];
        $builder = $this->builderRecordingSelects($sentSql, [[['count' => '2']], [['f_int' => 1], ['f_int' => 2]]]);

        $page = $builder
            ->from('examples')
            ->orderBy(fn (Column $column) => $column->name(fn (Column $inner) => $inner->name('f_int')))
            ->paginate(10);

        $this->assertSame(2, $page->total());
        $this->assertSame([
            'SELECT count() AS `count` FROM (SELECT * FROM `examples` ORDER BY (`f_int`) ASC)',
            'SELECT * FROM `examples` ORDER BY (`f_int`) ASC LIMIT 0, 10',
        ], $sentSql);
    }

    /**
     * The paginate() recipe for a set operation: its total comes from count(), for
     * an EXCEPT in from(), in a withExpression() or in a join.
     *
     * @return array<string, array{Closure(Builder): Builder, string[]}>
     */
    public static function paginatedSubQueryWithExceptProvider(): array
    {
        $except = '(SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`)';

        return [
            'from()' => [
                fn (Builder $builder): Builder => $builder->from(self::setOperationOf($builder, 'except'))->orderBy('g'),
                [
                    "SELECT count() as `count` FROM {$except} WHERE NOT ignore(*)",
                    "SELECT * FROM {$except} ORDER BY `g` ASC LIMIT 0, 10",
                ],
            ],
            'withExpression()' => [
                fn (Builder $builder): Builder => $builder->withExpression('w', self::setOperationOf($builder, 'except'))->from('w')->orderBy('g'),
                [
                    "WITH `w` AS {$except} SELECT count() as `count` FROM `w` WHERE NOT ignore(*)",
                    "WITH `w` AS {$except} SELECT * FROM `w` ORDER BY `g` ASC LIMIT 0, 10",
                ],
            ],
            'join' => [
                fn (Builder $builder): Builder => $builder
                    ->from('t')
                    ->allInnerJoin(self::setOperationOf($builder, 'except'), ['g'], false, 's')
                    ->orderBy('g'),
                [
                    "SELECT count() as `count` FROM `t` ALL INNER JOIN {$except} AS `s` USING `g` WHERE NOT ignore(*)",
                    "SELECT * FROM `t` ALL INNER JOIN {$except} AS `s` USING `g` ORDER BY `g` ASC LIMIT 0, 10",
                ],
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param string[] $expectedSql
     */
    #[DataProvider('paginatedSubQueryWithExceptProvider')]
    public function test_paginate_of_a_sub_query_with_except_reads_every_column_for_its_total(Closure $query, array $expectedSql): void
    {
        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
        $sentSql = [];
        $builder = $this->builderRecordingSelects($sentSql, [[['count' => '2']], [['g' => 'a', 'n' => 1], ['g' => 'b', 'n' => 2]]]);

        $page = $query($builder)->paginate(10);

        $this->assertSame(2, $page->total());
        $this->assertSame($expectedSql, $sentSql);
    }

    /**
     * A ClickHouse connection that needs no server, with the given options.
     *
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    /**
     * A builder on the given client that follows the options of the given connection, as Connection::query() makes
     * it, and logs its queries to that connection, so get() runs without Laravel.
     *
     * @param Client $client
     * @param Connection $connection
     * @return Builder
     */
    private function builderFollowing(Client $client, Connection $connection): Builder
    {
        $builder = new class($client) extends Builder {
            public static ?Connection $followedConnection = null;

            public function resolveConnection(): Connection
            {
                return static::$followedConnection;
            }
        };
        $builder::$followedConnection = $connection;

        return $builder->followConnectionOptions($connection);
    }

    /**
     * A smi2 client on port 1 whose curler records each request and answers it with the given response, so that a
     * query that goes through the transport runs without a server.
     *
     * @param CurlerResponse $response
     * @return array{0: Client, 1: CurlerRolling} The curler lists the requests in its $requests property
     */
    private function cannedClient(CurlerResponse $response): array
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $curler = new class($response) extends CurlerRolling {
            /** @var array<int, CurlerRequest> */
            public array $requests = [];

            public function __construct(private CurlerResponse $response)
            {
            }

            public function execOne(CurlerRequest $request, bool $auto_close = false): int
            {
                $this->requests[] = $request;
                $request->setResponse($this->response);

                return $request->response()->http_code();
            }
        };
        $client->transport()->setDirtyCurler($curler);

        return [$client, $curler];
    }

    /**
     * @param string $body
     * @param string $format The format that ClickHouse names in its X-ClickHouse-Format header
     * @return CurlerResponse
     */
    private function cannedResponse(string $body, string $format): CurlerResponse
    {
        $response = new CurlerResponse();
        $response->_info = ['http_code' => 200, 'content_type' => 'text/plain; charset=UTF-8'];
        $response->_headers = ['X-ClickHouse-Format' => $format];
        $response->_body = $body;

        return $response;
    }

    /**
     * Run a method of a builder that records its selects, with the paginators' resolvers set, and return the SQL
     * it sent and what it returned.
     *
     * @param Closure(Builder): Builder $query
     * @param Closure(Builder): mixed $run
     * @param array<int, array<int, array<string, mixed>>> $rowsPerSelect
     * @return array{0: string[], 1: mixed}
     */
    private function runRecorded(Closure $query, Closure $run, array $rowsPerSelect = []): array
    {
        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
        $sentSql = [];
        $result = $run($query($this->builderRecordingSelects($sentSql, $rowsPerSelect)));

        return [$sentSql, $result];
    }

    public function test_get_sends_a_query_whose_value_names_a_format_through_the_transport_and_reads_json(): void
    {
        [$client, $curler] = $this->cannedClient($this->cannedResponse(
            '{"meta":[{"name":"id","type":"UInt32"}],"data":[{"id":1}],"rows":1}',
            'JSON'
        ));
        $builder = $this->builderOn($client)->select('id')->from('t')->where('note', 'export as format csv please');

        $this->assertSame([['id' => 1]], $builder->getRows());
        $this->assertCount(1, $curler->requests);
        $this->assertSame(
            "SELECT `id` FROM `t` WHERE `note` = 'export as format csv please'",
            $curler->requests[0]->getDetails()['parameters']
        );
        parse_str((string) parse_url($curler->requests[0]->getUrl(), PHP_URL_QUERY), $urlQuery);
        $this->assertSame('JSON', $urlQuery['default_format']);
    }

    public function test_get_reads_a_format_that_the_client_does_not_know_in_that_format(): void
    {
        [$client, $curler] = $this->cannedClient($this->cannedResponse("<?xml version='1.0' encoding='UTF-8' ?>\n<result/>", 'XML'));

        $statement = $this->builderOn($client)->select('id')->from('t')->format('xml')->get();

        $this->assertSame('XML', $statement->getFormat());
        $this->assertStringStartsWith('<?xml', $statement->rawData());
        $this->assertSame('SELECT `id` FROM `t` FORMAT XML', $curler->requests[0]->getDetails()['parameters']);
    }

    public function test_get_query_to_send_and_get_query_client_are_what_get_uses(): void
    {
        $builder = (new Builder($this->mockClient))->from('t')->settings(['max_threads' => 2]);

        $this->assertSame($this->mockClient, $builder->getQueryClient());
        $this->assertSame('SELECT * FROM `t` FORMAT JSON SETTINGS max_threads=2', $builder->getQueryToSend()->toSql());
        $this->assertSame('SELECT * FROM `t` SETTINGS max_threads=2', $builder->toSql());
        $plain = (new Builder($this->mockClient))->from('t');
        $this->assertSame($plain, $plain->getQueryToSend());
    }

    public function test_get_grammar_returns_the_package_grammar_that_writes_bindings_into_raw_sql(): void
    {
        $grammar = (new Builder($this->mockClient))->getGrammar();

        $this->assertInstanceOf(Grammar::class, $grammar);
        $this->assertSame(
            "SELECT * FROM t WHERE a = 1 AND s = 'it\\'s'",
            $grammar->substituteBindingsIntoRawSql('SELECT * FROM t WHERE a = ? AND s = ?', [1, "it's"])
        );
        $this->assertSame('SELECT `a` FROM `t`', $grammar->substituteBindingsIntoRawSql('SELECT `a` FROM `t`', []));
    }

    /**
     * A Laravel database expression is written as raw() writes raw SQL, with the grammar of the connection that the
     * builder follows.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function laravelExpressionProvider(): array
    {
        return [
            'select' => [
                fn (Builder $builder): Builder => $builder->select(new LaravelExpression('count() AS c'))->from('t'),
                'SELECT count() AS c FROM `t`',
            ],
            'where column' => [
                fn (Builder $builder): Builder => $builder->from('t')->where(new LaravelExpression('a + 1'), 2),
                'SELECT * FROM `t` WHERE a + 1 = 2',
            ],
            'where value' => [
                fn (Builder $builder): Builder => $builder->from('t')->where('a', new LaravelExpression('b')),
                'SELECT * FROM `t` WHERE `a` = b',
            ],
            'where in' => [
                fn (Builder $builder): Builder => $builder->from('t')->whereIn('id', [new LaravelExpression('1 + 1')]),
                'SELECT * FROM `t` WHERE `id` IN (1 + 1)',
            ],
            'where null' => [
                fn (Builder $builder): Builder => $builder->from('t')->where(new LaravelExpression('nd'), null),
                'SELECT * FROM `t` WHERE nd IS NULL',
            ],
            'settings' => [
                fn (Builder $builder): Builder => $builder->from('t')->where('id', 1)->settings('max_threads', new LaravelExpression('2')),
                'SELECT * FROM `t` WHERE `id` = 1 SETTINGS max_threads=2',
            ],
            'table' => [
                fn (Builder $builder): Builder => $builder->from(new LaravelExpression('numbers(2)')),
                'SELECT * FROM numbers(2)',
            ],
            'order by' => [
                fn (Builder $builder): Builder => $builder->from('t')->orderBy(new LaravelExpression('a * -1')),
                'SELECT * FROM `t` ORDER BY a * -1 ASC',
            ],
            'with expression' => [
                fn (Builder $builder): Builder => $builder->withExpression('w', new LaravelExpression('SELECT 1 AS x'))->from('w'),
                'WITH `w` AS (SELECT 1 AS x) SELECT * FROM `w`',
            ],
            'in a closure' => [
                fn (Builder $builder): Builder => $builder->from('t')->where(fn (Builder $query) => $query->where(new LaravelExpression('a + 1'), 2)),
                'SELECT * FROM `t` WHERE (a + 1 = 2)',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('laravelExpressionProvider')]
    public function test_a_laravel_expression_is_written_with_the_grammar_of_the_followed_connection(Closure $query, string $sql): void
    {
        $this->assertSame($sql, $query($this->builderFollowing($this->mockClient, $this->connection()))->toSql());
    }

    public function test_a_laravel_expression_of_a_builder_that_follows_no_connection_uses_the_connection_of_its_name(): void
    {
        $builder = $this->builderOn($this->mockClient);
        $builder::$stubConnection = $this->connection();

        $this->assertSame('SELECT * FROM `t` WHERE a + 1 = 2', $builder->from('t')->where(new LaravelExpression('a + 1'), 2)->toSql());
    }

    /**
     * The application that the DB facade resolves connections from, or null for none.
     *
     * @return array<string, array{Container|null, string}>
     */
    public static function unresolvableConnectionProvider(): array
    {
        $container = new Container();
        $container->instance('db', new class {
            public function connection(?string $name = null): never
            {
                throw new InvalidArgumentException("Database connection [{$name}] not configured.");
            }
        });

        return [
            'outside a laravel application' => [null, 'A facade root has not been set.'],
            'a connection that the application does not configure' => [$container, 'Database connection [clickhouse] not configured.'],
        ];
    }

    #[DataProvider('unresolvableConnectionProvider')]
    public function test_a_laravel_expression_of_a_builder_whose_connection_cannot_be_resolved_says_why(
        ?Container $application,
        string $reason
    ): void {
        $previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstance('db');
        Facade::setFacadeApplication($application);

        try {
            (new Builder($this->mockClient))->from('t')->where(new LaravelExpression('a + 1'), 2)->toSql();
            $this->fail('toSql() did not throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cannot write a Laravel database expression without the grammar of a database connection: the builder'
                . " follows no connection, and its connection [clickhouse] cannot be resolved ({$reason}). Build the"
                . ' query from a ClickHouse connection or a model, or pass raw SQL as an ' . Expression::class . '.',
                $exception->getMessage()
            );
            $this->assertSame($reason, $exception->getPrevious()?->getMessage());
        } finally {
            Facade::clearResolvedInstance('db');
            Facade::setFacadeApplication($previousApplication);
        }
    }

    /**
     * An ORDER BY entry of a Laravel database expression is read as raw SQL: one with AS defines an alias, which
     * count() keeps in a subquery, and any other one only sorts, so count() drops it.
     *
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function countOfALaravelExpressionOrderProvider(): array
    {
        return [
            'an alias' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->orderBy(new LaravelExpression('a AS b')),
                'SELECT count() AS `count` FROM (SELECT `id` FROM `t` ORDER BY a AS b ASC)',
            ],
            'with fill' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->orderBy(new LaravelExpression('id WITH FILL')),
                'SELECT count() AS `count` FROM (SELECT `id` FROM `t` ORDER BY id WITH FILL ASC)',
            ],
            'a sort' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->orderBy(new LaravelExpression('a * -1')),
                'SELECT count() as `count` FROM `t`',
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('countOfALaravelExpressionOrderProvider')]
    public function test_count_reads_an_order_by_a_laravel_expression_as_raw_sql(Closure $query, string $sql): void
    {
        $sentSql = [];
        $client = $this->createStub(Client::class);
        $client->method('select')->willReturnCallback(function (string $sent) use (&$sentSql): Statement {
            $sentSql[] = $sent;
            $statement = $this->createStub(Statement::class);
            $statement->method('rows')->willReturn([['count' => '2']]);

            return $statement;
        });

        $this->assertSame(2, $query($this->builderFollowing($client, $this->connection()))->count());
        $this->assertSame([$sql], $sentSql);
    }

    public function test_a_mutation_of_a_query_ordered_by_a_laravel_expression_with_an_alias_is_refused(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('write');
        $builder = $this->builderFollowing($client, $this->connection())->from('t')->where('a', 1)->orderBy(new LaravelExpression('a AS b'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot delete with a query that uses ORDER BY: a ClickHouse DELETE');

        $builder->delete(false);
    }

    public function test_a_mutation_of_a_query_ordered_by_a_laravel_expression_that_only_sorts_is_sent(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('write')
            ->with('ALTER TABLE `t` DELETE WHERE `a` = 1')
            ->willReturn($this->createStub(Statement::class));

        $this->builderFollowing($client, $this->connection())->from('t')->where('a', 1)->orderBy(new LaravelExpression('a * -1'))->delete(false);
    }

    /**
     * @return array<string, array{Closure(Builder, Carbon, Carbon): Builder, string}>
     */
    public static function microsecondDateProvider(): array
    {
        return [
            'where' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->from('t')->where('d6', $date),
                "SELECT * FROM `t` WHERE `d6` = '2024-01-02 03:04:05.123456'",
            ],
            'where in, with a whole second' => [
                fn (Builder $builder, Carbon $date, Carbon $wholeSecond): Builder => $builder->from('t')->whereIn('d6', [$date, $wholeSecond]),
                "SELECT * FROM `t` WHERE `d6` IN ('2024-01-02 03:04:05.123456', '2024-01-02 03:04:05')",
            ],
            'where between' => [
                fn (Builder $builder, Carbon $date, Carbon $wholeSecond): Builder => $builder->from('t')->whereBetween('d6', [$wholeSecond, $date]),
                "SELECT * FROM `t` WHERE `d6` BETWEEN '2024-01-02 03:04:05' AND '2024-01-02 03:04:05.123456'",
            ],
            'prewhere' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->from('t')->preWhere('d6', '<', $date),
                "SELECT * FROM `t` PREWHERE `d6` < '2024-01-02 03:04:05.123456'",
            ],
            'having' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->select('d6')->from('t')->groupBy('d6')->having('d6', '>', $date),
                "SELECT `d6` FROM `t` GROUP BY `d6` HAVING `d6` > '2024-01-02 03:04:05.123456'",
            ],
            'with alias' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->withAlias('since', $date)->from('t')->where('d6', '>', new Expression('since')),
                "WITH '2024-01-02 03:04:05.123456' AS `since` SELECT * FROM `t` WHERE `d6` > since",
            ],
            'closure' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->from('t')->where(fn (Builder $query) => $query->where('d6', $date)),
                "SELECT * FROM `t` WHERE (`d6` = '2024-01-02 03:04:05.123456')",
            ],
            'sub-query' => [
                fn (Builder $builder, Carbon $date): Builder => $builder->from('t')->whereIn('id', fn (Builder $query) => $query->select('id')->from('u')->where('d6', $date)),
                "SELECT * FROM `t` WHERE `id` IN (SELECT `id` FROM `u` WHERE `d6` = '2024-01-02 03:04:05.123456')",
            ],
        ];
    }

    /**
     * @param Closure(Builder, Carbon, Carbon): Builder $query
     */
    #[DataProvider('microsecondDateProvider')]
    public function test_conditions_write_dates_at_the_microsecond_precision_of_the_followed_connection(Closure $query, string $sql): void
    {
        $builder = $this->builderFollowing($this->mockClient, $this->connection(['datetime_precision' => 'microsecond']));

        $this->assertSame(
            $sql,
            $query($builder, Carbon::parse('2024-01-02 03:04:05.123456'), Carbon::parse('2024-01-02 03:04:05'))->toSql()
        );
    }

    public function test_a_builder_made_with_only_a_client_writes_dates_with_second_precision(): void
    {
        $this->assertSame(
            "SELECT * FROM `t` WHERE `d6` = '2024-01-02 03:04:05'",
            (new Builder($this->mockClient))->from('t')->where('d6', Carbon::parse('2024-01-02 03:04:05.123456'))->toSql()
        );
    }

    public function test_update_writes_dates_at_the_microsecond_precision_of_the_followed_connection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('write')
            ->with("ALTER TABLE `t` UPDATE `d6` = '2024-01-02 03:04:05.123456' WHERE `d6` < '2024-01-02 03:04:06.500000'")
            ->willReturn($this->createStub(Statement::class));

        $this->builderFollowing($client, $this->connection(['datetime_precision' => 'microsecond']))
            ->from('t')
            ->where('d6', '<', Carbon::parse('2024-01-02 03:04:06.5'))
            ->update(['d6' => Carbon::parse('2024-01-02 03:04:05.123456')]);
    }

    public function test_a_builder_made_without_a_client_takes_the_client_of_its_connection_and_follows_it(): void
    {
        $template = new class($this->mockClient) extends Builder {
            public static ?Connection $namedConnection = null;
            public static ?Client $namedClient = null;

            public function resolveConnection(): Connection
            {
                return static::$namedConnection;
            }

            public function getThisClient(): Client
            {
                return static::$namedClient;
            }
        };
        $client = $this->createStub(Client::class);
        $template::$namedConnection = $this->connection(['datetime_precision' => 'microsecond']);
        $template::$namedClient = $client;

        $builder = new ($template::class)();

        $this->assertSame($client, $builder->getQueryClient());
        $this->assertSame(
            "SELECT * FROM `t` WHERE `d6` = '2024-01-02 03:04:05.123456' AND a + 1 = 2",
            $builder->from('t')->where('d6', Carbon::parse('2024-01-02 03:04:05.123456'))->where(new LaravelExpression('a + 1'), 2)->toSql()
        );
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, array<int, array<string, mixed>>, string, array<string, mixed>|null}>
     */
    public static function firstProvider(): array
    {
        return [
            'every column' => [
                fn (Builder $builder): Builder => $builder->from('examples'),
                [['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2, 'f_string' => 'b']],
                'SELECT * FROM `examples` LIMIT 1',
                ['f_int' => 1, 'f_string' => 'a'],
            ],
            'no row' => [
                fn (Builder $builder): Builder => $builder->from('examples')->where('f_int', 999),
                [],
                'SELECT * FROM `examples` WHERE `f_int` = 999 LIMIT 1',
                null,
            ],
            'own limit and offset' => [
                fn (Builder $builder): Builder => $builder->select('f_int')->from('examples')->orderBy('f_int')->limit(10, 5),
                [['f_int' => 6]],
                'SELECT `f_int` FROM `examples` ORDER BY `f_int` ASC LIMIT 5, 1',
                ['f_int' => 6],
            ],
            'own zero limit' => [
                fn (Builder $builder): Builder => $builder->from('examples')->limit(0),
                [],
                'SELECT * FROM `examples` LIMIT 0',
                null,
            ],
            'set operation with settings' => [
                fn (Builder $builder): Builder => $builder
                    ->select('f_int')
                    ->from('examples')
                    ->limit(1)
                    ->unionAll(fn (Builder $query) => $query->select('f_int')->from('b'))
                    ->settings(['max_threads' => 1]),
                [['f_int' => 1]],
                'SELECT * FROM (SELECT `f_int` FROM `examples` LIMIT 1 UNION ALL SELECT `f_int` FROM `b`) LIMIT 1'
                . ' FORMAT JSON SETTINGS max_threads=1',
                ['f_int' => 1],
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>|null $expected
     */
    #[DataProvider('firstProvider')]
    public function test_first_reads_one_row_from_a_copy_of_the_query(Closure $query, array $rows, string $sql, ?array $expected): void
    {
        [$sentSql, [$sqlBefore, $row, $sqlAfter]] = $this->runRecorded(
            $query,
            fn (Builder $builder): array => [$builder->toSql(), $builder->first(), $builder->toSql()],
            [$rows]
        );

        $this->assertSame([$sql], $sentSql);
        $this->assertSame($expected, $row);
        $this->assertSame($sqlBefore, $sqlAfter, 'first() leaves the builder unchanged');
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function firstColumnsProvider(): array
    {
        return [
            'columns replace *' => [
                fn (Builder $builder) => $builder->from('examples')->first(['f_int', 'f_string']),
                'SELECT `f_int`, `f_string` FROM `examples` LIMIT 1',
            ],
            'one column as a string' => [
                fn (Builder $builder) => $builder->from('examples')->first('f_int'),
                'SELECT `f_int` FROM `examples` LIMIT 1',
            ],
            'a model query selects *' => [
                fn (Builder $builder) => $builder->select(['*'])->from('examples')->where('f_int', 2)->first(['f_string']),
                'SELECT `f_string` FROM `examples` WHERE `f_int` = 2 LIMIT 1',
            ],
            'columns are ignored for a select list' => [
                fn (Builder $builder) => $builder->select('f_int')->from('examples')->first(['f_string']),
                'SELECT `f_int` FROM `examples` LIMIT 1',
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     */
    #[DataProvider('firstColumnsProvider')]
    public function test_first_selects_the_given_columns_only_when_the_query_selects_every_column(Closure $run, string $sql): void
    {
        [$sentSql] = $this->runRecorded(fn (Builder $builder): Builder => $builder, $run, [[['x' => 1]]]);

        $this->assertSame([$sql], $sentSql);
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, array<int, array<string, mixed>>, string, mixed}>
     */
    public static function valueProvider(): array
    {
        return [
            'a column of a query that selects every column' => [
                fn (Builder $builder) => $builder->from('t')->orderBy('id', 'desc')->value('s'),
                [['s' => 'z']],
                'SELECT `s` FROM `t` ORDER BY `id` DESC LIMIT 1',
                'z',
            ],
            'raw sql, read by its position' => [
                fn (Builder $builder) => $builder->from('t')->value(raw('a + 1')),
                [['plus(a, 1)' => '2']],
                'SELECT a + 1 FROM `t` LIMIT 1',
                '2',
            ],
            'a model query' => [
                fn (Builder $builder) => $builder->select(['*'])->from('t')->where('id', 2)->value('s'),
                [['s' => 'y']],
                'SELECT `s` FROM `t` WHERE `id` = 2 LIMIT 1',
                'y',
            ],
            'an alias of the select list' => [
                fn (Builder $builder) => $builder->select('a as x')->from('t')->value('x'),
                [['x' => 5]],
                'SELECT `a` AS `x` FROM `t` LIMIT 1',
                5,
            ],
            'a qualified column of the select list' => [
                fn (Builder $builder) => $builder->select('t.a')->from('t')->value('t.a'),
                [['a' => 3]],
                'SELECT `t`.`a` FROM `t` LIMIT 1',
                3,
            ],
            'an aliased raw column of the select list' => [
                fn (Builder $builder) => $builder->select(new RawColumn('a + 1', 'y'))->from('t')->value(new RawColumn('a + 1', 'y')),
                [['y' => 4]],
                'SELECT a + 1 AS `y` FROM `t` LIMIT 1',
                4,
            ],
            'a column of a joined table in the select list, named with its table' => [
                fn (Builder $builder) => $builder->select('t.id', 'u.s')->from('t')->allInnerJoin('u', ['id'])->value('u.s'),
                [['id' => 2, 'u.s' => 'y']],
                'SELECT `t`.`id`, `u`.`s` FROM `t` ALL INNER JOIN `u` USING `id` LIMIT 1',
                'y',
            ],
            'a column of a joined table, given in backticks' => [
                fn (Builder $builder) => $builder->select('t.id', 'u.s')->from('t')->allInnerJoin('u', ['id'])->value('`u`.`s`'),
                [['id' => 2, 'u.s' => 'y']],
                'SELECT `t`.`id`, `u`.`s` FROM `t` ALL INNER JOIN `u` USING `id` LIMIT 1',
                'y',
            ],
            'a column of the own table next to the column of that name of a joined table' => [
                fn (Builder $builder) => $builder->select('t.s', 'u.s')->from('t')->allInnerJoin('u', ['id'])->value('t.s'),
                [['s' => 'x', 'u.s' => 'y']],
                'SELECT `t`.`s`, `u`.`s` FROM `t` ALL INNER JOIN `u` USING `id` LIMIT 1',
                'x',
            ],
            'a column of a nested structure in the select list' => [
                fn (Builder $builder) => $builder->select('n.k')->from('t')->value('n.k'),
                [['n.k' => ['k1', 'k2']]],
                'SELECT `n`.`k` FROM `t` LIMIT 1',
                ['k1', 'k2'],
            ],
            'no row' => [
                fn (Builder $builder) => $builder->from('t')->where('id', 999)->value('s'),
                [],
                'SELECT `s` FROM `t` WHERE `id` = 999 LIMIT 1',
                null,
            ],
            'no row of a select list' => [
                fn (Builder $builder) => $builder->select('a')->from('t')->value('b'),
                [],
                'SELECT `a` FROM `t` LIMIT 1',
                null,
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     * @param array<int, array<string, mixed>> $rows
     */
    #[DataProvider('valueProvider')]
    public function test_value_reads_one_value_of_the_first_row(Closure $run, array $rows, string $sql, mixed $expected): void
    {
        [$sentSql, $value] = $this->runRecorded(fn (Builder $builder): Builder => $builder, $run, [$rows]);

        $this->assertSame([$sql], $sentSql);
        $this->assertSame($expected, $value);
    }

    public function test_value_of_a_laravel_expression_is_read_by_its_position(): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn([['max(a)' => 5]]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('select')->with('SELECT max(a) FROM `t` LIMIT 1')->willReturn($statement);

        $this->assertSame(5, $this->builderFollowing($client, $this->connection())->from('t')->value(new LaravelExpression('max(a)')));
    }

    public function test_value_throws_when_the_select_list_has_no_such_column(): void
    {
        $builder = $this->builderExpectingSelect('SELECT `a` FROM `t` LIMIT 1', [['a' => 1]])->select('a')->from('t');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The query does not select the column [b] that value() reads.');

        $builder->value('b');
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, array<int, array<string, mixed>>, string, array<int|string, mixed>}>
     */
    public static function pluckProvider(): array
    {
        return [
            'a column' => [
                fn (Builder $builder) => $builder->from('t')->orderBy('id')->pluck('id'),
                [['id' => 1], ['id' => 2]],
                'SELECT `id` FROM `t` ORDER BY `id` ASC',
                [1, 2],
            ],
            'a model query' => [
                fn (Builder $builder) => $builder->select(['*'])->from('t')->where('a', '>', 1)->pluck('id'),
                [['id' => 2]],
                'SELECT `id` FROM `t` WHERE `a` > 1',
                [2],
            ],
            'an alias' => [
                fn (Builder $builder) => $builder->from('t')->pluck('a as x'),
                [['x' => 1], ['x' => 5]],
                'SELECT `a` AS `x` FROM `t`',
                [1, 5],
            ],
            'raw sql' => [
                fn (Builder $builder) => $builder->from('t')->pluck(raw('a + 1')),
                [['plus(a, 1)' => '2'], ['plus(a, 1)' => '6']],
                'SELECT a + 1 FROM `t`',
                ['2', '6'],
            ],
            'a key' => [
                fn (Builder $builder) => $builder->from('t')->pluck('s', 'id'),
                [['s' => 'x', 'id' => 1], ['s' => 'y', 'id' => 2]],
                'SELECT `s`, `id` FROM `t`',
                [1 => 'x', 2 => 'y'],
            ],
            'the column as its own key' => [
                fn (Builder $builder) => $builder->from('t')->pluck('id', 'id'),
                [['id' => 1], ['id' => 2]],
                'SELECT `id` FROM `t`',
                [1 => 1, 2 => 2],
            ],
            'a column of a joined table' => [
                fn (Builder $builder) => $builder->from('t')->allInnerJoin('u', ['id'])->pluck('u.s'),
                [['u.s' => 'y']],
                'SELECT `u`.`s` FROM `t` ALL INNER JOIN `u` USING `id`',
                ['y'],
            ],
            'the select list of the query' => [
                fn (Builder $builder) => $builder->select('a', 's')->from('t')->pluck('s', 'a'),
                [['a' => 1, 's' => 'x']],
                'SELECT `a`, `s` FROM `t`',
                [1 => 'x'],
            ],
            'a qualified column of the select list' => [
                fn (Builder $builder) => $builder->select('t.a')->from('t')->pluck('t.a'),
                [['a' => 1]],
                'SELECT `t`.`a` FROM `t`',
                [1],
            ],
            'a column of a joined table in the select list, keyed by a column of the own table' => [
                fn (Builder $builder) => $builder->select('t.id', 'u.s')->from('t')->allInnerJoin('u', ['id'])->pluck('u.s', 't.id'),
                [['id' => 2, 'u.s' => 'y'], ['id' => 3, 'u.s' => 'z']],
                'SELECT `t`.`id`, `u`.`s` FROM `t` ALL INNER JOIN `u` USING `id`',
                [2 => 'y', 3 => 'z'],
            ],
            'a column of a nested structure in the select list' => [
                fn (Builder $builder) => $builder->select('id', 'n.k')->from('t')->pluck('n.k'),
                [['id' => 1, 'n.k' => ['k1']], ['id' => 2, 'n.k' => []]],
                'SELECT `id`, `n`.`k` FROM `t`',
                [['k1'], []],
            ],
            'a set operation' => [
                fn (Builder $builder) => $builder->from('t')->unionAll(fn (Builder $query) => $query->from('u'))->pluck('id'),
                [['id' => 1, 's' => 'x'], ['id' => 10, 's' => 'p']],
                'SELECT * FROM `t` UNION ALL SELECT * FROM `u`',
                [1, 10],
            ],
            'no row' => [
                fn (Builder $builder) => $builder->from('t')->pluck('id'),
                [],
                'SELECT `id` FROM `t`',
                [],
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     * @param array<int, array<string, mixed>> $rows
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('pluckProvider')]
    public function test_pluck_selects_only_its_columns_from_a_query_that_selects_every_column(
        Closure $run,
        array $rows,
        string $sql,
        array $expected
    ): void {
        [$sentSql, $values] = $this->runRecorded(fn (Builder $builder): Builder => $builder, $run, [$rows]);

        $this->assertSame([$sql], $sentSql);
        $this->assertSame($expected, $values->all());
    }

    public function test_pluck_of_a_laravel_expression_reads_its_sql_through_the_grammar_of_the_connection(): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn([['y' => '2'], ['y' => '3']]);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))->method('select')
            ->willReturnCallback(function (string $sql) use ($statement): Statement {
                $this->assertContains($sql, ['SELECT a + 1 AS y FROM `t`', 'SELECT a + 1 AS y FROM `t` ORDER BY `id` ASC']);

                return $statement;
            });
        $builder = fn (): Builder => $this->builderFollowing($client, $this->connection())->from('t');

        $this->assertSame(['2', '3'], $builder()->pluck(new LaravelExpression('a + 1 AS y'))->all());
        $this->assertSame(
            ['2', '3'],
            $builder()->select(new LaravelExpression('a + 1 AS y'))->orderBy('id')->pluck(new LaravelExpression('a + 1 AS y'))->all(),
            'read by its name when the query has a select list'
        );
    }

    public function test_get_collection_returns_the_rows_in_a_collection_and_passes_the_bindings(): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn([['id' => 1], ['id' => 2]]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('select')
            ->with('SELECT `id` FROM `t` WHERE `a` = :a', ['a' => 1])
            ->willReturn($statement);

        $collection = $this->builderOn($client)->select('id')->from('t')->where('a', new Expression(':a'))->getCollection(['a' => 1]);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame([['id' => 1], ['id' => 2]], $collection->all());
        $this->assertSame([1, 2], $collection->pluck('id')->all());
    }

    public function test_chunk_passes_each_page_and_its_number_and_sends_no_query_after_a_short_page(): void
    {
        $calls = [];
        [$sentSql] = $this->runRecorded(
            fn (Builder $builder): Builder => $builder->from('t')->orderBy('id'),
            function (Builder $builder) use (&$calls): void {
                $builder->chunk(2, function (array $rows, int $page) use (&$calls): void {
                    $calls[] = [$page, array_column($rows, 'id')];
                });
            },
            [[['id' => 1], ['id' => 2]], [['id' => 3]]]
        );

        $this->assertSame([[1, [1, 2]], [2, [3]]], $calls);
        $this->assertSame([
            'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 0, 2',
            'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 2, 2',
        ], $sentSql);
    }

    public function test_chunk_stops_when_the_callback_returns_false(): void
    {
        $calls = 0;
        [$sentSql] = $this->runRecorded(
            fn (Builder $builder): Builder => $builder->from('t'),
            function (Builder $builder) use (&$calls): void {
                $builder->chunk(1, function () use (&$calls): bool {
                    $calls++;

                    return false;
                });
            },
            [[['id' => 1]], [['id' => 2]]]
        );

        $this->assertSame(1, $calls);
        $this->assertSame(['SELECT * FROM `t` LIMIT 0, 1'], $sentSql);
    }

    public function test_chunk_does_not_call_the_callback_for_an_empty_page(): void
    {
        $calls = 0;
        [$sentSql] = $this->runRecorded(
            fn (Builder $builder): Builder => $builder->from('t'),
            function (Builder $builder) use (&$calls): void {
                $builder->chunk(2, function () use (&$calls): void {
                    $calls++;
                });
            },
            [[]]
        );

        $this->assertSame(0, $calls);
        $this->assertSame(['SELECT * FROM `t` LIMIT 0, 2'], $sentSql);
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, int, array<int, array<int, array<string, mixed>>>, string[]}>
     */
    public static function chunkWithinOwnLimitProvider(): array
    {
        return [
            'limit' => [
                fn (Builder $builder): Builder => $builder->from('t')->orderBy('id')->limit(2),
                1,
                [[['id' => 1]], [['id' => 2]]],
                ['SELECT * FROM `t` ORDER BY `id` ASC LIMIT 0, 1', 'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 1, 1'],
            ],
            'limit and offset' => [
                fn (Builder $builder): Builder => $builder->from('t')->orderBy('id')->limit(3, 4),
                2,
                [[['id' => 5], ['id' => 6]], [['id' => 7]]],
                ['SELECT * FROM `t` ORDER BY `id` ASC LIMIT 4, 2', 'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 6, 1'],
            ],
            'zero limit' => [
                fn (Builder $builder): Builder => $builder->from('t')->limit(0),
                2,
                [],
                [],
            ],
            'a set operation with a limit of its first query' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->limit(1)->unionAll(fn (Builder $query) => $query->select('id')->from('u')),
                2,
                [[['id' => 1], ['id' => 10]], [['id' => 11]]],
                [
                    'SELECT * FROM (SELECT `id` FROM `t` LIMIT 1 UNION ALL SELECT `id` FROM `u`) LIMIT 0, 2',
                    'SELECT * FROM (SELECT `id` FROM `t` LIMIT 1 UNION ALL SELECT `id` FROM `u`) LIMIT 2, 2',
                ],
            ],
            'except with settings' => [
                fn (Builder $builder): Builder => $builder
                    ->select('id')
                    ->from('t')
                    ->except(fn (Builder $query) => $query->select('id')->from('u'))
                    ->settings(['max_threads' => 1]),
                1,
                [[['id' => 1]], [['id' => 3]], []],
                [
                    'SELECT * FROM (SELECT `id` FROM `t` EXCEPT SELECT `id` FROM `u`) LIMIT 0, 1 FORMAT JSON SETTINGS max_threads=1',
                    'SELECT * FROM (SELECT `id` FROM `t` EXCEPT SELECT `id` FROM `u`) LIMIT 1, 1 FORMAT JSON SETTINGS max_threads=1',
                    'SELECT * FROM (SELECT `id` FROM `t` EXCEPT SELECT `id` FROM `u`) LIMIT 2, 1 FORMAT JSON SETTINGS max_threads=1',
                ],
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param array<int, array<int, array<string, mixed>>> $rowsPerSelect
     * @param string[] $expectedSql
     */
    #[DataProvider('chunkWithinOwnLimitProvider')]
    public function test_chunk_reads_within_the_query_s_own_limit_and_pages_a_set_operation_as_a_whole(
        Closure $query,
        int $count,
        array $rowsPerSelect,
        array $expectedSql
    ): void {
        $rows = [];
        [$sentSql] = $this->runRecorded($query, function (Builder $builder) use ($count, &$rows): void {
            $builder->chunk($count, function (array $page) use (&$rows): void {
                $rows = array_merge($rows, $page);
            });
        }, $rowsPerSelect);

        $this->assertSame($expectedSql, $sentSql);
        $this->assertSame(array_merge(...$rowsPerSelect ?: [[]]), $rows);
    }

    public function test_chunk_rejects_a_size_below_one(): void
    {
        $this->mockClient->expects($this->never())->method('select');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The chunk size should be at least 1, 0 given.');

        (new Builder($this->mockClient))->from('t')->chunk(0, fn () => null);
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, Closure(Builder): mixed, array<int, array<int, array<string, mixed>>>, string[]}>
     */
    public static function paginatedSetOperationProvider(): array
    {
        $union = '(SELECT `id` FROM `t` UNION ALL SELECT `id` FROM `u`)';
        $except = '(SELECT `id` FROM `t` EXCEPT SELECT `id` FROM `u`)';

        return [
            'paginate a union all' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->unionAll(fn (Builder $query) => $query->select('id')->from('u')),
                fn (Builder $builder) => $builder->paginate(2, ['*'], 'page', 2),
                [[['count' => '5']], [['id' => 3], ['id' => 10]]],
                ["SELECT count() AS `count` FROM {$union}", "SELECT * FROM {$union} LIMIT 2, 2"],
            ],
            'paginate an except with settings' => [
                fn (Builder $builder): Builder => $builder
                    ->select('id')
                    ->from('t')
                    ->except(fn (Builder $query) => $query->select('id')->from('u'))
                    ->settings(['max_threads' => 1]),
                fn (Builder $builder) => $builder->paginate(10),
                [[['count' => '2']], [['id' => 1], ['id' => 3]]],
                [
                    "SELECT count() AS `count` FROM {$except} WHERE NOT ignore(*) FORMAT JSON SETTINGS max_threads=1",
                    "SELECT * FROM {$except} LIMIT 0, 10 FORMAT JSON SETTINGS max_threads=1",
                ],
            ],
            'paginate a union all whose first query is ordered and limited' => [
                fn (Builder $builder): Builder => $builder
                    ->select('id')
                    ->from('t')
                    ->orderBy('id')
                    ->limit(1)
                    ->unionAll(fn (Builder $query) => $query->select('id')->from('u')),
                fn (Builder $builder) => $builder->paginate(10),
                [[['count' => '3']], [['id' => 1], ['id' => 10], ['id' => 11]]],
                [
                    'SELECT count() AS `count` FROM (SELECT `id` FROM `t` ORDER BY `id` ASC LIMIT 1 UNION ALL SELECT `id` FROM `u`)',
                    'SELECT * FROM (SELECT `id` FROM `t` ORDER BY `id` ASC LIMIT 1 UNION ALL SELECT `id` FROM `u`) LIMIT 0, 10',
                ],
            ],
            'simple paginate a union all' => [
                fn (Builder $builder): Builder => $builder->select('id')->from('t')->unionAll(fn (Builder $query) => $query->select('id')->from('u')),
                fn (Builder $builder) => $builder->simplePaginate(2),
                [[['id' => 1], ['id' => 2], ['id' => 3]]],
                ["SELECT * FROM {$union} LIMIT 0, 3"],
            ],
            'paginate replaces the limit of a query without set operations' => [
                fn (Builder $builder): Builder => $builder->from('t')->orderBy('id')->limit(5, 1),
                fn (Builder $builder) => $builder->paginate(2),
                [[['count' => '3']], [['id' => 1], ['id' => 2]]],
                ['SELECT count() as `count` FROM `t`', 'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 0, 2'],
            ],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     * @param Closure(Builder): mixed $run
     * @param array<int, array<int, array<string, mixed>>> $rowsPerSelect
     * @param string[] $expectedSql
     */
    #[DataProvider('paginatedSetOperationProvider')]
    public function test_the_paginators_page_a_set_operation_as_a_whole(
        Closure $query,
        Closure $run,
        array $rowsPerSelect,
        array $expectedSql
    ): void {
        $pageRows = end($rowsPerSelect);
        [$sentSql, $paginator] = $this->runRecorded($query, $run, $rowsPerSelect);

        $this->assertSame($expectedSql, $sentSql);
        $this->assertSame(array_slice($pageRows, 0, $paginator->perPage()), $paginator->items());
    }

    /**
     * @return array<string, array{Closure(Builder): Builder, string}>
     */
    public static function pageQueryProvider(): array
    {
        return [
            'a union all with settings' => [
                fn (Builder $builder): Builder => $builder
                    ->select('id')
                    ->from('t')
                    ->unionAll(fn (Builder $query) => $query->select('id')->from('u'))
                    ->settings(['max_threads' => 1]),
                'SELECT * FROM (SELECT `id` FROM `t` UNION ALL SELECT `id` FROM `u`) LIMIT 2, 2 FORMAT JSON SETTINGS max_threads=1',
            ],
            'a limited query' => [
                fn (Builder $builder): Builder => $builder->from('t')->orderBy('id')->limit(5, 1),
                'SELECT * FROM `t` ORDER BY `id` ASC LIMIT 2, 2',
            ],
        ];
    }

    /**
     * getQueryForPage() is the query of the rows that paginate() sends, so that a batch of parallel queries can send
     * it with getQueryForCount(), and a set operation is paged as a whole there too.
     *
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('pageQueryProvider')]
    public function test_get_query_for_page_is_the_page_that_paginate_sends(Closure $query, string $pageSql): void
    {
        [$sentSql] = $this->runRecorded($query, fn (Builder $builder) => $builder->paginate(2, ['*'], 'page', 2), [[['count' => '5']]]);
        $builder = $query(new Builder($this->mockClient));
        $sqlBefore = $builder->toSql();

        $this->assertSame($pageSql, $sentSql[1]);
        $this->assertSame($pageSql, $builder->getQueryForPage(2, 2)->getQueryToSend()->toSql());
        $this->assertSame($sqlBefore, $builder->toSql(), 'getQueryForPage() leaves the builder unchanged');
        $this->assertSame(
            str_replace('LIMIT 2, 2', 'LIMIT 0, 2', $pageSql),
            $builder->getQueryForPage(2)->getQueryToSend()->toSql(),
            'the first page by default'
        );
    }

    /**
     * @return array<string, array{Closure(Builder): mixed}>
     */
    public static function rowReadingMethodProvider(): array
    {
        return [
            'chunk' => [fn (Builder $builder) => $builder->chunk(1, fn () => null)],
            'paginate' => [fn (Builder $builder) => $builder->paginate(1)],
            'simple paginate' => [fn (Builder $builder) => $builder->simplePaginate(1)],
            'first' => [fn (Builder $builder) => $builder->first()],
            'value' => [fn (Builder $builder) => $builder->value('id')],
            'pluck' => [fn (Builder $builder) => $builder->pluck('id')],
            'count' => [fn (Builder $builder) => $builder->count()],
            'max' => [fn (Builder $builder) => $builder->max('id')],
            'exists' => [fn (Builder $builder) => $builder->exists()],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     */
    #[DataProvider('rowReadingMethodProvider')]
    public function test_a_method_that_reads_rows_leaves_the_builder_unchanged(Closure $run): void
    {
        foreach ([
            'plain' => fn (Builder $builder): Builder => $builder->select('id')->from('t')->where('id', '>', 0),
            'set operation' => fn (Builder $builder): Builder => $builder->select('id')->from('t')->unionAll(fn (Builder $query) => $query->select('id')->from('u')),
        ] as $label => $query) {
            [, [$before, $after]] = $this->runRecorded($query, function (Builder $builder) use ($run): array {
                $before = $builder->toSql();
                $run($builder);

                return [$before, $builder->toSql()];
            }, [[['id' => 1]], [['id' => 1]]]);

            $this->assertSame($before, $after, $label);
        }
    }

    public function test_a_delete_after_the_paging_methods_is_not_refused_for_a_limit(): void
    {
        Paginator::currentPageResolver(fn (): int => 1);
        Paginator::currentPathResolver(fn (): string => '/');
        $client = $this->createMock(Client::class);
        $client->method('select')->willReturn($this->createStub(Statement::class));
        $client->expects($this->once())
            ->method('write')
            ->with('ALTER TABLE `t` DELETE WHERE `id` > 0')
            ->willReturn($this->createStub(Statement::class));
        $builder = $this->builderOn($client)->from('t')->where('id', '>', 0);

        $builder->chunk(1, fn () => null);
        $builder->paginate(1);
        $builder->simplePaginate(1);
        $builder->first();
        $builder->delete(false);
    }

    /**
     * count() and the aggregates leave out the format of the query.
     *
     * @return array<string, array{Closure(Builder): mixed, array<int, array<string, mixed>>, string, mixed}>
     */
    public static function formatOfCountAndAggregateProvider(): array
    {
        return [
            'count, csv' => [
                fn (Builder $builder) => $builder->from('t')->format('CSV')->count(),
                [['count' => '4']],
                'SELECT count() as `count` FROM `t`',
                4,
            ],
            'sum, csv' => [
                fn (Builder $builder) => $builder->from('t')->format('CSV')->sum('v'),
                [['aggregate' => '100']],
                'SELECT sum(`v`) AS `aggregate` FROM `t`',
                '100',
            ],
            'count, xml' => [
                fn (Builder $builder) => $builder->from('t')->format('XML')->count(),
                [['count' => '4']],
                'SELECT count() as `count` FROM `t`',
                4,
            ],
            'count, json with settings' => [
                fn (Builder $builder) => $builder->from('t')->format('JSON')->settings(['max_threads' => 1])->count(),
                [['count' => '4']],
                'SELECT count() as `count` FROM `t` FORMAT JSON SETTINGS max_threads=1',
                4,
            ],
            'count of a subquery, csv' => [
                fn (Builder $builder) => $builder->select('a')->from('t')->groupBy('a')->format('CSV')->count(),
                [['count' => '2']],
                'SELECT count() AS `count` FROM (SELECT `a` FROM `t` GROUP BY `a`)',
                2,
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     * @param array<int, array<string, mixed>> $rows
     */
    #[DataProvider('formatOfCountAndAggregateProvider')]
    public function test_count_and_the_aggregates_leave_out_the_format(Closure $run, array $rows, string $sql, mixed $expected): void
    {
        $this->assertSame($expected, $run($this->builderExpectingSelect($sql, $rows)));
    }

    /**
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function aggregateColumnProvider(): array
    {
        return [
            'a name' => [fn (Builder $builder) => $builder->max('f_int'), 'max(`f_int`)'],
            'a qualified name' => [fn (Builder $builder) => $builder->max('examples.f_int'), 'max(`examples`.`f_int`)'],
            'every column' => [fn (Builder $builder) => $builder->aggregate('count', ['*']), 'count(*)'],
            'raw sql' => [fn (Builder $builder) => $builder->sum(raw('f_int * 2')), 'sum(f_int * 2)'],
            'a raw column' => [fn (Builder $builder) => $builder->sum(new RawColumn('f_int * 2')), 'sum(f_int * 2)'],
            'a name with a backtick' => [fn (Builder $builder) => $builder->max('we`ird'), 'max(`we``ird`)'],
            'a name with a backslash' => [fn (Builder $builder) => $builder->max('a\\'), 'max(`a\\\\`)'],
            'two columns' => [fn (Builder $builder) => $builder->aggregate('uniqExact', ['f_int', 'f_string']), 'uniqExact(`f_int`, `f_string`)'],
            'a column given as a string' => [fn (Builder $builder) => $builder->aggregate('max', 'f_int'), 'max(`f_int`)'],
            'a sub-query is a name' => [
                fn (Builder $builder) => $builder->max('(SELECT name FROM system.users LIMIT 1)'),
                'max(`(SELECT name FROM system`.`users LIMIT 1)`)',
            ],
            'sql that ends the function is a name' => [
                fn (Builder $builder) => $builder->max('f_int) AS aggregate FROM system.one --'),
                'max(`f_int)` AS `aggregate FROM system`.`one --`)',
            ],
            'a parametric aggregate' => [
                fn (Builder $builder) => $builder->aggregate('quantile(0.5)', ['f_int']),
                'quantile(0.5)(`f_int`)',
            ],
            'a parametric aggregate with two parameters' => [
                fn (Builder $builder) => $builder->aggregate('quantiles(0.5, 0.9)', ['f_int']),
                'quantiles(0.5, 0.9)(`f_int`)',
            ],
            'a parametric aggregate with an integer parameter' => [
                fn (Builder $builder) => $builder->aggregate('topK(2)', 'f_string'),
                'topK(2)(`f_string`)',
            ],
            'parameters with signs, fractions, exponents and spaces' => [
                fn (Builder $builder) => $builder->aggregate('quantiles( .5,+0.25 , 1e-1,-2E+0, 3. )', ['f_int']),
                'quantiles( .5,+0.25 , 1e-1,-2E+0, 3. )(`f_int`)',
            ],
        ];
    }

    /**
     * A string column is quoted as a name, so that it cannot add SQL to the query.
     *
     * @param Closure(Builder): mixed $run
     */
    #[DataProvider('aggregateColumnProvider')]
    public function test_an_aggregate_quotes_a_string_column_as_a_name(Closure $run, string $aggregate): void
    {
        $run($this->builderExpectingSelect("SELECT {$aggregate} AS `aggregate` FROM `examples`", [['aggregate' => 1]])->from('examples'));
    }

    public function test_an_aggregate_writes_a_laravel_expression_as_raw_sql(): void
    {
        $statement = $this->createStub(Statement::class);
        $statement->method('rows')->willReturn([['aggregate' => '27']]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('select')->with('SELECT sum(a * b) AS `aggregate` FROM `t`')->willReturn($statement);

        $this->assertSame('27', $this->builderFollowing($client, $this->connection())->from('t')->sum(new LaravelExpression('a * b')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAggregateFunctionProvider(): array
    {
        return [
            'sql after the name' => ['max(1)) --'],
            'empty' => [''],
            'a leading digit' => ['1max'],
            'a space' => ['max '],
            'a semicolon' => ['max;'],
            'sql after the parameters' => ['quantile(0.5) --'],
            'a column list after the parameters' => ['quantile(0.5)(a)'],
            'a string parameter' => ["quantile('0.5')"],
            'a name as a parameter' => ['topK(a)'],
            'a hexadecimal parameter' => ['topK(0x2)'],
            'an expression as a parameter' => ['topK(1 + 1)'],
            'no parameter' => ['quantile()'],
            'an empty parameter' => ['quantiles(0.5,)'],
            'an unclosed parameter list' => ['quantile(0.5'],
            'a space before the parameters' => ['quantile (0.5)'],
            'a line break after the parameters' => ["quantile(0.5)\n"],
        ];
    }

    #[DataProvider('invalidAggregateFunctionProvider')]
    public function test_an_aggregate_function_name_must_be_a_plain_identifier_with_numbers_as_parameters(string $function): void
    {
        $this->mockClient->expects($this->never())->method('select');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Invalid aggregate function name [{$function}]: a function name must match [A-Za-z_][A-Za-z0-9_]*,"
            . ' optionally followed by a list of numbers in parentheses, such as quantile(0.5).'
        );

        $this->builderOn($this->mockClient)->from('t')->aggregate($function, ['a']);
    }

    /**
     * The aggregates read the rows that the query returns: a query that count() counts in a subquery is aggregated
     * in one, and any other query replaces its columns and drops its ORDER BY and LIMIT.
     *
     * @return array<string, array{Closure(Builder): mixed, string}>
     */
    public static function aggregateQueryProvider(): array
    {
        return [
            'union all' => [
                fn (Builder $builder) => $builder->select('id')->from('t')->unionAll(fn (Builder $query) => $query->select('id')->from('u'))->max('id'),
                'SELECT max(`id`) AS `aggregate` FROM (SELECT `id` FROM `t` UNION ALL SELECT `id` FROM `u`)',
            ],
            'union all whose first query is ordered and limited' => [
                fn (Builder $builder) => $builder
                    ->select('id')
                    ->from('t')
                    ->orderBy('id', 'desc')
                    ->limit(1)
                    ->unionAll(fn (Builder $query) => $query->select('id')->from('u'))
                    ->min('id'),
                'SELECT min(`id`) AS `aggregate` FROM (SELECT `id` FROM `t` ORDER BY `id` DESC LIMIT 1 UNION ALL SELECT `id` FROM `u`)',
            ],
            'except' => [
                fn (Builder $builder) => $builder->select('id')->from('t')->except(fn (Builder $query) => $query->select('id')->from('u'))->sum('id'),
                'SELECT sum(`id`) AS `aggregate` FROM (SELECT `id` FROM `t` EXCEPT SELECT `id` FROM `u`) WHERE NOT ignore(*)',
            ],
            'group by' => [
                fn (Builder $builder) => $builder->select('a')->from('t')->groupBy('a')->max('a'),
                'SELECT max(`a`) AS `aggregate` FROM (SELECT `a` FROM `t` GROUP BY `a`)',
            ],
            'an alias that a condition reads' => [
                fn (Builder $builder) => $builder->select('a as x')->from('t')->where('x', '>', 1)->max('x'),
                'SELECT max(`x`) AS `aggregate` FROM (SELECT `a` AS `x` FROM `t` WHERE `x` > 1)',
            ],
            'limit by keeps the order by that decides the kept rows' => [
                fn (Builder $builder) => $builder->select('id', 'grp')->from('t')->orderBy('id', 'desc')->limitBy(1, 'grp')->min('id'),
                'SELECT min(`id`) AS `aggregate` FROM (SELECT `id`, `grp` FROM `t` ORDER BY `id` DESC LIMIT 1 BY `grp`)',
            ],
            'order by and limit with an offset, in place' => [
                fn (Builder $builder) => $builder->from('t')->orderBy('id')->limit(1, 1)->min('id'),
                'SELECT min(`id`) AS `aggregate` FROM `t`',
            ],
            'settings on the outer query' => [
                fn (Builder $builder) => $builder
                    ->select('id')
                    ->from('t')
                    ->unionAll(fn (Builder $query) => $query->select('id')->from('u'))
                    ->settings(['max_threads' => 2])
                    ->max('id'),
                'SELECT max(`id`) AS `aggregate` FROM (SELECT `id` FROM `t` UNION ALL SELECT `id` FROM `u`) FORMAT JSON SETTINGS max_threads=2',
            ],
            'settings in place' => [
                fn (Builder $builder) => $builder->from('t')->where('a', 1)->settings(['max_threads' => 2])->avg('a'),
                'SELECT avg(`a`) AS `aggregate` FROM `t` WHERE `a` = 1 FORMAT JSON SETTINGS max_threads=2',
            ],
            'in place from an except sub-query' => [
                fn (Builder $builder) => $builder->from(self::setOperationOf($builder, 'except'))->max('n'),
                'SELECT max(`n`) AS `aggregate` FROM (SELECT `g`, `n` FROM `a` EXCEPT SELECT `g`, `n` FROM `b`) WHERE NOT ignore(*)',
            ],
            'raw order by with fill' => [
                fn (Builder $builder) => $builder->select('a')->from('t')->orderByRaw('a WITH FILL FROM 0 TO 3')->sum('a'),
                'SELECT sum(`a`) AS `aggregate` FROM (SELECT `a` FROM `t` ORDER BY a WITH FILL FROM 0 TO 3)',
            ],
            'with fill after a plain order by, which decides the groups that it fills' => [
                fn (Builder $builder) => $builder->select('grp', 'x')->from('wf')->orderBy('grp')->orderByRaw('x WITH FILL')->sum('x'),
                'SELECT sum(`x`) AS `aggregate` FROM (SELECT `grp`, `x` FROM `wf` ORDER BY `grp` ASC, x WITH FILL)',
            ],
            'raw limit by' => [
                fn (Builder $builder) => $builder->select('id', 'grp')->from('t')->orderByRaw('id DESC LIMIT 1 BY grp')->min('id'),
                'SELECT min(`id`) AS `aggregate` FROM (SELECT `id`, `grp` FROM `t` ORDER BY id DESC LIMIT 1 BY grp)',
            ],
            'raw limit after a plain order by, which decides the rows that it keeps' => [
                fn (Builder $builder) => $builder->select('id', 'a')->from('t')->orderBy('a')->orderByRaw('id DESC LIMIT 2')->sum('a'),
                'SELECT sum(`a`) AS `aggregate` FROM (SELECT `id`, `a` FROM `t` ORDER BY `a` ASC, id DESC LIMIT 2)',
            ],
        ];
    }

    /**
     * @param Closure(Builder): mixed $run
     */
    #[DataProvider('aggregateQueryProvider')]
    public function test_an_aggregate_reads_the_rows_that_the_query_returns(Closure $run, string $sql): void
    {
        $this->assertSame(5, $run($this->builderExpectingSelect($sql, [['aggregate' => 5]])));
    }

    public function test_an_aggregate_without_a_row_is_null_and_a_sum_without_a_row_is_zero(): void
    {
        $this->assertNull($this->builderExpectingSelect('SELECT avg(`a`) AS `aggregate` FROM `t`', [])->from('t')->avg('a'));
        $this->assertSame(0, $this->builderExpectingSelect('SELECT sum(`a`) AS `aggregate` FROM `t`', [])->from('t')->sum('a'));
        $this->assertNull($this->builderExpectingSelect('SELECT max(`a`) AS `aggregate` FROM `t`', [['aggregate' => null]])->from('t')->max('a'));
    }

    /**
     * @return array<string, array{Closure(Builder): Builder}>
     */
    public static function countAndExistsQueryProvider(): array
    {
        return [
            'plain, ordered' => [fn (Builder $builder): Builder => $builder->from('t')->where('a', '>', 0)->orderBy('id')],
            'grouped' => [fn (Builder $builder): Builder => $builder->select('grp')->from('t')->groupBy('grp')],
            'nested set operation with settings' => [fn (Builder $builder): Builder => $builder
                ->select('id')
                ->from('t')
                ->except(fn (Builder $query) => $query->select('id')->from('u')->unionAll(fn (Builder $inner) => $inner->select('id')->from('r')))
                ->settings(['max_threads' => 2])],
            'format and settings' => [fn (Builder $builder): Builder => $builder->from('t')->format('CSV')->settings(['max_threads' => 1])],
        ];
    }

    /**
     * @param Closure(Builder): Builder $query
     */
    #[DataProvider('countAndExistsQueryProvider')]
    public function test_get_query_for_count_and_for_exists_are_what_count_and_exists_send(Closure $query): void
    {
        [$sentSql] = $this->runRecorded($query, function (Builder $builder): void {
            $builder->count();
            $builder->exists();
        });

        $this->assertSame([
            $query((new Builder($this->mockClient)))->getQueryForCount()->getQueryToSend()->toSql(),
            $query((new Builder($this->mockClient)))->getQueryForExists()->getQueryToSend()->toSql(),
        ], $sentSql);
    }

    public function test_get_query_for_count_and_for_exists_compile_to_the_documented_sql(): void
    {
        $query = fn (): Builder => (new Builder($this->mockClient))->from('my_table')->where('field_two', '>', 0)->orderBy('created_at');

        $this->assertSame('SELECT count() as `count` FROM `my_table` WHERE `field_two` > 0', $query()->getQueryForCount()->toSql());
        $this->assertSame('SELECT 1 FROM `my_table` WHERE `field_two` > 0 LIMIT 1', $query()->getQueryForExists()->toSql());
        $this->assertSame(
            'SELECT count() AS `count` FROM (SELECT `kind` FROM `t` GROUP BY `kind`)',
            (new Builder($this->mockClient))->select('kind')->from('t')->groupBy('kind')->getQueryForCount()->toSql()
        );
    }

    /**
     * @return Client&\PHPUnit\Framework\MockObject\MockObject
     */
    private function clientWithoutSession(): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('getSession')->willReturn(false);

        return $client;
    }

    public function test_insert_files_quotes_each_column_and_takes_the_format_in_any_letter_case(): void
    {
        $file = $this->temporaryFile();
        $statement = $this->createStub(Statement::class);
        $client = $this->clientWithoutSession();
        $client->expects($this->once())
            ->method('insertBatchFiles')
            ->with('`my_table`', $file, ['`k`', '`we``ird`', '`back\\\\slash`'], 'CSVWithNames')
            ->willReturn([$file => $statement]);
        $builder = $this->builderFollowing($client, $this->connection());

        $this->assertSame(
            [$file => $statement],
            $builder->from('my_table')->insertFiles($file, 'csvwithnames', ['k', 'we`ird', 'back\\slash'])
        );
    }

    public function test_insert_files_without_columns_writes_to_the_sources_table(): void
    {
        $files = [$this->temporaryFile(), $this->temporaryFile()];
        $client = $this->clientWithoutSession();
        $client->expects($this->once())
            ->method('insertBatchFiles')
            ->with('db.events', $files, [], 'TabSeparated')
            ->willReturn([]);

        $this->builderFollowing($client, $this->connection())->from('ignored')->setSourcesTable('db.events')->insertFiles($files, 'tabseparated');
    }

    /**
     * smi2's Client::insertBatchFiles() checks each file while it queues them, so a missing file after a readable
     * one left the readable one queued on the client: the next insertFiles() threw 'Queue must be empty', and the
     * next executeAsync() inserted it. Every path is checked before smi2 is called.
     *
     * @return array<string, array{Closure(string): array<int, string>, Closure(string): string}>
     */
    public static function unreadableFileProvider(): array
    {
        return [
            'a missing file after a readable one' => [
                fn (string $readable): array => [$readable, $readable . '-missing.csv'],
                fn (string $readable): string => $readable . '-missing.csv',
            ],
            'a directory' => [
                fn (string $readable): array => [sys_get_temp_dir()],
                fn (string $readable): string => sys_get_temp_dir(),
            ],
            'a missing file given as a string' => [
                fn (string $readable): string => $readable . '-missing.csv',
                fn (string $readable): string => $readable . '-missing.csv',
            ],
        ];
    }

    /**
     * @param Closure(string): (array<int, string>|string) $paths
     * @param Closure(string): string $refused
     */
    #[DataProvider('unreadableFileProvider')]
    public function test_insert_files_refuses_a_path_that_is_not_a_readable_file_before_sending_anything(Closure $paths, Closure $refused): void
    {
        $readable = $this->temporaryFile();
        $client = $this->clientWithoutSession();
        $client->expects($this->never())->method('insertBatchFiles');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage("Cannot insert the file [{$refused($readable)}]: it is not a readable file. No file was sent.");

        $this->builderFollowing($client, $this->connection())->from('t')->insertFiles($paths($readable), 'CSV');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedFileFormatProvider(): array
    {
        return [
            'xml' => ['XML'],
            'values' => ['Values'],
            'a format with sql after it' => ['CSV; DROP TABLE t'],
        ];
    }

    #[DataProvider('unsupportedFileFormatProvider')]
    public function test_insert_files_refuses_a_format_that_smi2_cannot_send_before_sending_anything(string $format): void
    {
        $client = $this->clientWithoutSession();
        $client->expects($this->never())->method('insertBatchFiles');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("insertFiles() cannot send the format [{$format}]: use one of TabSeparated, TabSeparatedWithNames,");

        $this->builderFollowing($client, $this->connection())->from('t')->insertFiles('/a.csv', $format);
    }

    public function test_insert_files_refuses_a_client_with_a_session_before_sending_anything(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('getSession')->willReturn('a-session-id');
        $client->expects($this->never())->method('insertBatchFiles');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cannot insert files in a ClickHouse session');

        $this->builderFollowing($client, $this->connection())->from('t')->insertFiles('/a.csv');
    }

    public function test_insert_files_logs_each_statement_and_sends_nothing_while_the_connection_pretends(): void
    {
        $client = $this->clientWithoutSession();
        $client->expects($this->never())->method('insertBatchFiles');
        $connection = $this->connection();
        $builder = $this->builderFollowing($client, $connection)->from('t');

        $log = $connection->pretend(function () use ($builder): void {
            $statements = $builder->insertFiles(['/a.csv', '/b.csv'], 'CSV', ['k']);
            $this->assertSame(['/a.csv', '/b.csv'], array_keys($statements));
            $this->assertFalse($statements['/a.csv']->isError());
        });

        $this->assertSame(
            ['INSERT INTO `t` ( `k` ) FORMAT CSV', 'INSERT INTO `t` ( `k` ) FORMAT CSV'],
            array_column($log, 'query')
        );
    }
}
