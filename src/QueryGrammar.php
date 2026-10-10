<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Query\Degeneration\Bindings;
use ClickHouseDB\Query\Query;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\IndexHint;
use Illuminate\Support\Arr;
use Illuminate\Support\Enumerable;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\Concerns\SubstitutesBindings;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar as LiteralGrammar;
use Stringable;

/**
 * The grammar of Laravel's query builder on a ClickHouse connection, used when fix_default_query_builder is false.
 *
 * Bindings are Laravel's "?" placeholders, which the connection writes into the SQL as ClickHouse literals before
 * the query is sent (see SubstitutesBindings::prepareQueryForClient()). Identifiers are double-quoted, with
 * backslashes and double quotes escaped. Deletes and updates are ClickHouse mutations, refused when the query uses
 * a clause that the mutation would ignore (see verifyClausesOfMutation()).
 */
class QueryGrammar extends Grammar
{
    use SubstitutesBindings;

    /**
     * The placeholder that parameter() wrote before Laravel's "?" was used.
     *
     * @deprecated parameter() writes "?", and the connection writes the bindings into the SQL. Nothing writes or
     *             reads this marker any more; it will be removed in 5.0.
     */
    const PARAMETER_SIGN = '#@?';

    /**
     * The package grammar that writes the literals of compileLiteral(), created when it is first needed.
     *
     * @var LiteralGrammar|null
     */
    protected ?LiteralGrammar $literalGrammar = null;

    /**
     * The qualifiers that a column name of the mutation being compiled may start with, each as its segments: the
     * table as the query names it, the table without its database, and the table's alias. Null outside a delete
     * or an update, and while a sub-query of one is compiled (see compileMutation() and wrapSegments()).
     *
     * @var list<list<string>>|null
     */
    protected ?array $mutationTableQualifiers = null;

    /**
     * The number of selects, exists queries and mutations being compiled, one inside the other, so that only the
     * outermost query gets the settings of timeout(), forceIndex() and ignoreIndex() (see compileQuerySettings()).
     *
     * @var int
     */
    protected int $queryDepth = 0;

    /**
     * The components of a select, in the order of ClickHouse's SELECT: Laravel's, with WITH first, ARRAY JOIN before
     * JOIN, PREWHERE before WHERE and LIMIT ... BY before LIMIT. FINAL and SAMPLE are part of the FROM clause (see
     * compileFrom()), and SETTINGS goes at the end of the outermost query (see compileQuerySettings()). The
     * ClickHouse components are those of QueryBuilder, which another builder does not have.
     *
     * @var list<string>
     */
    protected $selectComponents = [
        'withs',
        'aggregate',
        'columns',
        'from',
        'indexHint',
        'arrayJoins',
        'joins',
        'prewheres',
        'wheres',
        'groups',
        'havings',
        'orders',
        'limitBy',
        'limit',
        'offset',
        'lock',
    ];

    /**
     * Turn the deprecated "#@?" markers in a query into smi2's placeholders :0, :1 and so on, in order.
     *
     * @deprecated parameter() writes Laravel's "?", so queries have no such markers, and the connection writes "?"
     *             bindings into the SQL itself (see SubstitutesBindings::prepareQueryForClient()). A query without
     *             a marker is returned as it is. It will be removed in 5.0.
     *
     * @param string $sql
     * @return string
     */
    public static function prepareParameters(string $sql): string
    {
        $parameterNum = 0;
        while (($pos = strpos($sql, QueryGrammar::PARAMETER_SIGN)) !== false) {
            $sql = substr_replace($sql, ":$parameterNum", $pos, strlen(QueryGrammar::PARAMETER_SIGN));
            $parameterNum++;
        }

        return $sql;
    }

    /**
     * Write a PHP value as a ClickHouse literal: a Laravel database expression, such as DB::raw(), as its SQL, and
     * any other value as the package grammar writes it, with dates at the connection's datetime_precision.
     *
     * @see \Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar::compileLiteral()
     *
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException When the value cannot be written as a literal, or the connection's
     *                                  datetime_precision is neither 'second' nor 'microsecond'
     */
    public function compileLiteral(mixed $value): string
    {
        if ($this->isExpression($value)) {
            return (string) $this->getValue($value);
        }

        return $this->getLiteralGrammar()->compileLiteral($value);
    }

    /**
     * Get the package grammar that writes the literals of compileLiteral(). On this package's connection it is the
     * connection's own builder grammar (see Connection::newBuilderGrammar()), which writes dates at the connection's
     * datetime_precision; on any other connection, a package grammar at its datetime_precision option (see
     * getDateTimePrecisionOption()). Either way it reads a Laravel database expression in an array value through
     * this grammar.
     *
     * @return LiteralGrammar
     * @throws InvalidArgumentException When the connection's datetime_precision is neither 'second' nor 'microsecond'
     */
    protected function getLiteralGrammar(): LiteralGrammar
    {
        if ($this->literalGrammar === null) {
            $grammar = $this->connection instanceof Connection
                ? $this->connection->newBuilderGrammar()
                : (new LiteralGrammar())->setDateTimePrecision($this->getDateTimePrecisionOption());

            $this->literalGrammar = $grammar->setLaravelGrammarResolver(fn (): self => $this);
        }

        return $this->literalGrammar;
    }

    /**
     * Get the datetime_precision option of a connection other than this package's, read as
     * Connection::getDateTimePrecision() reads it: DateTimePrecision::SECOND when the option is left out, null or
     * blank (as an empty environment variable gives), and otherwise 'second' or 'microsecond' in any letter case and
     * with surrounding spaces, or a DateTimePrecision instance.
     *
     * Any other value is refused with the message of Grammar::setDateTimePrecision(), which names the value as given,
     * or its type when it is no string.
     *
     * @return string
     * @throws InvalidArgumentException For any other value
     */
    protected function getDateTimePrecisionOption(): string
    {
        $value = $this->connection->getConfig('datetime_precision');
        if ($value instanceof DateTimePrecision) {
            return $value->getValue();
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return DateTimePrecision::SECOND;
        }

        $precision = is_string($value) ? strtolower(trim($value)) : null;
        if ($precision !== null && DateTimePrecision::isValid($precision)) {
            return $precision;
        }

        throw new InvalidArgumentException(sprintf(
            "Invalid datetime precision [%s]: use '%s'.",
            is_string($value) ? $value : get_debug_type($value),
            implode("' or '", DateTimePrecision::toArray())
        ));
    }

    /**
     * Compile a select query.
     *
     * - A table qualifier is kept in every column name of the select, also when it is a sub-query of a delete or an
     *   update, which drops the mutation table's qualifiers from its own columns (see compileMutation()).
     * - A union with an order, a limit or an offset of its own, such as union()->orderBy() or the limit that
     *   first() and paginate() set, is compiled as select * from ((...) union (...)) followed by them: ClickHouse
     *   (24.8 checked) rejects an ORDER BY, a LIMIT or an OFFSET after the last parenthesized query of a union.
     * - The outermost query gets the settings of timeout(), forceIndex() and ignoreIndex() (see
     *   compileQuerySettings()).
     *
     * @param Builder $query
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        $isOutermost = $this->queryDepth === 0;
        $qualifiers = $this->mutationTableQualifiers;
        $this->mutationTableQualifiers = null;
        $this->queryDepth++;

        try {
            $sql = $this->compileSelectWithUnionClausesOutside($query);
        } finally {
            $this->queryDepth--;
            $this->mutationTableQualifiers = $qualifiers;
        }

        return $isOutermost ? $this->compileQuerySettings($query, $sql) : $sql;
    }

    /**
     * Compile a select query as Laravel's grammar does, except that the order, the limit and the offset of a union
     * are written after the whole union, which is selected from as a sub-query (see compileSelect()).
     *
     * A query with an aggregate, as count() of a union compiles it, is left to Laravel's grammar, which compiles
     * the union itself through compileSelect() again, without the aggregate.
     *
     * @param Builder $query
     * @return string
     */
    protected function compileSelectWithUnionClausesOutside(Builder $query): string
    {
        $unionOrders = $query->unionOrders;
        $unionLimit = $query->unionLimit;
        $unionOffset = $query->unionOffset;

        if (empty($query->unions) || $query->aggregate
            || (empty($unionOrders) && $unionLimit === null && $unionOffset === null)
        ) {
            return parent::compileSelect($query);
        }

        $query->unionOrders = null;
        $query->unionLimit = null;
        $query->unionOffset = null;

        try {
            $sql = parent::compileSelect($query);
        } finally {
            $query->unionOrders = $unionOrders;
            $query->unionLimit = $unionLimit;
            $query->unionOffset = $unionOffset;
        }

        return $this->concatenate([
            "select * from ({$sql})",
            empty($unionOrders) ? '' : $this->compileOrders($query, $unionOrders),
            $unionLimit === null ? '' : $this->compileLimit($query, $unionLimit),
            $unionOffset === null ? '' : $this->compileOffset($query, $unionOffset),
        ]);
    }

