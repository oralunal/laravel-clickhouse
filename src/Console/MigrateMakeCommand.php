<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\Console;

use Illuminate\Database\Console\Migrations\MigrateMakeCommand as LaravelMigrateMakeCommand;
use Illuminate\Support\Composer;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\MigrationCreator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * make:clickhouse-migration: Laravel's make:migration for a migration of a ClickHouse connection.
 *
 * It takes the arguments and options of make:migration, and finds the table in the same way: --create, --table, or
 * a name such as create_events_table. The migration sets its $connection property to the ClickHouse connection of
 * --database (see ConnectionOption), and its schema callback gets a SchemaBlueprint, so that editors and PHPStan know
 * the ClickHouse methods. A new table gets a UUID key, which Laravel's HasUuids trait fills in, because ClickHouse
 * has no auto-increment, and the key as its sorting key. See MigrationCreator for the stubs.
 */
#[AsCommand(name: 'make:clickhouse-migration')]
class MigrateMakeCommand extends LaravelMigrateMakeCommand
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'make:clickhouse-migration {name : The name of the migration}
        {--database= : The ClickHouse connection of the migration}
        {--create= : The table to be created}
        {--table= : The table to migrate}
        {--path= : The location where the migration file should be created}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--fullpath : Output the full path of the migration (Deprecated)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new migration file for a ClickHouse connection';

    /**
     * The ClickHouse connection of the migration.
     *
     * @var string
     */
    protected string $connectionName = Connection::DEFAULT_NAME;

    /**
     * @param MigrationCreator $clickHouseCreator The creator of the ClickHouse migrations. Laravel's command keeps it
     *                                           as its creator too.
     * @param Composer $composer
     */
    public function __construct(protected MigrationCreator $clickHouseCreator, Composer $composer)
    {
        parent::__construct($clickHouseCreator, $composer);
    }

    /**
     * Execute the console command.
     *
     * @return int
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

        parent::handle();

        return self::SUCCESS;
    }

    /**
     * Write the migration file to disk, with the ClickHouse connection.
     *
     * @param string $name
     * @param string $table
     * @param bool $create
     * @return void
     */
    protected function writeMigration($name, $table, $create)
    {
        $file = $this->clickHouseCreator->create(
            $name, $this->getMigrationPath(), $table, $create, $this->connectionName
        );

        if (windows_os()) {
            $file = str_replace('/', '\\', $file);
        }

        $this->components->info(sprintf('Migration [%s] created successfully.', $file));
    }
}
