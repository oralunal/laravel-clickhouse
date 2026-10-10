<?php

namespace Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder;

class Syntax
{
    const RESERVED_WORDS = ['add', 'after', 'algorithm', 'alias', 'all', 'alter', 'and', 'anti', 'any', 'append',
        'apply', 'as', 'asc', 'ascending', 'asof', 'assume', 'ast', 'async', 'attach', 'azure', 'backup',
        'bagexpansion', 'between', 'bidirectional', 'both', 'by', 'cascade', 'case', 'cast', 'change',
        'changed', 'char', 'character', 'check', 'cleanup', 'cluster', 'clusters', 'cn', 'codec', 'collate',
        'column', 'columns', 'comment', 'commit', 'compression', 'const', 'constraint', 'create', 'cross', 'cube',
        'currentuser', 'd', 'data', 'database', 'databases', 'date', 'day', 'days', 'dd', 'deduplicate', 'default',
        'definer', 'delete', 'desc', 'descending', 'describe', 'detach', 'dictionaries', 'dictionary', 'disk',
        'distinct', 'div', 'drop', 'else', 'empty', 'end', 'enforced', 'engine', 'ephemeral', 'estimate', 'event',
        'events', 'every', 'except', 'exists', 'explain', 'expression', 'extended', 'false', 'fetch', 'fields',
        'file', 'filter', 'final', 'first', 'following', 'for', 'foreign', 'format', 'freeze', 'from', 'full',
        'fulltext', 'function', 'global', 'grant', 'grantees', 'granularity', 'groups', 'h', 'hash', 'having',
        'hdfs', 'hh', 'hierarchical', 'host', 'hour', 'hours', 'http', 'id', 'identified', 'ilike', 'in',
        'index', 'indexes', 'indices', 'inherit', 'injective', 'inner', 'interpolate', 'intersect',
        'interval', 'invisible', 'invoker', 'ip', 'join', 'jwt', 'kerberos', 'key', 'keys', 'kill', 'kind', 'last',
        'layout', 'ldap', 'leading', 'left', 'level', 'lifetime', 'lightweight', 'like', 'limit', 'linear', 'list',
        'live', 'local', 'm', 'match', 'materialize', 'materialized', 'max', 'mcs', 'memory', 'merges', 'metrics',
        'mi', 'microsecond', 'microseconds', 'millisecond', 'milliseconds', 'min', 'minute', 'minutes', 'mm',
        'mod', 'modify', 'month', 'months', 'move', 'ms', 'mutation', 'n', 'name', 'nanosecond', 'nanoseconds',
        'next', 'none', 'not', 'ns', 'null', 'nulls', 'offset', 'on', 'only', 'or', 'outer', 'over', 'overridable',
        'part', 'partial', 'partition', 'partitions', 'paste', 'permanently', 'permissive', 'persistent', 'pipeline',
        'plan', 'populate', 'preceding', 'precision', 'prefix', 'prewhere', 'primary', 'profile', 'projection',
        'protobuf', 'pull', 'q', 'qq', 'quarter', 'quarters', 'query', 'quota', 'randomized', 'range', 'readonly',
        'realm', 'recompress', 'references', 'refresh', 'regexp', 'remove', 'rename', 'replace', 'restore', 'restrict',
        'restrictive', 'resume', 'revoke', 'right', 'rollback', 'rollup', 'row', 'rows', 's', 's3', 'salt', 'sample',
        'san', 'scheme', 'second', 'seconds', 'select', 'semi', 'server', 'set', 'settings', 'show', 'signed',
        'simple', 'skip', 'source', 'spatial', 'ss', 'statistics', 'step', 'storage', 'strict', 'subpartition',
        'subpartitions', 'suspend', 'sync', 'syntax', 'system', 'table', 'tables', 'tag', 'tags', 'temporary', 'test',
        'then', 'timestamp', 'to', 'top', 'totals', 'trailing', 'transaction', 'trigger', 'true', 'truncate', 'ttl',
        'type', 'typeof', 'unbounded', 'undrop', 'unfreeze', 'union', 'unique', 'unsigned', 'update', 'url', 'use',
        'using', 'uuid', 'values', 'varying', 'view', 'visible', 'watch', 'watermark', 'week', 'weeks', 'when',
        'where', 'window', 'qualify', 'with', 'recursive', 'wk', 'writable', 'ww', 'year', 'years', 'yy', 'yyyy',
        'zkpath', 'allowed_lateness', 'auto_increment', 'base_backup', 'bcrypt_hash', 'bcrypt_password',
        'changeable_in_readonly', 'cluster_host_ids', 'current_user', 'double_sha1_hash', 'double_sha1_password',
        'is_object_id', 'no_password', 'part_move_to_shard', 'plaintext_password', 'sha256_hash', 'sha256_password',
        'sql_tsi_day', 'sql_tsi_hour', 'sql_tsi_microsecond', 'sql_tsi_millisecond', 'sql_tsi_minute', 'sql_tsi_month',
        'sql_tsi_nanosecond', 'sql_tsi_quarter', 'sql_tsi_second', 'sql_tsi_week', 'sql_tsi_year', 'ssh_key',
        'ssl_certificate', 'strictly_ascending', 'with_itemindex'];

