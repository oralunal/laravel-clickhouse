<?php

declare(strict_types=1);

namespace Tests\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

/**
 * make:clickhouse-model and make:clickhouse-migration write their files into a temporary project, with the stubs of
 * the package or of the project.
 */
class MakeCommandsTest extends TestCase
{
    private string $project;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = new Filesystem();
        $this->project = sys_get_temp_dir() . '/phpch-make-' . bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($this->project . '/app/Models');
        $this->files->put($this->project . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
        $this->app->setBasePath($this->project);
    }

    protected function tearDown(): void
    {
        $this->files->deleteDirectory($this->project);

        parent::tearDown();
    }

    public function test_make_clickhouse_model_writes_a_model_of_the_package_with_a_uuid_key(): void
    {
        $this->artisan('make:clickhouse-model', ['name' => 'Event'])->assertSuccessful();

        $this->assertSame(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Concerns\HasUuids;
            use Oralunal\LaravelClickHouse\Eloquent\Model;

            class Event extends Model
            {
                use HasUuids;
            }

            PHP, $this->files->get($this->project . '/app/Models/Event.php'));
        $this->assertDirectoryDoesNotExist($this->project . '/database/migrations');
    }

    /**
     * --migration writes the migration with make:clickhouse-migration, and --factory calls Laravel's make:factory.
     */
    public function test_the_migration_and_the_factory_of_a_model(): void
    {
        $this->artisan('make:clickhouse-model', ['name' => 'Event', '--migration' => true, '--factory' => true])
            ->assertSuccessful();

        $this->assertSame(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Concerns\HasUuids;
            use Illuminate\Database\Eloquent\Factories\HasFactory;
            use Oralunal\LaravelClickHouse\Eloquent\Model;

            class Event extends Model
            {
                /** @use HasFactory<\Database\Factories\EventFactory> */
                use HasFactory;
                use HasUuids;
            }

            PHP, $this->files->get($this->project . '/app/Models/Event.php'));
        $this->assertSame(<<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;
            use Oralunal\LaravelClickHouse\SchemaBlueprint;

            return new class extends Migration
            {
                /**
                 * The ClickHouse connection of the migration.
                 *
                 * @var string|null
                 */
                protected $connection = 'clickhouse';

                /**
                 * Run the migrations.
                 */
                public function up(): void
                {
                    Schema::create('events', function (SchemaBlueprint $table) {
                        $table->uuid('id');
                        $table->timestamps();

                        $table->orderBy('id');
                    });
                }

                /**
                 * Reverse the migrations.
                 */
                public function down(): void
                {
                    Schema::dropIfExists('events');
                }
            };

            PHP, $this->files->get($this->migration('create_events_table')));
        $this->assertFileExists($this->project . '/database/factories/EventFactory.php');
    }

