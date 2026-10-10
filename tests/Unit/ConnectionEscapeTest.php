<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\UnitEnumFixture;

/**
 * Connection::escape() without PDO: the ClickHouse literals of the query grammar's compileLiteral(), x'..' for binary
 * values, and Laravel's refusals for strings with a NUL byte or invalid UTF-8.
 */
class ConnectionEscapeTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     * @return Connection
     */
    private function connection(array $config = []): Connection
    {
        return new Connection(null, 'db', '', $config + ['name' => 'clickhouse']);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function literals(): array
    {
        return [
            'a string' => ['abc', "'abc'"],
            'a quote' => ["it's", "'it\\'s'"],
            'a backslash' => ['a\\b', "'a\\\\b'"],
            'a double quote' => ['a"b', "'a\\\"b'"],
            'a newline is kept' => ["a\nb", "'a\nb'"],
            'unicode' => ['ünï😀', "'ünï😀'"],
            'an empty string' => ['', "''"],
            'an int' => [42, '42'],
            'a negative int' => [-7, '-7'],
            'a float with every digit' => [0.1 + 0.2, '0.30000000000000004'],
            'a large float' => [1e25, '1.0E+25'],
            'a whole float' => [2.0, '2.0'],
            'NaN' => [NAN, 'nan'],
            'INF' => [INF, 'inf'],
            '-INF' => [-INF, '-inf'],
            'true' => [true, '1'],
            'false' => [false, '0'],
            'null' => [null, 'null'],
            'a nested array' => [[1, 'a', null, [2.5, true]], "[1, 'a', null, [2.5, 1]]"],
            'an empty array' => [[], '[]'],
            'an int-backed enum' => [IntBackedEnumFixture::High, '3'],
            'a string-backed enum' => [StringBackedEnumFixture::Active, "'active'"],
            'a pure enum' => [UnitEnumFixture::Hearts, "'Hearts'"],
            'a Stringable value' => [Str::of("it's"), "'it\\'s'"],
            'raw SQL of the package' => [new Expression('now()'), 'now()'],
            'a Laravel expression' => [new LaravelExpression('now()'), 'now()'],
        ];
    }

    #[DataProvider('literals')]
    public function test_escape_writes_a_clickhouse_literal(mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->connection()->escape($value));
    }

    public function test_a_date_is_written_at_the_connections_precision_in_its_own_time_zone(): void
    {
        $date = new DateTimeImmutable('2024-01-02 03:04:05.123456', new DateTimeZone('Europe/Istanbul'));
        $wholeSecond = new DateTimeImmutable('2024-01-02 03:04:05');

        $this->assertSame("'2024-01-02 03:04:05'", $this->connection()->escape($date));
        $microsecond = $this->connection(['datetime_precision' => 'microsecond']);
        $this->assertSame("'2024-01-02 03:04:05.123456'", $microsecond->escape($date));
        $this->assertSame("'2024-01-02 03:04:05'", $microsecond->escape($wholeSecond));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function binaryValues(): array
    {
        return [
            'bytes with a NUL byte' => ["\xff\x00A", "x'ff0041'"],
            'invalid UTF-8' => ["\xc3\x28", "x'c328'"],
            'text' => ["it's", "x'69742773'"],
            'an empty string' => ['', "x''"],
        ];
    }

    #[DataProvider('binaryValues')]
    public function test_a_binary_value_is_a_hex_string_literal(string $value, string $expected): void
    {
        $this->assertSame($expected, $this->connection()->escape($value, true));
    }

    public function test_null_is_null_also_as_a_binary_value(): void
    {
        $this->assertSame('null', $this->connection()->escape(null, true));
    }

    public function test_only_a_string_can_be_escaped_as_a_binary_value(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only strings can be escaped as binary values, int given.');

        $this->connection()->escape(5, true);
    }

    public function test_a_string_with_a_nul_byte_must_be_escaped_as_binary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strings with null bytes cannot be escaped. Use the binary escape option.');

        $this->connection()->escape("a\0b");
    }

    public function test_a_string_with_invalid_utf8_must_be_escaped_as_binary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strings with invalid UTF-8 byte sequences cannot be escaped.');

        $this->connection()->escape("\xc3\x28");
    }

    public function test_a_value_that_is_no_literal_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot render a value of type Illuminate\Support\Collection in a ClickHouse query.');

        $this->connection()->escape(collect([1, 2]));
    }

    /**
     * Laravel's own grammar helpers call the connection's escape().
     */
    public function test_the_query_grammar_escapes_through_the_connection(): void
    {
        $connection = $this->connection();

        $this->assertSame("'it\\'s'", $connection->getQueryGrammar()->escape("it's"));
        $this->assertSame("x'ff'", $connection->getQueryGrammar()->escape("\xff", true));
    }
}
