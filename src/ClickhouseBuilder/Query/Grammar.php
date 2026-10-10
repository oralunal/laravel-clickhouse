<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query;

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
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ArrayCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ArrayJoinComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ColumnsComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\FormatComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\FromComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\GroupsComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\HavingsComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\JoinComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\LimitByComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\LimitComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\OrdersComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\PreWheresComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\SampleComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\TupleCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\TwoElementsLogicExpressionsCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\UnionsComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\WheresComponentCompiler;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\WithsComponentCompiler;
use Stringable;
use Traversable;
use UnitEnum;

class Grammar
{
    use ColumnsComponentCompiler;
    use FromComponentCompiler;
    use ArrayJoinComponentCompiler;
    use JoinComponentCompiler;
    use TwoElementsLogicExpressionsCompiler;
    use WheresComponentCompiler;
    use PreWheresComponentCompiler;
    use HavingsComponentCompiler;
    use SampleComponentCompiler;
    use GroupsComponentCompiler;
    use OrdersComponentCompiler;
    use LimitComponentCompiler;
    use LimitByComponentCompiler;
    use UnionsComponentCompiler;
    use FormatComponentCompiler;
    use TupleCompiler;
    use ArrayCompiler;
    use WithsComponentCompiler;

    protected $selectComponents = [
        'columns',
        'from',
        'sample',
        'arrayJoin',
        'joins',
        'prewheres',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limitBy',
        'limit',
        'unions',
        'format',
    ];

    /**
     * The precision of the DateTimeInterface values that the grammar writes: DateTimePrecision::SECOND or
     * DateTimePrecision::MICROSECOND (see formatDateTime()).
     *
     * @var string
     */
    protected $dateTimePrecision = DateTimePrecision::SECOND;

    /**
     * Resolves the grammar of a database connection, which a Laravel database expression, such as DB::raw(), needs
     * to give its SQL; null when the grammar has none (see getLaravelExpressionValue()).
     *
     * @var (Closure(): \Illuminate\Database\Grammar)|null
     */
    protected ?Closure $laravelGrammarResolver = null;

    /**
     * Compiles select query.
     *
     * @param BaseBuilder $query
     *
     * @return string
     */
    public function compileSelect(BaseBuilder $query)
    {
        if (empty($query->getColumns())) {
            $query->select();
        }

        $sql = [];

        foreach ($this->selectComponents as $component) {
            $compileMethod = 'compile'.ucfirst($component).'Component';
            $component = 'get'.ucfirst($component);

            if (!is_null($query->$component()) && !empty($query->$component())) {
                $sql[$component] = $this->$compileMethod($query, $query->$component());
            }
        }

        $select = trim('SELECT '.trim(implode(' ', $sql)));

        if (!empty($query->getWiths())) {
            return $this->compileWithsComponent($query, $query->getWiths()).' '.$select;
        }

        return $select;
    }

    /**
     * Compile insert query for values.
     *
     * @param BaseBuilder $query
     * @param             $values
     *
     * @throws GrammarException
     *
     * @return string
     */
    public function compileInsert(BaseBuilder $query, $values): string
    {
        $result = [];

        $from = $query->getFrom();

        if (is_null($from)) {
            throw GrammarException::missedTableForInsert();
        }

        $table = $from->getTable();

        if (!$table instanceof Identifier && !$table instanceof Expression) {
            throw GrammarException::missedTableForInsert();
        }

        $table = $this->wrap($table);

        $format = $query->getFormat() ?? Format::VALUES;

        if ($format == Format::VALUES) {
            $columns = array_map(function ($col) {
                return is_string($col) ? new Identifier($col) : null;
            }, array_keys($values[0]));

            $columns = array_filter($columns);
        }

        $columns = $this->compileTuple(new Tuple($columns));

        $result[] = "INSERT INTO {$table}";

        if ($columns !== '') {
            $result[] = "({$columns})";
        }

        $result[] = 'FORMAT '.$format;

        if ($format == Format::VALUES) {
            $result[] = $this->compileInsertValues($values);
        }

        return implode(' ', $result);
    }

