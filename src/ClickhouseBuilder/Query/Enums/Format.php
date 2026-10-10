<?php

namespace Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums;

use Oralunal\LaravelClickHouse\Enum\Enum;
use UnexpectedValueException;

/**
 * Formats.
 */
final class Format extends Enum
{
    public const BLOCK_TAB_SEPARATED = 'BlockTabSeparated';
    public const CSV = 'CSV';
    public const CSV_WITH_NAMES = 'CSVWithNames';
    public const JSON = 'JSON';
    public const JSON_COMPACT = 'JSONCompact';
    public const JSON_COMPACT_EACH_ROW = 'JSONCompactEachRow';
    public const JSON_EACH_ROW = 'JSONEachRow';
    public const NATIVE = 'Native';
    public const NULL = 'Null';
    public const PRETTY = 'Pretty';
    public const PRETTY_COMPACT = 'PrettyCompact';
    public const PRETTY_COMPACT_MONO_BLOCK = 'PrettyCompactMonoBlock';
    public const PRETTY_NO_ESCAPES = 'PrettyNoEscapes';
    public const PRETTY_COMPACT_NO_ESCAPES = 'PrettyCompactNoEscapes';
    public const PRETTY_SPACE_NO_ESCAPES = 'PrettySpaceNoEscapes';
    public const PRETTY_SPACE = 'PrettySpace';
    public const ROW_BINARY = 'RowBinary';
    public const TAB_SEPARATED = 'TabSeparated';
    public const TAB_SEPARATED_RAW = 'TabSeparatedRaw';
    public const TAB_SEPARATED_WITH_NAMES = 'TabSeparatedWithNames';
    public const TAB_SEPARATED_WITH_NAMES_AND_TYPES = 'TabSeparatedWithNamesAndTypes';
    public const TSKV = 'TSKV';
    public const VALUES = 'Values';
    public const VERTICAL = 'Vertical';
    public const XML = 'XML';
    public const TSV = 'TSV';

    /**
     * Get the format with this name in any letter case, as ClickHouse matches format names:
     * Format::named('jsoneachrow') returns the JSONEachRow format, whose value is the canonical name.
     *
     * @param string $name
     *
     * @throws UnexpectedValueException When the enum has no format of that name
     *
     * @return self
     */
    public static function named(string $name): self
    {
        foreach (static::toArray() as $value) {
            if (strcasecmp($value, $name) === 0) {
                return new self($value);
            }
        }

        throw new UnexpectedValueException("Value '{$name}' is not part of the enum ".static::class);
    }
}
