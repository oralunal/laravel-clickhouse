<?php

declare(strict_types=1);

namespace Tests\Unit;

use Oralunal\LaravelClickHouse\ResultValues;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ResultValues gives each value the PHP type of its ClickHouse type, whatever form the JSON result writes it in.
 */
class ResultValuesTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed, mixed}>
     */
    public static function values(): array
    {
        return [
            'a small integer stays' => ['UInt32', 5, 5],
            'a quoted UInt64 that fits' => ['UInt64', '5', 5],
            'a UInt64 above PHP_INT_MAX' => ['UInt64', '18446744073709551615', '18446744073709551615'],
            'a negative Int64' => ['Int64', '-9223372036854775808', PHP_INT_MIN],
            'an Int64 as a number' => ['Int64', 7, 7],
            'an Int128 that fits' => ['Int128', '-5', -5],
            'a UInt256 above PHP_INT_MAX' => ['UInt256', '99999999999999999999', '99999999999999999999'],
            'a whole Float64' => ['Float64', 2, 2.0],
            'a Float64 with a fraction' => ['Float64', 2.5, 2.5],
            'a Float64 that JSON writes as a big integer' => ['Float64', '100000000000000000000', 1.0E20],
            'an infinite Float32' => ['Float32', 'inf', INF],
            'a negative infinity' => ['Float64', '-inf', -INF],
            'a NaN of a read-only user' => ['Float64', null, null],
            'a quoted decimal' => ['Decimal(10, 2)', '1.50', '1.50'],
            'a decimal without trailing zeros' => ['Decimal(10, 2)', '1.5', '1.50'],
            'a whole decimal' => ['Decimal(10, 2)', '1', '1.00'],
            'a decimal as a number' => ['Decimal64(2)', 1.5, '1.50'],
            'a decimal as a whole number' => ['Decimal32(3)', 2, '2.000'],
            'a decimal without a scale' => ['Decimal(10, 0)', '42', '42'],
            'a nullable decimal' => ['Nullable(Decimal(10, 2))', null, null],
            'a nullable UInt64' => ['Nullable(UInt64)', '9', 9],
            'a low cardinality nullable string' => ['LowCardinality(Nullable(String))', 'a', 'a'],
            'a bool' => ['Bool', true, true],
            'a date' => ['DateTime64(3, \'UTC\')', '2024-01-02 03:04:05.123', '2024-01-02 03:04:05.123'],
            'an enum' => ["Enum8('a, b' = 1, 'c' = 2)", 'a, b', 'a, b'],
            'an array of floats' => ['Array(Float64)', [1, 2.5], [1.0, 2.5]],
            'an array of nullable UInt64' => ['Array(Nullable(UInt64))', ['1', null], [1, null]],
            'a map of UInt64' => ['Map(String, UInt64)', ['a' => '1', 'b' => '2'], ['a' => 1, 'b' => 2]],
            'a named tuple' => ['Tuple(a String, b Float64, `c d` UInt64)', ['a' => 'x', 'b' => 1, 'c d' => '3'], ['a' => 'x', 'b' => 1.0, 'c d' => 3]],
            'a named tuple written as an array' => ['Tuple(a String, b Float64)', ['x', 1], ['x', 1.0]],
            'an unnamed tuple' => ['Tuple(String, Decimal(10, 2))', ['x', '1'], ['x', '1.00']],
            'nested arrays' => ['Array(Array(Float32))', [[1], [2, 3]], [[1.0], [2.0, 3.0]]],
            'a point' => ['Point', [1, 2], [1.0, 2.0]],
            'a polygon' => ['Polygon', [[[1, 2], [3, 4]]], [[[1.0, 2.0], [3.0, 4.0]]]],
            'a simple aggregate function' => ['SimpleAggregateFunction(sum, UInt64)', '10', 10],
            'an interval' => ['IntervalDay', '3', 3],
            'a JSON value stays' => ['JSON', ['a' => 1], ['a' => 1]],
            'an unknown type stays' => ['Dynamic', '5', '5'],
        ];
    }

    #[DataProvider('values')]
    public function test_a_value_gets_the_php_type_of_its_clickhouse_type(string $type, mixed $value, mixed $expected): void
    {
        $converter = ResultValues::converter($type);
        $actual = $converter === null ? $value : $converter($value);

        if (is_float($expected) && is_nan($expected)) {
            $this->assertNan($actual);
        } else {
            $this->assertSame($expected, $actual);
        }
    }

    public function test_nan_becomes_nan(): void
    {
        $this->assertNan(ResultValues::converter('Float64')('nan'));
    }

    public function test_types_whose_values_stay_have_no_converter(): void
    {
        foreach (['String', 'UInt8', 'Int32', 'Bool', 'Date', 'UUID', 'Nullable(String)', 'Array(String)', 'Tuple(a String, b UInt8)'] as $type) {
            $this->assertNull(ResultValues::converter($type), $type);
        }
    }

    public function test_rows_become_objects_with_converted_values(): void
    {
        $rows = ResultValues::rows(
            [['name' => 'id', 'type' => 'UInt64'], ['name' => 'name', 'type' => 'String'], ['name' => 'price', 'type' => 'Decimal(10, 2)']],
            [['id' => '1', 'name' => 'a', 'price' => '1.5'], ['id' => '2', 'name' => 'b', 'price' => '2']]
        );

        $this->assertEquals([(object) ['id' => 1, 'name' => 'a', 'price' => '1.50'], (object) ['id' => 2, 'name' => 'b', 'price' => '2.00']], $rows);
        $this->assertSame(1, $rows[0]->id);
        $this->assertSame([], ResultValues::rows([['name' => 'id', 'type' => 'UInt64']], []));
    }
}
