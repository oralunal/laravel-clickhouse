<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Console;

use Illuminate\Foundation\Console\ModelMakeCommand as LaravelModelMakeCommand;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * make:clickhouse-model: Laravel's make:model for a model of a ClickHouse table.
 *
 * The model extends Oralunal\LaravelClickHouse\Eloquent\Model and uses Laravel's HasUuids trait, which gives the key
 * before the insert, because ClickHouse has no auto-increment. It sets its $connection property when the ClickHouse
 * connection of --database (see ConnectionOption) is not the default of the model, clickhouse.
 *
 * The options are those of make:model, except --pivot and --morph-pivot: a pivot model extends Laravel's Pivot, not
 * the model of the package. --migration creates the migration of the table with make:clickhouse-migration, on the
 * same connection. --factory, --seed, --controller, --policy and the other options call Laravel's commands.
 *
 * The stub is clickhouse.model.stub of the application's stubs directory, which stub:publish and
 * vendor:publish --tag=clickhouse-stubs write, else the stub of the package. Its placeholders are those of Laravel's
 * model.stub, and {{ connection }}.
 */
#[AsCommand(name: 'make:clickhouse-model')]
class ModelMakeCommand extends LaravelModelMakeCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:clickhouse-model
                    {name : The name of the model}
                    {--database= : The ClickHouse connection of the model}
                    {--a|all : Generate a migration, seeder, factory, policy, resource controller, and form request classes for the model}
                    {--c|controller : Create a new controller for the model}
                    {--f|factory : Create a new factory for the model}
                    {--force : Create the class even if the model already exists}
                    {--m|migration : Create a new ClickHouse migration file for the model}
                    {--policy : Create a new policy for the model}
                    {--s|seed : Create a new seeder for the model}
                    {--r|resource : Indicates if the generated controller should be a resource controller}
                    {--api : Indicates if the generated controller should be an API resource controller}
                    {--R|requests : Create new form request classes and use them in the resource controller}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Eloquent model class for a ClickHouse table';

    /**
     * The ClickHouse connection of the model.
     *
     * @var string
     */
    protected string $connectionName = Connection::DEFAULT_NAME;

    /**
     * Execute the console command.
     *
     * @return bool|int|null
     */
    public function handle()
    {
        try {
            $this->connectionName = ConnectionOption::resolve(
                (array) $this->laravel['config']->get('database.connections', []),
                $this->laravel['config']->get('database.default'),
                $this->option('database')
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return parent::handle();
    }

    /**
     * Create the migration of the table of the model with make:clickhouse-migration, on the connection of the model.
     *
     * @return void
     */
    protected function createMigration()
    {
        $table = Str::snake(Str::pluralStudly(class_basename($this->argument('name'))));

        $this->call('make:clickhouse-migration', [
            'name' => "create_{$table}_table",
            '--create' => $table,
            '--database' => $this->connectionName,
        ]);
    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return $this->resolveStubPath('/stubs/clickhouse.model.stub');
    }

    /**
     * Resolve the fully-qualified path to the stub: the stub of the application's stubs directory, else the stub of
     * the package.
     *
     * @param string $stub
     * @return string
     */
    protected function resolveStubPath($stub)
    {
        return file_exists($customPath = $this->laravel->basePath(trim($stub, '/')))
            ? $customPath
            : dirname(__DIR__, 2) . '/resources' . $stub;
    }

    /**
     * Build the replacements of the stub: those of the factory, as Laravel's make:model builds them, without the
     * comment that Laravel writes when there is no factory, and the $connection property.
     *
     * @return array<string, string>
     */
    protected function buildFactoryReplacements()
    {
        $replacements = parent::buildFactoryReplacements();
        if (($replacements['{{ factory }}'] ?? null) === '//') {
            unset($replacements['{{ factory }}']);
            $replacements["    {{ factory }}\n"] = '';
            $replacements["    {{ factory }}\r\n"] = '';
        }

        $connection = var_export($this->connectionName, true);
        $replacements['{{ connection }}'] = $this->connectionName === Connection::DEFAULT_NAME ? '' : <<<EOT


                /**
                 * The ClickHouse connection of the model.
                 *
                 * @var string|null
                 */
                protected \$connection = {$connection};
            EOT;

        return $replacements;
    }
}
