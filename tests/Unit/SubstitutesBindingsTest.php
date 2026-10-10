<?php

declare(strict_types=1);

namespace Tests\Unit;

use Carbon\Carbon;
use ClickHouseDB\Query\Degeneration\Bindings;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Type\DateTime64;
use ClickHouseDB\Type\Float64;
use ClickHouseDB\Type\MapType;
use ClickHouseDB\Type\UInt64;
use DateTimeImmutable;
use ErrorException;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Expressions\InsertArray;
use Oralunal\LaravelClickHouse\Grammar;
use Oralunal\LaravelClickHouse\QueryGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\UnitEnumFixture;

/**
 * The SubstitutesBindings trait of the package Grammar and of QueryGrammar: a single scan that writes "?" bindings
 * into the SQL as ClickHouse literals, and the choice between that and smi2's own placeholders.
 */
class SubstitutesBindingsTest extends TestCase
{
    private function queryGrammar(): QueryGrammar
    {
        return new QueryGrammar($this->createStub(Connection::class));
    }

    /**
     * A "?" inside a string literal, a quoted identifier, a heredoc or a comment is not a placeholder, and a literal
     * never starts a comment together with the character before its placeholder.
     *
     * @return array<string, array{string, list<mixed>, string}>
     */
    public static function scanProvider(): array
    {
        return [
            'plain placeholders' => ['select ?, ?', [1, 'a'], "select 1, 'a'"],
            'string literal' => ["select '?' as q, ?", [1], "select '?' as q, 1"],
            'string literal with a doubled quote' => ["select 'it''s ?', ?", [1], "select 'it''s ?', 1"],
            'string literal with a backslash escape' => ["select 'it\\'s ?', ?", [1], "select 'it\\'s ?', 1"],
            'string literal ending with an escaped backslash' => ["select 'a\\\\', ?", [1], "select 'a\\\\', 1"],
            'double-quoted identifier' => ['select "q?" from t where x = ?', [1], 'select "q?" from t where x = 1'],
            'double-quoted identifier with a doubled quote' => ['select "a""?" , ?', [1], 'select "a""?" , 1'],
            'double-quoted identifier with a backslash escape' => ['select "a\\"?", ?', [1], 'select "a\\"?", 1'],
            'backtick identifier' => ['select `q?` from t where x = ?', [1], 'select `q?` from t where x = 1'],
            'backtick identifier with a doubled backtick' => ['select `a``?`, ?', [1], 'select `a``?`, 1'],
            'backtick identifier with a backslash escape' => ['select `a\\`?`, ?', [1], 'select `a\\`?`, 1'],
            'heredoc' => ['select $$a?b$$ as h, ?', [1], 'select $$a?b$$ as h, 1'],
            'tagged heredoc' => ['select $tag$it\'s ? $$ ?$tag$, ?', [1], 'select $tag$it\'s ? $$ ?$tag$, 1'],
            'heredoc with any tag text, as ClickHouse reads it' => ['select $a b$x?y$a b$, ?', [1], 'select $a b$x?y$a b$, 1'],
            'a $ after a word character is part of a name' => ['select a$$, ?, $$', [1], 'select a$$, 1, $$'],
            'unclosed heredoc' => ['select $$?', [1], 'select $$1'],
            'heredoc after a no-break space' => ["select\u{00A0}\$\$a?b\$\$, ?", [1], "select\u{00A0}\$\$a?b\$\$, 1"],
            'tagged heredoc after a no-break space' => ["select\u{00A0}\$t\$?\$t\$, ?", [1], "select\u{00A0}\$t\$?\$t\$, 1"],
            'line comment' => ["select ? -- why?\n, ?", [1, 2], "select 1 -- why?\n, 2"],
            'line comment at the end' => ['select ? -- why?', [1], 'select 1 -- why?'],
            'double-slash comment' => ["select ? // why?\n, ?", [1, 2], "select 1 // why?\n, 2"],
            'double-slash comment with an apostrophe' => ["select ? // it's\n, ?", [1, 2], "select 1 // it's\n, 2"],
            'hash comment' => ["select ? # why?\n, ?", [1, 2], "select 1 # why?\n, 2"],
            'hash-bang comment' => ["select ? #! why?\n, ?", [1, 2], "select 1 #! why?\n, 2"],
            'a hash without a space is no comment' => ['select ?#?', [1, 2], 'select 1#2'],
            'block comment' => ['select /* ? */ ?', [1], 'select /* ? */ 1'],
            'nested block comments' => ['select /* a /* ? */ ? */ ?', [1], 'select /* a /* ? */ ? */ 1'],
            'unclosed block comment' => ['select ? /* ?', [1], 'select 1 /* ?'],
            'a single dash or slash' => ['select ? - ? / ?', [3, 2, 1], 'select 3 - 2 / 1'],
            'a negative int after a minus sign is no comment' => ['select 10-?', [-5], 'select 10- -5'],
            'a negative float after a minus sign is no comment' => ['select 10-?', [-1.5], 'select 10- -1.5'],
            'negative infinity after a minus sign is no comment' => ['select 10-?', [-INF], 'select 10- -inf'],
            'negative zero after a unary minus is no comment' => ['select -?', [-0.0], 'select - -0.0'],
            'a negative int after a spaced minus sign is no comment' => ['select 10 -?, ?-?', [-5, -1, -2], 'select 10 - -5, -1- -2'],
            'raw SQL after a slash is no comment' => ['select 4/?', [new Expression('/* two */ 2')], 'select 4/ /* two */ 2'],
            'a positive int after a minus sign' => ['select 10-?', [5], 'select 10-5'],
            'a negative int after a minus sign and a space' => ['select 10 - ?', [-5], 'select 10 - -5'],
            'escaped question mark' => ['select ? > 1 ?? 10 : 20 as t', [5], 'select 5 > 1 ? 10 : 20 as t'],
            'escaped question marks only' => ['select 1 ?? 2 : 3', [], 'select 1 ? 2 : 3'],
            'smi2 and server placeholders are kept' => ['select :a, {b}, {c:UInt8}, x::Int64, ?', [1], 'select :a, {b}, {c:UInt8}, x::Int64, 1'],
            'values of every kind' => [
                'insert into t values (?, ?, ?, ?, ?, ?, ?)',
                [null, false, 1 / 3, ['p', 'q'], [], StringBackedEnumFixture::Active, "a FORMAT CSV"],
                "insert into t values (null, 0, 0.3333333333333333, ['p', 'q'], [], 'active', 'a FORMAT CSV')",
            ],
            'a literal with a question mark is not scanned again' => ['select ?, ?', ["it's ?", 2], "select 'it\\'s ?', 2"],
            'multibyte text' => ["select 'İ?', \"ş?\", ?", ['ğ'], "select 'İ?', \"ş?\", 'ğ'"],
        ];
    }

