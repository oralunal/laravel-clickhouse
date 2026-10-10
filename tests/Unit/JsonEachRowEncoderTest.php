<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Type\Boolean;
use ClickHouseDB\Type\Float64;
use ClickHouseDB\Type\MapType;
use ClickHouseDB\Type\TupleType;
use ClickHouseDB\Type\UInt64;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use ErrorException;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Expressions\InsertArray;
use Oralunal\LaravelClickHouse\JsonEachRowEncoder;
use Oralunal\LaravelClickHouse\RawColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\UnitEnumFixture;

/**
 * How JsonEachRowEncoder writes rows for the JSONEachRow and JSONCompactEachRow input formats.
 */
class JsonEachRowEncoderTest extends TestCase
{
    /**
     * A value and the JSON that a row {"v": value} gets for it.
     *
     * @return array<string, array{mixed, string}>
     */
    public static function values(): array
    {
        $object = new stdClass();
        $object->a = 1;
        $object->b = [NAN, 'x'];

        return [
            'null' => [null, 'null'],
            'true' => [true, 'true'],
            'false' => [false, 'false'],
            'an integer' => [-42, '-42'],
            'a string with quotes, a backslash, a newline, a NUL byte and an emoji' => ["it's \"a\" \\ \n \0 😀", '"it\'s \"a\" \\\\ \n \u0000 😀"'],
            'slashes stay as they are' => ['a/b', '"a/b"'],
            'a float with every digit' => [0.1 + 0.2, '0.30000000000000004'],
            'a whole float loses its fraction so an integer column accepts it' => [1.0, '1'],
            'a big float' => [1.0E+25, '1.0e+25'],
            'NaN' => [NAN, '"nan"'],
            'INF' => [INF, '"inf"'],
            '-INF' => [-INF, '"-inf"'],
            'a list' => [[1, 'a', null], '[1,"a",null]'],
            'an array with keys' => [['x' => 1, 'y' => 2], '{"x":1,"y":2}'],
            'int-like keys stay object keys' => [['1' => 'a', '2' => 'b'], '{"1":"a","2":"b"}'],
            'nested arrays with floats' => [[[1.5, NAN], ['k' => INF]], '[[1.5,"nan"],{"k":"inf"}]'],
            'an int-backed enum' => [IntBackedEnumFixture::High, '3'],
            'a string-backed enum' => [StringBackedEnumFixture::Active, '"active"'],
            'a pure enum' => [UnitEnumFixture::Spades, '"Spades"'],
            'an empty stdClass' => [new stdClass(), '{}'],
            'a stdClass with properties' => [$object, '{"a":1,"b":["nan","x"]}'],
            'an empty array' => [[], '[]'],
            'an InsertArray of integers' => [new InsertArray(['1', '2', 3], InsertArray::TYPE_INT), '[1,2,3]'],
            'an InsertArray of strings' => [new InsertArray(['it\'s', 'b']), '["it\'s","b"]'],
            'an escaped InsertArray of strings' => [new InsertArray(['it\'s'], InsertArray::TYPE_STRING_ESCAPE), '["it\'s"]'],
            'an InsertArray of decimals' => [new InsertArray(['1.5', 2], InsertArray::TYPE_DECIMAL), '[1.5,2]'],
            'a smi2 UInt64' => [UInt64::fromString('18446744073709551615'), '"18446744073709551615"'],
            'a smi2 Float64' => [Float64::fromString('0.1'), '"0.1"'],
            'a smi2 Boolean of true' => [Boolean::fromBool(true), 'true'],
            'a smi2 Boolean of 0' => [Boolean::fromString('0'), 'false'],
            'a smi2 Boolean of TRUE' => [Boolean::fromString('TRUE'), 'true'],
            'a smi2 Boolean of another text' => [Boolean::fromString('yes'), '"yes"'],
            'a smi2 MapType' => [MapType::fromArray(['x' => 1, 'y' => NAN]), '{"x":1,"y":"nan"}'],
            'a smi2 MapType with integer keys' => [MapType::fromArray([0 => 'a', 1 => 'b']), '{"0":"a","1":"b"}'],
            'a smi2 TupleType' => [TupleType::fromArray(['a' => 1, 'b' => 'x']), '[1,"x"]'],
            'a collection of values' => [new Collection([1, 2]), '[1,2]'],
            'a collection with keys' => [new Collection(['a' => StringBackedEnumFixture::Paused]), '{"a":"paused"}'],
            'a Stringable' => [Str::of('text'), '"text"'],
        ];
    }

