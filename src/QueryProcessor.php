<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use RuntimeException;

/**
 * Turns the rows of the ClickHouse schema queries into the column and index
 * arrays that Laravel's schema builder returns.
 */
class QueryProcessor extends Processor
{
    /**
     * Refuse the insertGetId() of Laravel's base query builder before anything is sent.
     *
     * Laravel's processor inserts the row and then reads the generated ID from PDO,
     * which this connection does not have, so the row was written and the call failed.
     * ClickHouse has no auto-increment columns anyway. The package's QueryBuilder,
     * which the connection hands out, returns the key that the row carries instead,
     * and never calls this method; only a base builder created by hand reaches it,
     * with the row's values but without their column names.
     *
     * @param Builder $query
     * @param string $sql
     * @param array<int, mixed> $values
     * @param string|null $sequence
     * @return never
     * @throws RuntimeException Always
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null): never
    {
        $key = $sequence ?? 'id';

        throw new RuntimeException(
            "ClickHouse has no auto-increment columns, so insertGetId() cannot get the ID of the inserted row. Set the"
            . " [{$key}] value before inserting, for example with Str::uuid(), and call insert(), or use the query"
            . ' builder of the ClickHouse connection, whose insertGetId() returns the key the row carries. On an'
            . ' Eloquent model, set $incrementing to false.'
        );
    }

    /**
     * Process the results of a columns query (see SchemaGrammar::compileColumns()).
     *
     * type and type_name both hold the full ClickHouse type. A column is
     * nullable when its type accepts NULL: Nullable(...),
     * LowCardinality(Nullable(...)), Variant(...), Dynamic, and a
     * SimpleAggregateFunction over one of them; an Array(Nullable(...)) column
     * itself is not (see typeAcceptsNull()). MATERIALIZED columns are
     * stored generated columns and ALIAS columns virtual ones. An EPHEMERAL
     * column is not stored and cannot be selected, but INSERT takes a value for
     * it; its expression, the value used when an INSERT leaves it out, is
     * reported as the default, the same way as for a DEFAULT column.
     *
     * @param list<array{name: string, type: string, default_kind: string, default_expression: string, comment: string}> $results
     * @return list<array{name: string, type_name: string, type: string, collation: null, nullable: bool, default: string|null, auto_increment: false, comment: string|null, generation: array{type: 'stored'|'virtual', expression: string}|null}>
     */
    public function processColumns($results): array
    {
        return array_map(function ($result): array {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'type_name' => $result->type,
                'type' => $result->type,
                'collation' => null,
                'nullable' => static::typeAcceptsNull($result->type),
                'default' => in_array($result->default_kind, ['DEFAULT', 'EPHEMERAL'], true)
                    ? $result->default_expression
                    : null,
                'auto_increment' => false,
                'comment' => $result->comment === '' ? null : $result->comment,
                'generation' => match ($result->default_kind) {
                    'MATERIALIZED' => ['type' => 'stored', 'expression' => $result->default_expression],
                    'ALIAS' => ['type' => 'virtual', 'expression' => $result->default_expression],
                    default => null,
                },
            ];
        }, $results);
    }

    /**
     * Process the results of an indexes query (see SchemaGrammar::compileIndexes()).
     *
     * The primary key is named "primary" and its type is null, as on Laravel's
     * SQLite driver: the primary flag already marks it, so `db:table` lists it
     * once as primary. Its columns, and those of a data-skipping index, are the
     * elements of the key or index expression: a column name, or an expression
     * such as intHash32(id). A data-skipping index has its type, such as
     * minmax or bloom_filter. No ClickHouse index is unique. Names are
     * lowercased, as Laravel's other drivers do, so that hasIndex() finds them.
     *
     * @param list<array{name: string, expression: string, type: string, is_primary: int|string}> $results
     * @return list<array{name: string, columns: list<string>, type: string|null, unique: false, primary: bool}>
     */
    public function processIndexes($results): array
    {
        return array_map(function ($result): array {
            $result = (object) $result;
            $isPrimary = (bool) $result->is_primary;

            return [
                'name' => strtolower($result->name),
                'columns' => static::splitExpressionList($result->expression),
                'type' => $isPrimary ? null : strtolower($result->type),
                'unique' => false,
                'primary' => $isPrimary,
            ];
        }, $results);
    }

    /**
     * Determine if a column of the given ClickHouse type accepts NULL.
     *
     * Nullable(...), LowCardinality(Nullable(...)), Variant(...), Dynamic and
     * Dynamic(...) accept NULL, and so does SimpleAggregateFunction(<function>,
     * <type>) when <type> does. A type that only holds NULL inside, such as
     * Array(Nullable(...)) or Array(Variant(...)), does not.
     *
     * The rule is public so that the schema grammar can apply it to the
     * column types it compiles.
     *
     * @param string $type The full ClickHouse type, as system.columns prints it.
     * @return bool
     */
    public static function typeAcceptsNull(string $type): bool
    {
        if (preg_match('/\ASimpleAggregateFunction\((.+)\)\z/s', $type, $matches) === 1) {
            $arguments = static::splitExpressionList($matches[1]);

            return count($arguments) > 1 && static::typeAcceptsNull($arguments[array_key_last($arguments)]);
        }

        return str_starts_with($type, 'Nullable(')
            || str_starts_with($type, 'LowCardinality(Nullable(')
            || str_starts_with($type, 'Variant(')
            || $type === 'Dynamic'
            || str_starts_with($type, 'Dynamic(');
    }

    /**
     * Split an expression list, as ClickHouse prints a primary key or the
     * expression of a data-skipping index, at its top-level commas.
     *
     * Commas inside parentheses, brackets, braces and quotes do not split. An
     * element that is a single quoted identifier, such as `my column`, is unquoted.
     *
     * @param string $expressionList For example: id, intHash32(id)
     * @return list<string> For example: ['id', 'intHash32(id)']
     */
    protected static function splitExpressionList(string $expressionList): array
    {
        $elements = [];
        $depth = 0;
        $quote = null;
        $start = 0;
        $length = strlen($expressionList);

        for ($position = 0; $position < $length; $position++) {
            $character = $expressionList[$position];

            if ($quote !== null) {
                if ($character === '\\') {
                    $position++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '(' || $character === '[' || $character === '{') {
                $depth++;
            } elseif ($character === ')' || $character === ']' || $character === '}') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $elements[] = substr($expressionList, $start, $position - $start);
                $start = $position + 1;
            }
        }
        $elements[] = substr($expressionList, $start);

        $elements = array_map(fn (string $element): string => static::unquoteIdentifier(trim($element)), $elements);

        return array_values(array_filter($elements, fn (string $element): bool => $element !== ''));
    }

    /**
     * Remove the backticks or double quotes around an element that is a single
     * quoted identifier, undoing ClickHouse's backslash escapes. Any other
     * element is returned unchanged.
     *
     * @param string $element
     * @return string
     */
    protected static function unquoteIdentifier(string $element): string
    {
        if (preg_match('/\A`((?:[^`\\\\]|\\\\.)*)`\z/s', $element, $matches) === 1
            || preg_match('/\A"((?:[^"\\\\]|\\\\.)*)"\z/s', $element, $matches) === 1
        ) {
            return stripcslashes($matches[1]);
        }

        return $element;
    }
}
