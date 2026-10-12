<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Client;
use Closure;
use stdClass;

/**
 * Turns the values of a result into the PHP types of their ClickHouse types, the same on every ClickHouse version,
 * as PDO gives the values of MySQL to Laravel:
 * - Int8 to Int32 and UInt8 to UInt32 are ints. Int64, UInt64 and the wider integers are ints when they fit a PHP
 *   int, and numeric strings when they do not, such as a UInt64 above PHP_INT_MAX. ClickHouse 24.8 writes such
 *   integers as strings and 25.8 and later as numbers; both become the same value here;
 * - Float32, Float64 and BFloat16 are floats, also a whole number, which JSON writes without a fraction, such as 2.
 *   NaN and the infinities are NAN, INF and -INF;
 * - Decimal is a string with every digit and the scale of the column, as "1.50" for Decimal(10, 2);
 * - Bool is a bool. String, FixedString, UUID, IPv4, IPv6, Enum and the dates and times are strings;
 * - Array, Map, Tuple and Nested are PHP arrays whose values follow the same rules. A named tuple is an
 *   associative array, and Point and the other geo types are arrays of floats;
 * - Nullable gives null for NULL. LowCardinality and SimpleAggregateFunction take the type that they hold. JSON,
 *   Variant, Dynamic and the other types keep the values that the JSON result holds.
 *
 * The values are read exactly when ClickHouse writes the decimals, NaN and the infinities as strings: SETTINGS asks
 * for that. A read-only user, the readonly option of the connection, cannot change a setting, so the settings are
 * not sent for such a user: a decimal then comes as a JSON number, which can lose digits beyond about 15 significant
 * digits, and NaN and the infinities come as JSON null, which stays null.
 */
final class ResultValues
{
    /**
     * The settings that make ClickHouse write each value of a JSON result so that it can be read exactly.
     *
     * @var array<string, int>
     */
    public const SETTINGS = [
        'output_format_json_quote_decimals' => 1,
        'output_format_decimal_trailing_zeros' => 1,
        'output_format_json_quote_denormals' => 1,
    ];

    /**
     * The flags with which a JSON result is decoded: an integer that does not fit a PHP int stays a numeric string.
     */
    public const JSON_DECODE_FLAGS = JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING;

    /**
     * The types that hold an integer of 64 bits or more, which can exceed a PHP int.
     *
     * @var list<string>
     */
    private const WIDE_INTEGERS = [
        'Int64', 'UInt64', 'Int128', 'UInt128', 'Int256', 'UInt256',
        'IntervalNanosecond', 'IntervalMicrosecond', 'IntervalMillisecond', 'IntervalSecond', 'IntervalMinute',
        'IntervalHour', 'IntervalDay', 'IntervalWeek', 'IntervalMonth', 'IntervalQuarter', 'IntervalYear',
    ];

    /**
     * @var list<string>
     */
    private const FLOATS = ['Float32', 'Float64', 'BFloat16'];

    /**
     * The converters of the types that this process has read, by type name; null for a type whose values stay.
     *
     * @var array<string, (Closure(mixed): mixed)|null>
     */
    private static array $converters = [];

    /**
     * Get the settings that a select of the given client sends, so that its values can be read exactly: none for a
     * read-only user, who cannot change a setting.
     *
     * @param Client $client
     * @return array<string, int>
     */
    public static function settingsFor(Client $client): array
    {
        return $client->settings()->isReadOnlyUser() ? [] : self::SETTINGS;
    }

    /**
     * Turn the rows of a JSON result into stdClass objects with values of the PHP types of their columns.
     *
     * @param list<array{name: string, type: string}> $meta The meta of the result: the name and the type of each
     *                                                     column
     * @param iterable<array-key, array<string, mixed>> $rows The rows, each keyed by the names of the columns
     * @return list<stdClass>
     */
    public static function rows(array $meta, iterable $rows): array
    {
        $row = self::rowConverter($meta);
        $objects = [];
        foreach ($rows as $values) {
            $objects[] = $row($values);
        }

        return $objects;
    }

