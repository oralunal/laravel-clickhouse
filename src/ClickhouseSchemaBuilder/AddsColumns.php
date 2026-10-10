<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder;

use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\InvalidClickHouseDDLException;

trait AddsColumns
{
    public function string(string $name): Column
    {
        return $this->column($name, 'String');
    }

    public function date(string $name): Column
    {
        return $this->column($name, 'Date');
    }

    public function datetime(string $name, ?int $precision = null, ?string $timezone = null): Column
    {
        $type = $precision ? 'DateTime64' : 'DateTime';
        $params = [];
        if ($precision) {
            $params[] = $precision;
        }
        if ($timezone) {
            $params[] = $timezone;
        }
        return $this->column($name, $type, $params);
    }

    /**
     * Add an Enum column.
     *
     * An array with at least one string key maps each name to its number:
     * ['a' => 1, 'b' => 2] gives Enum('a' = 1, 'b' = 2). Every key is then a
     * name, an integer key too, because PHP turns a numeric string key into
     * an integer: ['active' => 1, '200' => 2] gives Enum('active' = 1,
     * '200' = 2). Every value must then be an integer or a string of one.
     *
     * An array whose keys are all integers is a list of names, written from
     * its values in order whatever its keys, as Laravel's enum() reads its
     * allowed values: [200, 404] and array_unique([200, 404, 200]) give
     * Enum('200', '404'), and ['200' => 1, '404' => 2] gives Enum('1', '2').
     * To number names that are all numbers, write the type yourself:
     * column('status', "Enum8('200' = 1, '404' = 2)").
     *
     * The names are written as escaped string literals.
     *
     * @param string $name
     * @param array<int|string, int|string> $values
     * @return Column
     * @throws InvalidClickHouseDDLException When there is no value, or a number is not an integer
     */
    public function enum(string $name, array $values): Column
    {
        if (empty($values)) {
            throw new InvalidClickHouseDDLException("Enum $name must contain at least one value");
        }
        $values0 = [];
        if (array_any($values, fn (mixed $index, int|string $value): bool => is_string($value))) {
            foreach ($values as $value => $index) {
                if (!is_int($index) && !(is_string($index) && preg_match('/\A-?\d+\z/', $index) === 1)) {
                    throw new InvalidClickHouseDDLException(
                        "Enum $name must give the value '$value' an integer."
                        . ' An array with a string key maps each name to its number, an integer key being a name too.'
                    );
                }
                $values0[] = Syntax::quoteString((string) $value) . " = $index";
            }
        } else {
            foreach ($values as $value) {
                $values0[] = Syntax::quoteString((string) $value);
            }
        }
        return $this->column($name, 'Enum(' . implode(', ', $values0) . ')');
    }

    public function uuid(string $name): Column
    {
        return $this->column($name, 'UUID');
    }

    public function bool(string $name): Column
    {
        return $this->column($name, 'Bool');
    }

    public function integer(string $name, int $bits = 32, bool $withSign = true): Column
    {
        return $this->column($name, ($withSign ? '' : 'U') . 'Int' . $bits);
    }

    public function int8(string $name): Column
    {
        return $this->column($name, 'Int8');
    }

    public function int16(string $name): Column
    {
        return $this->column($name, 'Int16');
    }

    public function int32(string $name): Column
    {
        return $this->column($name, 'Int32');
    }

    public function int64(string $name): Column
    {
        return $this->column($name, 'Int64');
    }

    public function int128(string $name): Column
    {
        return $this->column($name, 'Int128');
    }

    public function int256(string $name): Column
    {
        return $this->column($name, 'Int256');
    }

    public function uInt8(string $name): Column
    {
        return $this->column($name, 'UInt8');
    }

    public function uInt16(string $name): Column
    {
        return $this->column($name, 'UInt16');
    }

    public function uInt32(string $name): Column
    {
        return $this->column($name, 'UInt32');
    }

    public function uInt64(string $name): Column
    {
        return $this->column($name, 'UInt64');
    }

    public function uInt128(string $name): Column
    {
        return $this->column($name, 'UInt128');
    }

    public function uInt256(string $name): Column
    {
        return $this->column($name, 'UInt256');
    }

    public function float(string $name, int $bits = 32): Column
    {
        return $this->column($name, 'Float' . $bits);
    }

    public function float32(string $name): Column
    {
        return $this->column($name, 'Float32');
    }

    public function float64(string $name): Column
    {
        return $this->column($name, 'Float64');
    }

    /**
     * @param string $name
     * @param int $precision Valid range: [ 1 : 76 ]. Determines how many decimal digits number can have (including fraction). By default, the precision is 10.
     * @param int $scale Valid range: [ 0 : $precision ]. Determines how many decimal digits fraction can have.
     * @return Column
     */
    public function decimal(string $name, int $precision, int $scale): Column
    {
        return $this->column($name, "Decimal($precision, $scale)");
    }

    /**
     * @param string $name
     * @param int $scale Valid range: [ 0 : 9 ]. Determines how many decimal digits fraction can have.
     * @return Column
     */
    public function decimal32(string $name, int $scale): Column
    {
        return $this->column($name, "Decimal32($scale)");
    }

    /**
     * @param string $name
     * @param int $scale Valid range: [ 0 : 18 ]. Determines how many decimal digits fraction can have.
     * @return Column
     */
    public function decimal64(string $name, int $scale): Column
    {
        return $this->column($name, "Decimal64($scale)");
    }

    /**
     * @param string $name
     * @param int $scale Valid range: [ 0 : 38 ]. Determines how many decimal digits fraction can have.
     * @return Column
     */
    public function decimal128(string $name, int $scale): Column
    {
        return $this->column($name, "Decimal128($scale)");
    }

    /**
     * @param string $name
     * @param int $scale Valid range: [ 0 : 76 ]. Determines how many decimal digits fraction can have.
     * @return Column
     */
    public function decimal256(string $name, int $scale): Column
    {
        return $this->column($name, "Decimal256($scale)");
    }

}