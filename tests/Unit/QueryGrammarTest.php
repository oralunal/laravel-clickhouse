<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Connection as LaravelConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryBuilder;
use Oralunal\LaravelClickHouse\QueryGrammar;
use Oralunal\LaravelClickHouse\QueryProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SQL that the grammar of Laravel's query builder writes on a ClickHouse connection (fix_default_query_builder
 * false): placeholders, identifiers, random order, unions, like, null-safe and bitwise conditions, date conditions,
 * and the settings of timeout(), forceIndex() and ignoreIndex().
 */
class QueryGrammarTest extends TestCase
{
    /**
     * A query builder of the package on a stub ClickHouse connection with the given config, which hands out the
     * grammar's literal writer as Connection::newBuilderGrammar() does.
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
        $grammar = new QueryGrammar($connection);
        $connection->method('getQueryGrammar')->willReturn($grammar);

        return (new QueryBuilder($connection, $grammar, new QueryProcessor()))->from('t');
    }

    public function test_prepare_parameters_leaves_sql_unchanged_when_no_marker(): void
    {
        $result = QueryGrammar::prepareParameters('SELECT 1');

        $this->assertSame('SELECT 1', $result);
    }

    public function test_prepare_parameters_leaves_question_mark_placeholders_alone(): void
    {
        $sql = 'select * from "t" where "a" = ? and "b" = ?';

        $this->assertSame($sql, QueryGrammar::prepareParameters($sql));
    }

    public function test_prepare_parameters_replaces_single_marker(): void
    {
        $sql = 'SELECT * FROM t WHERE id = ' . QueryGrammar::PARAMETER_SIGN;

        $result = QueryGrammar::prepareParameters($sql);

        $this->assertSame('SELECT * FROM t WHERE id = :0', $result);
    }

    public function test_prepare_parameters_replaces_multiple_markers_sequentially(): void
    {
        $sign = QueryGrammar::PARAMETER_SIGN;
        $sql = "SELECT * FROM t WHERE a = $sign AND b = $sign AND c = $sign";

        $result = QueryGrammar::prepareParameters($sql);

        $this->assertSame('SELECT * FROM t WHERE a = :0 AND b = :1 AND c = :2', $result);
    }

    public function test_parameter_returns_a_question_mark_for_a_plain_value(): void
    {
        $connection = $this->createMock(LaravelConnection::class);
        $grammar = new QueryGrammar($connection);

        $result = $grammar->parameter('foo');

        $this->assertSame('?', $result);
    }

    public function test_parameter_returns_raw_value_for_expression(): void
    {
        $connection = $this->createMock(LaravelConnection::class);
        $grammar = new QueryGrammar($connection);
        $expression = new Expression('RAW_SQL');

        $result = $grammar->parameter($expression);

        $this->assertSame('RAW_SQL', $result);
    }

    public function test_a_query_shows_question_mark_placeholders(): void
    {
        $query = $this->query()->where('a', 1)->whereIn('b', [2, 3])->whereBetween('c', [4, 5]);

        $this->assertSame('select * from "t" where "a" = ? and "b" in (?, ?) and "c" between ? and ?', $query->toSql());
        $this->assertSame([1, 2, 3, 4, 5], $query->getBindings());
    }

    public function test_an_unseeded_random_order_is_rand(): void
    {
        $grammar = $this->query()->getGrammar();

        $this->assertSame('rand()', $grammar->compileRandom(''));
        $this->assertSame('rand()', $grammar->compileRandom(null));
        $this->assertSame('select * from "t" order by rand()', $this->query()->inRandomOrder()->toSql());
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function seedProvider(): array
    {
        return [
            'an int' => [42],
            'zero' => [0],
            'a string' => ['abc'],
        ];
    }

    #[DataProvider('seedProvider')]
    public function test_a_seeded_random_order_throws(int|string $seed): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ClickHouse has no seeded random order: rand() ignores its argument. For an order that a seed reproduces,'
            . " order by a hash of a key column, for example orderByRaw('cityHash64(?, id)', [\$seed])."
        );

        $this->query()->inRandomOrder($seed);
    }

    /**
     * ClickHouse reads backslash escapes inside double-quoted identifiers, so a backslash is doubled, as is a
     * double quote; names without either are written as before.
     *
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'a plain name' => [fn (QueryBuilder $query) => $query->select('name'), 'select "name" from "t"'],
            'a backslash' => [fn (QueryBuilder $query) => $query->select('na\\me'), 'select "na\\\\me" from "t"'],
            'a trailing backslash' => [fn (QueryBuilder $query) => $query->select('name\\'), 'select "name\\\\" from "t"'],
            'a double quote' => [fn (QueryBuilder $query) => $query->select('a"b'), 'select "a""b" from "t"'],
            'a backslash before a double quote' => [fn (QueryBuilder $query) => $query->select('a\\"b'), 'select "a\\\\""b" from "t"'],
            'every column' => [fn (QueryBuilder $query) => $query->select('*'), 'select * from "t"'],
            'a qualified name' => [fn (QueryBuilder $query) => $query->select('db.t\\x.c'), 'select "db"."t\\\\x"."c" from "t"'],
            'an alias' => [fn (QueryBuilder $query) => $query->select('a as b\\c'), 'select "a" as "b\\\\c" from "t"'],
            'a table' => [fn (QueryBuilder $query) => $query->from('db\\x.t"y'), 'select * from "db\\\\x"."t""y"'],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     */
    #[DataProvider('identifierProvider')]
    public function test_identifiers_escape_backslashes_and_double_quotes(Closure $build, string $sql): void
    {
        $this->assertSame($sql, $build($this->query())->toSql());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unionConfigProvider(): array
    {
        return [
            'no settings' => [[], ' union distinct '],
            'settings without union_default_mode' => [['settings' => ['max_threads' => 2]], ' union distinct '],
            'an empty union_default_mode' => [['settings' => ['union_default_mode' => '']], ' union distinct '],
            'union_default_mode DISTINCT' => [['settings' => ['union_default_mode' => 'DISTINCT']], ' union '],
            'union_default_mode ALL' => [['settings' => ['union_default_mode' => 'ALL']], ' union '],
        ];
    }

    /**
     * ClickHouse rejects a bare union unless union_default_mode is set, so union() sends union distinct, as Laravel
     * documents, and keeps a bare union when the connection's settings set the mode.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('unionConfigProvider')]
    public function test_union_is_union_distinct_unless_the_connection_sets_union_default_mode(array $config, string $conjunction): void
    {
        $query = $this->query($config)->select('id')->where('id', 1);
        $query->union($this->query($config)->select('id')->where('id', 2));

        $this->assertSame(
            '(select "id" from "t" where "id" = ?)' . $conjunction . '(select "id" from "t" where "id" = ?)',
            $query->toSql()
        );
    }

    public function test_union_all_is_unchanged(): void
    {
        $query = $this->query()->select('id')->unionAll($this->query()->select('id'));

        $this->assertSame('(select "id" from "t") union all (select "id" from "t")', $query->toSql());
    }

    /**
     * ClickHouse rejects an ORDER BY, a LIMIT or an OFFSET after the last parenthesized query of a union, so they
     * follow the whole union, which is selected from.
     *
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string}>
     */
    public static function unionClauseProvider(): array
    {
        $union = '(select "id" from "t" where "id" = ?) union all (select "id" from "t" where "id" > ?)';

        return [
            'order, limit and offset' => [
                fn (QueryBuilder $query) => $query->orderBy('id', 'desc')->limit(2)->offset(1),
                "select * from ({$union}) order by \"id\" desc limit 2 offset 1",
            ],
            'an order' => [fn (QueryBuilder $query) => $query->orderByRaw('id + ?', [1]), "select * from ({$union}) order by id + ?"],
            'a limit, as first() sets it' => [fn (QueryBuilder $query) => $query->limit(1), "select * from ({$union}) limit 1"],
            'a page' => [fn (QueryBuilder $query) => $query->forPage(3, 10), "select * from ({$union}) limit 10 offset 20"],
            'no clause' => [fn (QueryBuilder $query) => $query, $union],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $clauses
     */
    #[DataProvider('unionClauseProvider')]
    public function test_the_order_limit_and_offset_of_a_union_follow_the_whole_union(Closure $clauses, string $sql): void
    {
        $query = $this->query()->select('id')->where('id', 1)->unionAll($this->query()->select('id')->where('id', '>', 2));

        $this->assertSame($sql, $clauses($query)->toSql());
    }

    public function test_a_union_query_keeps_its_order_while_its_clauses_are_moved(): void
    {
        $query = $this->query()->select('id')->unionAll($this->query()->select('id'))->orderBy('id')->limit(5);

        $query->toSql();

        $this->assertSame([['column' => 'id', 'direction' => 'asc']], $query->unionOrders);
        $this->assertSame(5, $query->unionLimit);
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string}>
     */
    public static function likeProvider(): array
    {
        return [
            'whereLike()' => [fn (QueryBuilder $query) => $query->whereLike('name', 'A%'), 'select * from "t" where "name" ilike ?'],
            'whereLike() case-sensitive' => [
                fn (QueryBuilder $query) => $query->whereLike('name', 'A%', caseSensitive: true),
                'select * from "t" where "name" like ?',
            ],
            'whereNotLike()' => [fn (QueryBuilder $query) => $query->whereNotLike('name', 'A%'), 'select * from "t" where "name" not ilike ?'],
            'whereNotLike() case-sensitive' => [
                fn (QueryBuilder $query) => $query->whereNotLike('name', 'A%', caseSensitive: true),
                'select * from "t" where "name" not like ?',
            ],
            'orWhereLike()' => [
                fn (QueryBuilder $query) => $query->where('id', 1)->orWhereLike('name', 'A%'),
                'select * from "t" where "id" = ? or "name" ilike ?',
            ],
            'the like operator is unchanged' => [fn (QueryBuilder $query) => $query->where('name', 'like', 'A%'), 'select * from "t" where "name" like ?'],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     */
    #[DataProvider('likeProvider')]
    public function test_where_like_ignores_case_unless_it_is_case_sensitive(Closure $build, string $sql): void
    {
        $this->assertSame($sql, $build($this->query())->toSql());
    }

    /**
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string, list<mixed>}>
     */
    public static function nullSafeEqualsProvider(): array
    {
        return [
            'null' => [fn (QueryBuilder $query) => $query->whereNullSafeEquals('note', null), 'select * from "t" where ["note"] = [?]', [null]],
            'a scalar' => [fn (QueryBuilder $query) => $query->whereNullSafeEquals('note', 'n2'), 'select * from "t" where "note" = ?', ['n2']],
            'an expression' => [
                fn (QueryBuilder $query) => $query->whereNullSafeEquals('note', new Expression('upper(name)')),
                'select * from "t" where ["note"] = [upper(name)]',
                [],
            ],
            'orWhereNullSafeEquals()' => [
                fn (QueryBuilder $query) => $query->where('id', 1)->orWhereNullSafeEquals('note', null),
                'select * from "t" where "id" = ? or ["note"] = [?]',
                [1, null],
            ],
            'the <=> operator' => [fn (QueryBuilder $query) => $query->where('note', '<=>', 'x'), 'select * from "t" where "note" = ?', ['x']],
            'the <=> operator with null' => [fn (QueryBuilder $query) => $query->where('note', '<=>', null), 'select * from "t" where "note" is null', []],
            'having with <=>' => [
                fn (QueryBuilder $query) => $query->select('note')->groupBy('note')->having('note', '<=>', new Expression('NULL')),
                'select "note" from "t" group by "note" having ["note"] = [NULL]',
                [],
            ],
        ];
    }

    /**
     * ClickHouse has <=> and IS NOT DISTINCT FROM only in the ON section of a JOIN.
     *
     * @param Closure(QueryBuilder): QueryBuilder $build
     * @param list<mixed> $bindings
     */
    #[DataProvider('nullSafeEqualsProvider')]
    public function test_null_safe_equality_compares_arrays_for_null_and_expressions(Closure $build, string $sql, array $bindings): void
    {
        $query = $build($this->query());

        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
    }

    public function test_a_basic_where_with_the_null_safe_operator_is_null_safe(): void
    {
        $query = $this->query();
        $query->wheres[] = ['type' => 'Basic', 'column' => 'note', 'operator' => '<=>', 'value' => null, 'boolean' => 'and'];
        $query->addBinding(null);

        $this->assertSame('select * from "t" where ["note"] = [?]', $query->toSql());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bitwiseProvider(): array
    {
        return [
            '&' => ['&', 'bitAnd("flags", ?) != 0'],
            '|' => ['|', 'bitOr("flags", ?) != 0'],
            '^' => ['^', 'bitXor("flags", ?) != 0'],
            '<<' => ['<<', 'bitShiftLeft("flags", ?) != 0'],
            '>>' => ['>>', 'bitShiftRight("flags", ?) != 0'],
            '&~' => ['&~', 'bitAnd("flags", ?) != "flags"'],
        ];
    }

    #[DataProvider('bitwiseProvider')]
    public function test_a_bitwise_where_is_a_bit_function(string $operator, string $condition): void
    {
        $query = $this->query()->where('flags', $operator, 4);

        $this->assertSame('select * from "t" where ' . $condition, $query->toSql());
        $this->assertSame([4], $query->getBindings());
    }

    #[DataProvider('bitwiseProvider')]
    public function test_a_bitwise_having_is_a_bit_function(string $operator, string $condition): void
    {
        $query = $this->query()->select('flags')->groupBy('flags')->having('flags', $operator, 4);

        $this->assertSame('select "flags" from "t" group by "flags" having ' . $condition, $query->toSql());
        $this->assertSame([4], $query->getBindings());
    }

    public function test_a_bitwise_condition_on_an_expression_writes_the_expression(): void
    {
        $query = $this->query()->where(new Expression('flags + 1'), '&', new Expression('2'));

        $this->assertSame('select * from "t" where bitAnd(flags + 1, 2) != 0', $query->toSql());
    }

    public function test_an_unknown_bitwise_operator_throws(): void
    {
        $query = $this->query();
        $query->wheres[] = ['type' => 'Bitwise', 'column' => 'flags', 'operator' => '~', 'value' => 1, 'boolean' => 'and'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The bitwise operator [~] is not supported: use &, |, ^, <<, >> or &~.');

        $query->toSql();
    }

    /**
     * The date conditions call ClickHouse functions of the column, as the package builder does. QueryBuilder writes
     * numeric day, month and year values as numbers, integers for whole numbers, and pads a time to HH:MM:SS.
     *
     * @return array<string, array{Closure(QueryBuilder): QueryBuilder, string, list<mixed>}>
     */
    public static function dateConditionProvider(): array
    {
        return [
            'whereDate() with a string' => [
                fn (QueryBuilder $query) => $query->whereDate('created_at', '2024-01-05'),
                'toDate32("created_at") = ?',
                ['2024-01-05'],
            ],
            'whereDate() with a Carbon in its own time zone' => [
                fn (QueryBuilder $query) => $query->whereDate('created_at', '>=', Carbon::parse('2024-02-10 23:30:00', 'Asia/Tokyo')),
                'toDate32("created_at") >= ?',
                ['2024-02-10'],
            ],
            'whereDate() with an expression' => [
                fn (QueryBuilder $query) => $query->whereDate('created_at', new Expression('today()')),
                'toDate32("created_at") = today()',
                [],
            ],
            'orWhereDate()' => [
                fn (QueryBuilder $query) => $query->where('id', 1)->orWhereDate('created_at', '<', '2024-01-01'),
                '"id" = ? or toDate32("created_at") < ?',
                [1, '2024-01-01'],
            ],
            'whereTime() with H:i' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '>=', '10:00'),
                "formatDateTime(\"created_at\", '%H:%i:%S') >= ?",
                ['10:00:00'],
            ],
            'whereTime() with a one-digit hour' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '9:05'),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['09:05:00'],
            ],
            'whereTime() with a one-digit hour and minute' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '7:8'),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['07:08:00'],
            ],
            'whereTime() with a one-digit second and a fraction' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '>', '9:05:3.5'),
                "formatDateTime(\"created_at\", '%H:%i:%S') > ?",
                ['09:05:03'],
            ],
            'whereTime() with H:i:s and a fraction' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '<', '23:59:59.999'),
                "formatDateTime(\"created_at\", '%H:%i:%S') < ?",
                ['23:59:59'],
            ],
            'whereTime() with three digits for the minutes' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', '10:100'),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['10:100'],
            ],
            'whereTime() with a Stringable' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', Str::of('7:30')),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['07:30:00'],
            ],
            'whereTime() with a Carbon' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', Carbon::parse('2024-01-01 03:04:05')),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['03:04:05'],
            ],
            'whereTime() with any other text' => [
                fn (QueryBuilder $query) => $query->whereTime('created_at', 'noon'),
                "formatDateTime(\"created_at\", '%H:%i:%S') = ?",
                ['noon'],
            ],
            'whereDay() with an int' => [fn (QueryBuilder $query) => $query->whereDay('created_at', 5), 'toDayOfMonth("created_at") = ?', [5]],
            'whereDay() with a padded string' => [fn (QueryBuilder $query) => $query->whereDay('created_at', '05'), 'toDayOfMonth("created_at") = ?', [5]],
            'whereDay() with a Carbon' => [
                fn (QueryBuilder $query) => $query->whereDay('created_at', Carbon::parse('2024-03-07')),
                'toDayOfMonth("created_at") = ?',
                [7],
            ],
            'whereDay() with an expression' => [
                fn (QueryBuilder $query) => $query->whereDay('created_at', new Expression('toDayOfMonth(now())')),
                'toDayOfMonth("created_at") = toDayOfMonth(now())',
                [],
            ],
            'whereMonth()' => [fn (QueryBuilder $query) => $query->whereMonth('created_at', '>', '01'), 'toMonth("created_at") > ?', [1]],
            'whereYear() with an int' => [fn (QueryBuilder $query) => $query->whereYear('created_at', '>=', 2025), 'toYear("created_at") >= ?', [2025]],
            'whereYear() with a string' => [fn (QueryBuilder $query) => $query->whereYear('created_at', '2024'), 'toYear("created_at") = ?', [2024]],
            'whereYear() with a whole float' => [fn (QueryBuilder $query) => $query->whereYear('created_at', 2024.0), 'toYear("created_at") = ?', [2024]],
            'whereYear() with a whole number with a fraction part' => [
                fn (QueryBuilder $query) => $query->whereYear('created_at', '2024.0'),
                'toYear("created_at") = ?',
                [2024],
            ],
            'whereYear() with a fraction is not truncated' => [
                fn (QueryBuilder $query) => $query->whereYear('created_at', 2024.5),
                'toYear("created_at") = ?',
                [2024.5],
            ],
            'whereYear() with a string with a fraction is not truncated' => [
                fn (QueryBuilder $query) => $query->whereYear('created_at', '<', '2024.5'),
                'toYear("created_at") < ?',
                [2024.5],
            ],
            'whereYear() with null is bound as Laravel binds it' => [
                fn (QueryBuilder $query) => $query->whereYear('created_at', null),
                'toYear("created_at") = ?',
                [null],
            ],
            'whereDay() with a fraction, which Laravel writes as a padded whole number' => [
                fn (QueryBuilder $query) => $query->whereDay('created_at', 5.5),
                'toDayOfMonth("created_at") = ?',
                [5],
            ],
            'whereYear() with a Carbon' => [
                fn (QueryBuilder $query) => $query->whereYear('created_at', Carbon::parse('2024-06-01')),
                'toYear("created_at") = ?',
                [2024],
            ],
        ];
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $build
     * @param list<mixed> $bindings
     */
    #[DataProvider('dateConditionProvider')]
    public function test_date_conditions_call_click_house_functions(Closure $build, string $condition, array $bindings): void
    {
        $query = $build($this->query());

        $this->assertSame('select * from "t" where ' . $condition, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
    }

    /**
     * Laravel's own builder, such as the JoinClause of a join closure, compiles the same functions, but binds the
     * zero-padded text Laravel writes for a day or a month.
     */
    public function test_laravels_own_builder_compiles_the_functions_with_laravels_values(): void
    {
        $grammar = $this->query()->getGrammar();
        $query = (new Builder($this->query()->getConnection(), $grammar, new QueryProcessor()))->from('t')
            ->whereDay('created_at', 5)
            ->whereTime('created_at', '9:05');

        $this->assertSame(
            "select * from \"t\" where toDayOfMonth(\"created_at\") = ? and formatDateTime(\"created_at\", '%H:%i:%S') = ?",
            $query->toSql()
        );
        $this->assertSame(['05', '9:05'], $query->getBindings());
    }

    /**
     * timeout(), forceIndex() and ignoreIndex() become SETTINGS of the outermost query; a sub-query gets none.
     *
     * @return array<string, array{Closure(Closure(): QueryBuilder): string, string}>
     */
    public static function querySettingsProvider(): array
    {
        return [
            'get() with a timeout' => [
                fn (Closure $query) => $query()->where('id', 1)->timeout(5)->toSql(),
                'select * from "t" where "id" = ? settings max_execution_time = 5',
            ],
            'forceIndex()' => [
                fn (Closure $query) => $query()->where('name', 'c')->forceIndex('idx_name')->toSql(),
                "select * from \"t\" where \"name\" = ? settings force_data_skipping_indices = 'idx_name'",
            ],
            'ignoreIndex() of two indexes' => [
                fn (Closure $query) => $query()->ignoreIndex('idx_a,idx_b')->toSql(),
                "select * from \"t\" settings ignore_data_skipping_indices = 'idx_a,idx_b'",
            ],
            'a timeout and an index' => [
                fn (Closure $query) => $query()->forceIndex("idx'x")->timeout(2)->toSql(),
                "select * from \"t\" settings max_execution_time = 2, force_data_skipping_indices = 'idx\\'x'",
            ],
            'exists()' => [
                fn (Closure $query) => $query()->where('id', 1)->timeout(5)->getGrammar()->compileExists($query()->where('id', 1)->timeout(5)),
                'select exists(select * from "t" where "id" = ?) as "exists" settings max_execution_time = 5',
            ],
            'a union, selected from' => [
                fn (Closure $query) => $query()->select('id')->unionAll($query()->select('id'))->timeout(5)->toSql(),
                'select * from ((select "id" from "t") union all (select "id" from "t")) settings max_execution_time = 5',
            ],
            'a union with an order' => [
                fn (Closure $query) => $query()->select('id')->union($query()->select('id'))->orderBy('id')->timeout(5)->toSql(),
                'select * from ((select "id" from "t") union distinct (select "id" from "t")) order by "id" asc settings max_execution_time = 5',
            ],
            'a union part with a timeout of its own gets no settings' => [
                fn (Closure $query) => $query()->select('id')->unionAll($query()->select('id')->timeout(9))->toSql(),
                '(select "id" from "t") union all (select "id" from "t")',
            ],
            'an exists sub-query with a timeout of its own gets no settings' => [
                fn (Closure $query) => $query()->whereExists(fn (Builder $sub) => $sub->from('u')->timeout(9))->timeout(3)->toSql(),
                'select * from "t" where exists (select * from "u") settings max_execution_time = 3',
            ],
            'a group limit' => [
                fn (Closure $query) => $query()->orderBy('id')->groupLimit(1, 'flag')->timeout(3)->toSql(),
                'select * from (select *, row_number() over (partition by "flag" order by "id" asc) as "laravel_row" from "t")'
                . ' as "laravel_table" where "laravel_row" <= 1 order by "laravel_row" settings max_execution_time = 3',
            ],
            'a mutation gets none' => [
                fn (Closure $query) => ($q = $query()->where('id', 1)->timeout(3)->forceIndex('idx'))->getGrammar()->compileDelete($q),
                'alter table "t" delete where "id" = ?',
            ],
        ];
    }

    /**
     * Every query of a data set comes from the same connection, so they share its grammar, as the queries of a
     * union or a sub-query do.
     *
     * @param Closure(Closure(): QueryBuilder): string $compile
     */
    #[DataProvider('querySettingsProvider')]
    public function test_settings_of_the_query_go_on_the_outermost_query(Closure $compile, string $sql): void
    {
        $first = $this->query();

        $this->assertSame($sql, $compile(fn (): QueryBuilder => $first->newQuery()->from('t')));
    }

    /**
     * A count of a union runs Laravel's union aggregate, which selects from the union.
     */
    public function test_the_count_of_a_union_gets_the_settings_after_the_aggregate(): void
    {
        $query = $this->query()->select('id')->union($this->query()->select('id'))->timeout(4);
        $query->aggregate = ['function' => 'count', 'columns' => ['*']];

        $this->assertSame(
            'select count(*) as "aggregate" from ((select "id" from "t") union distinct (select "id" from "t")) as "temp_table"'
            . ' settings max_execution_time = 4',
            $query->toSql()
        );
    }

    public function test_use_index_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'ClickHouse chooses data-skipping indexes itself: use forceIndex() to require one or ignoreIndex() to skip one.'
        );

        $this->query()->useIndex('idx_name')->toSql();
    }

    public function test_the_query_depth_is_restored_after_an_exception(): void
    {
        try {
            $this->query()->useIndex('idx_name')->toSql();
        } catch (InvalidArgumentException) {
        }

        $query = $this->query()->timeout(2);
        $grammar = $query->getGrammar();
        try {
            $grammar->compileSelect((clone $query)->useIndex('idx'));
            $this->fail('useIndex() should throw');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame('select * from "t" settings max_execution_time = 2', $grammar->compileSelect($query));
    }
}
