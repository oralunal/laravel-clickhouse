<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Concerns;

use BackedEnum;
use ClickHouseDB\Query\Expression\Expression as ClientExpression;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Type\Type;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Stringable;
use UnitEnum;

/**
 * Writes the bindings of a query in place of its "?" placeholders, as ClickHouse literals.
 *
 * The query is scanned once, from left to right, and written out during the scan. A "?" is a placeholder only
 * outside the parts that ClickHouse does not read as SQL:
 * - string literals in single quotes, and identifiers in double quotes or backticks, each with backslash escapes
 *   and with its quote doubled inside;
 * - heredocs, $$...$$ or $tag$...$tag$, whose tag is the text up to the next $, when the first $ follows neither a
 *   word character nor another $ (ClickHouse reads a $ there as part of a name);
 * - comments up to the end of the line, which start with --, with // or with a # that whitespace or ! follows, and
 *   block comments, which may nest.
 *
 * There, "??" is written as a literal "?", the escape that Laravel uses for a question mark that is no placeholder,
 * so that the ternary operator can be written as "??" in a query with or without bindings. The scan also notes
 * smi2's own placeholders, :name or :0 (a colon that follows neither a word character, a colon, a dot nor a quote)
 * and {name} or {0}, and ClickHouse query parameters, {name:Type}, which prepareQueryForClient() must not mix with
 * "?" placeholders.
 *
 * A word character is an ASCII letter or digit, or an underscore, because ClickHouse (24.8 checked) reads a name
 * outside quotes in ASCII only: any other byte there belongs to a Unicode whitespace character, such as a
 * no-break space, which ClickHouse skips like a space, or is a syntax error.
 */
trait SubstitutesBindings
{
    /**
     * The characters of a name: of the key of a :key or {key} placeholder, and of the word before a $ or a colon.
     */
    private const BINDING_PLACEHOLDER_KEY_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';

    /**
     * Write a PHP value as a ClickHouse literal.
     *
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException When the value cannot be written as a literal
     */
    abstract public function compileLiteral(mixed $value): string;

    /**
     * Write the bindings of a query that is about to run in place of its "?" placeholders, in order, as literals
     * written by compileLiteral(), and each "??" as a literal "?" (see the scan rules of this trait). A literal that
     * would start a comment together with the character before its placeholder, such as a negative number after the
     * "-" of "10-?", gets a space before it: "10- -3", where "10--3" would be 10 followed by a comment.
     *
     * @param string $sql
     * @param array<int|string, mixed> $bindings The bindings in placeholder order; their keys are ignored
     * @return string
     * @throws InvalidArgumentException When the number of "?" placeholders differs from the number of bindings, or a
     *                                  binding cannot be written as a literal
     */
    public function substituteBindings(string $sql, array $bindings): string
    {
        $bindings = array_values($bindings);
        $scan = $this->scanBindingPlaceholders($sql, $bindings);
        $this->verifyBindingPlaceholderCount($scan['placeholders'], count($bindings));

        return $scan['sql'];
    }

    /**
     * Write the bindings of a query in place of its "?" placeholders for display, as Laravel's toRawSql() and
     * QueryExecuted::toRawSql() do, and each "??" as a literal "?". It follows the rules of substituteBindings(),
     * except that a "?" without a binding is left as it is and a binding without a "?" is left out.
     *
     * The signature is that of Laravel's query grammar, which this method overrides in QueryGrammar.
     *
     * @param string $sql
     * @param array<int|string, mixed> $bindings The bindings in placeholder order; their keys are ignored
     * @return string
     * @throws InvalidArgumentException When a binding cannot be written as a literal
     */
    public function substituteBindingsIntoRawSql($sql, $bindings)
    {
        return $this->scanBindingPlaceholders((string) $sql, array_values((array) $bindings))['sql'];
    }