    /**
     * @param list<mixed> $bindings
     */
    #[DataProvider('scanProvider')]
    public function test_placeholders_are_found_outside_literals_identifiers_heredocs_and_comments(string $sql, array $bindings, string $expected): void
    {
        $this->assertSame($expected, (new Grammar())->substituteBindings($sql, $bindings));
        $this->assertSame($expected, $this->queryGrammar()->substituteBindings($sql, $bindings));
    }

    public function test_binding_keys_are_ignored(): void
    {
        $this->assertSame("select 1, 'a'", (new Grammar())->substituteBindings('select ?, ?', ['x' => 1, 5 => 'a']));
    }

    /**
     * @return array<string, array{string, list<mixed>, string}>
     */
    public static function countMismatchProvider(): array
    {
        return [
            'too many bindings' => ['select ? as a', [1, 2], 'The query has 1 "?" placeholder, but 2 bindings were given. Write a literal "?" as "??".'],
            'too few bindings' => ['select ?, ?', [1], 'The query has 2 "?" placeholders, but 1 binding was given. Write a literal "?" as "??".'],
            'no placeholder' => ["select '?'", [1], 'The query has 0 "?" placeholders, but 1 binding was given. Write a literal "?" as "??".'],
        ];
    }

    /**
     * @param list<mixed> $bindings
     */
    #[DataProvider('countMismatchProvider')]
    public function test_substitute_bindings_refuses_a_count_mismatch(string $sql, array $bindings, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Grammar())->substituteBindings($sql, $bindings);
    }

    public function test_substitute_bindings_into_raw_sql_is_lenient(): void
    {
        $grammar = $this->queryGrammar();

        $this->assertSame("select 1, ?, '?'", $grammar->substituteBindingsIntoRawSql("select ?, ?, '?'", [1]));
        $this->assertSame('select 1', $grammar->substituteBindingsIntoRawSql('select ?', [1, 2]));
        $this->assertSame('select ? ?', $grammar->substituteBindingsIntoRawSql('select ? ??', []));
        $this->assertSame("select * from \"t\" where \"name\" = 'it\\'s'", $grammar->substituteBindingsIntoRawSql('select * from "t" where "name" = ?', ['name' => "it's"]));
        $this->assertSame('select 0.1', (new Grammar())->substituteBindingsIntoRawSql('select ?', [0.1]));
        $this->assertSame('select 10- -3, ?', $grammar->substituteBindingsIntoRawSql('select 10-?, ?', [-3]));
    }

    public function test_laravel_expressions_are_written_as_their_sql(): void
    {
        $this->assertSame(
            'select count(), [1 + 1]',
            $this->queryGrammar()->substituteBindings('select ?, ?', [new LaravelExpression('count()'), [new LaravelExpression('1 + 1')]])
        );
    }

    public function test_an_unwritable_binding_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot render a value of type stdClass');

        $this->queryGrammar()->substituteBindings('select ?', [new stdClass()]);
    }

    public function test_a_query_without_bindings_is_sent_as_it_is(): void
    {
        $sql = "select 1 > 0 ? 10 : 20, :0, {1}, {p:UInt8}, '??' -- ??";

        $this->assertSame([$sql, []], $this->queryGrammar()->prepareQueryForClient($sql, []));
    }

    /**
     * "??" is a literal "?" with and without bindings, in either mode, so the query that is sent is the one that
     * substituteBindingsIntoRawSql() shows. ClickHouse reads no "??".
     *
     * @return array<string, array{string, array<int|string, mixed>, array{string, array<int|string, mixed>}}>
     */
    public static function escapedQuestionMarkProvider(): array
    {
        return [
            'no bindings' => ['select 1 ?? 2 : 3', [], ['select 1 ? 2 : 3', []]],
            'named bindings' => ['select :a ?? 2 : 3', ['a' => 1], ['select :a ? 2 : 3', ['a' => 1]]],
            'a list of bindings without "?"' => ['select :0 ?? 2 : 3', [1], ['select :0 ? 2 : 3', [1]]],
            'a list of bindings with "?"' => ['select ? ?? 2 : 3', [1], ['select 1 ? 2 : 3', []]],
            'inside a literal, a name, a heredoc or a comment' => [
                "select '??', `??`, \$\$??\$\$ -- ??",
                [],
                ["select '??', `??`, \$\$??\$\$ -- ??", []],
            ],
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array{string, array<int|string, mixed>} $expected
     */
    #[DataProvider('escapedQuestionMarkProvider')]
    public function test_a_double_question_mark_is_a_literal_question_mark_in_every_mode(string $sql, array $bindings, array $expected): void
    {
        $grammar = $this->queryGrammar();

        $this->assertSame($expected, $grammar->prepareQueryForClient($sql, $bindings));
        $this->assertSame($expected[0], $grammar->substituteBindingsIntoRawSql($sql, $bindings), 'the raw SQL shows the query that is sent');
    }

    public function test_a_list_of_bindings_is_written_into_the_sql(): void
    {
        $this->assertSame(
            ["select id from t where id = 2 and name = 'a:0' and \"c{0}\" = 1", []],
            $this->queryGrammar()->prepareQueryForClient("select id from t where id = ? and name = 'a:0' and \"c{0}\" = ?", [2, 1])
        );
    }

    public function test_type_casts_and_subcolumn_types_are_not_smi2_placeholders(): void
    {
        $this->assertSame(
            ['select x::Int64, data.a.:Int64, "c":0, `d`:0, \'e\':0, a:0 from t where id = 1', []],
            $this->queryGrammar()->prepareQueryForClient('select x::Int64, data.a.:Int64, "c":0, `d`:0, \'e\':0, a:0 from t where id = ?', [1])
        );
    }

    public function test_a_smi2_placeholder_whose_key_is_no_binding_key_is_kept(): void
    {
        $this->assertSame(
            ['select :name, {other}, :00, {5}, 1', []],
            $this->queryGrammar()->prepareQueryForClient('select :name, {other}, :00, {5}, ?', [1])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mixedPlaceholderProvider(): array
    {
        return [
            'named placeholder' => ['select ? as a, :0 as b'],
            'raw placeholder' => ['select ? as a, {0} as b'],
            'second binding key' => ['select ?, ?, :1'],
            'query parameter' => ['select ? as a, {p:UInt8} as b'],
            'query parameter with spaces and a complex type' => ['select ?, { p : Array(Nullable(String)) }, ?'],
            'smi2 placeholder next to a ternary operator, which must be written as "??" with "?" bindings' => ["select :0 > 1 ? 'a' : 'b'"],
            'smi2 placeholder after a no-break space' => ["select ?,\u{00A0}:0"],
        ];
    }

    #[DataProvider('mixedPlaceholderProvider')]
    public function test_mixing_question_marks_with_smi2_placeholders_or_query_parameters_throws(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The query mixes "?" placeholders with smi2 placeholders (:0, {0}) or query parameters ({name:Type}). Use "?" for every binding.'
        );

        $this->queryGrammar()->prepareQueryForClient($sql, substr_count($sql, '?') === 1 ? [1] : [1, 2]);
    }

    public function test_the_strict_count_applies_to_a_list_of_bindings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The query has 1 "?" placeholder, but 2 bindings were given.');

        $this->queryGrammar()->prepareQueryForClient('select ? as a', [1, 2]);
    }

    /**
     * Without "?" placeholders, or with named bindings, smi2 replaces its own placeholders as in 3.0.0, with the
     * bindings made safe first.
     *
     * @return array<string, array{string, array<int|string, mixed>, array<int|string, mixed>}>
     */
    public static function smi2ModeProvider(): array
    {
        $date = new DateTimeImmutable('2024-01-02 03:04:05.5');
        $uint = UInt64::fromString('18446744073709551615');

        return [
            'named bindings' => ['select :id, {tbl}', ['id' => 1, 'tbl' => 't'], ['id' => 1, 'tbl' => 't']],
            'a list of bindings without "?"' => ['select :0, {1}', [true, false], [1, 0]],
            'query parameters' => ['select {p:UInt8}', ['p' => 4], ['p' => 4]],
            'named bindings with "?" in the SQL' => ['select ? as t, :id', ['id' => 2], ['id' => 2]],
            'enums' => [
                'select :a, :b, :c',
                ['a' => StringBackedEnumFixture::Active, 'b' => IntBackedEnumFixture::High, 'c' => UnitEnumFixture::Hearts],
                ['a' => 'active', 'b' => 3, 'c' => 'Hearts'],
            ],
            'stringable objects' => ['select :s', ['s' => Str::of('0 or 1 = 1')], ['s' => '0 or 1 = 1']],
            'dates, smi2 types and null are kept' => ['select :d, :u, :n', ['d' => $date, 'u' => $uint, 'n' => null], ['d' => $date, 'u' => $uint, 'n' => null]],
            'infinities are kept, which smi2 writes as INF and -INF' => ['select :a, {b}', ['a' => INF, 'b' => -INF], ['a' => INF, 'b' => -INF]],
            'arrays are made safe element by element' => [
                'select 1 where x in (:ids)',
                ['ids' => ['k' => true, 'e' => StringBackedEnumFixture::Paused, 's' => Str::of('x'), 'n' => [false]]],
                ['ids' => ['k' => 1, 'e' => 'paused', 's' => 'x', 'n' => [0]]],
            ],
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     * @param array<int|string, mixed> $expected
     */
    #[DataProvider('smi2ModeProvider')]
    public function test_smi2_placeholders_are_left_to_smi2_with_safe_bindings(string $sql, array $bindings, array $expected): void
    {
        $this->assertSame([$sql, $expected], $this->queryGrammar()->prepareQueryForClient($sql, $bindings));
    }

    /**
     * Raw SQL and NaN reach smi2 as expressions that smi2 writes as the "?" placeholders write them, for :key and
     * {key} placeholders and in lists, with no PHP 8.5 warning for NaN. In 3.0.0, smi2 failed with an Error for an
     * Expression, an Identifier or DB::raw() at any placeholder, and for a smi2 Raw or an InsertArray at {key} or in
     * a list, and NaN raised the warning "unexpected NAN value was coerced to string".
     *
     * @return array<string, array{string, array<int|string, mixed>, string}>
     */
    public static function smi2RawSqlProvider(): array
    {
        return [
            'expression' => ['select :e, {e}', ['e' => new Expression('now()')], 'select now(), now()'],
            'identifier' => ['select :i, {i}', ['i' => new Identifier('db.col')], 'select `db`.`col`, `db`.`col`'],
            'laravel expression' => ['select :e, {e}', ['e' => new LaravelExpression('now()')], 'select now(), now()'],
            'smi2 raw expression' => ['select :e, {e}', ['e' => new Raw('now()')], 'select now(), now()'],
            'insert array' => ['select :a, {a}', ['a' => new InsertArray(['x', 'y'])], "select ['x','y'], ['x','y']"],
            'NaN' => ['select :f, {f}', ['f' => NAN], 'select nan, nan'],
            'infinities' => ['select :f, {g}', ['f' => INF, 'g' => -INF], 'select INF, -INF'],
            'a list' => [
                'select 1 where x in (:l) or y in ({l})',
                ['l' => [NAN, INF, new Expression('1 + 1'), 2]],
                'select 1 where x in (nan,INF,1 + 1,2) or y in (nan, INF, 1 + 1, 2)',
            ],
            'a list of bindings without "?"' => ['select :0, {1}', [new Expression('now()'), NAN], 'select now(), nan'],
        ];
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    #[DataProvider('smi2RawSqlProvider')]
    public function test_smi2_writes_raw_sql_and_nan_as_the_question_mark_placeholders_do(string $sql, array $bindings, string $expected): void
    {
        set_error_handler(function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            [$query, $clientBindings] = $this->queryGrammar()->prepareQueryForClient($sql, $bindings);
            $degeneration = new Bindings();
            $degeneration->bindParams($clientBindings);

            $this->assertSame($expected, $degeneration->process($query));
        } finally {
            restore_error_handler();
        }
    }

    public function test_a_laravel_expression_for_smi2_needs_the_grammar_of_a_connection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot write the Laravel database expression Illuminate\Database\Query\Expression without the grammar of a database connection.');

        (new Grammar())->prepareQueryForClient('select :e', ['e' => new LaravelExpression('now()')]);
    }

    /**
     * The "?" placeholders write the values of the smi2 client as smi2 wrote them for the ":0" placeholders of
     * 3.0.0's Laravel query builder: numbers and expressions without quotes.
     */
    public function test_values_of_the_smi2_client_are_written_as_smi2_writes_them(): void
    {
        $this->assertSame(
            ["select 18446744073709551615 + 0, 0.5, now(), nan, ['a','b'], map('k', 1), '2024-01-02 03:04:05.123'", []],
            $this->queryGrammar()->prepareQueryForClient('select ? + 0, ?, ?, ?, ?, ?, ?', [
                UInt64::fromString('18446744073709551615'),
                Float64::fromString('0.5'),
                new Raw('now()'),
                new Raw('nan'),
                new InsertArray(['a', 'b']),
                MapType::fromArray(['k' => 1]),
                DateTime64::fromString('2024-01-02 03:04:05.123'),
            ])
        );
    }

    public function test_a_carbon_binding_stays_a_date_for_smi2(): void
    {
        $date = Carbon::parse('2024-01-02 03:04:05');

        [, $bindings] = $this->queryGrammar()->prepareQueryForClient('select :d', ['d' => $date]);

        $this->assertSame($date, $bindings['d']);
    }

    public function test_many_placeholders_are_substituted_in_one_pass(): void
    {
        $count = 100_000;
        $sql = 'select * from t where id in (' . implode(', ', array_fill(0, $count, '?')) . ')';

        [$substituted, $bindings] = $this->queryGrammar()->prepareQueryForClient($sql, range(1, $count));

        $this->assertSame([], $bindings);
        $this->assertStringStartsWith('select * from t where id in (1, 2, 3, ', $substituted);
        $this->assertStringEndsWith(', 99999, 100000)', $substituted);
        $this->assertSame($count - 1, substr_count($substituted, ', '));
    }
}
