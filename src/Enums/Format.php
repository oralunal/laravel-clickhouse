<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Enums;

use ValueError;

/**
 * The ClickHouse formats that the package names: the input formats of inserts and the output formats that a FORMAT
 * clause can give. The value of each case is the name that ClickHouse uses.
 */
enum Format: string
{
    case BlockTabSeparated = 'BlockTabSeparated';
    case Csv = 'CSV';
    case CsvWithNames = 'CSVWithNames';
    case CsvWithNamesAndTypes = 'CSVWithNamesAndTypes';
    case Json = 'JSON';
    case JsonCompact = 'JSONCompact';
    case JsonCompactEachRow = 'JSONCompactEachRow';
    case JsonEachRow = 'JSONEachRow';
    case Native = 'Native';
    case Null = 'Null';
    case Pretty = 'Pretty';
    case PrettyCompact = 'PrettyCompact';
    case PrettyCompactMonoBlock = 'PrettyCompactMonoBlock';
    case PrettyNoEscapes = 'PrettyNoEscapes';
    case PrettyCompactNoEscapes = 'PrettyCompactNoEscapes';
    case PrettySpaceNoEscapes = 'PrettySpaceNoEscapes';
    case PrettySpace = 'PrettySpace';
    case RowBinary = 'RowBinary';
    case TabSeparated = 'TabSeparated';
    case TabSeparatedRaw = 'TabSeparatedRaw';
    case TabSeparatedWithNames = 'TabSeparatedWithNames';
    case TabSeparatedWithNamesAndTypes = 'TabSeparatedWithNamesAndTypes';
    case Tskv = 'TSKV';
    case Tsv = 'TSV';
    case TsvWithNamesAndTypes = 'TSVWithNamesAndTypes';
    case Values = 'Values';
    case Vertical = 'Vertical';
    case Xml = 'XML';

    /**
     * Get the format of a name in any letter case and with surrounding spaces: named('jsoneachrow') is JsonEachRow.
     *
     * @param string $name
     * @return self
     * @throws ValueError For a name of no case
     */
    public static function named(string $name): self
    {
        foreach (self::cases() as $format) {
            if (strcasecmp($format->value, trim($name)) === 0) {
                return $format;
            }
        }

        throw new ValueError(sprintf('"%s" is not a format that %s names.', $name, self::class));
    }

    /**
     * Get the format of a name in any letter case, or null for a name of no case.
     *
     * @param string $name
     * @return self|null
     */
    public static function tryNamed(string $name): ?self
    {
        foreach (self::cases() as $format) {
            if (strcasecmp($format->value, trim($name)) === 0) {
                return $format;
            }
        }

        return null;
    }
}