    /**
     * --database names the ClickHouse connection of the model and of its migration. The model writes its $connection
     * property only when the connection is not its default, clickhouse.
     */
    public function test_the_connection_of_the_database_option(): void
    {
        $this->artisan('make:clickhouse-model', ['name' => 'Visit', '--database' => 'clickhouse2', '-m' => true])
            ->assertSuccessful();

        $model = $this->files->get($this->project . '/app/Models/Visit.php');
        $this->assertStringContainsString(<<<'PHP'
                use HasUuids;

                /**
                 * The ClickHouse connection of the model.
                 *
                 * @var string|null
                 */
                protected $connection = 'clickhouse2';
            }
            PHP, $model);
        $this->assertStringContainsString(
            "protected \$connection = 'clickhouse2';",
            $this->files->get($this->migration('create_visits_table'))
        );
    }

    /**
     * Without --database, the default connection is used when it is a ClickHouse connection, else the connection
     * named clickhouse.
     */
    public function test_the_default_connection(): void
    {
        $this->app['config']->set('database.default', 'clickhouse2');
        $this->artisan('make:clickhouse-migration', ['name' => 'create_hits_table'])->assertSuccessful();

        $this->app['config']->set('database.default', 'sqlite');
        $this->artisan('make:clickhouse-migration', ['name' => 'create_clicks_table'])->assertSuccessful();

        $this->assertStringContainsString(
            "protected \$connection = 'clickhouse2';",
            $this->files->get($this->migration('create_hits_table'))
        );
        $this->assertStringContainsString(
            "protected \$connection = 'clickhouse';",
            $this->files->get($this->migration('create_clicks_table'))
        );
    }

    public function test_a_connection_that_is_not_a_clickhouse_connection_is_refused(): void
    {
        $this->artisan('make:clickhouse-model', ['name' => 'Event', '--database' => 'sqlite'])
            ->expectsOutputToContain('The connection [sqlite] is not a ClickHouse connection of config/database.php.')
            ->assertFailed();
        $this->artisan('make:clickhouse-migration', ['name' => 'create_events_table', '--database' => 'missing'])
            ->expectsOutputToContain('The connection [missing] is not a ClickHouse connection of config/database.php.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->project . '/app/Models/Event.php');
        $this->assertDirectoryDoesNotExist($this->project . '/database/migrations');
    }

    /**
     * A pivot model extends Laravel's Pivot, not the model of the package, so the pivot options do not exist.
     */
    public function test_the_pivot_options_do_not_exist(): void
    {
        $this->expectException(InvalidOptionException::class);

        $this->artisan('make:clickhouse-model', ['name' => 'EventTag', '--pivot' => true]);
    }

    /**
     * make:clickhouse-migration finds the table as make:migration does: by --create, by --table, or by the name. A
     * migration without a table gets the blank stub.
     */
    public function test_the_stubs_of_make_clickhouse_migration(): void
    {
        $this->artisan('make:clickhouse-migration', ['name' => 'create_events_table'])
            ->expectsOutputToContain('created successfully')
            ->assertSuccessful();
        $this->artisan('make:clickhouse-migration', ['name' => 'add_url_to_events_table', '--table' => 'events'])
            ->assertSuccessful();
        $this->artisan('make:clickhouse-migration', ['name' => 'backfill_events'])->assertSuccessful();

        $this->assertStringContainsString(
            "Schema::create('events', function (SchemaBlueprint \$table) {",
            $this->files->get($this->migration('create_events_table'))
        );
        $update = $this->files->get($this->migration('add_url_to_events_table'));
        $this->assertSame(2, substr_count($update, "Schema::table('events', function (SchemaBlueprint \$table) {"));
        $this->assertStringContainsString("protected \$connection = 'clickhouse';", $update);
        $blank = $this->files->get($this->migration('backfill_events'));
        $this->assertStringContainsString("protected \$connection = 'clickhouse';", $blank);
        $this->assertStringNotContainsString('Schema::', $blank);
    }

    /**
     * The stubs of the stubs directory of the project win over those of the package.
     */
    public function test_the_stubs_of_the_project_win(): void
    {
        $this->files->ensureDirectoryExists($this->project . '/stubs');
        $this->files->put($this->project . '/stubs/clickhouse.model.stub', "<?php\n\nnamespace {{ namespace }};\n\n// custom {{ class }}\n");
        $this->files->put(
            $this->project . '/stubs/clickhouse.migration.create.stub',
            "<?php\n\n// custom {{ table }} on {{ connection }}\n"
        );

        $this->artisan('make:clickhouse-model', ['name' => 'Event', '-m' => true])->assertSuccessful();

        $this->assertSame(
            "<?php\n\nnamespace App\\Models;\n\n// custom Event\n",
            $this->files->get($this->project . '/app/Models/Event.php')
        );
        $this->assertSame(
            "<?php\n\n// custom events on clickhouse\n",
            $this->files->get($this->migration('create_events_table'))
        );
    }

    /**
     * stub:publish copies the stubs of the package into the project, with the stubs of Laravel, and
     * vendor:publish --tag=clickhouse-stubs copies the directory of the stubs of the package to the stubs directory of
     * the application.
     */
    public function test_the_stubs_are_published(): void
    {
        $stubs = dirname(__DIR__, 2) . '/resources/stubs';
        $names = [
            'clickhouse.migration.create.stub', 'clickhouse.migration.stub', 'clickhouse.migration.update.stub',
            'clickhouse.model.stub',
        ];

        $this->artisan('stub:publish')->assertSuccessful();

        foreach ($names as $name) {
            $this->assertFileEquals($stubs . '/' . $name, $this->project . '/stubs/' . $name);
        }
        $this->assertFileExists($this->project . '/stubs/model.stub');
        $this->assertSame($names, array_map('basename', $this->files->files($stubs)));

        // The application of the test boots before the test sets its base path, so the target is read, not written.
        $paths = ServiceProvider::pathsToPublish(ClickhouseServiceProvider::class, 'clickhouse-stubs');
        $this->assertSame([realpath($stubs)], array_map('realpath', array_keys($paths)));
        $this->assertSame('stubs', basename(array_values($paths)[0]));
    }

    /**
     * The generated migration creates a table on ClickHouse, and the generated model inserts a row with a UUID key
     * and reads it.
     */
    public function test_the_generated_model_and_migration_work_on_clickhouse(): void
    {
        $this->artisan('make:clickhouse-model', ['name' => 'GeneratedVisit', '-m' => true])->assertSuccessful();
        require_once $this->project . '/app/Models/GeneratedVisit.php';
        $migration = require $this->migration('create_generated_visits_table');
        $model = 'App\\Models\\GeneratedVisit';

        $migration->up();
        try {
            $this->assertSame(
                ['id' => 'UUID', 'created_at' => 'Nullable(DateTime)', 'updated_at' => 'Nullable(DateTime)'],
                array_column(DB::connection('clickhouse')->select('DESCRIBE TABLE generated_visits'), 'type', 'name')
            );

            $visit = $model::create();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $visit->getKey());
            $this->assertSame($visit->getKey(), $model::query()->sole()->getKey());
        } finally {
            $migration->down();
        }
    }

    /**
     * @param string $name
     * @return string The path of the one migration with the given name
     */
    private function migration(string $name): string
    {
        $files = glob($this->project . '/database/migrations/*_' . $name . '.php') ?: [];
        $this->assertCount(1, $files, "One migration {$name}");

        return $files[0];
    }
}
