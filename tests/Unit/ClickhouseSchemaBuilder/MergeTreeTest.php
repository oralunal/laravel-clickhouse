<?php

namespace Tests\Unit\ClickhouseSchemaBuilder;

use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\InvalidClickHouseDDLException;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Expression;
use Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree;
use PHPUnit\Framework\TestCase;

class MergeTreeTest extends TestCase
{
    public function testSimple()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one'),
            'simple_table'
        );
    }

    public function testColumns()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one')->default('5')->comment('some comment'),
                    $t->string('col_two')->default(new Expression('col_one')),
                    $t->integer('col_int1')->default(0),
                    $t->integer('col_int2', 128),
                    $t->integer('col_int3', 256, false),
                    $t->uInt8('col_int4'),
                    $t->uInt16('col_int5'),
                    $t->uInt32('col_int6'),
                    $t->uInt64('col_int7'),
                    $t->uInt128('col_int8'),
                    $t->uInt256('col_int9'),
                    $t->int8('col_int10'),
                    $t->int8('col_int11'),
                    $t->int16('col_int12'),
                    $t->int32('col_int13'),
                    $t->int64('col_int14'),
                    $t->int128('col_int15'),
                    $t->int256('col_int16'),
                    $t->bool('col_bool')->default(false),
                    $t->date('col_date'),
                    $t->datetime('col_datetime1'),
                    $t->datetime('col_datetime2', 3),
                    $t->float('col_float1'),
                    $t->float32('col_float2'),
                    $t->float64('col_float3'),
                    $t->decimal('col_decimal1', 20, 3),
                    $t->decimal32('col_decimal2', 1),
                    $t->decimal64('col_decimal3', 2),
                    $t->decimal128('col_decimal4', 3),
                    $t->decimal256('col_decimal5', 4),
                    $t->uuid('col_uuid'),
                    $t->enum('col_enum1', ['a', 'b']),
                    $t->enum('col_enum2', ['b' => 1, 'c' => 2]),
                    $t->string('col_nullable')->nullable(),
                    $t->column('col_common1', 'Tuple(UInt8, String)'),
                    $t->column('col_common2', 'Tuple', [new Expression('UInt8'), new Expression('String')]),
                    $t->column('col_common3', 'FixedString', [5]),
                    $t->column('col_common4', 'Object', ['json']),
                ])
                ->orderBy('col_one'),
            'table_with_columns'
        );
    }

    public function testOnCluster()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->onCluster('some_cluster'),
            'table_on_cluster'
        );
    }

    public function testReplacingMergeTree()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->engine(Engine::REPLACING_MERGE_TREE, 'col_one'),
            'table_replacing_merge_tree'
        );
    }

    public function testReplicatedMergeTree()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->engine((new Engine())->replicated()),
            'table_replicated_merge_tree'
        );
    }

    public function testReplicatedReplacingMergeTree()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->dbName('some_db')
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->engine((new Engine(Engine::REPLACING_MERGE_TREE))->replicated(), 'col_one'),
            'table_replicated_replacing_merge_tree'
        );
    }

    /**
     * Migration::createMergeTree() makes the engine replicated on a cluster
     * connection before the callback sets the engine type.
     */
    public function testEngineTypeKeepsReplication()
    {
        $table = (new MergeTree('some_table'))->dbName('some_db');
        $table->getEngine()->replicated();

        $this->assertMergeTree(
            $table
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->engine(Engine::REPLACING_MERGE_TREE, 'col_one'),
            'table_replicated_replacing_merge_tree'
        );
    }

    public function testPartitioned()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                    $t->datetime('at'),
                ])
                ->orderBy('col_one')
                ->partition('toDate(at)'),
            'partitioned_table'
        );
    }

    public function testOrderBy()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                    $t->datetime('at'),
                ])
                ->orderBy('col_one', 'at'),
            'table_with_order_by'
        );
    }

    public function testTTL()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                    $t->datetime('at'),
                ])
                ->orderBy('col_one')
                ->ttl('at', '1 month'),
            'table_with_ttl'
        );
    }

    public function testMergeTreeSettings()
    {
        $this->assertMergeTree(
            (new MergeTree('some_table'))
                ->columns(fn(MergeTree $t) => [
                    $t->string('col_one'),
                ])
                ->orderBy('col_one')
                ->settings(['ttl_only_drop_parts' => 1, 'index_granularity' => 8192]),
            'table_with_settings'
        );
    }

    /**
     * Names and values with quotes, backslashes, spaces or dots are escaped;
     * plain names, reserved words and values compile as in the fixtures.
     */
    public function testNamesAndValuesAreEscaped()
    {
        $table = (new MergeTree("it's table"))
            ->dbName("o'db")
            ->columns(fn(MergeTree $t) => [
                $t->uInt32('id'),
                $t->string('my col')->default("a'b")->comment("it's \\ here"),
                $t->string('slash')->default('ends with \\'),
                $t->string('n.a'),
                $t->string('we`ird\\'),
                $t->enum('e', ["it's", 'back\\slash']),
                $t->enum('e2', ["o'k" => 1, 'x' => '-2']),
                $t->datetime('at', 3, "Europe/Istanbul"),
            ])
            ->orderBy('id')
            ->onCluster("it's cluster");
        $table->getEngine()->replicated()->setReplicaName("{replica}'s");

        $this->assertSame(
            "CREATE TABLE `it's table` ON CLUSTER 'it\\'s cluster' (\n"
            . "  \"id\" UInt32,\n"
            . "  `my col` String DEFAULT 'a\\'b' COMMENT 'it\\'s \\\\ here',\n"
            . "  slash String DEFAULT 'ends with \\\\',\n"
            . "  `n.a` String,\n"
            . "  `we\\`ird\\\\` String,\n"
            . "  e Enum('it\\'s', 'back\\\\slash'),\n"
            . "  e2 Enum('o\\'k' = 1, 'x' = -2),\n"
            . "  at DateTime64(3, 'Europe/Istanbul')\n"
            . ")\n"
            . "ENGINE = ReplicatedMergeTree('/clickhouse/tables/o\\'db.it\\'s table', '{replica}\\'s')\n"
            . 'ORDER BY ("id")',
            $table->compile()
        );
    }

    public function testFloatDefaultsKeepEveryDigit()
    {
        $compiled = (new MergeTree('some_table'))
            ->columns(fn(MergeTree $t) => [
                $t->float64('third')->default(1 / 3),
                $t->float64('half')->default(0.5),
                $t->float64('whole')->default(2.0),
                $t->float64('large')->default(1e20),
                $t->float64('not_a_number')->default(NAN),
                $t->float64('infinity')->default(-INF),
            ])
            ->orderBy('third')
            ->compile();

        $this->assertStringContainsString(
            "  third Float64 DEFAULT 0.3333333333333333,\n"
            . "  half Float64 DEFAULT 0.5,\n"
            . "  whole Float64 DEFAULT 2,\n"
            . "  large Float64 DEFAULT 1.0E+20,\n"
            . "  not_a_number Float64 DEFAULT nan,\n"
            . "  infinity Float64 DEFAULT -inf\n",
            $compiled
        );
    }

    public function testOrderByStaysAnExpression()
    {
        $compiled = (new MergeTree('some_table'))
            ->columns(fn(MergeTree $t) => [$t->datetime('at'), $t->uInt32('id')])
            ->orderBy('toDate(at)', 'id')
            ->ttl('at', '1 day')
            ->compile();

        $this->assertStringEndsWith("ORDER BY (toDate(at), \"id\")\nTTL at + INTERVAL 1 day", $compiled);
    }

    public function testAnEnumNumberMustBeAnInteger()
    {
        $this->expectException(InvalidClickHouseDDLException::class);
        $this->expectExceptionMessage("Enum e must give the value 'a' an integer");

        (new MergeTree('some_table'))->enum('e', ['a' => '1; DROP TABLE x']);
    }

    /**
     * A number must be an integer or a string of digits, which may start
     * with a minus sign, so a whole float, a bool or a padded string throws.
     */
    public function testAnEnumNumberThatOnlyLooksLikeAnIntegerThrows()
    {
        foreach ([1.0, -1.0, true, '+1', ' 1', '1 '] as $number) {
            try {
                (new MergeTree('some_table'))->enum('e', ['low' => $number]);
                $this->fail('enum() should throw for ' . var_export($number, true));
            } catch (InvalidClickHouseDDLException $exception) {
                $this->assertStringStartsWith("Enum e must give the value 'low' an integer.", $exception->getMessage());
            }
        }
    }

    /**
     * A database name may qualify the table name, as in 3.0.0. Each part
     * that is a plain identifier or already quoted is written as given.
     */
    public function testADatabaseNameMayQualifyTheTableName()
    {
        foreach ([
            'analytics.events' => 'analytics.events',
            'default.events' => 'default.events',
            '`analytics`.`my events`' => '`analytics`.`my events`',
            '`analytics`.events' => '`analytics`.events',
            '"analytics"."events"' => '"analytics"."events"',
            '`my.db`.`my.events`' => '`my.db`.`my.events`',
            'analytics.my events' => 'analytics.`my events`',
            "o'db.it's" => "`o'db`.`it's`",
            'a.b.c' => 'a.b.c',
        ] as $name => $expected) {
            $this->assertStringStartsWith(
                "CREATE TABLE {$expected} (\n",
                (new MergeTree($name))->columns(fn (MergeTree $t) => [$t->uInt32('id')])->orderBy('id')->compile(),
                $name
            );
        }
    }

    /**
     * A name that is already one quoted identifier is written as given, as in
     * 3.0.0; any other name that is not a plain identifier is quoted.
     */
    public function testANameQuotedByHandIsKept()
    {
        $compiled = (new MergeTree('`hand quoted`'))
            ->columns(fn (MergeTree $t) => [
                $t->uInt32('id'),
                $t->string('`my col`'),
                $t->string('"my dq col"'),
                $t->string(' `padded` '),
                $t->string('`we\\`ird`'),
                $t->string('`do``ubled`'),
                $t->string('n.a'),
                $t->string('`unclosed'),
                $t->string('`a`.`b`'),
            ])
            ->orderBy('id')
            ->compile();

        $this->assertSame(
            "CREATE TABLE `hand quoted` (\n"
            . "  \"id\" UInt32,\n"
            . "  `my col` String,\n"
            . "  \"my dq col\" String,\n"
            . "  `padded` String,\n"
            . "  `we\\`ird` String,\n"
            . "  `do``ubled` String,\n"
            . "  `n.a` String,\n"
            . "  `\\`unclosed` String,\n"
            . "  `\\`a\\`.\\`b\\`` String\n"
            . ")\n"
            . "ENGINE = MergeTree()\n"
            . 'ORDER BY ("id")',
            $compiled
        );
        $this->assertStringStartsWith(
            'CREATE TABLE "dq table" (',
            (new MergeTree('"dq table"'))->columns(fn (MergeTree $t) => [$t->uInt32('id')])->orderBy('id')->compile()
        );
        $this->assertStringStartsWith(
            'CREATE TABLE `\\`unclosed` (',
            (new MergeTree('`unclosed'))->columns(fn (MergeTree $t) => [$t->uInt32('id')])->orderBy('id')->compile()
        );
    }

    /**
     * ClickHouse reads a bare number as a Float64 and truncates it to the
     * Decimal's scale (DEFAULT 19.99 stores 19.98), so a float default of a
     * Decimal column is written as a string literal.
     */
    public function testAFloatDefaultOfADecimalColumnIsAStringLiteral()
    {
        $compiled = (new MergeTree('some_table'))
            ->columns(fn (MergeTree $t) => [
                $t->uInt32('id'),
                $t->decimal('price', 10, 2)->default(19.99),
                $t->decimal32('small', 2)->nullable()->default(0.29),
                $t->decimal256('large', 4)->default(1e20),
                $t->column('typed', 'Decimal', [10, 2])->default(1.15),
                $t->column('numeric_alias', 'NUMERIC', [10, 2])->default(0.29),
                $t->column('lower', 'decimal64', [2])->default(0.29),
                $t->decimal('whole', 10, 2)->default(7),
                $t->decimal('text', 10, 2)->default('0.18'),
                $t->decimal('not_a_number', 10, 2)->default(NAN),
                $t->float64('ratio')->default(0.5),
            ])
            ->orderBy('id')
            ->compile();

        $this->assertStringContainsString(
            "  price Decimal(10, 2) DEFAULT '19.99',\n"
            . "  small Nullable(Decimal32(2)) DEFAULT '0.29',\n"
            . "  large Decimal256(4) DEFAULT '1.0E+20',\n"
            . "  typed Decimal(10, 2) DEFAULT '1.15',\n"
            . "  numeric_alias NUMERIC(10, 2) DEFAULT '0.29',\n"
            . "  lower decimal64(2) DEFAULT '0.29',\n"
            . "  whole Decimal(10, 2) DEFAULT 7,\n"
            . "  text Decimal(10, 2) DEFAULT '0.18',\n"
            . "  not_a_number Decimal(10, 2) DEFAULT nan,\n"
            . "  ratio Float64 DEFAULT 0.5\n",
            $compiled
        );
    }

    /**
     * An array with a string key maps each name to its number, an integer key
     * (which PHP makes of a numeric string key) being a name too. An array
     * whose keys are all integers is a list of names written from its values,
     * whatever the gaps, as in 3.0.0, so names that are all numbers are
     * numbered with column() and the type written out.
     */
    public function testAnEnumWithNumericNames()
    {
        $compiled = (new MergeTree('some_table'))
            ->columns(fn (MergeTree $t) => [
                $t->enum('numbered', ['active' => 1, 'inactive' => 2]),
                $t->enum('numeric_name', ['active' => 1, '200' => '-2']),
                $t->enum('numeric_name_first', [200 => 1, 'active' => 2]),
                $t->enum('mixed', ['200' => 1, 'teapot' => 418]),
                $t->enum('numbers', [200, 404]),
                $t->enum('unique_numbers', array_unique([200, 404, 200, 500])),
                $t->enum('filtered_numbers', array_filter([0, 200, 404])),
                $t->enum('numeric_names_only', ['200' => 1, '404' => 2]),
                $t->enum('names', [3 => 'x', 7 => 'y']),
                $t->column('status', "Enum8('200' = 1, '404' = 2)"),
            ])
            ->orderBy('numbered')
            ->compile();

        $this->assertStringContainsString(
            "  numbered Enum('active' = 1, 'inactive' = 2),\n"
            . "  numeric_name Enum('active' = 1, '200' = -2),\n"
            . "  numeric_name_first Enum('200' = 1, 'active' = 2),\n"
            . "  mixed Enum('200' = 1, 'teapot' = 418),\n"
            . "  numbers Enum('200', '404'),\n"
            . "  unique_numbers Enum('200', '404', '500'),\n"
            . "  filtered_numbers Enum('200', '404'),\n"
            . "  numeric_names_only Enum('1', '2'),\n"
            . "  names Enum('x', 'y'),\n"
            . "  status Enum8('200' = 1, '404' = 2)\n",
            $compiled
        );
    }

    /**
     * Every key of an array with a string key is a name, so an entry of a
     * list next to a string key is a name without a number.
     */
    public function testAListEntryNextToAStringKeyNeedsANumber()
    {
        $this->expectException(InvalidClickHouseDDLException::class);
        $this->expectExceptionMessage(
            "Enum e must give the value '0' an integer."
            . ' An array with a string key maps each name to its number, an integer key being a name too.'
        );

        (new MergeTree('some_table'))->enum('e', ['a', 'b' => 2]);
    }

    /**
     * Settings are written by the query builder's settings writer, with the
     * spacing of 3.0.0: an int as a number, a bool as 1 or 0, a string as an
     * escaped string literal, a float with every digit and an Expression as
     * given; a null value is left out.
     */
    public function testSettingValuesAreWrittenAsLiterals()
    {
        $compiled = (new MergeTree('some_table'))
            ->columns(fn (MergeTree $t) => [$t->uInt32('id')])
            ->orderBy('id')
            ->settings([
                'index_granularity' => 8192,
                'ttl_only_drop_parts' => false,
                'allow_nullable_key' => true,
                'storage_policy' => "it's \\ default",
                'merge_selecting_sleep_slowdown_factor' => 1 / 3,
                'min_bytes_for_wide_part' => new Expression('10 * 1024'),
                'skipped' => null,
            ])
            ->compile();

        $this->assertStringEndsWith(
            "\nSETTINGS index_granularity = 8192, ttl_only_drop_parts = 0, allow_nullable_key = 1,"
            . " storage_policy = 'it\\'s \\\\ default', merge_selecting_sleep_slowdown_factor = 0.3333333333333333,"
            . ' min_bytes_for_wide_part = 10 * 1024',
            $compiled
        );
        $this->assertStringEndsWith(
            "ORDER BY (\"id\")",
            (new MergeTree('some_table'))->columns(fn (MergeTree $t) => [$t->uInt32('id')])->orderBy('id')->settings(['skipped' => null])->compile(),
            'a SETTINGS clause with only null values is left out'
        );
    }

    public function testAnInvalidSettingNameThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ClickHouse setting name [index_granularity = 1, x]');

        (new MergeTree('some_table'))
            ->columns(fn (MergeTree $t) => [$t->uInt32('id')])
            ->orderBy('id')
            ->settings(['index_granularity = 1, x' => 1])
            ->compile();
    }

    /**
     * Compare the compiled statement with the fixture, byte for byte apart
     * from the newline at the end of the file.
     */
    protected function assertMergeTree(MergeTree $table, string $precompiledFile): void
    {
        $expected = file_get_contents(__DIR__ . "/compilations/$precompiledFile.sql");
        $this->assertSame(rtrim($expected, "\n"), $table->compile());
    }
}