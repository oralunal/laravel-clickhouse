<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use BackedEnum;
use ClickHouseDB\Query\Expression\Expression as ClientExpression;
use ClickHouseDB\Type\Boolean;
use ClickHouseDB\Type\MapType;
use ClickHouseDB\Type\NumericType;
use ClickHouseDB\Type\TupleType;
use ClickHouseDB\Type\Type as ClientType;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Enums\DateTimePrecision;
use Stringable;
use Traversable;
use UnitEnum;

/**
 * Writes PHP values as ClickHouse literals: the bindings of Laravel's query builder and of the connection's raw SQL,
 * escape(), the values of inserts and the SETTINGS of a query.
 *
 * Dates are written at the precision of the connection's datetime_precision option (see formatDateTime()), and a
 * Laravel database expression, such as DB::raw(), through the grammar of the connection (see
 * setLaravelGrammarResolver()). Connection::newLiteralGrammar() gives one with both set.
 */
class LiteralGrammar
{
    /**
     * The precision of the dates that formatDateTime() writes.
     *
     * @var DateTimePrecision
     */
    protected DateTimePrecision $dateTimePrecision = DateTimePrecision::Second;

    /**
     * Resolves the grammar of the database connection, which a Laravel database expression needs to give its SQL,
     * or null.
     *
     * @var (Closure(): \Illuminate\Database\Grammar)|null
     */
    protected ?Closure $laravelGrammarResolver = null;

