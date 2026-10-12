<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Concerns;

/**
 * Writes Laravel's JSON selectors, such as payload->user->name or payload->tags[0], with ClickHouse's JSON functions,
 * which read a JSON document from a String column, the type that json() creates: the column comes first, then each
 * key as a string literal and each array index as a number, as in JSONExtractString("payload", 'user', 'name').
 *
 * ClickHouse counts the elements of an array from 1, so [0] is written as 1, and a negative index counts from the
 * end, [-1] being the last element. A key can hold any character, such as a space, a dot or a quote, because it is
 * written as a string literal. Brackets that hold no whole number, as in a[b], are part of the key.
 */
trait CompilesJsonPaths
{
    /**
     * Write the value of a JSON selector: JSONExtractString(<column>, <path>), which gives a string without its
     * quotes, a number, a boolean, an object or an array as its JSON text, and '' for null and for a missing key;
     * or, with a type, JSONExtract(<column>, <path>, '<type>'), such as 'Nullable(Float64)'.
     *
     * @param string $selector
     * @param string|null $type
     * @return string
     */
    protected function compileJsonExtract(string $selector, ?string $type = null): string
    {
        [$column, $path] = $this->wrapJsonColumnAndPath($selector);

        return $type === null
            ? "JSONExtractString({$column}{$path})"
            : "JSONExtract({$column}{$path}, " . $this->compileJsonStringLiteral($type) . ')';
    }

    /**
     * Split a JSON selector into the wrapped column and the arguments that follow the column in a JSON function,
     * each written after ', ': payload->a->b[0] gives "payload" and , 'a', 'b', 1.
     *
     * @param string $selector
     * @return array{0: string, 1: string}
     */
    protected function wrapJsonColumnAndPath(string $selector): array
    {
        $parts = explode('->', $selector, 2);

        $path = '';
        foreach (isset($parts[1]) ? $this->getJsonPathKeys($parts[1]) : [] as $key) {
            $path .= ', ' . (is_int($key) ? (string) $key : $this->compileJsonStringLiteral($key));
        }

        return [$this->wrap($parts[0]), $path];
    }

    /**
     * Get the keys of a JSON path, the part of a selector after its first ->: a string for each key, and the
     * ClickHouse index of each array index, so that a->b[0][-1] gives ['a', 'b', 1, -1].
     *
     * @param string $path
     * @return list<string|int>
     */
    protected function getJsonPathKeys(string $path): array
    {
        $keys = [];

        foreach (explode('->', $path) as $segment) {
            preg_match('/^(.*?)((?:\[-?\d+\])*)$/s', $segment, $parts);

            if ($parts[1] !== '' || $parts[2] === '') {
                $keys[] = $parts[1];
            }

            preg_match_all('/-?\d+/', $parts[2], $indexes);
            foreach ($indexes[0] as $index) {
                $keys[] = (int) $index >= 0 ? (int) $index + 1 : (int) $index;
            }
        }

        return $keys;
    }

    /**
     * Write a string of a JSON function as a ClickHouse string literal, with backslashes and single quotes escaped:
     * a key of a JSON path, a type name or a JSON document, whose own escapes, such as \" and \n, are kept.
     *
     * @param string $value
     * @return string
     */
    protected function compileJsonStringLiteral(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
