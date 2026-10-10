<?php

declare(strict_types=1);

namespace Tests\Unit\ClickhouseBuilder;

use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * format() and Format::named() take a format name in any letter case, as ClickHouse does, and write the canonical name.
 */
class FormatTest extends TestCase
{
    /**
     * Every format of the enum, as written in canonical, lower and upper case.
     *
     * @return array<string, array{string, string}>
     */
    public static function formatNameProvider(): array
    {
        $cases = [];
        foreach (Format::toArray() as $canonical) {
            foreach ([$canonical, strtolower($canonical), strtoupper($canonical)] as $name) {
                $cases["{$canonical} as {$name}"] = [$name, $canonical];
            }
        }

        return $cases;
    }

    #[DataProvider('formatNameProvider')]
    public function test_format_takes_a_name_in_any_letter_case_and_writes_the_canonical_name(string $name, string $canonical): void
    {
        $builder = (new TestBuilder())->from('t')->format($name);

        $this->assertSame("SELECT * FROM `t` FORMAT {$canonical}", $builder->toSql());
        $this->assertSame($canonical, $builder->getFormat()->getValue());
        $this->assertTrue(Format::named($name)->equals(new Format($canonical)));
    }

    public function test_the_names_that_worked_before_compile_as_before(): void
    {
        $this->assertSame('SELECT * FROM `t` FORMAT JSON', (new TestBuilder())->from('t')->format('json')->toSql());
        $this->assertSame('SELECT * FROM `t` FORMAT CSV', (new TestBuilder())->from('t')->format('csv')->toSql());
        $this->assertSame('SELECT * FROM `t` FORMAT TSV', (new TestBuilder())->from('t')->format('tsv')->toSql());
        $this->assertSame('SELECT * FROM `t` FORMAT TSKV', (new TestBuilder())->from('t')->format('Tskv')->toSql());
        $this->assertSame('SELECT * FROM `t` FORMAT XML', (new TestBuilder())->from('t')->format('xml')->toSql());
    }

    public function test_names_with_lower_case_letters_no_longer_throw(): void
    {
        $this->assertSame('SELECT * FROM `t` FORMAT JSONEachRow', (new TestBuilder())->from('t')->format('JSONEachRow')->toSql());
        $this->assertSame('SELECT * FROM `t` FORMAT JSONEachRow', (new TestBuilder())->from('t')->format('jsoneachrow')->toSql());
        $this->assertSame(
            'SELECT * FROM `t` FORMAT TabSeparatedWithNames',
            (new TestBuilder())->from('t')->format('tabseparatedwithnames')->toSql()
        );
        $this->assertSame('SELECT * FROM `t` FORMAT JSONCompactEachRow', (new TestBuilder())->from('t')->format('JSONCompactEachRow')->toSql());
    }

    public function test_json_compact_each_row_is_a_format(): void
    {
        $this->assertSame('JSONCompactEachRow', Format::JSON_COMPACT_EACH_ROW);
        $this->assertSame('JSONCompactEachRow', (string) Format::named('jsoncompacteachrow'));
    }

    public function test_an_unknown_name_throws_with_the_name_as_given(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'jsonl' is not part of the enum " . Format::class);

        (new TestBuilder())->format('jsonl');
    }

    public function test_named_does_not_match_a_part_of_a_name(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'JSON ' is not part of the enum " . Format::class);

        Format::named('JSON ');
    }
}