    /**
     * Get a function that turns one row, keyed by the names of the columns, into a stdClass object with values of
     * the PHP types of their columns (see rows()).
     *
     * @param list<array{name: string, type: string}> $meta
     * @return Closure(array<string, mixed>): stdClass
     */
    public static function rowConverter(array $meta): Closure
    {
        $converters = [];
        foreach ($meta as $column) {
            $converter = self::converter($column['type']);
            if ($converter !== null) {
                $converters[$column['name']] = $converter;
            }
        }

        if ($converters === []) {
            return static fn (array $values): stdClass => (object) $values;
        }

        return static function (array $values) use ($converters): stdClass {
            foreach ($converters as $name => $converter) {
                if (array_key_exists($name, $values)) {
                    $values[$name] = $converter($values[$name]);
                }
            }

            return (object) $values;
        };
    }

    /**
     * Get the function that turns a value of a ClickHouse type, as a JSON result holds it, into its PHP value, or
     * null when the value stays as it is.
     *
     * @param string $type A ClickHouse type, as the meta of a result names it, such as 'Nullable(Decimal(10, 2))'
     * @return (Closure(mixed): mixed)|null
     */
    public static function converter(string $type): ?Closure
    {
        if (array_key_exists($type, self::$converters)) {
            return self::$converters[$type];
        }

        return self::$converters[$type] = self::newConverter($type);
    }

    /**
     * @param string $type
     * @return (Closure(mixed): mixed)|null
     */
    private static function newConverter(string $type): ?Closure
    {
        [$name, $arguments] = self::split(trim($type));

        return match (true) {
            $name === 'Nullable' => self::nullable(self::converter($arguments[0] ?? '')),
            $name === 'LowCardinality' => self::converter($arguments[0] ?? ''),
            $name === 'SimpleAggregateFunction' => self::converter($arguments[1] ?? ''),
            in_array($name, self::WIDE_INTEGERS, true) => self::wideInteger(...),
            in_array($name, self::FLOATS, true) => self::float(...),
            $name === 'Decimal' => self::decimal((int) ($arguments[1] ?? 0)),
            in_array($name, ['Decimal32', 'Decimal64', 'Decimal128', 'Decimal256'], true)
                => self::decimal((int) ($arguments[0] ?? 0)),
            $name === 'Array' => self::each(self::converter($arguments[0] ?? '')),
            $name === 'Map' => self::each(self::converter($arguments[1] ?? '')),
            $name === 'Tuple' => self::tuple($arguments),
            $name === 'Nested' => self::each(self::tuple($arguments)),
            $name === 'Point' => self::tuple(['Float64', 'Float64']),
            in_array($name, ['Ring', 'LineString'], true) => self::each(self::converter('Point')),
            in_array($name, ['Polygon', 'MultiLineString'], true) => self::each(self::converter('Ring')),
            $name === 'MultiPolygon' => self::each(self::converter('Polygon')),
            default => null,
        };
    }

    /**
     * Turn an integer of 64 bits or more into an int when it fits one, and leave a numeric string otherwise.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function wideInteger(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? $value : $integer;
    }

    /**
     * Turn a floating-point value into a float: a whole number, which JSON writes without a fraction, and the
     * quoted NaN and infinities, which become NAN, INF and -INF.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function float(mixed $value): mixed
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'nan', '+nan', '-nan' => NAN,
            'inf', '+inf', 'infinity', '+infinity' => INF,
            '-inf', '-infinity' => -INF,
            default => is_numeric($value) ? (float) $value : $value,
        };
    }

    /**
     * Get the function that writes a decimal as a string with the scale of its type, as "1.50" for a scale of 2.
     *
     * @param int $scale
     * @return Closure(mixed): mixed
     */
    private static function decimal(int $scale): Closure
    {
        return static function (mixed $value) use ($scale): mixed {
            if (is_float($value)) {
                return sprintf('%.' . $scale . 'F', $value);
            }

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (!is_string($value) || !is_numeric($value) || $scale === 0) {
                return $value;
            }

            $point = strpos($value, '.');
            if ($point === false) {
                return $value . '.' . str_repeat('0', $scale);
            }

            $digits = strlen($value) - $point - 1;

            return $digits < $scale ? $value . str_repeat('0', $scale - $digits) : $value;
        };
    }