    #[DataProvider('values')]
    public function test_values(mixed $value, string $json): void
    {
        $this->assertSame('{"v":' . $json . '}', $this->encoder()->encodeRows([['v' => $value]]));
    }

    public function test_dates_go_through_the_date_formatter(): void
    {
        $encoder = new JsonEachRowEncoder(
            fn (DateTimeInterface $value): string => $value->format('Y-m-d H:i:s.u')
        );
        $date = new DateTimeImmutable('2026-10-09 11:12:13.123456', new DateTimeZone('Europe/Istanbul'));

        $this->assertSame(
            '{"at":"2026-10-09 11:12:13.123456","list":["2026-10-09 11:12:13.123456",{"d":"2026-01-02 03:04:05.000000"}]}',
            $encoder->encodeRows([[
                'at' => $date,
                'list' => [$date, ['d' => Carbon::create(2026, 1, 2, 3, 4, 5, 'UTC')]],
            ]])
        );
    }

    public function test_a_date_keeps_its_own_time_zone(): void
    {
        $date = new DateTimeImmutable('2026-10-09 23:30:00', new DateTimeZone('America/New_York'));

        $this->assertSame('{"at":"2026-10-09 23:30:00"}', $this->encoder()->encodeRows([['at' => $date]]));
    }

    public function test_the_date_formatter_must_return_a_string(): void
    {
        $encoder = new JsonEachRowEncoder(fn (DateTimeInterface $value): mixed => $value->getTimestamp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot insert the value of column [at] in the row at index 0 as JSON: the date formatter of the JSON'
            . ' encoder must return a string, int given.'
        );

        $encoder->encodeRows([['at' => new DateTimeImmutable('2026-10-09 10:00:00')]]);
    }

    public function test_rows_are_one_object_per_line(): void
    {
        $this->assertSame(
            "{\"f_int\":1,\"f_string\":\"a\"}\n{\"f_string\":\"b\",\"f_int\":2}",
            $this->encoder()->encodeRows([['f_int' => 1, 'f_string' => 'a'], ['f_string' => 'b', 'f_int' => 2]])
        );
    }

    public function test_column_names_stay_raw_strings(): void
    {
        $this->assertSame(
            '{"we`ird":1,"back\\\\slash":2,"n.x":3,"0":4}',
            $this->encoder()->encodeRows([['we`ird' => 1, 'back\\slash' => 2, 'n.x' => 3, 0 => 4]])
        );
    }

    public function test_compact_rows_are_one_array_per_line_in_the_order_of_the_values(): void
    {
        $this->assertSame(
            "[1,\"a\",\"nan\"]\n[2,\"b\",1]",
            $this->encoder()->encodeCompactRows([[1, 'a', NAN], ['x' => 2, 'y' => 'b', 'z' => 1.0]])
        );
    }

    public function test_a_whole_float_is_written_as_an_integer_but_a_fraction_is_kept(): void
    {
        $this->assertSame(
            '{"a":2,"b":2.5,"c":-0,"d":1700000000}',
            $this->encoder()->encodeRows([['a' => 2.0, 'b' => 2.5, 'c' => -0.0, 'd' => 1.7e9]])
        );
    }

    public function test_no_rows_give_an_empty_string(): void
    {
        $this->assertSame('', $this->encoder()->encodeRows([]));
        $this->assertSame('', $this->encoder()->encodeCompactRows([]));
    }

    public function test_normalize_value_turns_a_value_into_what_json_encode_writes(): void
    {
        $encoder = $this->encoder();

        $this->assertSame('nan', $encoder->normalizeValue(NAN));
        $this->assertSame(['a' => 'inf', 'b' => ['x']], $encoder->normalizeValue(['a' => INF, 'b' => new InsertArray(['x'])]));
        $this->assertEquals((object) ['k' => 1], $encoder->normalizeValue(MapType::fromArray(['k' => 1])));
    }

    public function test_normalize_value_refuses_raw_sql_with_a_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the value as JSON: raw SQL (' . RawColumn::class . ') cannot be sent as data.');