    /**
     * Get the SQL and the bindings to hand to the smi2 client for a query and its bindings.
     *
     * - Without bindings, the query is returned as it is, so a "?" in it stays the ternary operator.
     * - With a list of bindings and at least one "?" placeholder, the bindings are written into the SQL by
     *   substituteBindings() and the client gets none, so that smi2 cannot replace :0 or {0} inside names or string
     *   literals. The query must not also have one of smi2's placeholders whose key is one of the binding keys, such
     *   as :0 or {0}, or a ClickHouse query parameter, such as {id:UInt32}.
     * - Otherwise, as for named bindings or a list of bindings without "?", the query is returned as it is, for
     *   smi2 to replace :name, :0, {name} and {0} as it always did, and for ClickHouse to replace {name:Type}. The
     *   bindings are made safe for smi2 first (see prepareBindingForClient()).
     *
     * In every case each "??" is written as a literal "?", as the scan rules of this trait describe, so that the
     * query that is sent is the one that substituteBindingsIntoRawSql() shows. ClickHouse reads no "??".
     *
     * @param string $query
     * @param array<int|string, mixed> $bindings
     * @return array{0: string, 1: array<int|string, mixed>}
     * @throws InvalidArgumentException When the query mixes "?" placeholders with smi2 placeholders or query
     *                                  parameters, the number of "?" placeholders differs from the number of
     *                                  bindings, or a binding cannot be written as a literal
     */
    public function prepareQueryForClient(string $query, array $bindings): array
    {
        if ($bindings !== [] && array_is_list($bindings)) {
            $scan = $this->scanBindingPlaceholders($query, $bindings);

            if ($scan['placeholders'] > 0) {
                if ($scan['parameters'] > 0 || array_intersect_key($scan['keys'], $bindings) !== []) {
                    throw new InvalidArgumentException(
                        'The query mixes "?" placeholders with smi2 placeholders (:0, {0}) or query parameters '
                        . '({name:Type}). Use "?" for every binding.'
                    );
                }

                $this->verifyBindingPlaceholderCount($scan['placeholders'], count($bindings));

                return [$scan['sql'], []];
            }

            $query = $scan['sql'];
        } elseif (str_contains($query, '??')) {
            $query = $this->scanBindingPlaceholders($query, [])['sql'];
        }

        return [$query, array_map($this->prepareBindingForClient(...), $bindings)];
    }