    /**
     * A plain identifier, which ClickHouse reads without quotes.
     */
    protected const PLAIN_IDENTIFIER = '[A-Za-z_][A-Za-z0-9_]*';

    /**
     * An identifier between backticks or double quotes, with its quote
     * characters escaped by a backslash or doubled, as ClickHouse reads it.
     */
    protected const QUOTED_IDENTIFIER = '`(?:[^`\\\\]|\\\\.|``)*`|"(?:[^"\\\\]|\\\\.|"")*"';

    /**
     * Write a name or an expression for a clause that takes expressions, such
     * as ORDER BY: a reserved word between double quotes, anything else as given.
     *
     * @param string $elementName
     * @return string
     */
    public static function escapeName(string $elementName): string
    {
        $elementName = trim($elementName);
        if (!in_array($elementName, self::RESERVED_WORDS)) {
            return $elementName;
        }
        return '"' . $elementName . '"';
    }

    /**
     * Write a column or table name as one identifier.
     *
     * A plain identifier ([A-Za-z_][A-Za-z0-9_]*) is written as escapeName()
     * writes it: as given, or between double quotes when it is a reserved word.
     * A name that is already one quoted identifier, such as `my col` or
     * "my col", is written as given, as in 3.0.0, where quoting a name by hand
     * was the only way to use a space or another special character. Any other
     * name, such as one with a space, a dot, a quote or a backslash, goes
     * between backticks with its backslashes and backticks escaped, so that it
     * stays one identifier: the Nested column n.a becomes `n.a`.
     *
     * @param string $name
     * @return string
     */
    public static function quoteName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/\A' . self::PLAIN_IDENTIFIER . '\z/', $name) === 1) {
            return self::escapeName($name);
        }
        return self::quoteNamePart($name);
    }

    /**
     * Write a table name, which a database name may qualify, as in 3.0.0:
     * events, analytics.events or `analytics`.`my events`.
     *
     * The name is split at each dot outside quotes. A name without such a dot
     * is written as quoteName() writes it. In a qualified name, each part that
     * is a plain identifier or already quoted is written as given, so that
     * every qualified name that 3.0.0 accepted compiles to the same SQL, and
     * any other part goes between backticks: analytics.my events becomes
     * analytics.`my events`. A table name that contains a dot must therefore
     * be quoted by hand: `my.events`. A name that cannot be split this way,
     * such as one with an unclosed quote, is written as one identifier.
     *
     * @param string $name
     * @return string
     */
    public static function quoteTableName(string $name): string
    {
        $name = trim($name);
        $part = self::QUOTED_IDENTIFIER . '|[^.`"]+';
        if (preg_match('/\A(?:' . $part . ')(?:\.(?:' . $part . '))+\z/s', $name) !== 1) {
            return self::quoteName($name);
        }
        preg_match_all('/' . $part . '/s', $name, $matches);
        return implode('.', array_map(self::quoteNamePart(...), $matches[0]));
    }

    /**
     * Write one part of a name: as given when it is a plain identifier or
     * already one quoted identifier, otherwise between backticks with its
     * backslashes and backticks escaped.
     *
     * @param string $name
     * @return string
     */
    protected static function quoteNamePart(string $name): string
    {
        $name = trim($name);
        if (preg_match('/\A(?:' . self::PLAIN_IDENTIFIER . '|' . self::QUOTED_IDENTIFIER . ')\z/s', $name) === 1) {
            return $name;
        }
        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $name) . '`';
    }

    /**
     * Write a value as a ClickHouse string literal, with its backslashes and
     * single quotes escaped.
     *
     * @param string $value
     * @return string
     */
    public static function quoteString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Write a default value or a type parameter.
     *
     * A string becomes an escaped string literal and a bool true or false. An
     * expression is written as given. A float keeps PHP's string form when
     * that is exact, and is written with every digit otherwise; NaN and
     * infinity become nan, inf and -inf. Anything else, such as an int, is
     * returned as it is.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function escapeParam(mixed $value)
    {
        return match (true) {
            is_string($value) => self::quoteString($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => self::writeFloat($value),
            $value instanceof Expression => $value->value,
            default => $value,
        };
    }

    /**
     * Write a float literal that holds the exact value.
     *
     * @param float $value
     * @return string
     */
    protected static function writeFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        $text = (string) $value;
        return (float) $text === $value ? $text : var_export($value, true);
    }
}