    /**
     * Compiles create table query.
     *
     * @param             $tableName
     * @param string      $engine
     * @param array       $structure
     * @param bool        $ifNotExists
     * @param string|null $clusterName
     * @param string|null $extraOptions
     *
     * @return string
     */
    public function compileCreateTable($tableName, string $engine, array $structure, bool $ifNotExists = false, ?string $clusterName = null, ?string $extraOptions = null): string
    {
        if ($tableName instanceof Identifier) {
            $tableName = (string) $tableName;
        }

        $onCluster = $clusterName === null ? '' : "ON CLUSTER {$clusterName}";
        $extraOptions = $extraOptions ?? '';

        return 'CREATE TABLE '
            .($ifNotExists ? 'IF NOT EXISTS ' : '')
            .rtrim("{$tableName} {$onCluster} ({$this->compileTableStructure($structure)}) ENGINE = {$engine} {$extraOptions}");
    }

    /**
     * Compiles drop table query.
     *
     * @param             $tableName
     * @param bool        $ifExists
     * @param string|null $clusterName
     *
     * @return string
     */
    public function compileDropTable($tableName, bool $ifExists = false, ?string $clusterName = null): string
    {
        if ($tableName instanceof Identifier) {
            $tableName = (string) $tableName;
        }

        $onCluster = $clusterName === null ? '' : "ON CLUSTER {$clusterName}";

        return trim('DROP TABLE '.($ifExists ? 'IF EXISTS ' : '')."{$tableName} {$onCluster}");
    }

    /**
     * Assembles table structure.
     *
     * @param array $structure
     *
     * @return string
     */
    public function compileTableStructure(array $structure): string
    {
        $result = [];

        foreach ($structure as $column => $type) {
            $result[] = $column.' '.$type;
        }

        return implode(', ', $result);
    }

    /**
     * Compiles rows for the VALUES format. An array value becomes a ClickHouse array literal.
     *
     * @param array $values
     *
     * @return string
     */
    public function compileInsertValues($values)
    {
        return implode(', ', array_map(function ($value) {
            return '('.implode(', ', array_map(function ($value) {
                return is_array($value) ? $this->compileArray($value) : $this->wrap($value);
            }, $value)).')';
        }, $values));
    }

    /**
     * Compile delete query.
     *
     * @param BaseBuilder $query
     *
     * @throws GrammarException
     *
     * @return string
     */
    public function compileDelete(BaseBuilder $query)
    {
        $this->verifyFrom($query->getFrom());

        $sql = "ALTER TABLE {$this->wrap($query->getFrom()->getTable())}";

        if (!is_null($query->getOnCluster())) {
            $sql .= " ON CLUSTER {$query->getOnCluster()}";
        }

        $sql .= ' DELETE';

        if (!is_null($query->getWheres()) && !empty($query->getWheres())) {
            $sql .= " {$this->compileWheresComponent($query, $query->getWheres())}";
        } else {
            throw GrammarException::missedWhereForDelete();
        }

        return $sql;
    }

    /**
     * Convert value in literal.
     *
     * An Expression is returned as written, and a Laravel database expression, such as DB::raw(), as the SQL it
     * gives (see getLaravelExpressionValue()). An array becomes an array of the converted elements, keyed as given.
     * An Identifier is quoted with quoteIdentifier(), part by part when it contains a dot or ' as '. An int is
     * returned as it is, a bool as 1 or 0 and a BackedEnum as its value converted. Any other value is written by
     * compileLiteral(): a float with every digit, or as nan, inf or -inf, a DateTimeInterface as a quoted string at
     * the precision of formatDateTime(), a UnitEnum as its quoted name, a value object or an expression of the smi2
     * client as compileLiteral() describes, and any other Stringable as a quoted string.
     *
     * An Arrayable or Traversable value is rejected. For a collection or another Traversable value the message
     * asks for a PHP array; for any other Arrayable, such as an Eloquent model, it asks for a scalar value, such
     * as $model->getKey(), or a PHP array.
     *
     * @param string|int|float|bool|null|Expression|ExpressionContract|Identifier|DateTimeInterface|UnitEnum|ClientType|ClientExpression|Stringable|array $value
     *
     * @throws InvalidArgumentException when the value cannot be rendered as SQL, or is a Laravel database expression
     *                                  and the grammar has no Laravel grammar resolver
     *
     * @return string|int|float|array
     */
    public function wrap($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        } elseif ($value instanceof ExpressionContract) {
            return $this->getLaravelExpressionValue($value);
        } elseif (is_array($value)) {
            return array_map([$this, 'wrap'], $value);
        } elseif ($value instanceof Identifier) {
            $value = (string) $value;

            if (strpos(strtolower($value), '.') !== false) {
                return implode('.', array_map(function ($element) {
                    return $this->wrap(new Identifier($element));
                }, array_map('trim', preg_split('/\./', $value))));
            }

            if (strpos(strtolower($value), ' as ') !== false) {
                list($value, $alias) = array_map('trim', preg_split('/\s+as\s+/i', $value));

                $value = $this->wrap(new Identifier($value));
                $alias = $this->wrap(new Identifier($alias));

                $value = "$value AS $alias";

                return $value;
            }

            if ($value === '*') {
                return $value;
            }

            return $this->quoteIdentifier($value);
        } elseif (is_int($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return $value ? 1 : 0;
        } elseif ($value instanceof BackedEnum) {
            return $this->wrap($value->value);
        }

        return $this->compileLiteral($value);
    }

