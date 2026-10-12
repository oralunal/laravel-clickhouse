<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Reads the rows of a ClickHouse connection in the tests. Each row is a stdClass object, as on Laravel's other
 * connections; these methods assert that, and give the rows as associative arrays, so that assertSame() compares
 * each value with its type.
 */
final class Rows
{
    /**
     * Assert that each row is a stdClass object, and get the rows as associative arrays, keyed as given.
     *
     * @param iterable<array-key, mixed> $rows
     * @return array<array-key, array<string, mixed>>
     */
    public static function asArrays(iterable $rows): array
    {
        $arrays = [];
        foreach ($rows as $key => $row) {
            $arrays[$key] = self::asArray($row);
        }

        return $arrays;
    }

    /**
     * Assert that each row of each result is a stdClass object, and get the results, keyed as given, with their rows
     * as associative arrays: the results of Parallel::getRows(), for example.
     *
     * @param array<array-key, iterable<array-key, mixed>> $results
     * @return array<array-key, array<array-key, array<string, mixed>>>
     */
    public static function ofEachResult(array $results): array
    {
        return array_map(self::asArrays(...), $results);
    }

    /**
     * Assert that the row is a stdClass object, and get it as an associative array.
     *
     * @param mixed $row
     * @return array<string, mixed>
     */
    public static function asArray(mixed $row): array
    {
        Assert::assertInstanceOf(stdClass::class, $row);

        return (array) $row;
    }
}
