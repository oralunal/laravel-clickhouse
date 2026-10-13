<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Larastan\Larastan\SQL\ColumnDefinition;
use Larastan\Larastan\SQL\IamcalSqlParser;
use Larastan\Larastan\SQL\SqlParser;
use Larastan\Larastan\SQL\SqlParserFactory;
use Larastan\Larastan\SQL\TableDefinition;
use Oralunal\LaravelClickHouse\SchemaState;

/**
 * Larastan's SQL parser with the schema dumps of ClickHouse connections: Larastan reads every dump in
 * database/schema to learn the columns of the tables of the models, and its own parsers cannot read the CREATE
 * statements of ClickHouse. extension.neon puts this parser in place of Larastan's sqlParser service. A dump that
 * SchemaState wrote, whose first line names its connection (see SchemaState::DUMP_HEADER), or any other SQL with a
 * ClickHouse ENGINE clause, is read here; any other SQL goes to Larastan's own parser.
 *
 * Each column gets the PHP type of the values that the package returns for its ClickHouse type (see ResultValues),
 * through the type names that Larastan converts:
 * - Int8 to Int64 are int, and UInt8 to UInt32 are int<0, max>. UInt64 and the integers of 128 and 256 bits are
 *   mixed: a value above PHP_INT_MAX is a numeric string, which no type that Larastan converts can say together
 *   with int;
 * - Float32, Float64 and BFloat16 are float. When a ClickHouse connection of the config is read-only, they are
 *   nullable: a read-only connection gets NaN and the infinities as null, and Larastan finds the columns of a model
 *   by the name of its table only, not by its connection. Decimal, String, FixedString, UUID, IPv4, IPv6 and the
 *   dates and times are string, Bool is bool, and Enum8 and Enum16 are the union of their values;
 * - Nullable(T) adds null. LowCardinality(T) and SimpleAggregateFunction(f, T) are T;
 * - Array, Map, Tuple, Nested, JSON and any other type are mixed: Larastan converts no array type.
 *
 * Views, materialized views and dictionaries are read as tables. An EPHEMERAL column, which a select cannot read,
 * and the INDEX, PROJECTION and CONSTRAINT lines of a table are left out. A MATERIALIZED or ALIAS column is
 * nullable: SELECT * does not read it, so a model that Eloquent selects with * has no value for it.
 *
 * ClickHouseMigrationParser converts the type of a rawColumn() of a migration with convert().
 */
final class ClickHouseSqlParser implements SqlParser
{
    /**
     * @param SqlParser $fallback The parser of Larastan, for SQL that is no ClickHouse schema
     * @param ConnectionConfig $config
     */
    public function __construct(private SqlParser $fallback, private ConnectionConfig $config)
    {
    }

    /**
     * Create the parser in place of Larastan's sqlParser service, with the parser that Larastan would use as its
     * fallback. extension.neon calls it, so that the extension names no service of Larastan and loads without it.
     *
     * @return object A SqlParser of Larastan
     */
    public static function create(): object
    {
        return new self((new SqlParserFactory(new IamcalSqlParser()))->create(), new ConnectionConfig());
    }