    /**
     * Compile an exists query: select exists(...) as "exists", which gets the settings of timeout(), forceIndex()
     * and ignoreIndex() when it is the outermost query (see compileQuerySettings()).
     *
     * @param Builder $query
     * @return string
     */
    public function compileExists(Builder $query)
    {
        $isOutermost = $this->queryDepth === 0;
        $this->queryDepth++;

        try {
            $sql = parent::compileExists($query);
        } finally {
            $this->queryDepth--;
        }

        return $isOutermost ? $this->compileQuerySettings($query, $sql) : $sql;
    }

    /**
     * Add the settings of the query to the SQL of the outermost query: SETTINGS max_execution_time = <seconds> for
     * timeout(), and force_data_skipping_indices or ignore_data_skipping_indices = '<names>' for forceIndex() or
     * ignoreIndex(), whose names are written as given, comma-separated for several.
     *
     * ClickHouse (24.8 checked) applies the settings of the outermost query to its sub-queries, but does not enforce
     * max_execution_time from the SETTINGS of a sub-query, and rejects SETTINGS after the last parenthesized query
     * of a union, so a union is selected from as a sub-query first: select * from ((...) union all (...)) settings
     * .... The client's own timeout_query still ends the request when it is shorter than timeout().
     *
     * @param Builder $query
     * @param string $sql
     * @return string
     */
    protected function compileQuerySettings(Builder $query, string $sql): string
    {
        $settings = [];

        if ($query->timeout !== null) {
            $settings[] = 'max_execution_time = ' . (int) $query->timeout;
        }

        if ($query->indexHint instanceof IndexHint && in_array($query->indexHint->type, ['force', 'ignore'], true)) {
            $setting = $query->indexHint->type === 'force' ? 'force_data_skipping_indices' : 'ignore_data_skipping_indices';
            $settings[] = $setting . ' = ' . $this->compileLiteral((string) $query->indexHint->index);
        }

        if ($query instanceof QueryBuilder && $query->settings !== []) {
            $settings[] = substr($this->getLiteralGrammar()->compileSettingsComponent(null, $query->settings), strlen('SETTINGS '));
        }

        if ($settings === []) {
            return $sql;
        }

        if (str_starts_with($sql, '(')) {
            $sql = "select * from ({$sql})";
        }

        return $sql . ' settings ' . implode(', ', $settings);
    }

    /**
     * Compile an index hint. forceIndex() and ignoreIndex() become settings of the outermost query (see
     * compileQuerySettings()), so nothing is written in their place. useIndex() is refused: ClickHouse chooses the
     * data-skipping indexes itself.
     *
     * @param Builder $query
     * @param IndexHint $indexHint
     * @return string
     * @throws InvalidArgumentException For useIndex()
     */
    protected function compileIndexHint(Builder $query, $indexHint): string
    {
        if ($indexHint->type === 'force' || $indexHint->type === 'ignore') {
            return '';
        }

        throw new InvalidArgumentException(
            'ClickHouse chooses data-skipping indexes itself: use forceIndex() to require one or ignoreIndex() to skip one.'
        );
    }

    /**
     * Compile a union: union all for unionAll(), and union distinct for union(), which removes duplicate rows as
     * Laravel documents. ClickHouse rejects a bare union unless the union_default_mode setting is set, so when the
     * connection's settings set it, union() keeps writing a bare union, which follows that mode.
     *
     * @param array{query: Builder, all: bool} $union
     * @return string
     */
    protected function compileUnion(array $union)
    {
        if (isset($union['type'])) {
            return ' ' . $union['type'] . (empty($union['distinct']) ? '' : ' distinct') . ' '
                . $this->wrapUnion($union['query']->toSql());
        }

        $conjunction = match (true) {
            (bool) $union['all'] => ' union all ',
            $this->hasUnionDefaultMode() => ' union ',
            default => ' union distinct ',
        };

        return $conjunction . $this->wrapUnion($union['query']->toSql());
    }

    /**
     * Determine if the connection's settings set union_default_mode, so that ClickHouse accepts a bare union.
     *
     * @return bool
     */
    protected function hasUnionDefaultMode(): bool
    {
        $settings = $this->connection->getConfig('settings');

        return is_array($settings) && (string) ($settings['union_default_mode'] ?? '') !== '';
    }

    /**
     * Compile the WITH clause of QueryBuilder::withExpression(), withRecursiveExpression() and withAlias():
     * with "name" as (<query>), <value> as "alias", or with recursive ... when one of them is recursive.
     *
     * @param Builder $query
     * @param list<array{type: string, name: string, query: \Illuminate\Contracts\Database\Query\Expression|string}> $withs
     * @return string
     */
    protected function compileWiths(Builder $query, array $withs): string
    {
        if ($withs === []) {
            return '';
        }

        $entries = array_map(
            fn (array $with): string => $with['type'] === 'alias'
                ? $this->getValue($with['query']) . ' as ' . $this->wrap($with['name'])
                : $this->wrap($with['name']) . ' as ' . $this->getValue($with['query']),
            $withs
        );

        return 'with ' . ($query instanceof QueryBuilder && $query->recursiveWith ? 'recursive ' : '')
            . implode(', ', $entries);
    }

    /**
     * Compile the FROM clause, with FINAL (see QueryBuilder::final()) and SAMPLE (see QueryBuilder::sample()):
     * from "t" as "x" final sample 0.1 offset 0.5.
     *
     * @param Builder $query
     * @param \Illuminate\Contracts\Database\Query\Expression|string $table
     * @return string
     * @throws \LogicException When a query that selects from a sub-query has FINAL, which ClickHouse rejects
     */
    protected function compileFrom(Builder $query, $table)
    {
        $sql = parent::compileFrom($query, $table);

        if (!$query instanceof QueryBuilder) {
            return $sql;
        }

        if ($query->final) {
            if ($this->isExpression($table) && str_starts_with(ltrim((string) $this->getValue($table)), '(')) {
                throw new \LogicException(
                    'FINAL applies to a table, not to a sub-query: put final() on the query of the sub-query.'
                );
            }

            $sql .= ' final';
        }

        if ($query->sample !== null) {
            $sql .= ' sample ' . $this->compileNumber($query->sample['coefficient'])
                . ($query->sample['offset'] === null ? '' : ' offset ' . $this->compileNumber($query->sample['offset']));
        }

        return $sql;
    }

    /**
     * Write a number of a SAMPLE clause: an int as it is, and a float with all its digits.
     *
     * @param int|float $number
     * @return string
     */
    protected function compileNumber(int|float $number): string
    {
        return is_int($number) ? (string) $number : $this->compileLiteral($number);
    }

    /**
     * Compile the ARRAY JOIN clauses of QueryBuilder::arrayJoin() and leftArrayJoin(), in the order of the calls:
     * array join "tags" as "tag", "scores", or left array join ....
     *
     * @param Builder $query
     * @param list<array{left: bool, arrays: list<array{array: \Illuminate\Contracts\Database\Query\Expression|string, as: string|null}>}> $arrayJoins
     * @return string
     */
    protected function compileArrayJoins(Builder $query, array $arrayJoins): string
    {
        return implode(' ', array_map(
            fn (array $arrayJoin): string => ($arrayJoin['left'] ? 'left ' : '') . 'array join ' . implode(', ', array_map(
                fn (array $array): string => $this->wrap($array['array'])
                    . ($array['as'] === null ? '' : ' as ' . $this->wrap($array['as'])),
                $arrayJoin['arrays']
            )),
            $arrayJoins
        ));
    }

