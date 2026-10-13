<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Oralunal\LaravelClickHouse\PHPStan\ClickHouseMigrationParser;
use Oralunal\LaravelClickHouse\PHPStan\ConnectionConfig;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Parser\Parser;
use PHPUnit\Framework\TestCase;

/**
 * The migrations parser of the PHPStan extension changes the blueprint calls of ClickHouse tables, so that Larastan
 * reads their columns with the PHP types of the values that the package returns. RowTypesTest checks the types that
 * Larastan then gives the models.
 */
class ClickHouseMigrationParserTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $this->useConnections([
            'mysql' => ['driver' => 'mysql'],
            'clickhouse' => ['driver' => 'clickhouse'],
        ]);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);

        parent::tearDown();
    }

    /**
     * The column methods that Larastan reads with the types of MySQL get the types of their ClickHouse columns. The
     * callback uses the connection of the migration, so Laravel's Blueprint type is enough.
     */
    public function test_the_columns_of_a_clickhouse_migration_get_the_types_of_the_package(): void
    {
        $callbacks = $this->rewrite(<<<'PHP'
            protected $connection = 'clickhouse';

            public function up(): void
            {
                Schema::create('hits', function (Blueprint $table) {
                    $table->id();
                    $table->decimal('price', 10, 2)->nullable();
                    $table->unsignedBigInteger('big');
                    $table->bigInteger('signed_big');
                    $table->bigInteger('unsigned_big')->unsigned();
                    $table->integer('counter', unsigned: true);
                    $table->year('year');
                    $table->float('ratio');
                    $table->timestampTz('at');
                    $table->enum('status', ['new', 'done']);
                    $table->enum('level', ['low' => 1, 'high' => 10]);
                    $table->string('name')->lowCardinality();
                    $table->addColumn('bigInteger', 'added', ['unsigned' => true]);
                });
            }
            PHP);

        $this->assertSame([<<<'PHP'
            $table->addColumn('mixed', 'id');
            $table->addColumn('string', 'price')->nullable();
            $table->addColumn('mixed', 'big');
            $table->bigInteger('signed_big');
            $table->addColumn('mixed', 'unsigned_big')->unsigned();
            $table->addColumn('unsignedInteger', 'counter');
            $table->addColumn('unsignedInteger', 'year');
            $table->float('ratio');
            $table->addColumn('string', 'at');
            $table->enum('status', ['new', 'done']);
            $table->enum('level', ['low', 'high']);
            $table->string('name')->lowCardinality();
            $table->addColumn('mixed', 'added');
            PHP], $callbacks);
    }

    /**
     * The table clauses add no column, and the ClickHouse column methods and rawColumn() get the types that a schema
     * dump gives their columns. A SchemaBlueprint parameter makes the callback a ClickHouse callback on any
     * connection.
     */
    public function test_the_clickhouse_methods_of_a_schema_blueprint(): void
    {
        $callbacks = $this->rewrite(<<<'PHP'
            public function up(): void
            {
                Schema::create('metrics', function (SchemaBlueprint $table) {
                    $table->engine('ReplacingMergeTree');
                    $table->orderBy('id', 'name');
                    $table->partitionBy('toYYYYMM(day)');
                    $table->sampleBy('id');
                    $table->ttl('day + INTERVAL 1 YEAR');
                    $table->settings(['index_granularity' => 8192]);
                    $table->comment('Metrics');
                    $table->replicated(false);
                    $table->ifNotExists();
                    $table->withoutOnCluster();
                    $table->ipv4('ip');
                    $table->date32('day');
                    $table->array('tags', 'String');
                    $table->rawColumn('total', 'UInt64');
                    $table->rawColumn('score', 'Nullable(Float32)');
                    $table->rawColumn('kind', "LowCardinality(Enum8('a' = 1, 'b' = 2))");
                    $table->rawColumn('small', 'UInt16');
                    $table->index('name', null, 'bloom_filter')->granularity(4);
                });
            }
            PHP);

        $this->assertSame([<<<'PHP'
            $table->addColumn('string', 'ip');
            $table->addColumn('string', 'day');
            $table->array('tags', 'String');
            $table->addColumn('mixed', 'total');
            $table->addColumn('float', 'score')->nullable();
            $table->enum('kind', ['a', 'b']);
            $table->addColumn('unsignedInteger', 'small');
            $table->index('name', \null, 'bloom_filter')->granularity(4);
            PHP], $callbacks);
    }

    /**
     * The morph columns and foreignIdFor() get the types of their ClickHouse columns: a numeric id is UInt64.
     */
    public function test_the_morph_and_foreign_id_columns(): void
    {
        $callbacks = $this->rewrite(<<<'PHP'
            public function up(): void
            {
                Schema::connection('clickhouse')->create('likes', function (Blueprint $table) {
                    $table->morphs('likeable');
                    $table->nullableUuidMorphs('owner');
                    $table->foreignIdFor(\Tests\Unit\PHPStan\IntegerKeyModel::class);
                    $table->foreignIdFor(\Tests\Unit\PHPStan\UuidKeyModel::class, 'uuid_model');
                    $table->foreignIdFor('App\Models\Missing');
                });
            }
            PHP);

        $this->assertSame([<<<'PHP'
            $table->addColumn('string', 'likeable_type');
            $table->addColumn('mixed', 'likeable_id');
            $table->addColumn('string', 'owner_type')->nullable();
            $table->addColumn('string', 'owner_id')->nullable();
            $table->addColumn('mixed', 'integer_key_model_id');
            $table->addColumn('string', 'uuid_model');
            $table->addColumn('mixed', 'missing_id');
            PHP], $callbacks);
    }

    /**
     * In Schema::table(), an EPHEMERAL column is dropped, a MATERIALIZED or ALIAS column is nullable, and
     * change()->renameTo() renames the column. Schema::dropSync() becomes drop(), which Larastan reads.
     */
    public function test_the_changes_of_a_clickhouse_table(): void
    {
        $callbacks = $this->rewrite(<<<'PHP'
            protected $connection = 'clickhouse';

            public function up(): void
            {
                Schema::table('hits', function (Blueprint $table) {
                    $table->string('raw')->ephemeral();
                    $table->string('kept')->ephemeral(false);
                    $table->unsignedInteger('doubled')->storedAs('counter * 2');
                    $table->decimal('total', 10, 2)->virtualAs('price * 2');
                    $table->string('title')->nullable()->change()->renameTo('heading');
                    $table->dropColumn('old');
                    $table->after('name', function (Blueprint $table) {
                        $table->decimal('after_price');
                    });
                    if (true) {
                        $table->decimal('in_if');
                    }
                });
                Schema::dropSync('old_hits');
                Schema::connection('clickhouse')->dropIfExistsSync('older_hits');
            }
            PHP, $statements);

        $this->assertSame([
            <<<'PHP'
            $table->dropColumn('raw');
            $table->string('kept')->ephemeral(\false);
            $table->unsignedInteger('doubled')->storedAs('counter * 2')->nullable();
            $table->addColumn('string', 'total')->virtualAs('price * 2')->nullable();
            $table->string('title')->nullable()->change()->renameTo('heading');
            $table->renameColumn('title', 'heading');
            $table->dropColumn('old');
            $table->after('name', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->addColumn('string', 'after_price');
            });
            if (\true) {
                $table->addColumn('string', 'in_if');
            }
            PHP,
            "\$table->addColumn('string', 'after_price');",
        ], $callbacks);
        $this->assertStringContainsString("Schema::drop('old_hits');", $statements);
        $this->assertStringContainsString("Schema::connection('clickhouse')->dropIfExists('older_hits');", $statements);
    }

    /**
     * A float() or double() column is nullable when a ClickHouse connection of the config is read-only: such a
     * connection gets NaN and the infinities as null, and Larastan finds the columns of a model by its table only.
     */
    public function test_a_float_is_nullable_when_a_clickhouse_connection_is_read_only(): void
    {
        $this->useConnections([
            'clickhouse' => ['driver' => 'clickhouse'],
            'reader' => ['driver' => 'clickhouse', 'readonly' => true],
        ]);

        $callbacks = $this->rewrite(<<<'PHP'
            public function up(): void
            {
                Schema::connection('clickhouse')->create('hits', function (Blueprint $table) {
                    $table->float('ratio');
                    $table->double('share')->nullable();
                    $table->rawColumn('score', 'Float64');
                });
            }
            PHP);

        $this->assertSame([<<<'PHP'
            $table->addColumn('float', 'ratio')->nullable();
            $table->addColumn('float', 'share')->nullable()->nullable();
            $table->addColumn('float', 'score')->nullable();
            PHP], $callbacks);
    }

    /**
     * The callbacks of other connections, and of a connection that is not a constant, stay as they are, unless their
     * parameter has the type SchemaBlueprint. So does the down() method, which Larastan does not read.
     */
    public function test_the_callbacks_of_other_connections_stay_as_they_are(): void
    {
        $callbacks = $this->rewrite(<<<'PHP'
            public function up(): void
            {
                Schema::create('users', function (Blueprint $table) {
                    $table->decimal('balance');
                });
                Schema::connection('mysql')->create('orders', function (Blueprint $table) {
                    $table->engine('InnoDB');
                });
                Schema::connection($this->name())->create('unknown', function (Blueprint $table) {
                    $table->decimal('amount');
                });
            }

            private function name(): string
            {
                return 'clickhouse';
            }
            PHP);

        $this->assertSame([
            "\$table->decimal('balance');",
            "\$table->engine('InnoDB');",
            "\$table->decimal('amount');",
        ], $callbacks);
    }

    /**
     * A migration that gives its connection with getConnection() is not read as ClickHouse by its default
     * connection, because the connection is not known.
     */
    public function test_a_connection_from_get_connection_is_not_known(): void
    {
        $this->useConnections(['clickhouse' => ['driver' => 'clickhouse']], 'clickhouse');

        $callbacks = $this->rewrite(<<<'PHP'
            public function getConnection(): ?string
            {
                return 'mysql';
            }

            public function up(): void
            {
                Schema::create('orders', function (Blueprint $table) {
                    $table->decimal('total');
                });
            }
            PHP);

        $this->assertSame(["\$table->decimal('total');"], $callbacks);
    }

    /**
     * @param array<string, array<string, mixed>> $connections
     * @param string $default
     * @return void
     */
    private function useConnections(array $connections, string $default = 'mysql'): void
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'database' => ['default' => $default, 'connections' => $connections],
        ]));
        Container::setInstance($container);
    }

    /**
     * Parse a migration class with the given body, and get the statements of each callback, printed as PHP with the
     * resolved names.
     *
     * @param string $body
     * @param string|null $printed Gets all the statements of the file, printed as PHP
     * @return list<string>
     */
    private function rewrite(string $body, ?string &$printed = null): array
    {
        $source = "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
            . "use Illuminate\\Support\\Facades\\Schema;\nuse Oralunal\\LaravelClickHouse\\SchemaBlueprint;\n\n"
            . "return new class extends Migration\n{\n" . $body . "\n};\n";
        $statements = (new ClickHouseMigrationParser(new NameResolvingParser(), new ConnectionConfig()))
            ->parseString($source);

        $printer = new Standard();
        $printed = $printer->prettyPrint($statements);

        return array_map(
            static fn (Closure $callback): string => $printer->prettyPrint($callback->stmts),
            (new NodeFinder())->findInstanceOf($statements, Closure::class)
        );
    }
}

/**
 * A parser of PHP that resolves the names, as the parser of PHPStan that Larastan uses for the migrations.
 */
final class NameResolvingParser implements Parser
{
    /**
     * @param string $file
     * @return Stmt[]
     */
    public function parseFile(string $file): array
    {
        return $this->parseString((string) file_get_contents($file));
    }

    /**
     * @param string $sourceCode
     * @return Stmt[]
     */
    public function parseString(string $sourceCode): array
    {
        $traverser = new NodeTraverser(new NameResolver());

        /** @var Stmt[] */
        return $traverser->traverse((new ParserFactory())->createForNewestSupportedVersion()->parse($sourceCode) ?? []);
    }
}

/**
 * A model with an integer key.
 */
final class IntegerKeyModel extends \Illuminate\Database\Eloquent\Model
{
}

/**
 * A model with a UUID key.
 */
final class UuidKeyModel extends \Illuminate\Database\Eloquent\Model
{
    protected $keyType = 'string';
}