    /**
     * Make a binding safe for smi2's own substitution, which writes an object with a value property, such as a
     * backed enum or Laravel's Str::of(), as that value without quotes, false as nothing, and fails for NaN, which
     * PHP 8.5 does not convert to a string without a warning:
     * - a bool becomes 1 or 0, and NaN an expression of nan;
     * - a backed enum becomes its value, and a pure enum its name;
     * - raw SQL becomes an expression of the SQL that compileLiteral() writes for it, so that smi2 writes it as the
     *   "?" placeholders do: an Expression and a Laravel database expression, such as DB::raw(), as their SQL, and an
     *   Identifier as a quoted name. An expression of the smi2 client, such as its Raw, becomes such an expression
     *   of its own SQL (see newRawSqlBindingForClient());
     * - any other Stringable object becomes its string, which smi2 then quotes;
     * - an array is made safe element by element, keeping its keys, for smi2's IN (:ids) lists.
     *
     * null, a DateTimeInterface, a smi2 type, such as UInt64, and any other value, INF and -INF included, are
     * returned as they are. smi2 sends the value of a ClickHouse query parameter, {name:Type}, as text and leaves an
     * expression out, so NaN for such a parameter is passed as the string 'nan'.
     *
     * @param mixed $value
     * @return mixed
     * @throws InvalidArgumentException When raw SQL cannot be written, such as a Laravel database expression without
     *                                  a Laravel grammar to give its SQL
     */
    protected function prepareBindingForClient(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            is_float($value) && is_nan($value) => $this->newRawSqlBindingForClient($this->compileLiteral($value)),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface, $value instanceof Type => $value,
            $value instanceof Expression,
            $value instanceof ExpressionContract,
            $value instanceof Identifier => $this->newRawSqlBindingForClient($this->compileLiteral($value)),
            $value instanceof ClientExpression => $this->newRawSqlBindingForClient($value->getValue()),
            $value instanceof Stringable => (string) $value,
            is_array($value) => array_map($this->prepareBindingForClient(...), $value),
            default => $value,
        };
    }

    /**
     * Get a smi2 expression of SQL, which smi2 writes as it is in place of a :key or a {key} placeholder, and as an
     * element of the array of an IN (:ids) list.
     *
     * smi2's own Raw expression is written for :key only: smi2 converts the value of a {key} placeholder and the
     * elements of an array to strings, which a Raw expression has no form of. This expression is also Stringable.
     * A ClickHouse query parameter, {name:Type}, cannot take SQL, and smi2 leaves such an object out of the
     * parameters it sends.
     *
     * @param string $sql
     * @return Raw
     */
    protected function newRawSqlBindingForClient(string $sql): Raw
    {
        return new class ($sql) extends Raw implements Stringable {
            /**
             * Get the SQL of the expression.
             *
             * @return string
             */
            public function __toString(): string
            {
                return $this->getValue();
            }
        };
    }

    /**
     * Scan a query once (see the rules of this trait) and write it out, with each "?" placeholder replaced by the
     * binding of the same position, written by compileLiteral(), and each "??" by a literal "?". A "?" without a
     * binding is kept. A space is written before a literal that would start a comment together with the character
     * before its placeholder: "--", "//" or "/*", as a negative number after the "-" of "10-?" would.
     *
     * The result also counts the "?" placeholders and the query parameters ({name:Type}), and has the keys of the
     * :key and {key} placeholders as array keys, so that :0 and {0} give the key 0 and :00 the key '00', as smi2
     * reads them.
     *
     * @param string $sql
     * @param list<mixed> $bindings
     * @return array{sql: string, placeholders: int, parameters: int, keys: array<int|string, true>}
     * @throws InvalidArgumentException When a binding cannot be written as a literal
     */
    protected function scanBindingPlaceholders(string $sql, array $bindings): array
    {
        $length = strlen($sql);
        $bindingCount = count($bindings);
        $output = '';
        $written = 0;
        $position = 0;
        $placeholders = 0;
        $parameters = 0;
        $keys = [];

        while (($position += strcspn($sql, "?'\"`-#/\$:{", $position)) < $length) {
            $character = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            if ($character === '?') {
                if ($next === '?') {
                    $output .= substr($sql, $written, $position + 1 - $written);
                    $position += 2;
                    $written = $position;
                } else {
                    if ($placeholders < $bindingCount) {
                        $literal = $this->compileLiteral($bindings[$placeholders]);
                        $pair = $position > 0 ? $sql[$position - 1] . substr($literal, 0, 1) : '';

                        if ($pair === '--' || $pair === '//' || $pair === '/*') {
                            $literal = ' ' . $literal;
                        }

                        $output .= substr($sql, $written, $position - $written) . $literal;
                        $written = $position + 1;
                    }
                    $placeholders++;
                    $position++;
                }
            } elseif ($character === "'" || $character === '"' || $character === '`') {
                $position = $this->findEndOfQuotedText($sql, $position);
            } elseif (($character === '-' && $next === '-')
                || ($character === '/' && $next === '/')
                || ($character === '#' && ($next === '!' || ($next !== '' && ctype_space($next))))) {
                $end = strpos($sql, "\n", $position);
                $position = $end === false ? $length : $end + 1;
            } elseif ($character === '/' && $next === '*') {
                $position = $this->findEndOfBlockComment($sql, $position);
            } elseif ($character === '$') {
                $position = $this->findEndOfHeredoc($sql, $position);
            } elseif ($character === ':') {
                $previous = $position > 0 ? $sql[$position - 1] : ' ';
                $keyLength = $this->isWordCharacter($previous) || str_contains(":.'\"`", $previous)
                    ? 0
                    : strspn($sql, self::BINDING_PLACEHOLDER_KEY_CHARACTERS, $position + 1);

                if ($keyLength > 0) {
                    $keys[substr($sql, $position + 1, $keyLength)] = true;
                }
                $position += 1 + $keyLength;
            } elseif ($character === '{') {
                $keyLength = strspn($sql, self::BINDING_PLACEHOLDER_KEY_CHARACTERS, $position + 1);

                if ($keyLength > 0 && ($sql[$position + 1 + $keyLength] ?? '') === '}') {
                    $keys[substr($sql, $position + 1, $keyLength)] = true;
                    $position += 2 + $keyLength;
                } elseif (preg_match('/\G\{\s*\w+\s*:[^}]+\}/', $sql, $match, 0, $position) === 1) {
                    $parameters++;
                    $position += strlen($match[0]);
                } else {
                    $position++;
                }
            } else {
                $position++;
            }
        }

        return [
            'sql' => $output . substr($sql, $written),
            'placeholders' => $placeholders,
            'parameters' => $parameters,
            'keys' => $keys,
        ];
    }

    /**
     * Get the position after a string literal or a quoted identifier that starts at a position: after its closing
     * quote, or the end of the SQL when it is not closed. A backslash escapes the next character, and a doubled quote
     * is part of the text.
     *
     * @param string $sql
     * @param int $position The position of the opening quote
     * @return int
     */
    protected function findEndOfQuotedText(string $sql, int $position): int
    {
        $length = strlen($sql);
        $quote = $sql[$position];
        $stops = '\\' . $quote;
        $position++;

        while (($position += strcspn($sql, $stops, $position)) < $length) {
            if ($sql[$position] !== '\\' && ($sql[$position + 1] ?? '') !== $quote) {
                return $position + 1;
            }

            $position = min($position + 2, $length);
        }

        return $length;
    }

    /**
     * Get the position after a block comment that starts at a position, counting the comments nested in it, or the
     * end of the SQL when it is not closed.
     *
     * @param string $sql
     * @param int $position The position of the slash that opens the comment
     * @return int
     */
    protected function findEndOfBlockComment(string $sql, int $position): int
    {
        $length = strlen($sql);
        $depth = 0;

        while (($position += strcspn($sql, '/*', $position)) < $length) {
            $pair = substr($sql, $position, 2);

            if ($pair === '/*') {
                $depth++;
                $position += 2;
            } elseif ($pair === '*/') {
                $depth--;
                $position += 2;

                if ($depth === 0) {
                    return $position;
                }
            } else {
                $position++;
            }
        }

        return $length;
    }

    /**
     * Get the position after a heredoc that starts with the $ at a position: $$...$$, or $tag$...$tag$, whose tag is
     * the text up to the next $, as ClickHouse reads it. When the $ follows a word character or another $, or the
     * heredoc is not closed, it is no heredoc and the position after the $ is returned.
     *
     * @param string $sql
     * @param int $position The position of the $
     * @return int
     */
    protected function findEndOfHeredoc(string $sql, int $position): int
    {
        $previous = $position > 0 ? $sql[$position - 1] : ' ';

        if ($previous !== '$' && !$this->isWordCharacter($previous)) {
            $tagEnd = strpos($sql, '$', $position + 1);

            if ($tagEnd !== false) {
                $delimiter = substr($sql, $position, $tagEnd + 1 - $position);
                $end = strpos($sql, $delimiter, $tagEnd + 1);

                if ($end !== false) {
                    return $end + strlen($delimiter);
                }
            }
        }

        return $position + 1;
    }

    /**
     * Determine if a character can be part of a name outside quotes: an ASCII letter or digit, or an underscore.
     *
     * A byte of a multibyte UTF-8 character is not: outside quotes, ClickHouse reads it as part of a Unicode
     * whitespace character, such as a no-break space before a $$ heredoc, or as a syntax error.
     *
     * @param string $character
     * @return bool
     */
    protected function isWordCharacter(string $character): bool
    {
        return $character !== '' && strspn($character, self::BINDING_PLACEHOLDER_KEY_CHARACTERS) === 1;
    }

    /**
     * Refuse a query whose number of "?" placeholders differs from its number of bindings.
     *
     * @param int $placeholders
     * @param int $bindings
     * @return void
     * @throws InvalidArgumentException
     */
    protected function verifyBindingPlaceholderCount(int $placeholders, int $bindings): void
    {
        if ($placeholders !== $bindings) {
            throw new InvalidArgumentException(sprintf(
                'The query has %d "?" placeholder%s, but %d binding%s %s given. Write a literal "?" as "??".',
                $placeholders,
                $placeholders === 1 ? '' : 's',
                $bindings,
                $bindings === 1 ? '' : 's',
                $bindings === 1 ? 'was' : 'were'
            ));
        }
    }
}