    /**
     * Compile the PREWHERE clause of QueryBuilder::preWhere() and its forms, as the where conditions are compiled.
     *
     * @param Builder $query
     * @param array<int, array<string, mixed>> $prewheres
     * @return string
     */
    protected function compilePrewheres(Builder $query, array $prewheres): string
    {
        if ($prewheres === []) {
            return '';
        }

        $wheres = $query->wheres;
        $query->wheres = $prewheres;

        try {
            return preg_replace('/^where /', 'prewhere ', $this->compileWheres($query)) ?? '';
        } finally {
            $query->wheres = $wheres;
        }
    }

    /**
     * Compile a GLOBAL IN condition of QueryBuilder::whereGlobalIn(). An empty list matches no row, as whereIn().
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereGlobalIn(Builder $query, $where): string
    {
        if (!empty($where['values'])) {
            return $this->wrap($where['column']) . ' global in (' . $this->parameterize($where['values']) . ')';
        }

        return '0 = 1';
    }

    /**
     * Compile a GLOBAL NOT IN condition of QueryBuilder::whereGlobalNotIn(). An empty list matches every row, as
     * whereNotIn().
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereGlobalNotIn(Builder $query, $where): string
    {
        if (!empty($where['values'])) {
            return $this->wrap($where['column']) . ' global not in (' . $this->parameterize($where['values']) . ')';
        }

        return '1 = 1';
    }

    /**
     * Compile an empty() or notEmpty() condition of QueryBuilder::whereEmpty() and whereNotEmpty().
     *
     * @param Builder $query
     * @param array{column: \Illuminate\Contracts\Database\Query\Expression|string, not: bool} $where
     * @return string
     */
    protected function whereEmpty(Builder $query, $where): string
    {
        return ($where['not'] ? 'notEmpty(' : 'empty(') . $this->wrap($where['column']) . ')';
    }

    /**
     * Compile a having condition, with the empty() and notEmpty() conditions of QueryBuilder::havingEmpty().
     *
     * @param array<string, mixed> $having
     * @return string
     */
    protected function compileHaving(array $having)
    {
        if ($having['type'] === 'Empty') {
            return ($having['not'] ? 'notEmpty(' : 'empty(') . $this->wrap($having['column']) . ')';
        }

        return parent::compileHaving($having);
    }

    /**
     * Compile the LIMIT ... BY clause of QueryBuilder::limitBy(): limit 3 by "user_id", or limit 3 offset 1 by
     * "user_id", "day".
     *
     * @param Builder $query
     * @param array{count: int, offset: int|null, columns: list<\Illuminate\Contracts\Database\Query\Expression|string>} $limitBy
     * @return string
     */
    protected function compileLimitBy(Builder $query, array $limitBy): string
    {
        return 'limit ' . $limitBy['count']
            . ($limitBy['offset'] === null ? '' : ' offset ' . $limitBy['offset'])
            . ' by ' . $this->columnize($limitBy['columns']);
    }

    /**
     * Compile a random order: rand(). ClickHouse's rand() takes no seed (an argument is ignored), so a seed is
     * refused rather than giving an order that the seed does not reproduce.
     *
     * @param string|int|null $seed
     * @return string
     * @throws InvalidArgumentException When a seed is given
     */
    public function compileRandom($seed)
    {
        if ($seed === '' || $seed === null) {
            return 'rand()';
        }

        throw new InvalidArgumentException(
            'ClickHouse has no seeded random order: rand() ignores its argument. For an order that a seed reproduces,'
            . " order by a hash of a key column, for example orderByRaw('cityHash64(?, id)', [\$seed])."
        );
    }

    /**
     * Compile a basic where clause. The null-safe operator <=> becomes the comparison of compileNullSafeEquals(),
     * because ClickHouse (24.8 checked) has <=> and IS NOT DISTINCT FROM only in the ON section of a JOIN.
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereBasic(Builder $query, $where)
    {
        if ($where['operator'] === '<=>') {
            return $this->compileNullSafeEquals($where['column'], $where['value']);
        }

        return parent::whereBasic($query, $where);
    }

    /**
     * Compile a whereLike() clause: ilike, or not ilike, which ignore case as Laravel documents, and like or not
     * like when caseSensitive is true. ClickHouse can use the primary key for a 'prefix%' pattern with like only.
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereLike(Builder $query, $where)
    {
        $where['operator'] = ($where['not'] ? 'not ' : '') . ($where['caseSensitive'] ? 'like' : 'ilike');

        return $this->whereBasic($query, $where);
    }

    /**
     * Compile a whereNullSafeEquals() clause (see compileNullSafeEquals()).
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereNullSafeEquals(Builder $query, $where)
    {
        return $this->compileNullSafeEquals($where['column'], $where['value']);
    }

    /**
     * Compile a null-safe comparison, which is true when both sides are equal or both are NULL.
     *
     * A value that is neither null nor an expression is compared with =, which can use the primary key: a NULL
     * column value does not match it either way. A null value or an expression, which may be NULL, is compared as
     * a one-element array, [column] = [value], which ClickHouse (24.8 checked) compares with NULL equal to NULL.
     *
     * @param mixed $column
     * @param mixed $value
     * @return string
     */
    protected function compileNullSafeEquals(mixed $column, mixed $value): string
    {
        $column = $this->wrap($column);
        $parameter = $this->parameter($value);

        if ($value !== null && !$this->isExpression($value)) {
            return "{$column} = {$parameter}";
        }

        return "[{$column}] = [{$parameter}]";
    }

    /**
     * Compile a where clause with a bitwise operator (see compileBitwise()).
     *
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function whereBitwise(Builder $query, $where)
    {
        return $this->compileBitwise($where['column'], $where['operator'], $where['value']);
    }

    /**
     * Compile a having clause with a bitwise operator (see compileBitwise()).
     *
     * @param array<string, mixed> $having
     * @return string
     */
    protected function compileHavingBit($having)
    {
        return $this->compileBitwise($having['column'], $having['operator'], $having['value']);
    }

    /**
     * Compile a basic having clause. Laravel's having() marks a bitwise operator, which it then compiles here, so
     * it becomes the bit function of compileBitwise(), and <=> the comparison of compileNullSafeEquals().
     *
     * @param array<string, mixed> $having
     * @return string
     */
    protected function compileBasicHaving($having)
    {
        if (in_array($having['operator'] ?? null, ['&', '|', '^', '<<', '>>', '&~'], true)) {
            return $this->compileHavingBit($having);
        }

        if (($having['operator'] ?? null) === '<=>') {
            return $this->compileNullSafeEquals($having['column'], $having['value']);
        }

        return parent::compileBasicHaving($having);
    }

    /**
     * Compile a bitwise condition as a ClickHouse bit function, since ClickHouse (24.8 checked) has no bitwise
     * operators. As in Laravel's other grammars, the condition holds when the result is not 0:
     *
     * - & → bitAnd(column, value) != 0;
     * - | → bitOr(column, value) != 0;
     * - ^ → bitXor(column, value) != 0;
     * - << → bitShiftLeft(column, value) != 0;
     * - >> → bitShiftRight(column, value) != 0;
     * - &~ → bitAnd(column, value) != column: column &~ value is not 0 when the column has a bit that the value does
     *   not have. bitNot(value) would only be as wide as the value's type, so bitAnd(column, bitNot(1)) would drop
     *   every bit of the column above the eighth.
     *
     * @param mixed $column
     * @param string $operator
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException For any other operator
     */
    protected function compileBitwise(mixed $column, string $operator, mixed $value): string
    {
        $column = $this->wrap($column);
        $parameter = $this->parameter($value);

        return match ($operator) {
            '&' => "bitAnd({$column}, {$parameter}) != 0",
            '|' => "bitOr({$column}, {$parameter}) != 0",
            '^' => "bitXor({$column}, {$parameter}) != 0",
            '<<' => "bitShiftLeft({$column}, {$parameter}) != 0",
            '>>' => "bitShiftRight({$column}, {$parameter}) != 0",
            '&~' => "bitAnd({$column}, {$parameter}) != {$column}",
            default => throw new InvalidArgumentException(sprintf(
                'The bitwise operator [%s] is not supported: use &, |, ^, <<, >> or &~.',
                $operator
            )),
        };
    }