    /**
     * Write a PHP value as a ClickHouse literal.
     *
     * - null becomes null, a bool 1 or 0, and an int its digits;
     * - a finite float is written with var_export(), the shortest text that reads back as the same float, so it
     *   keeps every digit (1/3 becomes 0.3333333333333333 and 2.0 becomes 2.0); NaN, INF and -INF become nan, inf
     *   and -inf;
     * - a string becomes a quoted string literal (see compileStringLiteral());
     * - an array becomes an array literal of its elements: [1, 'a', [2.5, null]]; keys are ignored;
     * - a DateTimeInterface becomes a quoted string in its own time zone, at the precision of formatDateTime();
     * - a BackedEnum is written as its value, and a UnitEnum as its name in a string literal;
     * - a Laravel database expression, such as DB::raw(), is written as the SQL it gives;
     * - an expression of the smi2 client, such as its Raw('now()'), is written as its SQL, and a value object of the
     *   smi2 client, such as UInt64 or MapType, as compileClientTypeLiteral() writes it;
     * - any other Stringable object, such as Str::of() or a ramsey/uuid UUID, becomes a quoted string.
     *
     * A collection or any other Arrayable or Traversable value is rejected, although a collection is Stringable. So is
     * any other value, such as stdClass, a closure or a resource.
     *
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException When the value cannot be written as a literal, or is a Laravel database
     *                                  expression and the grammar has no Laravel grammar resolver
     */
    public function compileLiteral(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->compileFloatLiteral($value),
            is_string($value) => $this->compileStringLiteral($value),
            is_array($value) => '[' . implode(', ', array_map(fn (mixed $element): string => $this->compileLiteral($element), $value)) . ']',
            $value instanceof ExpressionContract => (string) $this->getLaravelExpressionValue($value),
            $value instanceof ClientExpression => $value->getValue(),
            $value instanceof ClientType => $this->compileClientTypeLiteral($value),
            $value instanceof DateTimeInterface => $this->compileStringLiteral($this->formatDateTime($value)),
            $value instanceof BackedEnum => $this->compileLiteral($value->value),
            $value instanceof UnitEnum => $this->compileStringLiteral($value->name),
            $value instanceof Arrayable && !$value instanceof Traversable => throw new InvalidArgumentException(sprintf(
                'Cannot render a value of type %s in a ClickHouse query. Pass a scalar value, such as $model->getKey(), or a PHP array.',
                get_debug_type($value)
            )),
            $value instanceof Arrayable, $value instanceof Traversable => throw new InvalidArgumentException(sprintf(
                'Cannot render a value of type %s in a ClickHouse query. Pass a PHP array instead; whereIn() and the other In methods also accept a collection.',
                get_debug_type($value)
            )),
            $value instanceof Stringable => $this->compileStringLiteral((string) $value),
            default => throw new InvalidArgumentException(sprintf(
                'Cannot render a value of type %s in a ClickHouse query. Pass a scalar, null, array, DateTimeInterface, enum or Stringable value, or raw SQL with DB::raw().',
                get_debug_type($value)
            )),
        };
    }

    /**
     * Write a string as a quoted ClickHouse string literal.
     *
     * addslashes() puts a backslash before each single quote, double quote, backslash and NUL byte, which ClickHouse
     * reads back as the character itself (\0 as the NUL byte), in queries and in the VALUES format. Any other byte,
     * such as a newline, a tab or a byte of a multibyte character, is written as it is.
     *
     * @param string $value
     * @return string
     */
    public function compileStringLiteral(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    /**
     * Quote a string as a ClickHouse string literal, escaping backslashes and single quotes only.
     *
     * @param string $value
     * @return string
     */
    public function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Quote a name as one back-quoted identifier. The name is not split on dots. ClickHouse reads a backslash inside
     * a back-quoted identifier as an escape character, so a backslash is doubled as well as a backtick.
     *
     * @param string $name
     * @return string
     */
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace(['\\', '`'], ['\\\\', '``'], $name) . '`';
    }

    /**
     * Escape column names for smi2's Client::insert(), which writes each name between backticks as given: backslashes
     * and backticks are doubled, as quoteIdentifier() does inside its backticks, so that each name stays one
     * identifier. Names without either, such as plain names and Nested names like n.a, are returned unchanged.
     *
     * @param array<int, int|string> $columns
     * @return array<int, string>
     */
    public function escapeInsertColumns(array $columns): array
    {
        return array_map(
            fn (int|string $column): string => substr($this->quoteIdentifier((string) $column), 1, -1),
            $columns
        );
    }

    /**
     * Compile the settings of a query: name=value, ..., without the SETTINGS keyword, or '' for none.
     *
     * Booleans become 1 or 0, integers and floats are written as numbers, strings as escaped string literals, a Laravel
     * database expression, such as DB::raw(), as the SQL it gives, a backed enum as its value and any other Stringable
     * object, such as Str::of() or a Carbon date, as its string. A collection or any other Arrayable or Traversable
     * value is rejected. A null value is left out.
     *
     * @param array<int|string, mixed> $settings
     * @return string
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type
     */
    public function compileSettings(array $settings): string
    {
        $compiled = [];
        foreach ($settings as $name => $value) {
            if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException(
                    "Invalid ClickHouse setting name [{$name}]: a setting name must match [A-Za-z_][A-Za-z0-9_]*."
                );
            }

            if ($value !== null) {
                $compiled[] = $name . '=' . $this->compileSettingValue($name, $value);
            }
        }

        return implode(', ', $compiled);
    }

    /**
     * Compile the value of one setting (see compileSettings()). The Stringable arm comes after the expression arm,
     * because a Laravel expression can be Stringable too, and after the Arrayable and Traversable arm, because a
     * collection is Stringable and would be written as its JSON.
     *
     * @param string $name
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException When the value has an unsupported type
     */
    protected function compileSettingValue(string $name, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_string($value) => $this->quoteString($value),
            $value instanceof ExpressionContract => (string) $this->getLaravelExpressionValue($value),
            $value instanceof BackedEnum => $this->compileSettingValue($name, $value->value),
            $value instanceof Arrayable, $value instanceof Traversable => throw $this->invalidSettingValue($name, $value),
            $value instanceof Stringable => $this->quoteString((string) $value),
            default => throw $this->invalidSettingValue($name, $value),
        };
    }

    /**
     * Build the exception for a setting value that compileSettingValue() cannot write.
     *
     * @param string $name
     * @param mixed $value
     * @return InvalidArgumentException
     */
    protected function invalidSettingValue(string $name, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(
            "Invalid value for ClickHouse setting [{$name}]: expected bool, int, float, string, a database expression,"
            . ' a backed enum or a Stringable object other than a collection or another Arrayable or Traversable value,'
            . ' got ' . get_debug_type($value) . '. For a map or an array, pass the SQL with DB::raw().'
        );
    }

    /**
     * Write a float as a ClickHouse number literal: nan, inf or -inf for a value that is not finite, and otherwise the
     * text of var_export(), which reads back as the same float, such as 0.1, 2.0 or 1.0E+25. PHP's own string
     * conversion keeps only 14 significant digits.
     *
     * @param float $value
     * @return string
     */
    protected function compileFloatLiteral(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        return var_export($value, true);
    }

    /**
     * Write a value object of the smi2 client as a ClickHouse literal, with the values in it written by
     * compileLiteral():
     * - a number type, such as UInt64, Int64, Float64 or Decimal, is written as its number without quotes, and a
     *   Boolean as its 1, 0, true or false; a value that is no such number or boolean becomes a string literal, so
     *   that it cannot add SQL to the query;
     * - a MapType becomes map(key, value, ...), and a TupleType (value, ...);
     * - any other type, such as StringType, Date, DateTime64, UUID or IPv4, becomes a quoted string of its value.
     *
     * @param ClientType $value
     * @return string
     * @throws InvalidArgumentException When a value in a MapType or a TupleType cannot be written as a literal
     */
    protected function compileClientTypeLiteral(ClientType $value): string
    {
        if ($value instanceof MapType) {
            $pairs = [];
            foreach ($value->value as $key => $element) {
                $pairs[] = $this->compileLiteral($key) . ', ' . $this->compileLiteral($element);
            }

            return 'map(' . implode(', ', $pairs) . ')';
        }

        if ($value instanceof TupleType) {
            return '(' . implode(', ', array_map(fn (mixed $element): string => $this->compileLiteral($element), $value->value)) . ')';
        }

        $content = $value->getValue();

        if (($value instanceof NumericType || $value instanceof Boolean) && is_string($content)
            && (is_numeric($content) || ($value instanceof Boolean && in_array(strtolower($content), ['true', 'false'], true)))) {
            return $content;
        }

        return $this->compileLiteral($content);
    }

    /**
     * Format a date without quotes and in the date's own time zone: 'Y-m-d H:i:s', or, at the microsecond precision
     * and when the date has a sub-second part, 'Y-m-d H:i:s.u'.
     *
     * A date without a sub-second part keeps the 'Y-m-d H:i:s' form at either precision, because ClickHouse (24.8
     * checked) rejects a literal with a fraction, '.000000' included, that it compares with a DateTime column. The
     * method can be passed on as a date formatter: $grammar->formatDateTime(...).
     *
     * @param DateTimeInterface $value
     * @return string
     */
    public function formatDateTime(DateTimeInterface $value): string
    {
        if ($this->hasSubSecondPart($value)) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return $value->format('Y-m-d H:i:s');
    }

    /**
     * Determine if a date is written with its sub-second part: at the microsecond precision, a date whose
     * microseconds are not 0.
     *
     * @param DateTimeInterface $value
     * @return bool
     */
    public function hasSubSecondPart(DateTimeInterface $value): bool
    {
        return $this->dateTimePrecision === DateTimePrecision::Microsecond && $value->format('u') !== '000000';
    }

    /**
     * Set the precision of the dates that formatDateTime() writes.
     *
     * @param DateTimePrecision $precision
     * @return static
     */
    public function setDateTimePrecision(DateTimePrecision $precision): static
    {
        $this->dateTimePrecision = $precision;

        return $this;
    }

    /**
     * Get the precision of the dates that formatDateTime() writes.
     *
     * @return DateTimePrecision
     */
    public function getDateTimePrecision(): DateTimePrecision
    {
        return $this->dateTimePrecision;
    }

    /**
     * Set the closure that resolves the grammar of the database connection, which a Laravel database expression,
     * such as DB::raw(), needs to give its SQL. It is only called when such an expression is written.
     *
     * @param (Closure(): \Illuminate\Database\Grammar)|null $resolver
     * @return static
     */
    public function setLaravelGrammarResolver(?Closure $resolver): static
    {
        $this->laravelGrammarResolver = $resolver;

        return $this;
    }

    /**
     * Get the SQL of a Laravel database expression, such as DB::raw('count()'), from the grammar of the database
     * connection that the Laravel grammar resolver returns.
     *
     * @param ExpressionContract $expression
     * @return string|int|float
     * @throws InvalidArgumentException When the grammar has no Laravel grammar resolver
     */
    public function getLaravelExpressionValue(ExpressionContract $expression): string|int|float
    {
        if ($this->laravelGrammarResolver === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot write the database expression %s without the grammar of a database connection. Use the'
                . ' grammar of a ClickHouse connection: Connection::newLiteralGrammar().',
                get_debug_type($expression)
            ));
        }

        return $expression->getValue(($this->laravelGrammarResolver)());
    }
}
