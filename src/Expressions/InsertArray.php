<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Expressions;

use ClickHouseDB\Query\Expression\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar;

/**
 * Class InsertArray
 * @package Oralunal\LaravelClickHouse
 *
 * Used to insert Array datatype. getValue() writes the items as a ClickHouse array literal for a Values insert;
 * every string item is escaped (backslashes and single quotes), so an item with a quote or a trailing backslash
 * can no longer end the literal early and change the statement. TYPE_STRING and TYPE_STRING_ESCAPE therefore
 * produce the same, safe literal; a value a caller escaped by hand is escaped again. A TYPE_DECIMAL item is
 * written as the float that floatval() gives, with every digit, as the query builder writes a float value
 * (see Grammar::compileLiteral()): 1/3 as 0.3333333333333333, 2 as 2.0, and NAN, INF and -INF as nan, inf and
 * -inf. An empty array of any type is written as [].
 * @link https://clickhouse.tech/docs/ru/sql-reference/data-types/array/
 * @example Model::insertAssoc([[1,'str',new InsertArray(['a','b'])]]);
 */
class InsertArray implements Expression
{
    const TYPE_STRING = 'string';
    const TYPE_STRING_ESCAPE = 'string_e';
    // Can also be used for float types
    const TYPE_DECIMAL = 'decimal';
    const TYPE_INT = 'int';

    private $expression;

    /**
     * The items as the constructor got them.
     *
     * @var array<int|string, mixed>
     */
    private array $items;

    /**
     * The type that the constructor got.
     *
     * @var string
     */
    private string $type;

    /**
     * @param array $items
     * @param string $type
     */
    public function __construct(array $items, string $type = self::TYPE_STRING)
    {
        $this->items = $items;
        $this->type = $type;

        if (self::TYPE_INT == $type) {
            $this->expression = "[" . implode(",", array_map('intval', $items)) . "]";
        } elseif (self::TYPE_DECIMAL == $type) {
            $grammar = new Grammar();
            $this->expression = "[" . implode(",", array_map(
                fn ($item): string => $grammar->compileLiteral(floatval($item)),
                $items
            )) . "]";
        } else {
            $quoted = array_map(
                fn ($item): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $item) . "'",
                $items
            );
            $this->expression = "[" . implode(",", $quoted) . "]";
        }
    }

    /**
     * @return bool
     */
    public function needsEncoding(): bool
    {
        return false;
    }

    /**
     * The ClickHouse array literal for a Values insert, with every string item escaped and every decimal item
     * written with all its digits (see the class docblock).
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->expression;
    }

    /**
     * Get the items as a list, with the type of the constructor applied, as getValue() writes them: intval()
     * for TYPE_INT, floatval() for TYPE_DECIMAL (getValue() writes each of these floats as
     * Grammar::compileLiteral() does), and strval() for TYPE_STRING and TYPE_STRING_ESCAPE, without the escaping
     * that getValue() adds.
     *
     * The JSONEachRow encoder sends these items instead of the SQL of getValue().
     *
     * @return array<int, int|float|string>
     */
    public function getItems(): array
    {
        $cast = match ($this->type) {
            self::TYPE_INT => 'intval',
            self::TYPE_DECIMAL => 'floatval',
            default => 'strval',
        };

        return array_values(array_map($cast, $this->items));
    }
}