    /**
     * @param (Closure(mixed): mixed)|null $converter
     * @return (Closure(mixed): mixed)|null
     */
    private static function nullable(?Closure $converter): ?Closure
    {
        return $converter === null
            ? null
            : static fn (mixed $value): mixed => $value === null ? null : $converter($value);
    }

    /**
     * Get the function that turns each value of an array or a map, keeping its keys.
     *
     * @param (Closure(mixed): mixed)|null $converter
     * @return (Closure(mixed): mixed)|null
     */
    private static function each(?Closure $converter): ?Closure
    {
        if ($converter === null) {
            return null;
        }

        return static function (mixed $values) use ($converter): mixed {
            if (!is_array($values)) {
                return $values;
            }

            foreach ($values as $key => $value) {
                $values[$key] = $converter($value);
            }

            return $values;
        };
    }

    /**
     * Get the function that turns the elements of a tuple: by name for a named tuple, which JSON writes as an
     * object, and by position for a tuple that JSON writes as an array.
     *
     * @param list<string> $elements The elements of the tuple type, such as 'a String' or 'String'
     * @return (Closure(mixed): mixed)|null
     */
    private static function tuple(array $elements): ?Closure
    {
        $byName = [];
        $byPosition = [];
        foreach ($elements as $position => $element) {
            [$name, $type] = self::tupleElement($element);
            $converter = self::converter($type);
            if ($converter !== null) {
                $byPosition[$position] = $converter;
                if ($name !== null) {
                    $byName[$name] = $converter;
                }
            }
        }

        if ($byPosition === []) {
            return null;
        }

        return static function (mixed $values) use ($byName, $byPosition): mixed {
            if (!is_array($values)) {
                return $values;
            }

            $converters = array_is_list($values) ? $byPosition : $byName;
            foreach ($converters as $key => $converter) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $converter($values[$key]);
                }
            }

            return $values;
        };
    }

    /**
     * Split an element of a tuple type into its name, or null when it has none, and its type: 'a String' gives
     * ['a', 'String'], '`a b` String' gives ['a b', 'String'], and 'String' gives [null, 'String'].
     *
     * @param string $element
     * @return array{0: string|null, 1: string}
     */
    private static function tupleElement(string $element): array
    {
        $element = trim($element);
        if (str_starts_with($element, '`')) {
            $end = strpos($element, '`', 1);
            if ($end !== false) {
                return [substr($element, 1, $end - 1), trim(substr($element, $end + 1))];
            }
        }

        $space = self::topLevelSpace($element);

        return $space === null
            ? [null, $element]
            : [substr($element, 0, $space), trim(substr($element, $space + 1))];
    }

    /**
     * Find the first space outside parentheses and quotes, or null.
     *
     * @param string $type
     * @return int|null
     */
    private static function topLevelSpace(string $type): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = 0, $length = strlen($type); $i < $length; $i++) {
            $char = $type[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ' ' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Split a type into its name and its arguments, at the commas outside nested parentheses and quotes:
     * 'Map(String, Array(UInt8))' gives ['Map', ['String', 'Array(UInt8)']], and 'String' gives ['String', []].
     *
     * @param string $type
     * @return array{0: string, 1: list<string>}
     */
    private static function split(string $type): array
    {
        $open = strpos($type, '(');
        if ($open === false || !str_ends_with($type, ')')) {
            return [$type, []];
        }

        $arguments = [];
        $depth = 0;
        $quote = null;
        $start = $open + 1;
        $end = strlen($type) - 1;
        for ($i = $start; $i < $end; $i++) {
            $char = $type[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $arguments[] = trim(substr($type, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $arguments[] = trim(substr($type, $start, $end - $start));

        return [trim(substr($type, 0, $open)), $arguments];
    }
}