    /**
     * Compile a whereDate(), whereTime(), whereDay(), whereMonth() or whereYear() clause with a ClickHouse function
     * of the column, as the package builder does:
     *
     * - date → toDate32(column), which also holds dates before 1970 and after 2149, where toDate() wraps around;
     * - time → formatDateTime(column, '%H:%i:%S'), compared as text in the column's own time zone, to the second;
     * - day → toDayOfMonth(column), month → toMonth(column) and year → toYear(column), compared with a number.
     *
     * QueryBuilder writes numeric day, month and year values as numbers, integers for whole numbers, and pads a time
     * such as '9:05' to '09:05:00'.
     *
     * @param string $type
     * @param Builder $query
     * @param array<string, mixed> $where
     * @return string
     */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $column = $this->wrap($where['column']);

        $function = match ($type) {
            'date' => "toDate32({$column})",
            'time' => "formatDateTime({$column}, '%H:%i:%S')",
            'day' => "toDayOfMonth({$column})",
            'month' => "toMonth({$column})",
            'year' => "toYear({$column})",
            default => "{$type}({$column})",
        };

        return $function . ' ' . $where['operator'] . ' ' . $this->parameter($where['value']);
    }

    /**
     * Compile an update statement as an ALTER TABLE ... UPDATE mutation (see compileMutation() and
     * compileUpdateWithoutJoins()).
     *
     * @param Builder $query
     * @param array<string, mixed> $values
     * @return string
     * @throws QueryException See compileUpdateWithoutJoins()
     */
    public function compileUpdate(Builder $query, array $values)
    {
        return $this->compileMutation($query, fn (): string => parent::compileUpdate($query, $values));
    }

    /**
     * Compile a delete statement as an ALTER TABLE ... DELETE mutation, or a lightweight DELETE FROM (see
     * compileMutation() and compileDeleteWithoutJoins()).
     *
     * @param Builder $query
     * @return string
     * @throws QueryException See compileDeleteWithoutJoins()
     */
    public function compileDelete(Builder $query)
    {
        return $this->compileMutation($query, fn (): string => parent::compileDelete($query));
    }

    /**
     * Compile a delete or an update without the mutation table's qualifiers in its column names.
     *
     * ClickHouse (24.8 checked) rejects a column name qualified by the table in ALTER TABLE ... DELETE, ALTER TABLE
     * ... UPDATE and DELETE FROM, such as "t"."id", which Laravel's delete($id) and Eloquent's qualified key names
     * write, and an alias after the table. So while the mutation is compiled, a column name loses a leading
     * qualifier that names the table: the table as the query names it, such as db.t, the table without its
     * database, t, and the table's alias. The table is written without its alias. A sub-query keeps the qualifiers
     * of its own columns (see compileSelect()), and raw SQL is written as it is.
     *
     * @param Builder $query
     * @param Closure(): string $compile Compiles the statement
     * @return string
     */
    protected function compileMutation(Builder $query, Closure $compile): string
    {
        $from = $query->from;
        $qualifiers = $this->mutationTableQualifiers;
        $this->mutationTableQualifiers = [];

        if (is_string($from)) {
            $parts = preg_split('/\s+as\s+/i', $from);
            $table = explode('.', $parts[0]);

            $this->mutationTableQualifiers[] = $table;
            if (count($table) > 1) {
                $this->mutationTableQualifiers[] = [$table[array_key_last($table)]];
            }
            if (isset($parts[1])) {
                $this->mutationTableQualifiers[] = [$parts[1]];
                $query->from = $parts[0];
            }
        }

        $wheres = $query->wheres;
        if ($query instanceof QueryBuilder && $query->prewheres !== []) {
            $query->wheres = $this->joinPrewheresToWheres($query);
        }

        $this->queryDepth++;

        try {
            return $compile();
        } finally {
            $this->queryDepth--;
            $this->mutationTableQualifiers = $qualifiers;
            $query->from = $from;
            $query->wheres = $wheres;
        }
    }

    /**
     * Get the where conditions of a mutation of a query with prewhere conditions. A mutation has no PREWHERE, so the
     * prewhere conditions are joined to the where conditions: where (<prewheres>) and (<wheres>), or the prewhere
     * conditions alone when the query has no where condition. Their bindings come in the same order (see the
     * bindings of QueryBuilder).
     *
     * @param QueryBuilder $query
     * @return array<int, array<string, mixed>>
     */
    protected function joinPrewheresToWheres(QueryBuilder $query): array
    {
        if ($query->wheres === []) {
            return $query->prewheres;
        }

        $prewheres = $query->newQuery();
        $prewheres->wheres = $query->prewheres;
        $wheres = $query->newQuery();
        $wheres->wheres = $query->wheres;

        return [
            ['type' => 'Nested', 'query' => $prewheres, 'boolean' => 'and'],
            ['type' => 'Nested', 'query' => $wheres, 'boolean' => 'and'],
        ];
    }

    /**
     * Wrap the segments of a column name. While a mutation is compiled, the longest leading run of segments that
     * equals one of the mutation table's qualifiers is dropped first (see compileMutation()).
     *
     * @param list<string> $segments
     * @return string
     */
    protected function wrapSegments($segments)
    {
        if ($this->mutationTableQualifiers !== null && count($segments) > 1) {
            $dropped = 0;

            foreach ($this->mutationTableQualifiers as $qualifier) {
                $length = count($qualifier);

                if ($length > $dropped && $length < count($segments) && array_slice($segments, 0, $length) === $qualifier) {
                    $dropped = $length;
                }
            }

            $segments = array_slice($segments, $dropped);
        }

        return parent::wrapSegments($segments);
    }

    /**
     * Compile the columns of an update. An array or a collection value is written as a ClickHouse array literal,
     * such as ['a', 'b'], in place of a placeholder: Laravel would bind its elements one by one, so that they took
     * the places of the following placeholders. prepareBindingsForUpdate() leaves its elements out of the bindings.
     *
     * @param Builder $query
     * @param array<string, mixed> $values
     * @return string
     * @throws InvalidArgumentException When an element of an array cannot be written as a literal
     */
    protected function compileUpdateColumns(Builder $query, array $values)
    {
        $columns = [];

        foreach ($values as $column => $value) {
            $columns[] = $this->wrap($column) . ' = ' . ($this->isArrayValue($value)
                ? $this->compileLiteral($value instanceof Enumerable ? $value->toArray() : $value)
                : $this->parameter($value));
        }

        return implode(', ', $columns);
    }

    /**
     * Prepare the bindings of an update: Laravel's, without the array and collection values that
     * compileUpdateColumns() writes as literals, and without the bindings of the order, such as those of
     * orderByRaw('...?', [...]), inOrderOf() or orderBy() of a sub-query, since the mutation has no ORDER BY.
     *
     * @param array<string, array<int, mixed>> $bindings
     * @param array<string, mixed> $values
     * @return array<int, mixed>
     */
    public function prepareBindingsForUpdate(array $bindings, array $values)
    {
        return parent::prepareBindingsForUpdate(
            Arr::except($bindings, ['order', 'unionOrder']),
            array_filter($values, fn (mixed $value): bool => !$this->isArrayValue($value))
        );
    }

    /**
     * Prepare the bindings of a delete: Laravel's, without the bindings of the order, such as those of
     * orderByRaw('...?', [...]), inOrderOf() or orderBy() of a sub-query, since the mutation has no ORDER BY.
     *
     * @param array<string, array<int, mixed>> $bindings
     * @return array<int, mixed>
     */
    public function prepareBindingsForDelete(array $bindings)
    {
        return parent::prepareBindingsForDelete(Arr::except($bindings, ['order', 'unionOrder']));
    }

    /**
     * Determine if an update value is written as an array literal: a PHP array, or a collection, such as a Collection
     * or a LazyCollection. Other Arrayable objects, such as Eloquent models, are bound and refused when they are
     * written (see compileLiteral()).
     *
     * @param mixed $value
     * @return bool
     */
    protected function isArrayValue(mixed $value): bool
    {
        return is_array($value) || $value instanceof Enumerable;
    }

    /**
     * Wrap a single identifier in double quotes. A backslash is doubled and a double quote is doubled, because
     * ClickHouse (24.8 checked) reads backslash escapes inside double-quoted identifiers: "a\b" is a, a backspace
     * and b, and a name that ends with a backslash would escape the closing quote.
     *
     * @param string $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '""'], $value) . '"';
    }

    /**
     * Compile the query that counts the client connections to the active node, which `php artisan db:show` prints
     * as its open connections: the native TCP, HTTP, MySQL and PostgreSQL protocol connections from system.metrics,
     * the connection asking included. Connections between replicas are left out, since they are not clients.
     *
     * @return string
     */
    public function compileThreadCount()
    {
        return 'SELECT toUInt32(sum(value)) AS `Value` FROM system.metrics'
            . " WHERE metric IN ('TCPConnection', 'HTTPConnection', 'MySQLConnection', 'PostgreSQLConnection')";
    }

    /**
     * Compile a delete statement without joins: a lightweight DELETE FROM when the
     * connection sets use_lightweight_delete, an ALTER TABLE ... DELETE mutation otherwise.
     *
     * The table gets ON CLUSTER for the default cluster of the connection's use_on_cluster
     * option (see compileOnClusterClause()). Before the statement is returned, and so
     * before anything is sent, the delete is refused:
     * - for a query that uses a clause the delete would ignore (see verifyClausesOfMutation());
     * - as a lightweight delete, for a condition that holds UNION, INTERSECT or EXCEPT (see
     *   refuseASetOperationInALightweightDelete());
     * - in a session, as a lightweight delete or with ON CLUSTER, when a temporary table
     *   of the session has the table's name (see refuseATemporaryTableThatTheStatementMisses());
     * - for a condition with a sub-query or an IN <table> that ClickHouse cannot run in a
     *   SELECT (see refuseConditionsThatClickHouseCannotRun()).
     * The last two send read-only queries to the server while the delete is compiled,
     * unless the connection pretends.
     *
     * @param Builder $query
     * @param string $table
     * @param string $where
     * @return string
     * @throws QueryException When the query has no where condition, which ClickHouse would reject with a syntax
     *                        error, or the delete is refused for one of the reasons above
     */
    protected function compileDeleteWithoutJoins(Builder $query, $table, $where): string
    {
        if (trim($where) === '') {
            throw QueryException::cannotDeleteWithoutWhere();
        }
        $this->verifyClausesOfMutation($query, 'delete');

        $lightweight = ($query instanceof QueryBuilder ? $query->lightweightDelete : null)
            ?? (bool) $this->connection->getConfig('use_lightweight_delete');
        if ($lightweight) {
            $this->refuseASetOperationInALightweightDelete($where);
        }

        $cluster = $this->getClusterOf($query);
        if ($lightweight) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $query,
                $cluster === null ? 'DELETE FROM' : 'DELETE FROM ... ON CLUSTER',
                'Send ALTER TABLE ... DELETE' . ($cluster === null ? '' : ' without ON CLUSTER') . ', which reaches the'
                    . " temporary table, with the connection's statement(), such as statement('ALTER TABLE ... DELETE"
                    . " WHERE ...')."
            );
        } elseif ($cluster !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $query,
                'ALTER TABLE ... ON CLUSTER ... DELETE',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        $this->refuseConditionsThatClickHouseCannotRun($query, 'delete', $table, $where, $lightweight || $cluster !== null);

        $table .= $this->compileOnClusterClause($cluster);
        $partition = $this->compilePartitionClause($query);

        return $lightweight ? "delete from {$table}{$partition} $where" : "alter table $table delete{$partition} $where";
    }

    /**
     * Compile a truncate statement: TRUNCATE TABLE, with ON CLUSTER for the default cluster of the connection's
     * use_on_cluster option (see compileOnClusterClause()), so that every node is emptied. The table is written
     * without an alias.
     *
     * In a session, a truncate with ON CLUSTER of a name that a temporary table of the session has is refused (see
     * refuseATemporaryTableThatTheStatementMisses()).
     *
     * @param Builder $query
     * @return array<string, array<int, mixed>>
     * @throws QueryException When the statement would miss a temporary table of the session
     */
    public function compileTruncate(Builder $query)
    {
        $from = $query->from;
        if (is_string($from)) {
            $from = preg_split('/\s+as\s+/i', trim($from))[0];
        }

        $cluster = $this->getClusterOf($query);
        if ($cluster !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $query,
                'TRUNCATE TABLE ... ON CLUSTER',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        return ['truncate table ' . $this->wrapTable($from) . $this->compileOnClusterClause($cluster) => []];
    }

    /**
     * Get the cluster that deletes, updates and truncates of Laravel's query builder send ON CLUSTER to: the default
     * cluster of this package's connection (see Connection::getDefaultCluster()), which is null unless the
     * use_on_cluster option is on and no Connection::withoutOnCluster() callback runs. Any other connection has none.
     *
     * @return string|null
     * @throws InvalidArgumentException When the connection's use_on_cluster option is not a boolean
     */
    protected function getDefaultCluster(): ?string
    {
        return $this->connection instanceof Connection ? $this->connection->getDefaultCluster() : null;
    }

    /**
     * Get the cluster that a delete, an update or a truncate of a query is sent ON CLUSTER to: the cluster of
     * QueryBuilder::onCluster(), else none after QueryBuilder::withoutOnCluster(), else the default cluster of the
     * connection (see getDefaultCluster()).
     *
     * @param Builder $query
     * @return string|null
     */
    protected function getClusterOf(Builder $query): ?string
    {
        if ($query instanceof QueryBuilder) {
            if ($query->onCluster !== null) {
                return $query->onCluster;
            }

            if (!$query->usesTheDefaultCluster) {
                return null;
            }
        }

        return $this->getDefaultCluster();
    }

    /**
     * Compile the ON CLUSTER clause that follows the table of a delete, an update or a truncate (see
     * getClusterOf()): on cluster '<name>', with the name written as a string literal. Nothing without a cluster.
     *
     * @param string|null $cluster
     * @return string
     */
    protected function compileOnClusterClause(?string $cluster): string
    {
        return $cluster === null ? '' : ' on cluster ' . (new LiteralGrammar())->quoteString($cluster);
    }

    /**
     * Compile the IN PARTITION clause of a delete or an update (see QueryBuilder::delete()): an int is written as a
     * number, a string as a string literal, and an expression as its SQL. Nothing without a partition.
     *
     * @param Builder $query
     * @return string
     */
    protected function compilePartitionClause(Builder $query): string
    {
        if (!$query instanceof QueryBuilder || $query->partition === null) {
            return '';
        }

        return ' in partition ' . $this->compileLiteral($query->partition);
    }

    /**
     * Describe how to send a statement without ON CLUSTER, for the message of a statement that would miss a
     * temporary table of the session (see refuseATemporaryTableThatTheStatementMisses()).
     *
     * @return string
     */
    protected function describeHowToLeaveOutOnCluster(): string
    {
        return "Run it in the connection's withoutOnCluster() callback, so that it is sent without ON CLUSTER and"
            . ' reaches the temporary table.';
    }

    /**
     * Refuse a statement that would miss a temporary table of the session and change the table of that name in the
     * database instead: a lightweight DELETE FROM, or a statement with ON CLUSTER, which ClickHouse (24.8 checked)
     * runs on the database table (see Builder::refuseATemporaryTableThatTheStatementMisses()).
     *
     * The check runs when the query's table is a name without a database, and the connection is in a session and
     * does not pretend: Connection::hasTemporaryTable() then sends EXISTS TEMPORARY TABLE `<name>`, with the
     * connection's table prefix, which is logged. Otherwise nothing is sent.
     *
     * @param Builder $query
     * @param string $statement The statement, such as 'DELETE FROM' or 'TRUNCATE TABLE ... ON CLUSTER'
     * @param string $alternative A sentence that says what to do instead
     * @return void
     * @throws QueryException When the session has a temporary table of the name (see
     *                        QueryException::cannotReachTemporaryTable())
     */
    protected function refuseATemporaryTableThatTheStatementMisses(
        Builder $query,
        string $statement,
        string $alternative
    ): void {
        if (!$this->connection instanceof Connection || $this->connection->pretending()) {
            return;
        }

        $table = $this->getUnqualifiedTableOf($query);
        if ($table !== null && $this->connection->hasTemporaryTable($table)) {
            throw QueryException::cannotReachTemporaryTable($statement, $table, $alternative);
        }
    }

    /**
     * Get the table of a delete, an update or a truncate as a name without a database, as a temporary table is
     * named: the query's table without its alias, with the connection's table prefix. Null for a name with a
     * database, quotes or spaces, and for raw SQL, which are not checked against the temporary tables of a session.
     *
     * @param Builder $query
     * @return string|null
     */
    protected function getUnqualifiedTableOf(Builder $query): ?string
    {
        if (!is_string($query->from)) {
            return null;
        }

        $table = trim(preg_split('/\s+as\s+/i', trim($query->from))[0]);
        if ($table === '' || preg_match('/[.`"\s]/', $table) === 1) {
            return null;
        }

        return $this->connection->getTablePrefix() . $table;
    }

    /**
     * Refuse a lightweight delete whose conditions hold UNION, INTERSECT or EXCEPT, outside string literals and quoted
     * names, as a whereIn() or whereExists() sub-query with a union writes them.
     *
     * ClickHouse (24.8 checked) runs a lightweight DELETE FROM as a mutation that fails for such a condition, and the
     * failed mutation stays: every later mutation of the table waits behind it until KILL MUTATION (see
     * Builder::refuseASetOperationInALightweightDelete()). ALTER TABLE ... DELETE runs the same condition.
     *
     * @param string $where The compiled where clause of the delete
     * @return void
     * @throws QueryException When the conditions hold a set operation
     */
    protected function refuseASetOperationInALightweightDelete(string $where): void
    {
        if (preg_match('/\b(?:UNION|INTERSECT|EXCEPT)\b/i', $this->getSqlOutsideQuotes($where)) !== 1) {
            return;
        }

        throw new QueryException(
            'Cannot send a lightweight DELETE FROM whose where condition holds a sub-query with UNION, INTERSECT or'
            . ' EXCEPT: ClickHouse (24.8 checked) fails such a delete and leaves its mutation behind, which blocks'
            . ' every later mutation of the table until KILL MUTATION. Nothing was sent. Send ALTER TABLE ... DELETE'
            . " with the same condition, with the connection's statement() or the package builder's delete(false), or"
            . " select the keys first, and delete by them instead: whereIn('id', \$query->pluck('id'))."
        );
    }

    /**
     * Refuse a delete or an update whose conditions hold a sub-query or an IN <table> that ClickHouse cannot run in a
     * SELECT, such as whereExists() with whereColumn() on the table of the mutation, which ClickHouse (24.8 checked)
     * accepts as a mutation that then never finishes and blocks every later mutation of the table until KILL
     * MUTATION (see Builder::refuseConditionsThatClickHouseCannotRun()).
     *
     * When the conditions hold select, or in followed by anything but a parenthesis, outside string literals and
     * quoted names, and the connection does not pretend, explain plan select 1 from <table> <where> runs with the
     * from and where bindings of the query, and is logged. It ends with settings use_index_for_in_with_subqueries = 0,
     * on a line of its own, so that ClickHouse does not run an in sub-query on a column of the primary key to build
     * its set; a scalar sub-query still runs once for it. ClickHouse refuses it for such a condition.
     *
     * It runs through the connection's select(), unless the connection is in a session and ClickHouse runs the
     * mutation outside the session: a lightweight delete, a statement with ON CLUSTER, and a mutation of a table that
     * is not a temporary table of the session (see getUnqualifiedTableOf() and Connection::hasTemporaryTable(); a
     * name with a database and raw SQL are taken for tables of the database). It is then sent outside the session
     * (see selectOutsideTheSession()), where the temporary tables of the session do not exist, so that a condition
     * that reads one is refused.
     *
     * @param Builder $query
     * @param string $statement The statement: delete or update
     * @param string $table The table of the mutation, as it is compiled
     * @param string $where The compiled where clause of the mutation
     * @param bool $runsOutsideTheSession Whether ClickHouse runs the mutation outside the session whatever its table:
     *                                    a lightweight delete, or a statement with ON CLUSTER
     * @return void
     * @throws QueryException When ClickHouse refuses the explain, with its DatabaseException as the previous exception
     */
    protected function refuseConditionsThatClickHouseCannotRun(
        Builder $query,
        string $statement,
        string $table,
        string $where,
        bool $runsOutsideTheSession = false
    ): void {
        if (!$this->connection instanceof Connection || $this->connection->pretending()
            || preg_match('/\bselect\b|\bin\b(?!\s*\()/i', $this->getSqlOutsideQuotes($where)) !== 1
        ) {
            return;
        }

        $sql = "explain plan select 1 from {$table} {$where}\nsettings use_index_for_in_with_subqueries = 0";
        $bindings = $query->cleanBindings(Arr::flatten(Arr::only($query->getRawBindings(), ['from', 'where'])));
        $outsideTheSession = false;
        if ($this->connection->inSession()) {
            $temporaryTable = $this->getUnqualifiedTableOf($query);
            $outsideTheSession = $runsOutsideTheSession
                || $temporaryTable === null
                || !$this->connection->hasTemporaryTable($temporaryTable);
        }

        try {
            if ($outsideTheSession) {
                $this->selectOutsideTheSession($sql, $bindings);
            } else {
                $this->connection->select($sql, $bindings);
            }
        } catch (DatabaseException $exception) {
            throw new QueryException(
                $this->describeConditionsThatClickHouseCannotRun($statement, $exception, $outsideTheSession),
                0,
                $exception
            );
        }
    }

    /**
     * Describe the refusal of a mutation whose explain ClickHouse refused, as the package builder describes it (see
     * Builder::describeConditionsThatClickHouseCannotRun()).
     *
     * @param string $statement The statement: delete or update
     * @param DatabaseException $exception The refusal of the explain
     * @param bool $outsideTheSession Whether the explain was sent outside the session
     * @return string
     */
    protected function describeConditionsThatClickHouseCannotRun(
        string $statement,
        DatabaseException $exception,
        bool $outsideTheSession
    ): string {
        $error = $exception->getClickHouseExceptionName() ?? 'code ' . $exception->getCode();
        $reason = $outsideTheSession
            ? sprintf(
                'it refused the condition in a SELECT outside the session with %2$s. ClickHouse (24.8 checked) runs the'
                . ' mutation outside the session, where the temporary tables of the session do not exist, so a'
                . ' condition that reads one is such a condition, and so is a sub-query that reads a column of the'
                . ' table of the %1$s: ClickHouse accepts the mutation and then fails it again and again',
                $statement,
                $error
            )
            : sprintf(
                'it refused the condition in a SELECT with %2$s. A sub-query that reads a column of the table of the'
                . ' %1$s, such as whereExists() with whereColumn() on that table, is such a condition: ClickHouse (24.8'
                . ' checked) accepts the mutation and then fails it again and again',
                $statement,
                $error
            );

        return sprintf(
            'Cannot %1$s with a where condition that ClickHouse cannot run: %2$s, which blocks every later mutation of'
            . " the table until KILL MUTATION. Nothing was sent. Select the keys first, and %1\$s by them instead:"
            . " whereIn('id', \$query->pluck('id')).",
            $statement,
            $reason
        );
    }

    /**
     * Run a select on the connection's client outside the ClickHouse session of the connection, as the connection's
     * select() runs it otherwise: the beforeExecuting() callbacks run first, the bindings are written as select()
     * writes them (see Connection::prepareSelectForClient()), and the query is logged with its bindings, as given,
     * when it succeeds. The request leaves out session_id, session_timeout and session_check, so that the temporary
     * tables of the session do not exist for it, as for a mutation that ClickHouse runs in the background, and it
     * is sent with readonly=2 and default_format=JSON through the client's curler.
     *
     * @param string $sql
     * @param array<int|string, mixed> $bindings
     * @return void
     * @throws DatabaseException When ClickHouse refuses the query
     */
    protected function selectOutsideTheSession(string $sql, array $bindings): void
    {
        /** @var Connection $connection */
        $connection = $this->connection;
        $connection->runBeforeExecutingCallbacks($sql, $bindings);
        $start = microtime(true);

        [$clientSql, $clientBindings] = $connection->prepareSelectForClient($sql, $bindings);
        $degeneration = new Bindings();
        $degeneration->bindParams($clientBindings);
        $transport = $connection->getClient()->transport();
        $request = $transport->getRequestRead(new Query($clientSql, [$degeneration]), null, null, [
            'default_format' => 'JSON',
            'session_id' => null,
            'session_timeout' => null,
            'session_check' => null,
        ]);
        $request->setRequestExtendedInfo(array_merge($request->getRequestExtendedInfo(), ['format' => 'JSON']));
        $transport->getCurler()->execOne($request);
        ClientRequests::selectStatement($request)->rows();

        $connection->logQuery($sql, $bindings, round((microtime(true) - $start) * 1000, 2));
    }

    /**
     * Get raw SQL without its string literals in single quotes and its names in double quotes or backticks (with
     * backslash escapes and doubled quotes), each replaced by a space, so that a word in a value or a name is not
     * read as a keyword. If the SQL is too long for PCRE to scan, it is returned as it is.
     *
     * @param string $sql
     * @return string
     */
    protected function getSqlOutsideQuotes(string $sql): string
    {
        return preg_replace(
            '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`(?:[^`\\\\]++|\\\\.|``)*+`/s',
            ' ',
            $sql
        ) ?? $sql;
    }

    /**
     * Refuse a delete statement with joins.
     *
     * Laravel's grammar would compile MySQL's "delete ... from ... join ...", which
     * ClickHouse rejects, and a ClickHouse mutation has no JOIN. This runs the checks
     * of compileDeleteWithoutJoins(), which refuse the join, so nothing is sent.
     *
     * @param Builder $query
     * @param string $table
     * @param string $where
     * @return string
     * @throws QueryException Always: for the missing where condition, or for the join (see verifyClausesOfMutation())
     */
    protected function compileDeleteWithJoins(Builder $query, $table, $where): string
    {
        return $this->compileDeleteWithoutJoins($query, $table, $where);
    }

    /**
     * Compile an update statement without joins as an ALTER TABLE ... UPDATE mutation.
     *
     * ClickHouse 24.8 rejects Laravel's "update ... set ..." with a syntax error. The table gets ON CLUSTER for the
     * default cluster of the connection's use_on_cluster option (see compileOnClusterClause()). Before the statement
     * is returned, the update is refused for a query that uses a clause the update would ignore (see
     * verifyClausesOfMutation()), in a session with ON CLUSTER when a temporary table of the session has the table's
     * name (see refuseATemporaryTableThatTheStatementMisses()), and for a condition with a sub-query or an IN <table>
     * that ClickHouse cannot run in a SELECT (see refuseConditionsThatClickHouseCannotRun()).
     *
     * @param Builder $query
     * @param string $table
     * @param string $columns
     * @param string $where
     * @return string
     * @throws QueryException When the query has no where condition, which ClickHouse would reject with a syntax
     *                        error, or the update is refused for one of the reasons above
     */
    protected function compileUpdateWithoutJoins(Builder $query, $table, $columns, $where): string
    {
        if (trim($where) === '') {
            throw QueryException::cannotUpdateWithoutWhere();
        }
        $this->verifyClausesOfMutation($query, 'update');

        $cluster = $this->getClusterOf($query);
        if ($cluster !== null) {
            $this->refuseATemporaryTableThatTheStatementMisses(
                $query,
                'ALTER TABLE ... ON CLUSTER ... UPDATE',
                $this->describeHowToLeaveOutOnCluster()
            );
        }

        $this->refuseConditionsThatClickHouseCannotRun($query, 'update', $table, $where, $cluster !== null);

        return "alter table {$table}{$this->compileOnClusterClause($cluster)} update $columns{$this->compilePartitionClause($query)} $where";
    }

    /**
     * Refuse an update statement with joins.
     *
     * Laravel's grammar would compile MySQL's "update ... join ... set ...", which
     * ClickHouse rejects, and a ClickHouse mutation has no JOIN. This runs the checks
     * of compileUpdateWithoutJoins(), which refuse the join, so nothing is sent.
     *
     * @param Builder $query
     * @param string $table
     * @param string $columns
     * @param string $where
     * @return string
     * @throws QueryException Always: for the missing where condition, or for the join (see verifyClausesOfMutation())
     */
    protected function compileUpdateWithJoins(Builder $query, $table, $columns, $where): string
    {
        return $this->compileUpdateWithoutJoins($query, $table, $columns, $where);
    }

    /**
     * Refuse a delete or update of a query with a clause that the mutation would ignore.
     *
     * Laravel's grammar leaves the select list, joins, GROUP BY, HAVING, ORDER BY,
     * LIMIT, OFFSET and unions out of a delete or update, and a ClickHouse mutation
     * only takes the where conditions, so the mutation could change rows that the
     * query does not select:
     *
     * - 'SELECT aliases or expressions': a column with ' as ', or an expression, such
     *   as selectRaw() or selectSub(). A condition on a select alias would read the
     *   table's column of that name;
     * - JOIN, GROUP BY and HAVING;
     * - ORDER BY, when an order defines an alias, which the where conditions read
     *   instead of the table's column of that name (see
     *   hasAnOrderThatDefinesAnAlias()), calls arrayJoin(), which drops the rows
     *   whose array is empty and repeats the others (see
     *   hasAnOrderThatCallsArrayJoin()), or limits the rows with LIMIT, LIMIT ... BY,
     *   OFFSET or FETCH, or adds a set operation with UNION, INTERSECT or EXCEPT, in
     *   its raw SQL, such as orderByRaw('ts desc limit 1 by user'), which ClickHouse
     *   reads as part of the select (see hasAnOrderThatLimitsRows());
     * - LIMIT (limit(0) included), which also names groupLimit(): a per-group
     *   LIMIT that Laravel compiles into a row_number() window;
     * - OFFSET, above 0, and each kind of union, named UNION (union(), sent as
     *   UNION DISTINCT) or UNION ALL.
     *
     * An offset of 0 skips no row and is allowed, as is any other ORDER BY: it sorts
     * the rows, and WITH FILL only adds rows that the table does not have. DISTINCT,
     * timeout(), forceIndex() and ignoreIndex() do not change which rows match either,
     * and a mutation does not use them. The mutation leaves an allowed order out with
     * its bindings (see prepareBindingsForDelete() and prepareBindingsForUpdate()).
     * The where conditions are sent, whatever they compile to: whereDate() and the
     * other date conditions, whereLike(), null-safe equality and bitwise conditions
     * read the table's columns as a select does.
     *
     * first(), value(), find(), sole(), chunk(), paginate() and simplePaginate() leave
     * a LIMIT on the query they run on, which the exception names for a LIMIT or an
     * OFFSET, and updateOrInsert() calls limit(1)->update(), so it is refused for an
     * existing row, as the exception says for an update with a LIMIT: a mutation
     * cannot change one row of several. The exception recommends selecting the keys
     * first, never a sub-query, which ClickHouse can run again for each data part it
     * mutates (see QueryException::cannotMutateWithClauses()).
     *
     * @param Builder $query
     * @param string $statement The statement: delete or update
     * @return void
     * @throws QueryException
     */
    protected function verifyClausesOfMutation(Builder $query, string $statement): void
    {
        $clauses = array_keys(array_filter([
            'SELECT aliases or expressions' => $this->selectsAnAliasOrAnExpression($query),
            'JOIN' => !empty($query->joins),
            'GROUP BY' => !empty($query->groups),
            'HAVING' => !empty($query->havings),
            'ORDER BY' => $this->hasAnOrderThatDefinesAnAlias($query) || $this->hasAnOrderThatCallsArrayJoin($query)
                || $this->hasAnOrderThatLimitsRows($query),
            'LIMIT' => $query->limit !== null || $query->groupLimit !== null,
            'OFFSET' => !empty($query->offset),
            'FINAL' => $query instanceof QueryBuilder && $query->final,
            'SAMPLE' => $query instanceof QueryBuilder && $query->sample !== null,
            'ARRAY JOIN' => $query instanceof QueryBuilder && $query->arrayJoins !== [],
            'LIMIT BY' => $query instanceof QueryBuilder && $query->limitBy !== null,
            'WITH' => $query instanceof QueryBuilder && $query->withs !== [],
            'SETTINGS' => $query instanceof QueryBuilder && $query->settings !== [],
        ]));
        $unions = array_map(
            fn (array $union): string => match (true) {
                isset($union['type']) => strtoupper($union['type']) . (empty($union['distinct']) ? '' : ' DISTINCT'),
                empty($union['all']) => 'UNION',
                default => 'UNION ALL',
            },
            $query->unions ?? []
        );
        $clauses = array_merge($clauses, array_values(array_unique($unions)));

        if ($clauses !== []) {
            throw QueryException::cannotMutateWithClauses(
                $statement,
                $clauses,
                ['first()', 'chunk()', 'paginate()', 'simplePaginate()'],
                'updateOrInsert()'
            );
        }
    }

    /**
     * Determine if an order of the query calls arrayJoin() in the raw SQL it writes
     * (see getSqlOfTheExpressionsOfAnOrder()). arrayJoin() drops the rows whose array
     * is empty and repeats the others.
     *
     * Public so that QueryBuilder can count such a query in a sub-query.
     *
     * @param Builder $query
     * @return bool
     */
    public function hasAnOrderThatCallsArrayJoin(Builder $query): bool
    {
        foreach ($query->orders ?? [] as $order) {
            foreach ($this->getSqlOfTheExpressionsOfAnOrder($order) as $sql) {
                if (preg_match('/\barrayJoin\b/i', $sql) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if an order of the query adds rows: WITH FILL, in any case, in the raw
     * SQL it writes (see getSqlOfTheExpressionsOfAnOrder()). WITH FILL adds a row for
     * each missing value of the range it fills.
     *
     * Public so that QueryBuilder can count such a query in a sub-query. A mutation
     * leaves such an order out: the added rows are not in the table.
     *
     * @param Builder $query
     * @return bool
     */
    public function hasAnOrderThatAddsRows(Builder $query): bool
    {
        foreach ($query->orders ?? [] as $order) {
            foreach ($this->getSqlOfTheExpressionsOfAnOrder($order) as $sql) {
                if (preg_match('/\bWITH\s+FILL\b/i', $sql) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if an order of the query limits the rows, or changes them with a set operation: LIMIT, OFFSET, FETCH,
     * UNION, INTERSECT or EXCEPT, in any case, in the raw SQL it writes (see getSqlOfTheExpressionsOfAnOrder()),
     * outside parentheses and quotes (see getTopLevelOfSql()).
     *
     * ClickHouse (24.8 checked) reads such words after ORDER BY as clauses of the select, so that
     * orderByRaw('ts desc limit 1 by user') returns one row for each user, and orderByRaw('id limit 1') or
     * orderByRaw('id offset 2 rows') fewer rows than the conditions match. orderByRaw('a except select * from t
     * where b = 20') makes the query a set operation, which returns the rows of its first select that the other
     * select does not; intersect keeps only those that it does, and union adds the rows of the other select. A
     * sub-query in an order, such as orderBy() of a query with limit(1), keeps its LIMIT inside its parentheses and
     * does not count. A word inside a comment counts.
     *
     * Public so that QueryBuilder can count such a query in a sub-query. A mutation would leave such an order out
     * and change every row that the conditions match.
     *
     * @param Builder $query
     * @return bool
     */
    public function hasAnOrderThatLimitsRows(Builder $query): bool
    {
        foreach ($query->orders ?? [] as $order) {
            foreach ($this->getSqlOfTheExpressionsOfAnOrder($order) as $sql) {
                if (preg_match('/\b(?:LIMIT|OFFSET|FETCH|UNION|INTERSECT|EXCEPT)\b/i', $this->getTopLevelOfSql($sql)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the top level of raw SQL: the SQL with each string literal in single quotes, each identifier in double
     * quotes or backticks (with backslash escapes and doubled quotes), and then each parenthesized part, innermost
     * first, replaced by a space. Unbalanced quotes and parentheses are left as they are. If the SQL is too long for
     * PCRE to scan, it is returned as it is, so that a word in it still counts.
     *
     * @param string $sql
     * @return string
     */
    protected function getTopLevelOfSql(string $sql): string
    {
        $topLevel = preg_replace(
            '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|`(?:[^`\\\\]++|\\\\.|``)*+`/s',
            ' ',
            $sql
        );

        $count = 1;
        while ($topLevel !== null && $count > 0) {
            $topLevel = preg_replace('/\([^()]*+\)/', ' ', $topLevel, -1, $count);
        }

        return $topLevel ?? $sql;
    }

    /**
     * Determine if an order of the query defines an alias: a column name with an
     * alias (see isAliasedName()), or the word AS, in any case, in the raw SQL the
     * order writes (see getSqlOfTheExpressionsOfAnOrder()).
     *
     * ClickHouse (24.8 checked) lets the where conditions read an alias defined in
     * ORDER BY, so a condition on that name reads the aliased expression rather than
     * the table's column of that name. ClickHouse has no ORDER BY alias without AS.
     * Raw SQL that has AS in it for another reason, such as CAST(x AS String) or a
     * sub-query that selects an alias, counts too.
     *
     * Public so that QueryBuilder can count such a query in a sub-query.
     *
     * @param Builder $query
     * @return bool
     */
    public function hasAnOrderThatDefinesAnAlias(Builder $query): bool
    {
        foreach ($query->orders ?? [] as $order) {
            if ($this->isAliasedName($order['column'] ?? null)) {
                return true;
            }

            foreach ($this->getSqlOfTheExpressionsOfAnOrder($order) as $sql) {
                if (preg_match('/\bAS\b/i', $sql) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the raw SQL that an order writes as it is: the SQL of orderByRaw(), and of
     * each expression, such as DB::raw() or a sub-query, given to orderBy() or
     * inOrderOf() as the column, or to inOrderOf() as one of the values, which
     * parameter() writes as raw SQL.
     *
     * @param array<string, mixed> $order
     * @return array<int, string>
     */
    protected function getSqlOfTheExpressionsOfAnOrder(array $order): array
    {
        if (($order['type'] ?? null) === 'Raw') {
            return [(string) $this->getValue($order['sql'])];
        }

        $values = ($order['type'] ?? null) === 'InOrderOf' ? $order['values'] ?? [] : [];

        $sql = [];
        foreach ([$order['column'] ?? null, ...$values] as $value) {
            if ($this->isExpression($value)) {
                $sql[] = (string) $this->getValue($value);
            }
        }

        return $sql;
    }

    /**
     * Determine if a column is a name with ' as ' in it, in any case, given as a string
     * or as a Stringable, such as $request->string('sort'): Laravel's wrap() compiles
     * both as a name with an alias.
     *
     * @param mixed $column
     * @return bool
     */
    protected function isAliasedName(mixed $column): bool
    {
        return (is_string($column) || ($column instanceof Stringable && !$this->isExpression($column)))
            && stripos((string) $column, ' as ') !== false;
    }

    /**
     * Determine if the query selects an alias or an expression: a column name with an
     * alias (see isAliasedName()), or an expression, such as selectRaw() and selectSub() add.
     *
     * Public so that QueryBuilder can count such a query in a sub-query.
     *
     * @param Builder $query
     * @return bool
     */
    public function selectsAnAliasOrAnExpression(Builder $query): bool
    {
        foreach ($query->columns ?? [] as $column) {
            if ($this->isExpression($column) || $this->isAliasedName($column)) {
                return true;
            }
        }

        return false;
    }
}
