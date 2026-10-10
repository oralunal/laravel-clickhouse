<?php

namespace Oralunal\LaravelClickHouse;


use BackedEnum;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Concerns\SubstitutesBindings;
use Stringable;
use Traversable;

class Grammar extends \Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar
{
    use SubstitutesBindings;

    public function __construct()
    {
        $this->selectComponents[] = 'settings';
    }

    /**
     * Compile the SETTINGS clause of a SELECT statement.
     *
     * Booleans become 1 or 0, integers and floats are written as numbers, strings
     * as escaped string literals, expressions as written and a Laravel database
     * expression, such as DB::raw(), as the SQL it gives. A backed enum is
     * written as its value, and any other Stringable object, such as Str::of()
     * or a Carbon date, as its string. A collection or any other Arrayable or
     * Traversable value is rejected, although a collection is Stringable. A null
     * value is left out.
     *
     * @param mixed $_
     * @param array<string, bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable|null> $settings
     * @return string
     * @throws InvalidArgumentException When a setting name is not a plain identifier or a value has an unsupported type,
     *                                  such as a collection, or is a Laravel database expression and the grammar has no
     *                                  Laravel grammar resolver
     */
    public function compileSettingsComponent($_, array $settings): string
    {
        $compiled = [];
        foreach ($settings as $name => $value) {
            $this->verifySettingName($name);
            if ($value === null) {
                continue;
            }
            $compiled[] = $name . '=' . $this->compileSettingValue($name, $value);
        }

        return $compiled === [] ? '' : 'SETTINGS ' . implode(', ', $compiled);
    }

    /**
     * Quote a string as a ClickHouse string literal, escaping backslashes and single quotes.
     *
     * @param string $value
     * @return string
     */
    public function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Escape column names for smi2's Client::insert(), which writes each name between backticks as given.
     *
     * Backslashes and backticks are doubled, as quoteIdentifier() does inside its backticks, so that each
     * name stays one identifier: a backtick in a name can no longer end the identifier and change the
     * statement, and a trailing backslash no longer escapes the closing backtick. Names without either,
     * such as plain names and Nested names like n.a, are returned unchanged.
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
     * Reject a setting name that is not a plain identifier, so that it cannot change the statement.
     *
     * @param int|string $name
     * @return void
     * @throws InvalidArgumentException
     */
    protected function verifySettingName(int|string $name): void
    {
        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
            throw new InvalidArgumentException(
                "Invalid ClickHouse setting name [{$name}]: a setting name must match [A-Za-z_][A-Za-z0-9_]*."
            );
        }
    }

    /**
     * Compile the value of one setting.
     *
     * The Stringable arm comes after the Expression arms, because an Expression is Stringable as well.
     * An Arrayable or Traversable value is rejected before the Stringable arm, as Grammar::wrap() does,
     * because a collection is Stringable too and would be written as its JSON.
     *
     * @param string $name
     * @param bool|int|float|string|Expression|ExpressionContract|BackedEnum|Stringable $value
     * @return string
     * @throws InvalidArgumentException When the value has an unsupported type, or is Arrayable or Traversable, or is a
     *                                  Laravel database expression and the grammar has no Laravel grammar resolver
     */
    protected function compileSettingValue(string $name, mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => var_export($value, true),
            is_string($value) => $this->quoteString($value),
            $value instanceof Expression => (string) $value->getValue(),
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
            "Invalid value for ClickHouse setting [{$name}]: expected bool, int, float, string, " . Expression::class
            . ', a backed enum or a Stringable object other than a collection or another Arrayable or Traversable value,'
            . ' got ' . get_debug_type($value) . '. For a map or an array, pass the SQL as an ' . Expression::class . '.'
        );
    }
}