        $this->encoder()->normalizeValue(new RawColumn('now()'));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refusedValues(): array
    {
        return [
            'a RawColumn' => [new RawColumn('now()'), 'raw SQL (' . RawColumn::class . ') cannot be sent as data'],
            "the package's Expression" => [new Expression('now()'), 'raw SQL (' . Expression::class . ') cannot be sent as data'],
            "smi2's Raw" => [new Raw('now()'), 'raw SQL (' . Raw::class . ') cannot be sent as data'],
            "Laravel's DB::raw()" => [new LaravelExpression('now()'), 'raw SQL (' . LaravelExpression::class . ') cannot be sent as data'],
            'raw SQL inside an array' => [[1, new RawColumn('now()')], 'raw SQL (' . RawColumn::class . ') cannot be sent as data'],
            "the package's Identifier" => [new Identifier('f_int'), 'raw SQL (' . Identifier::class . ') cannot be sent as data'],
            'an Arrayable that is not a collection' => [
                new Fluent(['id' => 1]),
                'a value of type ' . Fluent::class . ' cannot be sent as JSON. Pass a scalar value, such as $model->getKey(), or an array.',
            ],
            'another object' => [new class {
            }, 'a value of type class@anonymous cannot be sent as JSON'],
            'a closure' => [fn (): int => 1, 'a value of type Closure cannot be sent as JSON'],
        ];
    }

    #[DataProvider('refusedValues')]
    public function test_refused_values_name_the_column_and_the_row(mixed $value, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot insert the value of column [f_value] in the row at index 1 as JSON: {$reason}");

        $this->encoder()->encodeRows([['f_value' => 1], ['f_value' => $value]]);
    }

