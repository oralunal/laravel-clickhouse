<?php

declare(strict_types=1);

namespace Tests\Unit\ClickhouseBuilder;

use ArrayIterator;
use Carbon\Carbon;
use ClickHouseDB\Query\Expression\Func\UUIDStringToNum;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Type\Boolean;
use ClickHouseDB\Type\Date;
use ClickHouseDB\Type\DateTime64;
use ClickHouseDB\Type\Decimal;
use ClickHouseDB\Type\Float64;
use ClickHouseDB\Type\Int64;
use ClickHouseDB\Type\IPv4;
use ClickHouseDB\Type\MapType;
use ClickHouseDB\Type\StringType;
use ClickHouseDB\Type\TupleType;
use ClickHouseDB\Type\UInt64;
use ClickHouseDB\Type\UUID as ClientUuid;
use DateTimeImmutable;
use DateTimeZone;
use ErrorException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Expressions\InsertArray;
use Oralunal\LaravelClickHouse\Grammar as PackageGrammar;
use Oralunal\LaravelClickHouse\QueryGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use stdClass;

/**
 * The one writer of ClickHouse literals: Grammar::compileLiteral(), which wrap() uses for values, and the rows that
 * the package Grammar prepares for smi2's VALUES inserts.
 */
class GrammarLiteralTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function literalProvider(): array
    {
        return [
            'null' => [null, 'null'],
            'true' => [true, '1'],
            'false' => [false, '0'],
            'int' => [42, '42'],
            'negative int' => [-7, '-7'],
            'largest int' => [PHP_INT_MAX, '9223372036854775807'],
            'smallest int' => [PHP_INT_MIN, '-9223372036854775808'],
            'string' => ['text', "'text'"],
            'numeric string' => ['10', "'10'"],
            'empty string' => ['', "''"],
            'string backed enum' => [StringBackedEnumFixture::Active, "'active'"],
            'int backed enum' => [IntBackedEnumFixture::High, '3'],
            'pure enum' => [UnitEnumFixture::Hearts, "'Hearts'"],
            'expression' => [new Expression('now()'), 'now()'],
            'identifier' => [new Identifier('db.col'), '`db`.`col`'],
            'stringable' => [Str::of("it's"), "'it\\'s'"],
            'uuid' => [Uuid::fromString('0f1e2d3c-4b5a-4968-8776-655443322110'), "'0f1e2d3c-4b5a-4968-8776-655443322110'"],
            'carbon' => [Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'), "'2024-01-02 03:04:05'"],
            'empty array' => [[], '[]'],
            'array' => [[1, 'a', null, true], "[1, 'a', null, 1]"],
            'nested arrays' => [[[1, 2], [], [['x']]], "[[1, 2], [], [['x']]]"],
            'array keys are ignored' => [['a' => 1, 'b' => [5 => 'x']], "[1, ['x']]"],
            'array of floats' => [[0.1, 1 / 3, INF, NAN], '[0.1, 0.3333333333333333, inf, nan]'],
            'array of enums and dates' => [
                [StringBackedEnumFixture::Paused, new DateTimeImmutable('2024-01-02 03:04:05')],
                "['paused', '2024-01-02 03:04:05']",
            ],
        ];
    }

    #[DataProvider('literalProvider')]
    public function test_compile_literal_writes_each_kind_of_value(mixed $value, string $expected): void
    {
        $this->assertSame($expected, (new Grammar())->compileLiteral($value));
    }

    /**
     * The value objects and expressions of the smi2 client are written in the form that smi2 writes into a query:
     * numbers and expressions without quotes, so that 3.0.0's queries with them keep working. The values inside a
     * MapType or a TupleType are written by compileLiteral(), and a number type whose value is no number, which
     * smi2 does not check for UInt64, Int64, Decimal and Boolean, becomes a string literal. Every other type is a
     * quoted string, also UUID, IPv4 and DateTime64, which smi2 wrote without quotes, so that ClickHouse failed.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function clientValueProvider(): array
    {
        return [
            'UInt64' => [UInt64::fromString('18446744073709551615'), '18446744073709551615'],
            'negative Int64' => [Int64::fromString('-9223372036854775808'), '-9223372036854775808'],
            'Float64' => [Float64::fromString('0.5'), '0.5'],
            'Float64 with an exponent' => [Float64::fromString('1.0E+25'), '1.0E+25'],
            'Decimal' => [Decimal::fromString('12.50'), '12.50'],
            'UInt64 whose value is no number' => [UInt64::fromString('1 OR 1 = 1'), "'1 OR 1 = 1'"],
            'Decimal whose value has a comma' => [Decimal::fromString('1,5'), "'1,5'"],
            'Boolean from a bool' => [Boolean::fromBool(true), '1'],
            'Boolean from a word' => [Boolean::fromString('false'), 'false'],
            'Boolean whose value is no boolean' => [Boolean::fromString('1) OR (1'), "'1) OR (1'"],
            'MapType' => [
                MapType::fromArray(['a' => 1, 'b' => 1 / 3, 5 => null, "it's" => [false, 'x']]),
                "map('a', 1, 'b', 0.3333333333333333, 5, null, 'it\\'s', [0, 'x'])",
            ],
            'empty MapType' => [MapType::fromArray([]), 'map()'],
            'TupleType' => [TupleType::fromArray([1, "it's", null, true, 0.1, NAN]), "(1, 'it\\'s', null, 1, 0.1, nan)"],
            'StringType' => [StringType::fromString("it's"), "'it\\'s'"],
            'Date' => [Date::fromString('2024-01-02'), "'2024-01-02'"],
            'DateTime64' => [DateTime64::fromString('2024-01-02 03:04:05.123'), "'2024-01-02 03:04:05.123'"],
            'UUID' => [ClientUuid::fromString('0f1e2d3c-4b5a-4968-8776-655443322110'), "'0f1e2d3c-4b5a-4968-8776-655443322110'"],
            'IPv4' => [IPv4::fromString('127.0.0.1'), "'127.0.0.1'"],
            'Raw expression' => [new Raw('now()'), 'now()'],
            'Raw nan' => [new Raw('nan'), 'nan'],
            'UUIDStringToNum expression' => [
                new UUIDStringToNum('0f1e2d3c-4b5a-4968-8776-655443322110'),
                "UUIDStringToNum('0f1e2d3c-4b5a-4968-8776-655443322110')",
            ],
            'InsertArray' => [new InsertArray(['a', 'b']), "['a','b']"],
            'in an array' => [[UInt64::fromString('1'), new Raw('nan'), StringType::fromString('x')], "[1, nan, 'x']"],
        ];
    }

    #[DataProvider('clientValueProvider')]
    public function test_values_of_the_smi2_client_are_written_as_smi2_writes_them(mixed $value, string $expected): void
    {
        $grammar = new Grammar();

        $this->assertSame($expected, $grammar->compileLiteral($value));

        if (!is_array($value)) {
            $this->assertSame($expected, $grammar->wrap($value), 'wrap() writes values with compileLiteral()');
        }
    }

    public function test_the_package_builder_writes_values_of_the_smi2_client(): void
    {
        $this->assertSame(
            'SELECT * FROM `t` WHERE `id` = 18446744073709551615 AND `created_at` < now() AND `tags` = [\'a\',\'b\']',
            (new TestBuilder())->from('t')
                ->where('id', UInt64::fromString('18446744073709551615'))
                ->where('created_at', '<', new Raw('now()'))
                ->where('tags', new InsertArray(['a', 'b']))
                ->toSql()
        );
    }

    /**
     * Floats keep every digit: var_export() writes the shortest text that reads back as the same float, where PHP's
     * own string conversion keeps 14 significant digits.
     *
     * @return array<string, array{float, string}>
     */
    public static function floatProvider(): array
    {
        return [
            '0.1' => [0.1, '0.1'],
            '1/3' => [1 / 3, '0.3333333333333333'],
            '2/3' => [2 / 3, '0.6666666666666666'],
            '0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'],
            'whole number' => [2.0, '2.0'],
            'large' => [1e25, '1.0E+25'],
            'small' => [1e-7, '1.0E-7'],
            'largest float' => [PHP_FLOAT_MAX, '1.7976931348623157E+308'],
            'smallest normal float' => [PHP_FLOAT_MIN, '2.2250738585072014E-308'],
            'negative zero' => [-0.0, '-0.0'],
            'negative' => [-1.5, '-1.5'],
            'NaN' => [NAN, 'nan'],
            'INF' => [INF, 'inf'],
            '-INF' => [-INF, '-inf'],
        ];
    }

    #[DataProvider('floatProvider')]
    public function test_floats_keep_every_digit_and_non_finite_floats_are_named(float $value, string $expected): void
    {
        $grammar = new Grammar();

        $this->assertSame($expected, $grammar->compileLiteral($value));
        $this->assertSame($expected, $grammar->wrap($value));

        if (is_finite($value)) {
            $this->assertSame($value, (float) $expected, 'the text reads back as the same float');
        }
    }

    public function test_nan_does_not_warn_on_php_85(): void
    {
        set_error_handler(function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $this->assertSame('nan', (new Grammar())->compileLiteral(NAN));
            $this->assertSame(
                'SELECT * FROM `t` WHERE `f` = nan OR `f` IN (nan, inf, -inf)',
                (new TestBuilder())->from('t')->where('f', NAN)->orWhereIn('f', [NAN, INF, -INF])->toSql()
            );
        } finally {
            restore_error_handler();
        }
    }

    public function test_float_text_does_not_follow_the_numeric_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        $locale = setlocale(LC_NUMERIC, 'tr_TR.UTF-8', 'tr_TR', 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR');

        if ($locale === false) {
            $this->markTestSkipped('No locale with a decimal comma is installed.');
        }

        try {
            $this->assertSame(',', localeconv()['decimal_point']);
            $this->assertSame('0.3333333333333333', (new Grammar())->compileLiteral(1 / 3));
        } finally {
            setlocale(LC_NUMERIC, $previous);
        }
    }

    /**
     * addslashes() escapes the single quote, the double quote, the backslash and the NUL byte, which ClickHouse
     * reads back as the characters themselves; every other byte is written as it is.
     *
     * @return array<string, array{string, string}>
     */
    public static function stringProvider(): array
    {
        return [
            'single quote' => ["it's", "'it\\'s'"],
            'double quote' => ['say "hi"', "'say \\\"hi\\\"'"],
            'backslash' => ['a\\b', "'a\\\\b'"],
            'trailing backslash' => ['ends\\', "'ends\\\\'"],
            'backslash before a quote' => ["a\\'b", "'a\\\\\\'b'"],
            'NUL byte' => ["a\0b", "'a\\0b'"],
            'newline and tab' => ["a\nb\tc\r", "'a\nb\tc\r'"],
            'multibyte' => ['İstanbul 東京 🙂', "'İstanbul 東京 🙂'"],
            'invalid UTF-8' => ["a\xC3\x28\xFF", "'a\xC3\x28\xFF'"],
            'question mark and placeholders' => ['? :0 {0} {p:UInt8} $$', "'? :0 {0} {p:UInt8} \$\$'"],
            'format words are not rewritten' => ['a FORMAT CSV', "'a FORMAT CSV'"],
        ];
    }

    #[DataProvider('stringProvider')]
    public function test_strings_are_quoted_with_addslashes(string $value, string $expected): void
    {
        $grammar = new Grammar();

        $this->assertSame($expected, $grammar->compileStringLiteral($value));
        $this->assertSame($expected, $grammar->compileLiteral($value));
        $this->assertSame($expected, $grammar->wrap($value));
    }

    public function test_wrap_keeps_ints_bools_and_int_backed_enums_as_ints(): void
    {
        $grammar = new Grammar();

        $this->assertSame(10, $grammar->wrap(10));
        $this->assertSame(1, $grammar->wrap(true));
        $this->assertSame(0, $grammar->wrap(false));
        $this->assertSame(3, $grammar->wrap(IntBackedEnumFixture::High));
        $this->assertSame(["'a'", '1.5', 0], $grammar->wrap(['a', 1.5, false]));
    }

    public function test_dates_follow_the_precision_in_their_own_time_zone(): void
    {
        $grammar = new Grammar();
        $fraction = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');
        $whole = Carbon::parse('2024-01-02 03:04:05', 'UTC');
        $istanbul = new DateTimeImmutable('2024-01-02 03:04:05.5', new DateTimeZone('Europe/Istanbul'));

        $this->assertSame(DateTimePrecision::SECOND, $grammar->getDateTimePrecision());
        $this->assertSame("'2024-01-02 03:04:05'", $grammar->compileLiteral($fraction));
        $this->assertSame("'2024-01-02 03:04:05'", $grammar->compileLiteral($istanbul));

        $this->assertSame($grammar, $grammar->setDateTimePrecision(DateTimePrecision::MICROSECOND));
        $this->assertSame("'2024-01-02 03:04:05.123456'", $grammar->compileLiteral($fraction));
        $this->assertSame("'2024-01-02 03:04:05'", $grammar->compileLiteral($whole), 'a whole second keeps the plain form');
        $this->assertSame("'2024-01-02 03:04:05.500000'", $grammar->compileLiteral($istanbul));
        $this->assertSame("['2024-01-02 03:04:05.123456', '2024-01-02 03:04:05']", $grammar->compileLiteral([$fraction, $whole]));
        $this->assertSame("'2024-01-02 03:04:05.123456'", $grammar->wrap($fraction));
    }

    /**
     * @return array<string, array{object|resource, string}>
     */
    public static function unwritableValueProvider(): array
    {
        return [
            'stdClass' => [new stdClass(), 'Cannot render a value of type stdClass in a ClickHouse query. Pass a scalar'],
            'collection' => [collect([1, 2]), 'Cannot render a value of type Illuminate\Support\Collection in a ClickHouse query. Pass a PHP array instead'],
            'lazy collection' => [LazyCollection::make([1]), 'Pass a PHP array instead'],
            'array iterator' => [new ArrayIterator([1]), 'Pass a PHP array instead'],
            'arrayable' => [new class () implements Arrayable {
                public function toArray(): array
                {
                    return [1];
                }
            }, 'Pass a scalar value, such as $model->getKey(), or a PHP array.'],
            'closure' => [fn (): int => 1, 'Cannot render a value of type Closure'],
            'resource' => [fopen('php://memory', 'r'), 'Cannot render a value of type resource (stream)'],
        ];
    }

    #[DataProvider('unwritableValueProvider')]
    public function test_values_that_cannot_be_written_throw(mixed $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Grammar())->compileLiteral($value);
    }

    public function test_an_unwritable_value_inside_an_array_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot render a value of type stdClass');

        (new Grammar())->compileLiteral([1, [new stdClass()]]);
    }

    public function test_a_laravel_expression_needs_the_grammar_of_a_connection(): void
    {
        $grammar = new Grammar();

        try {
            $grammar->compileLiteral(new LaravelExpression('count()'));
            $this->fail('Expected an exception without a Laravel grammar resolver');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Cannot write the Laravel database expression Illuminate\Database\Query\Expression without the grammar '
                . 'of a database connection. Build the query from a ClickHouse connection or a model, or pass raw SQL '
                . 'as an Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression.',
                $exception->getMessage()
            );
        }

        $laravelGrammar = new QueryGrammar($this->createStub(Connection::class));
        $resolved = 0;
        $this->assertSame($grammar, $grammar->setLaravelGrammarResolver(function () use ($laravelGrammar, &$resolved): QueryGrammar {
            $resolved++;

            return $laravelGrammar;
        }));
        $this->assertSame(0, $resolved, 'the resolver runs only when a Laravel expression is written');

        $this->assertSame('count()', $grammar->compileLiteral(new LaravelExpression('count()')));
        $this->assertSame('a + 1', $grammar->wrap(new LaravelExpression('a + 1')));
        $this->assertSame(5, $grammar->getLaravelExpressionValue(new LaravelExpression(5)));
        $this->assertSame("[1 + 1, 'a']", $grammar->compileLiteral([new LaravelExpression('1 + 1'), 'a']));
        $this->assertSame(4, $resolved);

        $grammar->setLaravelGrammarResolver(null);
        $this->expectException(InvalidArgumentException::class);
        $grammar->wrap(new LaravelExpression('count()'));
    }

    public function test_settings_take_a_laravel_expression(): void
    {
        $grammar = new PackageGrammar();
        $grammar->setLaravelGrammarResolver(fn (): QueryGrammar => new QueryGrammar($this->createStub(Connection::class)));

        $this->assertSame(
            "SETTINGS max_threads=2, log_comment='x'",
            $grammar->compileSettingsComponent(null, ['max_threads' => new LaravelExpression('2'), 'log_comment' => 'x'])
        );
    }

    public function test_settings_with_a_laravel_expression_need_the_grammar_of_a_connection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot write the Laravel database expression Illuminate\Database\Query\Expression without the grammar of a database connection.');

        (new PackageGrammar())->compileSettingsComponent(null, ['max_threads' => new LaravelExpression('2')]);
    }

    public function test_query_grammar_writes_laravel_expressions_and_delegates_other_values(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturnCallback(
            fn (?string $option = null): ?string => $option === 'datetime_precision' ? 'microsecond' : null
        );
        $grammar = new QueryGrammar($connection);

        $this->assertSame('count()', $grammar->compileLiteral(new LaravelExpression('count()')));
        $this->assertSame("[1 + 1, 0.1, 'x']", $grammar->compileLiteral([new LaravelExpression('1 + 1'), 0.1, 'x']));
        $this->assertSame("'2024-01-02 03:04:05.250000'", $grammar->compileLiteral(new DateTimeImmutable('2024-01-02 03:04:05.25')));
        $this->assertSame('nan', $grammar->compileLiteral(NAN));
    }

    public function test_query_grammar_writes_seconds_when_the_connection_sets_no_precision(): void
    {
        $grammar = new QueryGrammar($this->createStub(Connection::class));

        $this->assertSame("'2024-01-02 03:04:05'", $grammar->compileLiteral(new DateTimeImmutable('2024-01-02 03:04:05.25')));
    }

    public function test_query_grammar_rejects_an_invalid_datetime_precision(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getConfig')->willReturn('millisecond');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid datetime precision [millisecond]: use 'second' or 'microsecond'.");

        (new QueryGrammar($connection))->compileLiteral(new DateTimeImmutable());
    }

    public function test_format_date_time_can_be_passed_on_as_a_formatter(): void
    {
        $formatter = (new PackageGrammar())->setDateTimePrecision(DateTimePrecision::MICROSECOND)->formatDateTime(...);

        $this->assertSame('2024-01-02 03:04:05.000001', $formatter(new DateTimeImmutable('2024-01-02 03:04:05.000001')));
        $this->assertSame('2024-01-02 03:04:05', $formatter(new DateTimeImmutable('2024-01-02 03:04:05')));
    }
}