    /**
     * Write a PHP value as a ClickHouse literal.
     *
     * - null becomes null, a bool 1 or 0, and an int its digits;
     * - a finite float is written with var_export(), the shortest text that reads back as the same float, so it
     *   keeps every digit (1/3 becomes 0.3333333333333333 and 2.0 becomes 2.0); NaN, INF and -INF become nan, inf
     *   and -inf;
     * - a string becomes a quoted string literal (see compileStringLiteral());
     * - an array becomes an array literal of its elements, written by this method: [1, 'a', [2.5, null]]; keys are
     *   ignored and nested arrays stay nested;
     * - a DateTimeInterface becomes a quoted string in its own time zone, at the precision of formatDateTime();
     * - a BackedEnum is written as its value, and a UnitEnum as its name in a string literal;
     * - an Expression is written as it is, a Laravel database expression, such as DB::raw(), as the SQL it gives
     *   (see getLaravelExpressionValue()), and an Identifier as a quoted name, as wrap() writes it;
     * - an expression of the smi2 client, such as its Raw('now()') or this package's InsertArray, is written as its
     *   SQL, and a value object of the smi2 client, such as UInt64 or MapType, as compileClientTypeLiteral() writes
     *   it;
     * - any other Stringable object, such as Str::of() or a ramsey/uuid UUID, becomes a quoted string.
     *
     * A collection or any other Arrayable or Traversable value is rejected, although a collection is Stringable, as
     * wrap() does. So is any other value, such as stdClass, a closure or a resource.
     *
     * @param mixed $value
     *
     * @throws InvalidArgumentException When the value cannot be written as a literal, or is a Laravel database
     *                                  expression and the grammar has no Laravel grammar resolver
     *
     * @return string
     */
    public function compileLiteral(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->compileFloatLiteral($value),
            is_string($value) => $this->compileStringLiteral($value),
            is_array($value) => '['.implode(', ', array_map(fn (mixed $element): string => $this->compileLiteral($element), $value)).']',
            $value instanceof Expression => (string) $value->getValue(),
            $value instanceof ExpressionContract => (string) $this->getLaravelExpressionValue($value),
            $value instanceof Identifier => (string) $this->wrap($value),
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
                'Cannot render a value of type %s in a ClickHouse query. Pass a scalar, null, array, DateTimeInterface, enum or Stringable value, or raw SQL as an %s.',
                get_debug_type($value),
                Expression::class
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
     *
     * @return string
     */
    public function compileStringLiteral(string $value): string
    {
        return "'".addslashes($value)."'";
    }

    /**
     * Write a float as a ClickHouse number literal: nan, inf or -inf for a value that is not finite, and otherwise
     * the text of var_export(), which reads back as the same float, such as 0.1, 2.0 or 1.0E+25.
     *
     * PHP's own string conversion keeps only 14 significant digits, and warns for NaN on PHP 8.5.
     *
     * @param float $value
     *
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
     * Write a value object of the smi2 client as a ClickHouse literal, in the form that smi2 writes into a query,
     * with the values in it written by compileLiteral():
     * - a number type, such as UInt64, Int64, Float64 or Decimal, is written as its number without quotes, and a
     *   Boolean as its 1, 0, true or false; a value that is no such number or boolean, which smi2 does not check
     *   for UInt64, Int64, Decimal and Boolean, becomes a string literal, so that it cannot add SQL to the query;
     * - a MapType becomes map(key, value, ...), and a TupleType (value, ...);
     * - any other type, such as StringType, Date, DateTime64, UUID or IPv4, becomes a literal of its value, a quoted
     *   string for each type of the smi2 client.
     *
     * @param ClientType $value
     *
     * @throws InvalidArgumentException When a value in a MapType or a TupleType cannot be written as a literal
     *
     * @return string
     */
    protected function compileClientTypeLiteral(ClientType $value): string
    {
        if ($value instanceof MapType) {
            $pairs = [];

            foreach ($value->value as $key => $element) {
                $pairs[] = $this->compileLiteral($key).', '.$this->compileLiteral($element);
            }

            return 'map('.implode(', ', $pairs).')';
        }

        if ($value instanceof TupleType) {
            return '('.implode(', ', array_map(fn (mixed $element): string => $this->compileLiteral($element), $value->value)).')';
        }

        $content = $value->getValue();

        if (($value instanceof NumericType || $value instanceof Boolean) && is_string($content)
            && (is_numeric($content) || ($value instanceof Boolean && in_array(strtolower($content), ['true', 'false'], true)))) {
            return $content;
        }

        return $this->compileLiteral($content);
    }

    /**
     * Format a date as the grammar writes it, without quotes and in the date's own time zone: 'Y-m-d H:i:s', or, at
     * DateTimePrecision::MICROSECOND and when the date has a sub-second part, 'Y-m-d H:i:s.u'.
     *
     * A date without a sub-second part keeps the 'Y-m-d H:i:s' form at either precision, because ClickHouse (24.8
     * checked) rejects a literal with a fraction, '.000000' included, that it compares with a DateTime column. The
     * method can be passed on as a date formatter: $grammar->formatDateTime(...).
     *
     * @param DateTimeInterface $value
     *
     * @return string
     */
    public function formatDateTime(DateTimeInterface $value): string
    {
        if ($this->dateTimePrecision === DateTimePrecision::MICROSECOND && $value->format('u') !== '000000') {
            return $value->format('Y-m-d H:i:s.u');
        }

        return $value->format('Y-m-d H:i:s');
    }

    /**
     * Set the precision of the DateTimeInterface values that the grammar writes (see formatDateTime()).
     *
     * @param string|DateTimePrecision $precision DateTimePrecision::SECOND, the default, or DateTimePrecision::MICROSECOND
     *
     * @throws InvalidArgumentException For any other precision
     *
     * @return static
     */
    public function setDateTimePrecision(string|DateTimePrecision $precision): static
    {
        $precision = $precision instanceof DateTimePrecision ? $precision->getValue() : $precision;

        if (!DateTimePrecision::isValid($precision)) {
            throw new InvalidArgumentException(
                "Invalid datetime precision [{$precision}]: use '".implode("' or '", DateTimePrecision::toArray())."'."
            );
        }

        $this->dateTimePrecision = $precision;

        return $this;
    }

    /**
     * Get the precision of the DateTimeInterface values that the grammar writes: DateTimePrecision::SECOND or
     * DateTimePrecision::MICROSECOND.
     *
     * @return string
     */
    public function getDateTimePrecision(): string
    {
        return $this->dateTimePrecision;
    }

    /**
     * Set the closure that resolves the grammar of the database connection, which a Laravel database expression,
     * such as DB::raw(), needs to give its SQL. It is only called when such an expression is written.
     *
     * @param (Closure(): \Illuminate\Database\Grammar)|null $resolver
     *
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
     *
     * @throws InvalidArgumentException When the grammar has no Laravel grammar resolver
     *
     * @return string|int|float
     */
    public function getLaravelExpressionValue(ExpressionContract $expression): string|int|float
    {
        if ($this->laravelGrammarResolver === null) {
            throw new InvalidArgumentException(sprintf(
                'Cannot write the Laravel database expression %s without the grammar of a database connection. Build the query from a ClickHouse connection or a model, or pass raw SQL as an %s.',
                get_debug_type($expression),
                Expression::class
            ));
        }

        return $expression->getValue(($this->laravelGrammarResolver)());
    }

    /**
     * Quotes a name as one back-quoted identifier.
     *
     * The name is not split on dots or ' as '. ClickHouse reads a backslash inside a back-quoted identifier as
     * an escape character, so a backslash is doubled as well as a backtick.
     *
     * @param string $name
     *
     * @return string
     */
    public function quoteIdentifier(string $name): string
    {
        return '`'.str_replace(['\\', '`'], ['\\\\', '``'], $name).'`';
    }
}