    public function test_a_resource_is_refused(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Cannot insert the value of column [f] in the row at index 0 as JSON: a value of type resource (stream) cannot be sent as JSON');

            $this->encoder()->encodeRows([['f' => $resource]]);
        } finally {
            fclose($resource);
        }
    }

    public function test_invalid_utf8_names_the_column_and_the_row_and_points_to_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot insert the value of column [f_string] in the row at index 2 as JSON: Malformed UTF-8 characters,'
            . ' possibly incorrectly encoded. JSON needs valid UTF-8: insert binary strings with the Values insert format.'
        );

        $this->encoder()->encodeRows([
            ['f_int' => 1, 'f_string' => 'a'],
            ['f_int' => 2, 'f_string' => 'b'],
            ['f_int' => 3, 'f_string' => "\xB1\x31"],
        ]);
    }

    public function test_invalid_utf8_in_a_compact_row_names_its_position(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the value of column [1] in the row at index 0 as JSON: Malformed UTF-8');

        $this->encoder()->encodeCompactRows([[1, ["ok", "\xFF"]]]);
    }

    public function test_an_invalid_utf8_column_name_is_shown_in_hex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the name of column [hex ff61] in the row at index 0 as JSON: Malformed UTF-8');

        $this->encoder()->encodeRows([["\xFFa" => 1]]);
    }

    public function test_a_row_must_be_an_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot insert the row at index 1 as JSON: a row must be an array, string given.');

        $this->encoder()->encodeRows([['a' => 1], 'a']);
    }

    public function test_a_value_nested_too_deep_is_refused(): void
    {
        $value = 1;
        for ($level = 0; $level < 600; $level++) {
            $value = [$value];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value is nested more than 512 levels deep.');

        $this->encoder()->encodeRows([['deep' => $value]]);
    }

    /**
     * @return array<string, array{InsertArray, array<int, int|float|string>}>
     */
    public static function insertArrays(): array
    {
        return [
            'integers' => [new InsertArray(['a' => '1', 'b' => '2.7', 'c' => 3], InsertArray::TYPE_INT), [1, 2, 3]],
            'decimals' => [new InsertArray(['1.5', 2, '0.1'], InsertArray::TYPE_DECIMAL), [1.5, 2.0, 0.1]],
            'strings, unescaped' => [new InsertArray(["it's", 5, null]), ["it's", '5', '']],
            'escaped strings, unescaped' => [new InsertArray(["it's"], InsertArray::TYPE_STRING_ESCAPE), ["it's"]],
        ];
    }

    /**
     * @param array<int, int|float|string> $items
     */
    #[DataProvider('insertArrays')]
    public function test_insert_array_items_have_the_type_applied(InsertArray $array, array $items): void
    {
        $this->assertSame($items, $array->getItems());
    }

    public function test_insert_array_sql_is_unchanged_for_items_without_a_quote_or_a_backslash(): void
    {
        $this->assertSame('[1,2]', (new InsertArray(['1', '2'], InsertArray::TYPE_INT))->getValue());
        $this->assertSame("['it\\'s']", (new InsertArray(["it's"], InsertArray::TYPE_STRING_ESCAPE))->getValue());
        $this->assertSame("['a','b']", (new InsertArray(['a', 'b']))->getValue());
    }

    /**
     * Decimal items and the array literal that getValue() writes for them.
     *
     * @return array<string, array{array<int, mixed>, string}>
     */
    public static function decimalInsertArrays(): array
    {
        return [
            'a third keeps every digit' => [[1 / 3], '[0.3333333333333333]'],
            'a computed sum keeps every digit' => [[0.1 + 0.2], '[0.30000000000000004]'],
            'negative zero keeps its sign' => [[-0.0], '[-0.0]'],
            'NaN' => [[NAN], '[nan]'],
            'INF' => [[INF], '[inf]'],
            '-INF' => [[-INF], '[-inf]'],
            'numeric strings and ints are written as floats' => [['1.5', '2', 3], '[1.5,2.0,3.0]'],
            'a big and a small float' => [[1.0E+25, 1.0E-7], '[1.0E+25,1.0E-7]'],
            'all of them together' => [
                [1 / 3, 0.1 + 0.2, -0.0, NAN, INF, -INF],
                '[0.3333333333333333,0.30000000000000004,-0.0,nan,inf,-inf]',
            ],
        ];
    }

    /**
     * getValue() writes each decimal item as the query builder writes a float (Grammar::compileLiteral()), so a
     * Values insert stores the same floats as the JSONEachRow encoder, which sends getItems(), and NaN raises no
     * warning on PHP 8.5.
     *
     * @param array<int, mixed> $items
     */
    #[DataProvider('decimalInsertArrays')]
    public function test_decimal_insert_array_items_are_written_as_the_query_builder_writes_floats(array $items, string $sql): void
    {
        set_error_handler(function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $array = new InsertArray($items, InsertArray::TYPE_DECIMAL);
            $grammar = new Grammar();
            $writtenItems = array_map(fn (float $item): string => $grammar->compileLiteral($item), $array->getItems());

            $this->assertSame($sql, $array->getValue());
            $this->assertSame('[' . implode(',', $writtenItems) . ']', $array->getValue(), 'getValue() writes getItems()');

            foreach ($array->getItems() as $index => $item) {
                if (is_finite($item)) {
                    $this->assertSame($item, (float) $writtenItems[$index], 'the text reads back as the same float');
                }
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function insertArrayTypes(): array
    {
        return [
            'the default type' => [null],
            'string' => [InsertArray::TYPE_STRING],
            'escaped string' => [InsertArray::TYPE_STRING_ESCAPE],
            'int' => [InsertArray::TYPE_INT],
            'decimal' => [InsertArray::TYPE_DECIMAL],
        ];
    }

    #[DataProvider('insertArrayTypes')]
    public function test_an_empty_insert_array_is_an_empty_array(?string $type): void
    {
        $array = $type === null ? new InsertArray([]) : new InsertArray([], $type);

        $this->assertSame('[]', $array->getValue());
        $this->assertSame([], $array->getItems());
        $this->assertSame('{"a":[]}', $this->encoder()->encodeRows([['a' => $array]]));
    }

    /**
     * @return array<string, array{InsertArray, string}>
     */
    public static function escapedInsertArrays(): array
    {
        return [
            'a single quote is escaped' => [new InsertArray(["O'Brien", 'x']), "['O\\'Brien','x']"],
            'a backslash is doubled' => [new InsertArray(['C:\\temp', 'path\\']), "['C:\\\\temp','path\\\\']"],
            'a trailing backslash cannot escape the closing quote' => [new InsertArray(['ends\\']), "['ends\\\\']"],
            'the escape type escapes backslashes too' => [
                new InsertArray(['a\\', "b'"], InsertArray::TYPE_STRING_ESCAPE),
                "['a\\\\','b\\'']",
            ],
            'a non-string item becomes a quoted string' => [new InsertArray([5, null]), "['5','']"],
        ];
    }

    #[DataProvider('escapedInsertArrays')]
    public function test_insert_array_escapes_string_items(InsertArray $array, string $sql): void
    {
        $this->assertSame($sql, $array->getValue());
    }

    /**
     * An encoder whose date formatter writes seconds, as the connection does at its default precision.
     *
     * @return JsonEachRowEncoder
     */
    private function encoder(): JsonEachRowEncoder
    {
        return new JsonEachRowEncoder(fn (DateTimeInterface $value): string => $value->format('Y-m-d H:i:s'));
    }
}