    /**
     * @param string $sql
     * @return list<TableDefinition>
     */
    public function parseTables(string $sql): array
    {
        if (!str_starts_with($sql, SchemaState::DUMP_HEADER) && preg_match('/^\)\s*\R\s*ENGINE\s*=/mi', $sql) !== 1) {
            return $this->fallback->parseTables($sql);
        }

        $floatsNullable = $this->config->hasReadOnlyClickHouseConnection();
        $tables = [];
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            $table = $this->parseCreate($statement, $floatsNullable);
            if ($table !== null) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Read the name and the columns of a CREATE TABLE, VIEW, MATERIALIZED VIEW or DICTIONARY statement, or null for
     * any other statement.
     *
     * @param string $statement
     * @param bool $floatsNullable
     * @return TableDefinition|null
     */
    private function parseCreate(string $statement, bool $floatsNullable): ?TableDefinition
    {
        $pattern = '/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:TEMPORARY\s+)?'
            . '(?:TABLE|VIEW|MATERIALIZED\s+VIEW|LIVE\s+VIEW|WINDOW\s+VIEW|DICTIONARY)\s+(?:IF\s+NOT\s+EXISTS\s+)?'
            . '((?:`(?:[^`\\\\]|\\\\.)*`|[\w.]+))(?:(?!\bAS\b)[^(])*?\(/msi';
        $body = preg_replace('/\A(?:\s+|--[^\n]*(?:\n|\z)|\/\*.*?\*\/)*/s', '', $statement) ?? '';
        if (preg_match($pattern, $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $name = $this->unquote($match[1][0]);
        $dot = strrpos($name, '.');
        if ($dot !== false && !str_starts_with($match[1][0], '`')) {
            $name = substr($name, $dot + 1);
        }

        $open = $match[0][1] + strlen($match[0][0]) - 1;
        $columns = [];
        foreach (self::splitTopLevel(self::insideParentheses($body, $open), ',') as $definition) {
            $column = $this->parseColumn($definition, $floatsNullable);
            if ($column !== null) {
                $columns[] = $column;
            }
        }

        return new TableDefinition($name, $columns);
    }

    /**
     * Read a column definition, or null for an EPHEMERAL column and for an INDEX, PROJECTION or CONSTRAINT line.
     *
     * @param string $definition
     * @param bool $floatsNullable
     * @return ColumnDefinition|null
     */
    private function parseColumn(string $definition, bool $floatsNullable): ?ColumnDefinition
    {
        $definition = trim($definition);
        if ($definition === '' || preg_match('/^(INDEX|PROJECTION|CONSTRAINT|PRIMARY\s+KEY)\b/i', $definition) === 1) {
            return null;
        }

        if (preg_match('/^(`(?:[^`\\\\]|\\\\.)*`|[^\s`]+)\s+(.*)$/s', $definition, $match) !== 1) {
            return null;
        }

        $type = self::leadingType($match[2]);
        $modifiers = substr($match[2], strlen($type));
        if (preg_match('/^\s*EPHEMERAL\b/i', $modifiers) === 1) {
            return null;
        }

        [$larastanType, $options, $nullable, $values] = self::convert($type, $floatsNullable);
        $nullable = $nullable || preg_match('/^\s*(?:MATERIALIZED|ALIAS)\b/i', $modifiers) === 1;

        return new ColumnDefinition($this->unquote($match[1]), $larastanType, $options, $nullable, $values);
    }

    /**
     * Map a ClickHouse type to the type name, the options and the values that Larastan's converter reads, and
     * whether the column is nullable: INT, with the option unsigned for int<0, max>, DOUBLE, BOOLEAN, ENUM, VARCHAR,
     * or CLICKHOUSE, which Larastan reads as mixed.
     *
     * @param string $type The ClickHouse type, such as Nullable(Decimal(10, 2))
     * @param bool $floatsNullable Whether a floating-point type is nullable (see parseTables())
     * @return array{0: string, 1: list<lowercase-string>, 2: bool, 3: list<string>}
     */
    public static function convert(string $type, bool $floatsNullable): array
    {
        $nullable = false;
        while (preg_match('/^(Nullable|LowCardinality)\((.*)\)$/s', $type, $match) === 1) {
            $nullable = $nullable || $match[1] === 'Nullable';
            $type = trim($match[2]);
        }

        if (preg_match('/^SimpleAggregateFunction\((.*)\)$/s', $type, $match) === 1) {
            $arguments = self::splitTopLevel($match[1], ',');

            return self::convert(trim($arguments[1] ?? ''), $floatsNullable);
        }

        $name = preg_replace('/\(.*$/s', '', $type) ?? $type;

        return match (true) {
            in_array($name, ['Int8', 'Int16', 'Int32', 'Int64'], true),
            str_starts_with($name, 'Interval') => ['INT', [], $nullable, []],
            in_array($name, ['UInt8', 'UInt16', 'UInt32'], true) => ['INT', ['unsigned'], $nullable, []],
            in_array($name, ['Float32', 'Float64', 'BFloat16'], true) => ['DOUBLE', [], $nullable || $floatsNullable, []],
            $name === 'Bool' => ['BOOLEAN', [], $nullable, []],
            in_array($name, ['Enum8', 'Enum16', 'Enum'], true) => ['ENUM', [], $nullable, self::enumValues($type)],
            in_array($name, [
                'String', 'FixedString', 'UUID', 'IPv4', 'IPv6', 'Date', 'Date32', 'DateTime', 'DateTime64', 'Time',
                'Time64', 'Decimal', 'Decimal32', 'Decimal64', 'Decimal128', 'Decimal256',
            ], true) => ['VARCHAR', [], $nullable, []],
            default => ['CLICKHOUSE', [], $nullable, []],
        };
    }

    /**
     * @param string $name
     * @return string
     */
    private function unquote(string $name): string
    {
        if (str_starts_with($name, '`') && str_ends_with($name, '`') && strlen($name) >= 2) {
            return (string) preg_replace('/\\\\(.)/s', '$1', substr($name, 1, -1));
        }

        return $name;
    }

    /**
     * Get the values of an Enum8 or Enum16 type: Enum8('a' = 1, 'b' = 2) gives ['a', 'b'].
     *
     * @param string $type
     * @return list<string>
     */
    private static function enumValues(string $type): array
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\\s*=/", $type, $matches);

        return array_values(array_map(
            static fn (string $value): string => (string) preg_replace('/\\\\(.)/s', '$1', $value),
            $matches[1]
        ));
    }

    /**
     * Get the type at the start of a column definition: the text up to the first space outside parentheses and
     * quotes, as 'DateTime64(3, \'UTC\')' of 'DateTime64(3, \'UTC\') DEFAULT now()'.
     *
     * @param string $definition
     * @return string
     */
    private static function leadingType(string $definition): string
    {
        $parts = self::splitTopLevel($definition, ' ');

        return trim($parts[0] ?? '');
    }

    /**
     * Get the text between the parenthesis at the given offset and the parenthesis that closes it.
     *
     * @param string $text
     * @param int $open The offset of the opening parenthesis
     * @return string
     */
    private static function insideParentheses(string $text, int $open): string
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '`' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($text, $open + 1, $i - $open - 1);
            }
        }

        return substr($text, $open + 1);
    }

    /**
     * Split text at a separator outside parentheses and quotes.
     *
     * @param string $text
     * @param string $separator One character
     * @return list<string>
     */
    private static function splitTopLevel(string $text, string $separator): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $start = 0;
        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === "'" || $char === '`' || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = substr($text, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($text, $start);

        return $parts;
    }
}
