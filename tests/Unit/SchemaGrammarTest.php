<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Client;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection as ClickHouseConnection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException as ClickHouseQueryException;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Unit\ClickhouseBuilder\IntBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\StringBackedEnumFixture;
use Tests\Unit\ClickhouseBuilder\UnitEnumFixture;

class SchemaGrammarTest extends TestCase
{
    /**
     * The reads that the active node's client of a connection from clickhouseConnection() received.
     *
     * @var list<string>
     */
    private array $selects = [];

    /**
     * The statements that a connection from clickhouseConnection() was given.
     *
     * @var list<string>
     */
    private array $statements = [];

    public function test_compile_table_exists_queries_system_tables(): void
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);

        $result = $grammar->compileTableExists('mydb', 'users');

        $this->assertSame(
            "select name from system.tables where database = 'mydb' and name = 'users'"
            . " and is_temporary = 0 and not startsWith(name, '.inner')"
            . " and not endsWith(engine, 'View') and engine != 'Dictionary'",
            $result
        );
    }

    public function test_compile_dictionary_exists_queries_system_tables(): void
    {
        $grammar = new SchemaGrammar($this->createMock(Connection::class));

        $this->assertSame(
            "select name from system.tables where database = 'mydb' and name = 'it\\'s' and engine = 'Dictionary'",
            $grammar->compileDictionaryExists('mydb', "it's")
        );
    }

    public function test_compile_drop_and_drop_if_exists(): void
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $blueprint = new Blueprint($connection, 'migrations');

        $this->assertSame('DROP TABLE `migrations`', $grammar->compileDrop($blueprint, new Fluent()));
        $this->assertSame('DROP TABLE IF EXISTS `migrations`', $grammar->compileDropIfExists($blueprint, new Fluent()));
        $this->assertSame(
            'DROP TABLE `analytics`.`my events`',
            $grammar->compileDrop(new Blueprint($connection, 'analytics.my events'), new Fluent())
        );
    }

    public function test_drop_and_drop_if_exists_wait_with_sync(): void
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $blueprint = new Blueprint($connection, 'visits_old');

        $this->assertSame('DROP TABLE `visits_old` SYNC', $grammar->compileDrop($blueprint, (new Fluent())->sync()));
        $this->assertSame('DROP TABLE IF EXISTS `visits_old` SYNC', $grammar->compileDropIfExists($blueprint, (new Fluent())->sync()));
        $this->assertSame('DROP TABLE `visits_old`', $grammar->compileDrop($blueprint, (new Fluent())->sync(false)));
    }

    public function test_compile_rename_column(): void
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $connection->method('getSchemaGrammar')->willReturn($grammar);

        $this->assertSame(
            'ALTER TABLE `events` RENAME COLUMN `n.a` TO `we\\`ird`',
            $grammar->compileRenameColumn(new Blueprint($connection, 'events'), new Fluent(['from' => 'n.a', 'to' => 'we`ird']))
        );
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function wrappedValues(): array
    {
        return [
            'plain name' => ['a', '`a`'],
            'Nested column' => ['n.a', '`n.a`'],
            'backtick' => ['we`ird', '`we\\`ird`'],
            'trailing backslash' => ['end\\', '`end\\\\`'],
            'space and alias syntax' => ['a as b', '`a as b`'],
            'JSON path syntax' => ['payload->name', '`payload->name`'],
            'expression' => [new Expression('intHash32(id)'), 'intHash32(id)'],
            'column definition' => [new Fluent(['name' => 'n.a']), '`n.a`'],
            'star' => ['*', '*'],
        ];
    }

    #[DataProvider('wrappedValues')]
    public function test_wrap_quotes_one_identifier(mixed $value, string $expected): void
    {
        $this->assertSame($expected, $this->grammar()->wrap($value));
    }

    public function test_wrap_table_quotes_the_database_and_the_table(): void
    {
        $grammar = $this->grammar();

        $this->assertSame('`db`.`t`', $grammar->wrapTable('db.t'));
        $this->assertSame('`my table`', $grammar->wrapTable('my table'));
        $this->assertSame('`a\\`b`', $grammar->wrapTable('a`b'));
    }

    #[DataProvider('typeMappings')]
    public function test_column_type_mappings(string $method, string $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $grammar = new SchemaGrammar($connection);
        $column = new Fluent(['name' => 'f']);

        $ref = new \ReflectionMethod($grammar, $method);
        $result = $ref->invoke($grammar, $column);

        $this->assertSame($expected, $result);
    }

    public static function typeMappings(): array
    {
        return [
            ['typeTinyInteger',  'Int16'],
            ['typeInteger',      'Int32'],
            ['typeBigInteger',   'Int64'],
            ['typeString',       'String'],
            ['typeText',         'String'],
            ['typeMediumText',   'String'],
            ['typeLongText',     'String'],
            ['typeTimestamp',    'DateTime'],
        ];
    }

    /**
     * Every column method of Laravel's Blueprint that has a ClickHouse type,
     * with the type it gets. Integers are tested separately.
     *
     * @return array<string, array{\Closure(Blueprint): mixed, string}>
     */
    public static function columnTypes(): array
    {
        return [
            'char' => [fn (Blueprint $t) => $t->char('c', 2), 'FixedString(2)'],
            'char with the default length' => [fn (Blueprint $t) => $t->char('c'), 'FixedString(255)'],
            'ulid' => [fn (Blueprint $t) => $t->ulid('c'), 'FixedString(26)'],
            'foreignUlid' => [fn (Blueprint $t) => $t->foreignUlid('c'), 'FixedString(26)'],
            'string' => [fn (Blueprint $t) => $t->string('c', 100), 'String'],
            'tinyText' => [fn (Blueprint $t) => $t->tinyText('c'), 'String'],
            'text' => [fn (Blueprint $t) => $t->text('c'), 'String'],
            'mediumText' => [fn (Blueprint $t) => $t->mediumText('c'), 'String'],
            'longText' => [fn (Blueprint $t) => $t->longText('c'), 'String'],
            'float' => [fn (Blueprint $t) => $t->float('c'), 'Float64'],
            'float with precision 24' => [fn (Blueprint $t) => $t->float('c', 24), 'Float32'],
            'float with precision 25' => [fn (Blueprint $t) => $t->float('c', 25), 'Float64'],
            'unsigned float' => [fn (Blueprint $t) => $t->float('c', 10)->unsigned(), 'Float32'],
            'double' => [fn (Blueprint $t) => $t->double('c'), 'Float64'],
            'decimal' => [fn (Blueprint $t) => $t->decimal('c', 10, 3), 'Decimal(10, 3)'],
            'decimal with the defaults' => [fn (Blueprint $t) => $t->decimal('c'), 'Decimal(8, 2)'],
            'unsigned decimal' => [fn (Blueprint $t) => $t->decimal('c')->unsigned(), 'Decimal(8, 2)'],
            'boolean' => [fn (Blueprint $t) => $t->boolean('c'), 'Bool'],
            'enum' => [fn (Blueprint $t) => $t->enum('c', ['new', "it's", 'back\\slash']), "Enum('new', 'it\\'s', 'back\\\\slash')"],
            'enum with numbers' => [fn (Blueprint $t) => $t->enum('c', ['low' => 1, 'high' => '10']), "Enum('low' = 1, 'high' = 10)"],
            'enum with string keys' => [fn (Blueprint $t) => $t->enum('c', ['active' => 1, 'inactive' => 2]), "Enum('active' = 1, 'inactive' = 2)"],
            'enum with a string key and a numeric name, which PHP makes an integer key' => [
                fn (Blueprint $t) => $t->enum('c', ['active' => 1, '200' => '-2']),
                "Enum('active' = 1, '200' = -2)",
            ],
            'enum with a numeric name before a string key' => [fn (Blueprint $t) => $t->enum('c', [200 => 1, 'active' => 2]), "Enum('200' = 1, 'active' = 2)"],
            'enum with numeric and other names' => [fn (Blueprint $t) => $t->enum('c', ['200' => 1, 'teapot' => 418]), "Enum('200' = 1, 'teapot' = 418)"],
            'enum whose names are all numbers is a list of its values, as in 3.0.0' => [
                fn (Blueprint $t) => $t->enum('c', ['200' => 1, '404' => 2]),
                "Enum('1', '2')",
            ],
            'enum of numbers' => [fn (Blueprint $t) => $t->enum('c', [200, 404]), "Enum('200', '404')"],
            'enum of numbers with gaps from array_unique()' => [
                fn (Blueprint $t) => $t->enum('c', array_unique([200, 404, 200, 500])),
                "Enum('200', '404', '500')",
            ],
            'enum of numbers with gaps from array_filter()' => [
                fn (Blueprint $t) => $t->enum('c', array_filter([0, 200, 404])),
                "Enum('200', '404')",
            ],
            'enum of names with integer keys' => [fn (Blueprint $t) => $t->enum('c', [3 => 'x', 7 => 'y']), "Enum('x', 'y')"],
            'rawColumn numbering names that are all numbers' => [
                fn (Blueprint $t) => $t->rawColumn('c', "Enum8('200' = 1, '404' = 2)"),
                "Enum8('200' = 1, '404' = 2)",
            ],
            'enum of backed enums' => [fn (Blueprint $t) => $t->enum('c', StringBackedEnumFixture::cases()), "Enum('active', 'paused')"],
            'json' => [fn (Blueprint $t) => $t->json('c'), 'String'],
            'jsonb' => [fn (Blueprint $t) => $t->jsonb('c'), 'String'],
            'date' => [fn (Blueprint $t) => $t->date('c'), 'Date'],
            'dateTime' => [fn (Blueprint $t) => $t->dateTime('c'), 'DateTime'],
            'dateTime with precision 0' => [fn (Blueprint $t) => $t->dateTime('c', 0), 'DateTime'],
            'dateTime with precision 6' => [fn (Blueprint $t) => $t->dateTime('c', 6), 'DateTime64(6)'],
            'dateTimeTz' => [fn (Blueprint $t) => $t->dateTimeTz('c', 3), 'DateTime64(3)'],
            'timestamp' => [fn (Blueprint $t) => $t->timestamp('c'), 'DateTime'],
            'timestamp with precision' => [fn (Blueprint $t) => $t->timestamp('c', 3), 'DateTime64(3)'],
            'timestampTz' => [fn (Blueprint $t) => $t->timestampTz('c'), 'DateTime'],
            'timestamp with a time zone' => [fn (Blueprint $t) => $t->timestamp('c')->timezone('UTC'), "DateTime('UTC')"],
            'timestamp with precision and a time zone' => [
                fn (Blueprint $t) => $t->timestamp('c', 3)->timezone("Europe/Istanbul"),
                "DateTime64(3, 'Europe/Istanbul')",
            ],
            'softDeletes' => [fn (Blueprint $t) => $t->softDeletes('c'), 'Nullable(DateTime)'],
            'year' => [fn (Blueprint $t) => $t->year('c'), 'UInt16'],
            'binary' => [fn (Blueprint $t) => $t->binary('c'), 'String'],
            'binary with a length' => [fn (Blueprint $t) => $t->binary('c', 16), 'String'],
            'fixed binary' => [fn (Blueprint $t) => $t->binary('c', 16, true), 'FixedString(16)'],
            'uuid' => [fn (Blueprint $t) => $t->uuid('c'), 'UUID'],
            'foreignUuid' => [fn (Blueprint $t) => $t->foreignUuid('c'), 'UUID'],
            'ipAddress' => [fn (Blueprint $t) => $t->ipAddress('c'), 'String'],
            'macAddress' => [fn (Blueprint $t) => $t->macAddress('c'), 'String'],
            'point' => [fn (Blueprint $t) => $t->geometry('c', 'point'), 'Point'],
            'ring' => [fn (Blueprint $t) => $t->geometry('c', 'RING'), 'Ring'],
            'linestring' => [fn (Blueprint $t) => $t->geometry('c', 'linestring', 4326), 'LineString'],
            'multilinestring' => [fn (Blueprint $t) => $t->geometry('c', 'multilinestring'), 'MultiLineString'],
            'polygon' => [fn (Blueprint $t) => $t->geography('c', 'polygon'), 'Polygon'],
            'multipolygon' => [fn (Blueprint $t) => $t->geography('c', 'multipolygon'), 'MultiPolygon'],
            'vector' => [fn (Blueprint $t) => $t->vector('c', 3), 'Array(Float32)'],
            'rawColumn' => [fn (Blueprint $t) => $t->rawColumn('c', 'Tuple(a UInt8, b String)'), 'Tuple(a UInt8, b String)'],
            'nullable' => [fn (Blueprint $t) => $t->uuid('c')->nullable(), 'Nullable(UUID)'],
            'nullable(false)' => [fn (Blueprint $t) => $t->uuid('c')->nullable(false), 'UUID'],
            'lowCardinality' => [fn (Blueprint $t) => $t->string('c')->lowCardinality(), 'LowCardinality(String)'],
            'lowCardinality and nullable' => [
                fn (Blueprint $t) => $t->string('c')->lowCardinality()->nullable(),
                'LowCardinality(Nullable(String))',
            ],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('columnTypes')]
    public function test_laravel_column_types(\Closure $define, string $type): void
    {
        $this->assertSame("`c` {$type}", $this->compileColumn($define));
    }

    /**
     * @return array<string, array{string, bool, string, string}>
     */
    public static function integerTypes(): array
    {
        return [
            'tinyInteger' => ['tinyInteger', false, 'Int16', 'Int8'],
            'unsigned tinyInteger' => ['tinyInteger', true, 'Int16', 'UInt8'],
            'smallInteger' => ['smallInteger', false, 'Int16', 'Int16'],
            'unsigned smallInteger' => ['smallInteger', true, 'Int32', 'UInt16'],
            'mediumInteger' => ['mediumInteger', false, 'Int32', 'Int32'],
            'unsigned mediumInteger' => ['mediumInteger', true, 'Int32', 'UInt32'],
            'integer' => ['integer', false, 'Int32', 'Int32'],
            'unsigned integer' => ['integer', true, 'Int32', 'UInt32'],
            'bigInteger' => ['bigInteger', false, 'Int64', 'Int64'],
            'unsigned bigInteger' => ['bigInteger', true, 'Int64', 'UInt64'],
        ];
    }

    #[DataProvider('integerTypes')]
    public function test_integer_types(string $method, bool $unsigned, string $legacyType, string $exactType): void
    {
        $define = fn (Blueprint $t) => $t->{$method}('c', false, $unsigned);

        $this->assertSame("`c` {$legacyType}", $this->compileColumn($define));
        $this->assertSame("`c` {$legacyType}", $this->compileColumn($define, ['exact_integer_types' => false]));
        $this->assertSame("`c` {$exactType}", $this->compileColumn($define, ['exact_integer_types' => true]));
        $this->assertSame("`c` {$exactType}", $this->compileColumn($define, ['exact_integer_types' => '1']));
    }

    /**
     * @return array<string, array{\Closure(Blueprint): mixed, string, string}>
     */
    public static function integerShortcuts(): array
    {
        return [
            'id' => [fn (Blueprint $t) => $t->id('c'), 'Int64', 'UInt64'],
            'increments' => [fn (Blueprint $t) => $t->increments('c'), 'Int32', 'UInt32'],
            'foreignId' => [fn (Blueprint $t) => $t->foreignId('c'), 'Int64', 'UInt64'],
            'unsignedTinyInteger' => [fn (Blueprint $t) => $t->unsignedTinyInteger('c'), 'Int16', 'UInt8'],
            'integer()->unsigned()' => [fn (Blueprint $t) => $t->integer('c')->unsigned(), 'Int32', 'UInt32'],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('integerShortcuts')]
    public function test_integer_shortcuts(\Closure $define, string $legacyType, string $exactType): void
    {
        $this->assertSame("`c` {$legacyType}", $this->compileColumn($define));
        $this->assertSame("`c` {$exactType}", $this->compileColumn($define, ['exact_integer_types' => true]));
    }

    /**
     * @return array<string, array{\Closure(Blueprint): mixed, string}>
     */
    public static function modifiers(): array
    {
        return [
            'default' => [fn (Blueprint $t) => $t->string('c')->default('x'), "`c` String DEFAULT 'x'"],
            'default(null)' => [fn (Blueprint $t) => $t->string('c')->default(null), '`c` String'],
            'storedAs' => [fn (Blueprint $t) => $t->string('c')->storedAs('domain(url)'), '`c` String MATERIALIZED domain(url)'],
            'storedAs an expression' => [
                fn (Blueprint $t) => $t->string('c')->storedAs(new Expression('domain(url)')),
                '`c` String MATERIALIZED domain(url)',
            ],
            'virtualAs' => [fn (Blueprint $t) => $t->string('c')->virtualAs('upper(url)'), '`c` String ALIAS upper(url)'],
            'ephemeral' => [fn (Blueprint $t) => $t->string('c')->ephemeral(), '`c` String EPHEMERAL'],
            'ephemeral with a default' => [fn (Blueprint $t) => $t->string('c')->ephemeral(''), "`c` String EPHEMERAL ''"],
            'ephemeral(false)' => [fn (Blueprint $t) => $t->string('c')->ephemeral(false), '`c` String'],
            'comment' => [fn (Blueprint $t) => $t->string('c')->comment("it's \\ \"quoted\""), "`c` String COMMENT 'it\\'s \\\\ \"quoted\"'"],
            'codec' => [fn (Blueprint $t) => $t->string('c')->codec('ZSTD(3)'), '`c` String CODEC(ZSTD(3))'],
            'ttl' => [
                fn (Blueprint $t) => $t->dateTime('c')->ttl('c + INTERVAL 1 DAY'),
                '`c` DateTime TTL c + INTERVAL 1 DAY',
            ],
            'every modifier, in the order ClickHouse reads them' => [
                fn (Blueprint $t) => $t->string('c')->ttl(new Expression('d + INTERVAL 1 DAY'))->codec('LZ4')->comment('all')
                    ->default('x')->nullable()->lowCardinality(),
                "`c` LowCardinality(Nullable(String)) DEFAULT 'x' COMMENT 'all' CODEC(LZ4) TTL d + INTERVAL 1 DAY",
            ],
            'autoIncrement, charset, collation, invisible and useCurrentOnUpdate add nothing' => [
                fn (Blueprint $t) => $t->string('c')->autoIncrement()->charset('utf8mb4')->collation('utf8mb4_bin')
                    ->invisible()->useCurrentOnUpdate()->from(10),
                '`c` String',
            ],
            'unsigned on a string adds nothing' => [fn (Blueprint $t) => $t->string('c')->unsigned(), '`c` String'],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('modifiers')]
    public function test_column_modifiers(\Closure $define, string $expected): void
    {
        $this->assertSame($expected, $this->compileColumn($define));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function defaultValues(): array
    {
        return [
            'string' => ['x', "'x'"],
            'quote and backslash' => ["it's \\ here", "'it\\'s \\\\ here'"],
            'trailing backslash' => ['end\\', "'end\\\\'"],
            'empty string' => ['', "''"],
            'true' => [true, '1'],
            'false' => [false, '0'],
            'int' => [-5, '-5'],
            'zero' => [0, '0'],
            'float' => [1.5, '1.5'],
            'float with every digit' => [1 / 3, '0.3333333333333333'],
            'whole float' => [2.0, '2.0'],
            'large float' => [1e100, '1.0E+100'],
            'NaN' => [NAN, 'nan'],
            'infinity' => [INF, 'inf'],
            'negative infinity' => [-INF, '-inf'],
            'string-backed enum' => [StringBackedEnumFixture::Active, "'active'"],
            'int-backed enum' => [IntBackedEnumFixture::High, '3'],
            'unit enum' => [UnitEnumFixture::Hearts, "'Hearts'"],
            'date' => [new DateTimeImmutable('2024-01-02 03:04:05.678', new DateTimeZone('Europe/Istanbul')), "'2024-01-02 03:04:05'"],
            'array' => [['a', "b'c"], "['a', 'b\\'c']"],
            'array with keys and a null' => [['x' => 1, 'y' => null], '[1, NULL]'],
            'empty array' => [[], '[]'],
            'nested array' => [[[1, 2], [3]], '[[1, 2], [3]]'],
            'expression' => [new Expression("concat('a', 'b')"), "concat('a', 'b')"],
            'Stringable' => [Str::of("it's"), "'it\\'s'"],
        ];
    }

    #[DataProvider('defaultValues')]
    public function test_default_values(mixed $value, string $expected): void
    {
        $this->assertSame("`c` String DEFAULT {$expected}", $this->compileColumn(fn (Blueprint $t) => $t->string('c')->default($value)));
    }

    /**
     * ClickHouse reads a bare number as a Float64 and truncates it to the
     * Decimal's scale (DEFAULT 19.99 stores 19.98), so a float of a column
     * whose type holds a Decimal is written as a string literal.
     *
     * @return array<string, array{\Closure(Blueprint): mixed, string}>
     */
    public static function decimalDefaults(): array
    {
        return [
            'decimal' => [fn (Blueprint $t) => $t->decimal('c', 10, 2)->default(19.99), "`c` Decimal(10, 2) DEFAULT '19.99'"],
            'nullable decimal' => [
                fn (Blueprint $t) => $t->decimal('c', 10, 2)->nullable()->default(0.29),
                "`c` Nullable(Decimal(10, 2)) DEFAULT '0.29'",
            ],
            'raw Decimal type' => [fn (Blueprint $t) => $t->rawColumn('c', 'Decimal(10, 2)')->default(1.15), "`c` Decimal(10, 2) DEFAULT '1.15'"],
            'raw Decimal256 type, large float' => [
                fn (Blueprint $t) => $t->rawColumn('c', 'Decimal256(4)')->default(1e20),
                "`c` Decimal256(4) DEFAULT '1.0E+20'",
            ],
            'raw decimal type in lower case' => [fn (Blueprint $t) => $t->rawColumn('c', 'decimal(10, 2)')->default(19.99), "`c` decimal(10, 2) DEFAULT '19.99'"],
            'raw NUMERIC alias' => [fn (Blueprint $t) => $t->rawColumn('c', 'NUMERIC(10, 2)')->default(19.99), "`c` NUMERIC(10, 2) DEFAULT '19.99'"],
            'raw DEC alias' => [fn (Blueprint $t) => $t->rawColumn('c', 'DEC(10, 2)')->default(19.99), "`c` DEC(10, 2) DEFAULT '19.99'"],
            'raw FIXED alias' => [fn (Blueprint $t) => $t->rawColumn('c', 'FIXED(10, 2)')->default(19.99), "`c` FIXED(10, 2) DEFAULT '19.99'"],
            'a FixedString is no decimal' => [fn (Blueprint $t) => $t->rawColumn('c', 'FixedString(8)')->ephemeral(1.5), '`c` FixedString(8) EPHEMERAL 1.5'],
            'array of decimals' => [
                fn (Blueprint $t) => $t->addColumn('array', 'c', ['innerType' => 'Decimal(10, 2)'])->default([19.99, 0.29]),
                "`c` Array(Decimal(10, 2)) DEFAULT ['19.99', '0.29']",
            ],
            'array of arrays of decimals' => [
                fn (Blueprint $t) => $t->addColumn('array', 'c', ['innerType' => 'Array(Decimal(10, 2))'])->default([[19.99], [0.29, 1.5]]),
                "`c` Array(Array(Decimal(10, 2))) DEFAULT [['19.99'], ['0.29', '1.5']]",
            ],
            'ephemeral decimal' => [fn (Blueprint $t) => $t->decimal('c', 10, 2)->ephemeral(19.99), "`c` Decimal(10, 2) EPHEMERAL '19.99'"],
            'decimal with an int' => [fn (Blueprint $t) => $t->decimal('c', 10, 2)->default(7), '`c` Decimal(10, 2) DEFAULT 7'],
            'decimal with a string' => [fn (Blueprint $t) => $t->decimal('c', 10, 2)->default('0.18'), "`c` Decimal(10, 2) DEFAULT '0.18'"],
            'decimal with NaN, which the server refuses' => [fn (Blueprint $t) => $t->decimal('c', 10, 2)->default(NAN), '`c` Decimal(10, 2) DEFAULT nan'],
            'decimal with an expression' => [
                fn (Blueprint $t) => $t->decimal('c', 10, 2)->default(new Expression('toDecimal32(1, 2)')),
                '`c` Decimal(10, 2) DEFAULT toDecimal32(1, 2)',
            ],
            'double keeps a bare number' => [fn (Blueprint $t) => $t->double('c')->default(0.1 + 0.2), '`c` Float64 DEFAULT 0.30000000000000004'],
            'float keeps a bare number' => [fn (Blueprint $t) => $t->float('c', 10)->default(0.5), '`c` Float32 DEFAULT 0.5'],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('decimalDefaults')]
    public function test_a_float_default_of_a_decimal_column_is_a_string_literal(\Closure $define, string $expected): void
    {
        $this->assertSame($expected, $this->compileColumn($define));
    }

    public function test_a_collection_is_not_a_default_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Illuminate\Support\Collection given');

        $this->compileColumn(fn (Blueprint $t) => $t->string('c')->default(collect([1])));
    }

    /**
     * @return array<string, array{\Closure(Blueprint): mixed, string}>
     */
    public static function currentTimeDefaults(): array
    {
        return [
            'dateTime' => [fn (Blueprint $t) => $t->dateTime('c')->useCurrent(), '`c` DateTime DEFAULT now()'],
            'dateTime with precision' => [fn (Blueprint $t) => $t->dateTime('c', 3)->useCurrent(), '`c` DateTime64(3) DEFAULT now64(3)'],
            'timestamp with a time zone' => [
                fn (Blueprint $t) => $t->timestamp('c', 6)->timezone('UTC')->useCurrent(),
                "`c` DateTime64(6, 'UTC') DEFAULT now64(6)",
            ],
            'timestampTz' => [fn (Blueprint $t) => $t->timestampTz('c')->useCurrent(), '`c` DateTime DEFAULT now()'],
            'dateTimeTz' => [fn (Blueprint $t) => $t->dateTimeTz('c')->useCurrent(), '`c` DateTime DEFAULT now()'],
            'date' => [fn (Blueprint $t) => $t->date('c')->useCurrent(), '`c` Date DEFAULT today()'],
            'year' => [fn (Blueprint $t) => $t->year('c')->useCurrent(), '`c` UInt16 DEFAULT toYear(now())'],
            'useCurrent wins over default' => [
                fn (Blueprint $t) => $t->timestamp('c')->default('2020-01-01 00:00:00')->useCurrent(),
                '`c` DateTime DEFAULT now()',
            ],
            'useCurrent on a string keeps its default' => [
                fn (Blueprint $t) => $t->string('c')->default('x')->useCurrent(),
                "`c` String DEFAULT 'x'",
            ],
            'nullable timestamp' => [
                fn (Blueprint $t) => $t->timestamp('c')->nullable()->useCurrent(),
                '`c` Nullable(DateTime) DEFAULT now()',
            ],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('currentTimeDefaults')]
    public function test_use_current(\Closure $define, string $expected): void
    {
        $this->assertSame($expected, $this->compileColumn($define));
    }

    public function test_the_column_position_is_for_alter_table(): void
    {
        $grammar = $this->grammar();
        $position = new ReflectionMethod($grammar, 'compileColumnPosition');

        $this->assertSame(' FIRST', $position->invoke($grammar, new ColumnDefinition(['name' => 'c', 'first' => true])));
        $this->assertSame(' AFTER `n.a`', $position->invoke($grammar, new ColumnDefinition(['name' => 'c', 'after' => 'n.a'])));
        $this->assertSame('', $position->invoke($grammar, new ColumnDefinition(['name' => 'c'])));
    }

    public function test_json_generated_columns_throw(): void
    {
        foreach (['virtualAsJson', 'storedAsJson'] as $method) {
            try {
                $this->compileColumn(fn (Blueprint $t) => $t->string('c')->{$method}('payload->name'));
                $this->fail("{$method}() should throw");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("ClickHouse does not support {$method}() (column [c])", $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function geometrySubtypesWithoutAType(): array
    {
        return [
            'no subtype' => [null],
            'multipoint' => ['multipoint'],
            'geometrycollection' => ['geometrycollection'],
        ];
    }

    #[DataProvider('geometrySubtypesWithoutAType')]
    public function test_a_geometry_subtype_without_a_clickhouse_type_throws(?string $subtype): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The geometry column [c] needs a subtype that ClickHouse has a type for');

        $this->compileColumn(fn (Blueprint $t) => $t->geometry('c', $subtype));
    }

    /**
     * Arrays with a string key, in which every key is a name that needs an
     * integer, and the part of the message naming the name and the value.
     *
     * @return array<string, array{array<int|string, mixed>, string}>
     */
    public static function enumNumbersThatAreNotIntegers(): array
    {
        return [
            'a word' => [['low' => 'x'], '[low], [x] given.'],
            'a float' => [['low' => 1.5], '[low], [1.5] given.'],
            'a whole float' => [['low' => 1.0], '[low], [1] given.'],
            'a negative whole float' => [['low' => -1.0], '[low], [-1] given.'],
            'a bool' => [['low' => true], '[low], [1] given.'],
            'a plus sign' => [['low' => '+1'], '[low], [+1] given.'],
            'a leading space' => [['low' => ' 1'], '[low], [ 1] given.'],
            'a trailing space' => [['low' => '1 '], '[low], [1 ] given.'],
            'a list entry next to a string key' => [['a', 'b' => 2], '[0], [a] given.'],
            'a numeric name before a string key' => [[200 => 'ok', 'active' => 1], '[200], [ok] given.'],
        ];
    }

    /**
     * @param array<int|string, mixed> $allowed
     */
    #[DataProvider('enumNumbersThatAreNotIntegers')]
    public function test_an_enum_number_must_be_an_integer(array $allowed, string $nameAndValue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The enum column [c] needs an integer for the value {$nameAndValue}"
            . ' An array with a string key maps each name to its number, an integer key being a name too.'
        );

        $this->compileColumn(fn (Blueprint $t) => $t->enum('c', $allowed));
    }

    /**
     * @return array<string, array{\Closure(Blueprint): mixed, string}>
     */
    public static function typesClickHouseDoesNotHave(): array
    {
        return [
            'set' => [
                fn (Blueprint $t) => $t->set('c', ['a']),
                "ClickHouse has no SET type (column [c]). Use an array of an enum, for example rawColumn('c', \"Array(Enum('a', 'b'))\").",
            ],
            'time' => [
                fn (Blueprint $t) => $t->time('c'),
                'ClickHouse has no time-of-day type for time() (column [c]). Store the seconds since midnight in an integer'
                . ' column, the time as a string, or use dateTime().',
            ],
            'timeTz' => [fn (Blueprint $t) => $t->timeTz('c', 3), 'ClickHouse has no time-of-day type for timeTz() (column [c]).'],
            'computed' => [
                fn (Blueprint $t) => $t->computed('c', 'a + 1'),
                'ClickHouse has no computed() column (column [c]). Give the column a type and use storedAs() for a'
                . ' MATERIALIZED column or virtualAs() for an ALIAS column.',
            ],
        ];
    }

    /**
     * @param \Closure(Blueprint): mixed $define
     */
    #[DataProvider('typesClickHouseDoesNotHave')]
    public function test_a_type_that_clickhouse_does_not_have_throws(\Closure $define, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->compileColumn($define);
    }

    public function test_a_temporary_table_without_an_engine_has_no_engine_clause(): void
    {
        $this->assertSame(
            ['CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32, `name` String)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id');
                $table->string('name');
            }, table: 'tmp_ids')
        );
    }

    public function test_a_temporary_table_takes_the_engine_of_the_blueprint_only(): void
    {
        $config = ['engine' => 'ReplacingMergeTree', 'cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']]];

        $this->assertSame(
            ['CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->temporary();
                $table->replicated();
                $table->integer('id');
            }, $config, 'tmp_ids')
        );
        $this->assertSame(
            ['CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32) ENGINE = Memory'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->temporary();
                $table->engine('Memory');
                $table->integer('id');
            }, $config, 'tmp_ids')
        );
        $this->assertSame(
            ['CREATE TEMPORARY TABLE IF NOT EXISTS `tmp_ids` (`id` Int32, `v` UInt64) ENGINE = ReplacingMergeTree(v) ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->temporary();
                $table->ifNotExists();
                $table->engine('ReplacingMergeTree(v)');
                $table->integer('id');
                $table->rawColumn('v', 'UInt64');
            }, $config, 'tmp_ids')
        );
        $this->assertSame([], $this->selects);
    }

    public function test_a_temporary_table_without_an_engine_writes_the_clauses_it_is_given(): void
    {
        $this->assertSame(
            ['CREATE TEMPORARY TABLE `tmp_ids` (`id` Int32) ORDER BY (`id`) COMMENT \'ids\''],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('id')->primary();
                $table->orderBy('id');
                $table->comment('ids');
            }, table: 'tmp_ids')
        );
    }

    public function test_a_temporary_drop_drops_the_temporary_table_only(): void
    {
        $config = ['cluster_name' => 'company_cluster'];
        $drop = fn (string $command, bool $sync = false): array => $this->compileTable(function (SchemaBlueprint $table) use ($command, $sync) {
            $table->temporary();
            $table->{$command}()->sync($sync);
        }, $config, 'tmp_ids');

        $this->assertSame(['DROP TEMPORARY TABLE `tmp_ids`'], $drop('drop'));
        $this->assertSame(['DROP TEMPORARY TABLE IF EXISTS `tmp_ids`'], $drop('dropIfExists'));
        $this->assertSame(['DROP TEMPORARY TABLE IF EXISTS `tmp_ids` SYNC'], $drop('dropIfExists', true));
    }

    public function test_compile_temporary_table_exists(): void
    {
        $this->assertSame('EXISTS TEMPORARY TABLE `tmp_ids`', $this->grammar()->compileTemporaryTableExists('tmp_ids'));
        $this->assertSame('EXISTS TEMPORARY TABLE `we\\`ird`', $this->grammar()->compileTemporaryTableExists('we`ird'));
    }

    public function test_every_statement_goes_on_cluster_with_a_cluster_name(): void
    {
        $config = ['cluster_name' => "it's"];
        $onCluster = " ON CLUSTER 'it\\'s'";

        $this->assertSame(
            ["CREATE TABLE `events`{$onCluster} (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`)"],
            $this->compileCreate(fn (SchemaBlueprint $table) => $table->integer('id'), $config)
        );
        $this->assertSame(
            ["CREATE TABLE IF NOT EXISTS `analytics`.`events`{$onCluster} (`id` Int32) ENGINE = Memory"],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->ifNotExists();
                $table->engine('Memory');
                $table->integer('id');
            }, $config, 'analytics.events')
        );
        $this->assertSame(["DROP TABLE `events`{$onCluster}"], $this->compileTable(fn (SchemaBlueprint $table) => $table->drop(), $config));
        $this->assertSame(
            ["DROP TABLE IF EXISTS `events`{$onCluster} SYNC"],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->dropIfExists()->sync(), $config)
        );
        $this->assertSame(
            ["RENAME TABLE `analytics`.`events` TO `analytics`.`events_old`{$onCluster}"],
            $this->compileTable(fn (SchemaBlueprint $table) => $table->rename('events_old'), $config, 'analytics.events')
        );
        $this->assertSame(
            [
                "ALTER TABLE `events`{$onCluster} ADD COLUMN `name` String, MODIFY ORDER BY (`id`, `name`)",
                "ALTER TABLE `events`{$onCluster} DROP COLUMN `old`",
                "ALTER TABLE `events`{$onCluster} RENAME COLUMN `a` TO `b`",
                "ALTER TABLE `events`{$onCluster} MODIFY COMMENT 'c'",
                "ALTER TABLE `events`{$onCluster} ADD INDEX `events_url_index` `url` TYPE minmax",
                "ALTER TABLE `events`{$onCluster} DROP INDEX IF EXISTS `i`",
                "ALTER TABLE `events`{$onCluster} MODIFY SAMPLE BY id",
                "ALTER TABLE `events`{$onCluster} MODIFY TTL d + INTERVAL 1 DAY",
                "ALTER TABLE `events`{$onCluster} MODIFY SETTING merge_with_ttl_timeout=60",
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->string('name');
                $table->orderBy('id', 'name');
                $table->dropColumn('old');
                $table->renameColumn('a', 'b');
                $table->comment('c');
                $table->index('url', null, 'minmax');
                $table->dropIndex('i');
                $table->sampleBy('id');
                $table->ttl('d + INTERVAL 1 DAY');
                $table->settings(['merge_with_ttl_timeout' => 60]);
            }, $config)
        );
    }

    public function test_change_goes_on_cluster_and_checks_every_host_for_null_values(): void
    {
        $rows = fn (string $sql): array => str_contains($sql, 'system.columns')
            ? [['type' => 'Nullable(String)', 'default_kind' => 'DEFAULT', 'comment' => 'c']]
            : [];

        $this->assertSame(
            [
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' MODIFY COLUMN `n` REMOVE DEFAULT",
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' MODIFY COLUMN `n` REMOVE COMMENT",
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' MODIFY COLUMN `n` String",
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' RENAME COLUMN `n` TO `m`",
            ],
            $this->compileTable(
                fn (SchemaBlueprint $table) => $table->string('n')->renameTo('m')->change(),
                ['cluster_name' => 'company_cluster'],
                rows: $rows
            )
        );
        $this->assertSame(
            [
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'events' AND name = 'n'",
                "SELECT 1 FROM clusterAllReplicas('company_cluster', 'analytics', 'events') WHERE `n` IS NULL LIMIT 1"
                . ' SETTINGS apply_deleted_mask = 0',
            ],
            $this->selects
        );
    }

    public function test_change_refuses_null_values_that_another_host_holds(): void
    {
        $rows = fn (string $sql): array => match (true) {
            str_contains($sql, 'system.columns') => [['type' => 'Nullable(String)', 'default_kind' => '', 'comment' => '']],
            str_contains($sql, 'clusterAllReplicas') => [['1' => 1]],
            default => [],
        };

        try {
            $this->compileTable(
                fn (SchemaBlueprint $table) => $table->string('n')->change(),
                ['cluster_name' => 'company_cluster'],
                'analytics.events',
                $rows
            );
            $this->fail('change() should refuse a column that holds NULL values on a host of the cluster.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith(
                'The column [n] of the table [analytics.events] holds NULL values, so its type cannot change from Nullable(String) to String',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            "SELECT 1 FROM clusterAllReplicas('company_cluster', 'analytics', 'events') WHERE `n` IS NULL LIMIT 1"
            . ' SETTINGS apply_deleted_mask = 0',
            $this->selects[1]
        );
    }

    /**
     * A table that the schema builder makes replicated gets SETTINGS replicated_deduplication_window=0,
     * replicated_deduplication_window_for_async_inserts=0, so that it keeps an insert identical to an earlier one,
     * synchronous or asynchronous, as its MergeTree engine does; an engine named as replicated, and one with a SETTINGS
     * clause of its own, get nothing.
     *
     * @return array<string, array{string|null, array<string, mixed>, bool|null, string}>
     */
    public static function enginesOnACluster(): array
    {
        $cluster = ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']]];
        $window = ' SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0';

        return [
            'the default engine' => [null, $cluster, null, 'ReplicatedMergeTree() ORDER BY (`id`)' . $window],
            'an engine with parameters' => ['ReplacingMergeTree(v)', $cluster, null, 'ReplicatedReplacingMergeTree(v) ORDER BY (`id`)' . $window],
            'an engine without parentheses' => ['SummingMergeTree', $cluster, null, 'ReplicatedSummingMergeTree ORDER BY (`id`)' . $window],
            'an engine with its own clauses' => ['MergeTree ORDER BY id', $cluster, null, 'ReplicatedMergeTree ORDER BY id' . $window],
            'an engine with its own SETTINGS clause' => [
                'MergeTree ORDER BY id SETTINGS index_granularity = 1024',
                $cluster,
                null,
                'ReplicatedMergeTree ORDER BY id SETTINGS index_granularity = 1024',
            ],
            'a replicated engine' => [
                "ReplicatedMergeTree('/clickhouse/tables/{database}/{table}', '{replica}')",
                $cluster,
                null,
                "ReplicatedMergeTree('/clickhouse/tables/{database}/{table}', '{replica}') ORDER BY (`id`)",
            ],
            'a shared engine' => ['SharedMergeTree', $cluster, null, 'SharedMergeTree ORDER BY (`id`)'],
            'an engine outside the family' => ['Memory', $cluster, null, 'Memory'],
            'replicated(false)' => [null, $cluster, false, 'MergeTree() ORDER BY (`id`)'],
            'a cluster_name without cluster nodes' => [null, ['cluster_name' => 'company_cluster'], null, 'MergeTree() ORDER BY (`id`)'],
            'replicated() without cluster nodes' => [null, ['cluster_name' => 'company_cluster'], true, 'ReplicatedMergeTree() ORDER BY (`id`)' . $window],
            'cluster nodes without a cluster_name' => [null, ['cluster' => $cluster['cluster']], null, 'MergeTree() ORDER BY (`id`)'],
            'replicated() without a cluster_name' => ['ReplacingMergeTree', [], true, 'ReplicatedReplacingMergeTree ORDER BY (`id`)' . $window],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('enginesOnACluster')]
    public function test_a_merge_tree_engine_becomes_replicated_on_a_cluster(?string $engine, array $config, ?bool $replicated, string $expected): void
    {
        $sql = $this->compileCreate(function (SchemaBlueprint $table) use ($engine, $replicated) {
            if ($engine !== null) {
                $table->engine($engine);
            }
            if ($replicated !== null) {
                $table->replicated($replicated);
            }
            $table->integer('id');
        }, $config);

        $onCluster = isset($config['cluster_name']) ? " ON CLUSTER 'company_cluster'" : '';
        $this->assertSame(["CREATE TABLE `events`{$onCluster} (`id` Int32) ENGINE = {$expected}"], $sql);
    }

    public function test_the_engine_option_becomes_replicated_on_a_cluster(): void
    {
        $config = ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a']]];

        $this->assertSame(
            [
                "CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32) ENGINE = ReplicatedReplacingMergeTree"
                . ' ORDER BY (`id`) SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0',
            ],
            $this->compileCreate(fn (SchemaBlueprint $table) => $table->integer('id'), ['engine' => 'ReplacingMergeTree'] + $config)
        );
        $this->assertSame(
            ["CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32) ENGINE = ReplicatedReplacingMergeTree ORDER BY (`id`)"],
            $this->compileCreate(fn (SchemaBlueprint $table) => $table->integer('id'), ['engine' => 'ReplicatedReplacingMergeTree'] + $config),
            'An engine option that names a replicated engine keeps the deduplication of the server.'
        );
    }

    public function test_a_plain_laravel_blueprint_goes_on_cluster_too(): void
    {
        $connection = $this->clickhouseConnection(['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a']]]);
        $blueprint = new Blueprint($connection, 'events');
        $blueprint->create();
        $blueprint->integer('id');

        $this->assertSame(
            [
                "CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32) ENGINE = ReplicatedMergeTree()"
                . ' ORDER BY (`id`) SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0',
            ],
            $blueprint->toSql()
        );
    }

    /**
     * settings() starts from replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0 on
     * a table that the schema builder makes replicated: a value for either setting replaces it, a null value leaves it
     * out, and other settings come after them.
     */
    public function test_settings_override_the_deduplication_windows_of_a_replicated_table(): void
    {
        $config = ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a']]];
        $create = fn (array $settings): array => $this->compileCreate(function (SchemaBlueprint $table) use ($settings) {
            $table->integer('id');
            $table->settings($settings);
        }, $config);
        $start = "CREATE TABLE `events` ON CLUSTER 'company_cluster' (`id` Int32) ENGINE = ReplicatedMergeTree() ORDER BY (`id`)";

        $this->assertSame(
            [
                "{$start} SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=0,"
                . ' index_granularity=1024',
            ],
            $create(['index_granularity' => 1024])
        );
        $this->assertSame(
            [
                "{$start} SETTINGS replicated_deduplication_window=100, replicated_deduplication_window_for_async_inserts=0,"
                . ' index_granularity=1024',
            ],
            $create(['index_granularity' => 1024, 'replicated_deduplication_window' => 100])
        );
        $this->assertSame(
            ["{$start} SETTINGS replicated_deduplication_window=0, replicated_deduplication_window_for_async_inserts=100"],
            $create(['replicated_deduplication_window_for_async_inserts' => 100])
        );
        $this->assertSame(
            ["{$start} SETTINGS replicated_deduplication_window_for_async_inserts=0"],
            $create(['replicated_deduplication_window' => null])
        );
        $this->assertSame(
            [$start],
            $create(['replicated_deduplication_window' => null, 'replicated_deduplication_window_for_async_inserts' => null])
        );
        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`) SETTINGS index_granularity=1024'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->integer('id');
                $table->settings(['index_granularity' => 1024]);
            }),
            'A table that is not replicated gets no deduplication setting.'
        );
    }

    public function test_without_a_cluster_name_nothing_goes_on_cluster(): void
    {
        $config = ['cluster' => [['host' => 'a'], ['host' => 'b']], 'cluster_name' => ' '];

        $this->assertSame(['DROP TABLE `events`'], $this->compileTable(fn (SchemaBlueprint $table) => $table->drop(), $config));
        $this->assertSame(
            ['RENAME TABLE `events` TO `events_old`', 'ALTER TABLE `events` ADD COLUMN `a` Int32'],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->rename('events_old');
                $table->integer('a');
            }, $config)
        );
    }

    /**
     * @return array<string, array{\Closure(SchemaBuilder): mixed, string, string}>
     */
    public static function onClusterStatementsOnATemporaryTable(): array
    {
        $change = 'Call $table->temporary() in the blueprint, or Schema::dropTemporary() or dropTemporaryIfExists(), to'
            . ' change or drop the temporary table without ON CLUSTER, or run the statement outside session() to change'
            . ' the table of that name on every host.';
        $rename = 'ClickHouse cannot rename a temporary table. Run the statement outside session() to rename the table'
            . ' of that name on every host.';

        return [
            'drop' => [fn (SchemaBuilder $schema) => $schema->drop('tmp'), 'DROP TABLE ... ON CLUSTER', $change],
            'dropIfExists' => [fn (SchemaBuilder $schema) => $schema->dropIfExistsSync('tmp'), 'DROP TABLE ... ON CLUSTER', $change],
            'rename' => [fn (SchemaBuilder $schema) => $schema->rename('tmp', 'tmp_old'), 'RENAME TABLE ... ON CLUSTER', $rename],
            'table' => [
                fn (SchemaBuilder $schema) => $schema->table('tmp', fn (SchemaBlueprint $table) => $table->integer('a')),
                'ALTER TABLE ... ON CLUSTER',
                $change,
            ],
        ];
    }

    /**
     * @param \Closure(SchemaBuilder): mixed $run
     */
    #[DataProvider('onClusterStatementsOnATemporaryTable')]
    public function test_an_on_cluster_statement_on_a_temporary_table_of_the_session_throws(\Closure $run, string $statement, string $alternative): void
    {
        $connection = $this->clickhouseConnection(
            ['cluster_name' => 'company_cluster'],
            inSession: true,
            rows: fn (string $sql): array => [['result' => $sql === 'EXISTS TEMPORARY TABLE `tmp`' ? 1 : 0]]
        );

        try {
            $run($connection->getSchemaBuilder());
            $this->fail('The statement should be refused.');
        } catch (ClickHouseQueryException $exception) {
            $this->assertSame(
                "Cannot send {$statement} for tmp: the session has a temporary table named tmp, and ClickHouse (24.8"
                . ' checked) runs an ON CLUSTER statement on every node outside the session, on the table tmp of the'
                . " database. {$alternative}",
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->statements);
        $this->assertSame(['EXISTS TEMPORARY TABLE `tmp`'], $this->selects);
    }

    /**
     * ClickHouse creates the table of the database, with or without ON CLUSTER, also when a temporary table of the
     * session has its name, so Schema::create() is sent ON CLUSTER without a look at the session.
     */
    public function test_a_create_in_a_session_goes_on_cluster_without_checking_the_session(): void
    {
        $connection = $this->clickhouseConnection(
            ['cluster_name' => 'company_cluster'],
            inSession: true,
            rows: fn (string $sql): array => [['result' => 1]]
        );

        $connection->getSchemaBuilder()->create('tmp', function (SchemaBlueprint $table) {
            $table->ifNotExists();
            $table->integer('a');
        });

        $this->assertSame(
            ["CREATE TABLE IF NOT EXISTS `tmp` ON CLUSTER 'company_cluster' (`a` Int32) ENGINE = MergeTree() ORDER BY (`a`)"],
            $this->statements
        );
        $this->assertSame([], $this->selects);
    }

    public function test_a_blueprint_built_by_hand_is_refused_while_it_compiles(): void
    {
        $connection = $this->clickhouseConnection(
            ['cluster_name' => 'company_cluster'],
            inSession: true,
            rows: fn (string $sql): array => [['result' => 1]]
        );
        $blueprint = new Blueprint($connection, 'tmp');
        $blueprint->drop();

        $this->expectException(ClickHouseQueryException::class);
        $this->expectExceptionMessage('Cannot send DROP TABLE ... ON CLUSTER for tmp: the session has a temporary table named tmp');

        $blueprint->build();
    }

    public function test_an_on_cluster_statement_checks_the_session_once_per_blueprint(): void
    {
        $connection = $this->clickhouseConnection(
            ['cluster_name' => 'company_cluster'],
            inSession: true,
            rows: fn (string $sql): array => [['result' => 0]]
        );

        $connection->getSchemaBuilder()->table('events', function (SchemaBlueprint $table) {
            $table->integer('a');
            $table->dropColumn('b');
            $table->rename('events_old');
        });

        $this->assertSame(['EXISTS TEMPORARY TABLE `events`'], $this->selects);
        $this->assertSame(
            [
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' ADD COLUMN `a` Int32",
                "ALTER TABLE `events` ON CLUSTER 'company_cluster' DROP COLUMN `b`",
                "RENAME TABLE `events` TO `events_old` ON CLUSTER 'company_cluster'",
            ],
            $this->statements
        );
    }

    public function test_the_session_is_not_checked_without_need(): void
    {
        $rows = fn (string $sql): array => [['result' => 1]];

        $this->clickhouseConnection(['cluster_name' => 'company_cluster'], rows: $rows)->getSchemaBuilder()->drop('tmp');
        $this->clickhouseConnection(['cluster_name' => 'company_cluster'], true, $rows)->getSchemaBuilder()->drop('analytics.tmp');
        $this->clickhouseConnection(['cluster_name' => 'company_cluster'], true, $rows)->getSchemaBuilder()->dropTemporaryIfExists('tmp');
        $this->clickhouseConnection([], true, $rows)->getSchemaBuilder()->drop('tmp');

        $this->assertSame([], $this->selects);
        $this->assertSame(
            [
                "DROP TABLE `tmp` ON CLUSTER 'company_cluster'",
                "DROP TABLE `analytics`.`tmp` ON CLUSTER 'company_cluster'",
                'DROP TEMPORARY TABLE IF EXISTS `tmp`',
                'DROP TABLE `tmp`',
            ],
            $this->statements
        );
    }

    public function test_change_reads_a_temporary_table_of_the_session(): void
    {
        $rows = fn (string $sql): array => match (true) {
            str_starts_with($sql, 'EXISTS TEMPORARY TABLE') => [['result' => 1]],
            str_contains($sql, 'system.columns') => [['type' => 'Nullable(String)', 'default_kind' => '', 'comment' => '']],
            default => [],
        };

        $this->clickhouseConnection(inSession: true, rows: $rows)->getSchemaBuilder()->table('tmp', function (SchemaBlueprint $table) {
            $table->string('n')->change();
            $table->dropIndex('i');
        });

        $this->assertSame(
            [
                'EXISTS TEMPORARY TABLE `tmp`',
                "SELECT type, default_kind, comment FROM system.columns WHERE database = '' AND table = 'tmp' AND name = 'n'",
                'SELECT 1 FROM `tmp` WHERE `n` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0',
                "SELECT engine FROM system.tables WHERE database = '' AND name = 'tmp'",
            ],
            $this->selects
        );
        $this->assertSame(['ALTER TABLE `tmp` MODIFY COLUMN `n` String', 'ALTER TABLE `tmp` DROP INDEX IF EXISTS `i`'], $this->statements);
    }

    public function test_change_reads_the_database_table_without_a_temporary_table_of_that_name(): void
    {
        $rows = fn (string $sql): array => match (true) {
            str_starts_with($sql, 'EXISTS TEMPORARY TABLE') => [['result' => 0]],
            str_contains($sql, 'system.columns') => [['type' => 'String', 'default_kind' => '', 'comment' => '']],
            default => [],
        };

        $this->clickhouseConnection(inSession: true, rows: $rows)->getSchemaBuilder()->table('events', function (SchemaBlueprint $table) {
            $table->string('n')->change();
        });

        $this->assertSame(
            [
                'EXISTS TEMPORARY TABLE `events`',
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'events' AND name = 'n'",
            ],
            $this->selects
        );
    }

    /**
     * withoutOnCluster() sends the statements of a blueprint to the active node only, as 3.0.0 did, for a table that
     * not every host has: no ON CLUSTER, no automatic replicated engine, and change() reads the table of the active
     * node instead of clusterAllReplicas().
     */
    public function test_without_on_cluster_sends_the_statements_to_the_active_node_only(): void
    {
        $config = ['cluster_name' => 'company_cluster', 'cluster' => [['host' => 'a'], ['host' => 'b']]];
        $rows = fn (string $sql): array => str_contains($sql, 'system.columns')
            ? [['type' => 'Nullable(String)', 'default_kind' => '', 'comment' => '']]
            : [];

        $this->assertSame(
            ['CREATE TABLE `events` (`id` Int32) ENGINE = MergeTree() ORDER BY (`id`)'],
            $this->compileCreate(function (SchemaBlueprint $table) {
                $table->withoutOnCluster();
                $table->integer('id');
            }, $config)
        );
        $this->assertSame(
            [
                'ALTER TABLE `events` ADD COLUMN `a` Int32',
                'ALTER TABLE `events` MODIFY COLUMN `n` String',
                'RENAME TABLE `events` TO `events_old`',
                'DROP TABLE `events` SYNC',
            ],
            $this->compileTable(function (SchemaBlueprint $table) {
                $table->withoutOnCluster();
                $table->integer('a');
                $table->string('n')->change();
                $table->rename('events_old');
                $table->drop()->sync();
            }, $config, rows: $rows)
        );
        $this->assertSame(
            [
                "SELECT type, default_kind, comment FROM system.columns WHERE database = 'analytics' AND table = 'events' AND name = 'n'",
                'SELECT 1 FROM `events` WHERE `n` IS NULL LIMIT 1 SETTINGS apply_deleted_mask = 0',
            ],
            $this->selects
        );
    }

    /**
     * Without ON CLUSTER there is no session guard to run, so withoutOnCluster() reads nothing inside a session.
     */
    public function test_without_on_cluster_does_not_check_the_session(): void
    {
        $this->clickhouseConnection(['cluster_name' => 'company_cluster'], true, fn (string $sql): array => [['result' => 1]])
            ->getSchemaBuilder()
            ->table('events', function (SchemaBlueprint $table) {
                $table->withoutOnCluster();
                $table->integer('a');
                $table->drop();
            });

        $this->assertSame(['ALTER TABLE `events` ADD COLUMN `a` Int32', 'DROP TABLE `events`'], $this->statements);
        $this->assertSame([], $this->selects);
    }

    /**
     * Schema::table() with temporary() changes the session's temporary table, without ON CLUSTER, once the session
     * is found to have it.
     */
    public function test_a_temporary_table_of_the_session_is_altered_without_on_cluster(): void
    {
        $this->clickhouseConnection(['cluster_name' => 'company_cluster'], true, fn (string $sql): array => [['result' => 1]])
            ->getSchemaBuilder()
            ->table('tmp', function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('a');
                $table->dropColumn('b');
            });

        $this->assertSame(['ALTER TABLE `tmp` ADD COLUMN `a` Int32', 'ALTER TABLE `tmp` DROP COLUMN `b`'], $this->statements);
        $this->assertSame(['EXISTS TEMPORARY TABLE `tmp`'], $this->selects);
    }

    /**
     * @return array<string, array{bool, string, string, list<string>}>
     */
    public static function temporaryTablesThatTheSessionLacks(): array
    {
        $leaveOut = ' Leave out $table->temporary() to change the table of the database.';

        return [
            'outside a session' => [
                false,
                'tmp',
                'Cannot send ALTER TABLE for the temporary table tmp outside a session: a temporary table only exists in'
                . ' the session that creates it, and ClickHouse would change the table tmp of the database instead, on'
                . " the active node only. Change the temporary table inside the connection's session() callback that"
                . ' creates it.' . $leaveOut,
                [],
            ],
            'in a session without the temporary table' => [
                true,
                'tmp',
                'Cannot send ALTER TABLE for the temporary table tmp: the session has no temporary table named tmp, so'
                . ' ClickHouse would change the table tmp of the database instead, on the active node only. Create the'
                . ' temporary table first, with $table->temporary() in Schema::create().' . $leaveOut,
                ['EXISTS TEMPORARY TABLE `tmp`'],
            ],
            'a name with a database' => [
                true,
                'analytics.tmp',
                'Cannot send ALTER TABLE for the temporary table analytics.tmp: the session has no temporary table named'
                . ' analytics.tmp, so ClickHouse would change the table analytics.tmp of the database instead, on the'
                . ' active node only. Create the temporary table first, with $table->temporary() in Schema::create().'
                . $leaveOut,
                [],
            ],
        ];
    }

    /**
     * A temporary() blueprint compiles without ON CLUSTER, so its ALTER would change the table of the database on the
     * active node only, and leave the other hosts as they were, when the session has no such temporary table.
     *
     * @param list<string> $selects
     */
    #[DataProvider('temporaryTablesThatTheSessionLacks')]
    public function test_a_temporary_table_that_the_session_lacks_is_refused(bool $inSession, string $table, string $message, array $selects): void
    {
        $schema = $this->clickhouseConnection(['cluster_name' => 'company_cluster'], $inSession, fn (string $sql): array => [['result' => 0]])
            ->getSchemaBuilder();

        try {
            $schema->table($table, function (SchemaBlueprint $table) {
                $table->temporary();
                $table->integer('a');
            });
            $this->fail('Schema::table() should refuse a temporary table that the session does not have.');
        } catch (ClickHouseQueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame([], $this->statements);
        $this->assertSame($selects, $this->selects);
    }

    /**
     * ClickHouse's RENAME TABLE never reaches a temporary table, so a temporary() blueprint refuses it, even when the
     * session has the temporary table, without a read.
     */
    public function test_a_temporary_table_cannot_be_renamed(): void
    {
        $schema = $this->clickhouseConnection(inSession: true, rows: fn (string $sql): array => [['result' => 1]])->getSchemaBuilder();

        try {
            $schema->table('tmp', function (SchemaBlueprint $table) {
                $table->temporary();
                $table->rename('tmp_old');
            });
            $this->fail('A temporary table cannot be renamed.');
        } catch (ClickHouseQueryException $exception) {
            $this->assertSame(
                'Cannot rename the temporary table tmp: ClickHouse (24.8 checked) cannot rename a temporary table, and'
                . ' RENAME TABLE would rename the table tmp of the database, on the active node only. Leave out'
                . ' $table->temporary() to change the table of the database.',
                $exception->getMessage()
            );
        }

        $this->assertSame([], $this->statements);
        $this->assertSame([], $this->selects);
    }

    /**
     * On a connection with a cluster_name, createDatabase(), dropDatabaseIfExists() and the DROPs of dropAllTables()
     * go ON CLUSTER once, so that every host of the cluster gets them, as the schema builder's CREATE ... ON CLUSTER
     * reaches every host, also one that the connection does not list.
     */
    public function test_database_statements_and_drop_all_tables_go_on_cluster_with_a_cluster_name(): void
    {
        $objects = fn (string $sql): array => str_contains($sql, 'FROM system.tables') ? [
            ['name' => 'events_dictionary', 'engine' => 'Dictionary', 'dependents' => []],
            ['name' => 'events', 'engine' => 'ReplicatedMergeTree', 'dependents' => ['events_dictionary']],
        ] : [];
        $schema = $this->clickhouseConnection(['cluster_name' => "it's", 'cluster' => [['host' => 'a']]], rows: $objects)
            ->getSchemaBuilder();

        $this->assertTrue($schema->createDatabase('we`ird'));
        $this->assertTrue($schema->dropDatabaseIfExists('archive'));
        $schema->dropAllTables();

        $this->assertSame(
            [
                "CREATE DATABASE IF NOT EXISTS `we\\`ird` ON CLUSTER 'it\\'s'",
                "DROP DATABASE IF EXISTS `archive` ON CLUSTER 'it\\'s' SYNC",
                "DROP DICTIONARY IF EXISTS `analytics`.`events_dictionary` ON CLUSTER 'it\\'s' SYNC",
                "DROP TABLE IF EXISTS `analytics`.`events` ON CLUSTER 'it\\'s' SYNC",
            ],
            $this->statements
        );
    }

    public function test_database_statements_and_drop_all_tables_go_to_every_node_without_a_cluster_name(): void
    {
        $objects = fn (string $sql): array => str_contains($sql, 'FROM system.tables')
            ? [['name' => 'events', 'engine' => 'MergeTree', 'dependents' => []]]
            : [];
        $schema = $this->clickhouseConnection(['cluster' => [['host' => 'a'], ['host' => 'b']]], rows: $objects)->getSchemaBuilder();

        $schema->createDatabase('archive');
        $schema->dropDatabaseIfExists('archive');
        $schema->dropAllTables();

        $this->assertSame(
            [
                '[every node] CREATE DATABASE IF NOT EXISTS `archive`',
                '[every node] DROP DATABASE IF EXISTS `archive` SYNC',
                '[every node] DROP TABLE IF EXISTS `analytics`.`events` SYNC',
            ],
            $this->statements
        );
    }

    /**
     * Compile the one column that $define adds, with the given connection options.
     *
     * @param \Closure(Blueprint): mixed $define
     * @param array<string, mixed> $config
     */
    private function compileColumn(\Closure $define, array $config = []): string
    {
        $grammar = $this->grammar($config);
        $blueprint = new Blueprint($this->connectionFor($grammar), 'events');
        $define($blueprint);
        $columns = $blueprint->getAddedColumns();
        $this->assertCount(1, $columns);

        return (new ReflectionMethod($grammar, 'getColumn'))->invoke($grammar, $blueprint, array_values($columns)[0]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function grammar(array $config = []): SchemaGrammar
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturnCallback(fn (?string $option = null): mixed => $config[$option] ?? null);
        $connection->method('getTablePrefix')->willReturn('');

        return new SchemaGrammar($connection);
    }

    private function connectionFor(SchemaGrammar $grammar): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getSchemaGrammar')->willReturn($grammar);
        $connection->method('getSchemaBuilder')->willReturnCallback(
            fn (): \Oralunal\LaravelClickHouse\SchemaBuilder => new \Oralunal\LaravelClickHouse\SchemaBuilder($connection)
        );

        return $connection;
    }

    /**
     * Compile the statements of Schema::create() for a SchemaBlueprint on a connection from clickhouseConnection().
     *
     * @param \Closure(SchemaBlueprint): mixed $callback
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function compileCreate(\Closure $callback, array $config = [], string $table = 'events'): array
    {
        $blueprint = new SchemaBlueprint($this->clickhouseConnection($config), $table);
        $blueprint->create();
        $callback($blueprint);

        return $blueprint->toSql();
    }

    /**
     * Compile the statements of Schema::table() for a SchemaBlueprint on a connection from clickhouseConnection().
     *
     * @param \Closure(SchemaBlueprint): mixed $callback
     * @param array<string, mixed> $config
     * @param (\Closure(string): list<array<string, mixed>>)|null $rows The rows that the client answers a read with
     * @return list<string>
     */
    private function compileTable(\Closure $callback, array $config = [], string $table = 'events', ?\Closure $rows = null): array
    {
        $blueprint = new SchemaBlueprint($this->clickhouseConnection($config, rows: $rows), $table);
        $callback($blueprint);

        return $blueprint->toSql();
    }

    /**
     * A connection of the package with the given options and the database `analytics`, without a server. Its active
     * node's client carries a session id when $inSession is true, records each read in $selects and answers it with
     * the rows that $rows gives for its SQL, or with none. The connection records each statement in $statements, a
     * statement of statementOnEveryNode() with '[every node] ' in front.
     *
     * @param array<string, mixed> $config
     * @param (\Closure(string): list<array<string, mixed>>)|null $rows
     */
    private function clickhouseConnection(array $config = [], bool $inSession = false, ?\Closure $rows = null): ClickHouseConnection
    {
        $client = $this->createMock(Client::class);
        $client->method('getSession')->willReturn($inSession ? 'p4b-session' : false);
        $client->method('select')->willReturnCallback(function (string $sql) use ($rows): Statement {
            $this->selects[] = $sql;

            return $this->statement($rows === null ? [] : $rows($sql));
        });

        $connection = new class (null, 'analytics', '', $config + ['name' => 'clickhouse']) extends ClickHouseConnection {
            public Client $fakeClient;

            /** @var \Closure(string): bool */
            public \Closure $recordStatement;

            public function getClient(): Client
            {
                return $this->fakeClient;
            }

            public function statement($query, $bindings = []): bool
            {
                return ($this->recordStatement)($query);
            }

            public function statementOnEveryNode(string $query): bool
            {
                return ($this->recordStatement)('[every node] ' . $query);
            }
        };
        $connection->fakeClient = $client;
        $connection->recordStatement = function (string $query): bool {
            $this->statements[] = $query;

            return true;
        };
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }

    /**
     * A statement whose rows() returns the given rows and whose request has no response, as ClientRequests::select()
     * reads it.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function statement(array $rows): Statement
    {
        $statement = $this->createMock(Statement::class);
        $statement->method('rows')->willReturn($rows);
        $statement->method('getRequest')->willReturn(new CurlerRequest());
        $statement->method('getFormat')->willReturn('JSON');

        return $statement;
    }
}
