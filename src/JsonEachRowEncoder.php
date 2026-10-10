<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use BackedEnum;
use ClickHouseDB\Query\Expression\Expression as ClientExpression;
use ClickHouseDB\Type\Boolean;
use ClickHouseDB\Type\MapType;
use ClickHouseDB\Type\TupleType;
use ClickHouseDB\Type\Type;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as LaravelExpression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use JsonException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\Expressions\InsertArray;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Encodes rows for ClickHouse's JSONEachRow and JSONCompactEachRow input formats: one JSON object, or one JSON
 * array, per line, with the lines joined by "\n".
 *
 * Values are written as follows:
 * - null, booleans, integers and strings as they are; a string must be valid UTF-8;
 * - finite floats with every digit, as JSON writes them, so a whole float is written without a fraction (2.0 as
 *   2), which ClickHouse (24.8 checked) reads into an integer column as well as a Float column, while a fraction
 *   (2.5 into an integer column) is refused, where a Values insert would truncate it; NaN, INF and -INF as the
 *   strings "nan", "inf" and "-inf", which ClickHouse reads into Float columns;
 * - a list as a JSON array and any other array as a JSON object, so an array with keys suits a Map column;
 * - a date and time through the date formatter that the encoder was given, so that it follows the
 *   connection's datetime_precision, as every other path does;
 * - a backed enum as its value, and any other enum as its name;
 * - a stdClass as a JSON object, so new stdClass() is an empty Map;
 * - an InsertArray as its items (see InsertArray::getItems());
 * - smi2's MapType as an object, TupleType as an array, Boolean as a boolean, and any other smi2 Type as its
 *   getValue(), a string that ClickHouse reads into numbers, decimals, dates, UUIDs and IP addresses;
 * - a collection as its items, and any other Stringable object as its string.
 * Raw SQL (this package's Expression, RawColumn and Identifier, smi2's Expression and Laravel's DB::raw())
 * cannot be sent as data and is refused, as are an Arrayable value that is not a collection, such as an Eloquent
 * model, other objects and resources.
 */
final class JsonEachRowEncoder
{
    /**
     * The json_encode() flags: throw on errors and keep Unicode and slashes as they are, as upstream's encoder
     * does. JSON_PRESERVE_ZERO_FRACTION is left out on purpose: it writes a whole float as 2.0, which ClickHouse
     * (24.8 checked) refuses for an Int, UInt, Enum, Bool or DateTime column in JSONEachRow, while 2 is accepted
     * everywhere. 2 and 2.0 are the same number, so no precision is lost.
     */
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * How deep arrays and objects may be nested in a value, as json_encode() allows by default.
     */
    private const MAX_DEPTH = 512;

    /**
     * @param Closure(DateTimeInterface): string $formatDateTime Writes a date and time as the connection writes
     *                                                           it everywhere else, at its datetime_precision
     */
    public function __construct(private readonly Closure $formatDateTime)
    {
    }

    /**
     * Encode keyed rows for JSONEachRow: one JSON object per row, keyed by column name.
     *
     * @param array<int|string, array<int|string, mixed>> $rows
     * @return string
     * @throws InvalidArgumentException When a row is not an array, or a value cannot be sent as JSON; the
     *                                  message names the column and the index of the row
     */
    public function encodeRows(array $rows): string
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = $this->encodeLine($this->normalizeRow($row, $index), $index, true);
        }

        return implode("\n", $lines);
    }

    /**
     * Encode positional rows for JSONCompactEachRow: one JSON array per row, in the order of the row's values.
     *
     * @param array<int|string, array<int|string, mixed>> $rows
     * @return string
     * @throws InvalidArgumentException When a row is not an array, or a value cannot be sent as JSON; the
     *                                  message names the column and the index of the row
     */
    public function encodeCompactRows(array $rows): string
    {
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = $this->encodeLine($this->normalizeRow($row, $index), $index, false);
        }

        return implode("\n", $lines);
    }

    /**
     * Turn a value into what json_encode() writes as ClickHouse expects it (see the class description).
     *
     * @param mixed $value
     * @return mixed
     * @throws InvalidArgumentException For a value that cannot be sent as JSON, such as raw SQL
     */
    public function normalizeValue(mixed $value): mixed
    {
        try {
            return $this->normalize($value, 1);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(
                'Cannot insert the value as JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * Normalize the values of one row, naming the column and the row when a value is refused.
     *
     * @param mixed $row
     * @param int|string $index
     * @return array<int|string, mixed>
     * @throws InvalidArgumentException
     */
    private function normalizeRow(mixed $row, int|string $index): array
    {
        if (!is_array($row)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot insert the row at index %s as JSON: a row must be an array, %s given.',
                $index,
                get_debug_type($row)
            ));
        }

        foreach ($row as $column => $value) {
            if ($value === null || is_int($value) || is_string($value) || is_bool($value)) {
                continue;
            }

            try {
                $row[$column] = $this->normalize($value, 1);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidArgumentException(sprintf(
                    'Cannot insert the value of column [%s] in the row at index %s as JSON: %s',
                    $column,
                    $index,
                    $exception->getMessage()
                ), 0, $exception);
            }
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @param int $depth
     * @return mixed
     * @throws InvalidArgumentException
     */
    private function normalize(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('the value is nested more than ' . self::MAX_DEPTH . ' levels deep.');
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_string($value) => $value,
            is_float($value) => $this->normalizeFloat($value),
            is_array($value) => $this->normalizeArray($value, $depth),
            $value instanceof DateTimeInterface => $this->formatDateTime($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof InsertArray => $this->normalizeArray($value->getItems(), $depth),
            $value instanceof Expression,
            $value instanceof Identifier,
            $value instanceof ClientExpression,
            $value instanceof LaravelExpression => throw new InvalidArgumentException(sprintf(
                'raw SQL (%s) cannot be sent as data. Insert the value itself, or use the Values insert format.',
                get_debug_type($value)
            )),
            $value instanceof MapType => (object) $this->normalizeArray($value->value, $depth),
            $value instanceof TupleType => $this->normalizeArray(array_values($value->value), $depth),
            $value instanceof Boolean => $this->normalizeBoolean($value),
            $value instanceof Type => $this->normalize($value->getValue(), $depth + 1),
            $value instanceof Enumerable => $this->normalizeArray($value->all(), $depth),
            $value instanceof Arrayable => throw new InvalidArgumentException(sprintf(
                'a value of type %s cannot be sent as JSON. Pass a scalar value, such as $model->getKey(), or an array.',
                get_debug_type($value)
            )),
            $value instanceof stdClass => (object) $this->normalizeArray(get_object_vars($value), $depth),
            $value instanceof Stringable => (string) $value,
            default => throw new InvalidArgumentException(sprintf(
                'a value of type %s cannot be sent as JSON. Pass a scalar, an array, a date, an enum, a stdClass or'
                . ' a Stringable object.',
                get_debug_type($value)
            )),
        };
    }

    /**
     * Write NaN, INF and -INF as the strings that ClickHouse reads into Float columns; json_encode() refuses them.
     *
     * @param float $value
     * @return float|string
     */
    private function normalizeFloat(float $value): float|string
    {
        return match (true) {
            is_nan($value) => 'nan',
            $value === INF => 'inf',
            $value === -INF => '-inf',
            default => $value,
        };
    }

    /**
     * Write smi2's Boolean as a JSON boolean: ClickHouse (24.8 checked) refuses the quoted "1" of its getValue()
     * for a Bool column. A value other than 1, 0, true or false is left as its string, which ClickHouse refuses.
     *
     * @param Boolean $value
     * @return bool|string
     */
    private function normalizeBoolean(Boolean $value): bool|string
    {
        return match (strtolower(trim($value->getValue()))) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => $value->getValue(),
        };
    }

    /**
     * @param array<int|string, mixed> $values
     * @param int $depth
     * @return array<int|string, mixed>
     * @throws InvalidArgumentException
     */
    private function normalizeArray(array $values, int $depth): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->normalize($value, $depth + 1);
        }

        return $values;
    }

    /**
     * @param DateTimeInterface $value
     * @return string
     * @throws InvalidArgumentException When the date formatter does not return a string
     */
    private function formatDateTime(DateTimeInterface $value): string
    {
        $formatted = ($this->formatDateTime)($value);
        if (!is_string($formatted)) {
            throw new InvalidArgumentException(sprintf(
                'the date formatter of the JSON encoder must return a string, %s given.',
                get_debug_type($formatted)
            ));
        }

        return $formatted;
    }

    /**
     * Encode one normalized row as a JSON object (keyed) or a JSON array (positional).
     *
     * @param array<int|string, mixed> $row
     * @param int|string $index
     * @param bool $keyed
     * @return string
     * @throws InvalidArgumentException When json_encode() fails, naming the column whose value it cannot write
     */
    private function encodeLine(array $row, int|string $index, bool $keyed): string
    {
        try {
            return json_encode($keyed ? (object) $row : array_values($row), self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw $this->describeEncodingFailure($row, $index, $keyed, $exception);
        }
    }

    /**
     * Encode the row's names and values one by one to find the column that json_encode() cannot write.
     *
     * @param array<int|string, mixed> $row
     * @param int|string $index
     * @param bool $keyed
     * @param JsonException $exception
     * @return InvalidArgumentException
     */
    private function describeEncodingFailure(
        array $row,
        int|string $index,
        bool $keyed,
        JsonException $exception
    ): InvalidArgumentException {
        $advice = $exception->getCode() === JSON_ERROR_UTF8
            ? ' JSON needs valid UTF-8: insert binary strings with the Values insert format.'
            : '';

        foreach ($row as $column => $value) {
            foreach ($keyed ? ['name' => (string) $column, 'value' => $value] : ['value' => $value] as $part => $item) {
                try {
                    json_encode($item, self::JSON_FLAGS);
                } catch (JsonException $partException) {
                    return new InvalidArgumentException(sprintf(
                        'Cannot insert the %s of column [%s] in the row at index %s as JSON: %s.%s',
                        $part,
                        $part === 'name' ? $this->printable((string) $column) : $column,
                        $index,
                        $partException->getMessage(),
                        $advice
                    ), 0, $exception);
                }
            }
        }

        return new InvalidArgumentException(sprintf(
            'Cannot insert the row at index %s as JSON: %s.%s',
            $index,
            $exception->getMessage(),
            $advice
        ), 0, $exception);
    }

    /**
     * Make a column name that is not valid UTF-8 printable for an exception message.
     *
     * @param string $name
     * @return string
     */
    private function printable(string $name): string
    {
        return mb_check_encoding($name, 'UTF-8') ? $name : 'hex ' . bin2hex($name);
    }
}
