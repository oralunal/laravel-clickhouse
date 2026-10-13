<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Database\Migrations\MigrationCreator as LaravelMigrationCreator;

/**
 * Laravel's migration creator with the stubs of a ClickHouse migration, for make:clickhouse-migration.
 *
 * The stubs set the $connection property of the migration and give the schema callback a SchemaBlueprint. An
 * application can change them: stub:publish, or vendor:publish --tag=clickhouse-stubs, copies them to its stubs
 * directory as clickhouse.migration.stub, clickhouse.migration.create.stub and clickhouse.migration.update.stub, and
 * a stub there is used in place of the stub of the package. The placeholders are {{ table }} and {{ connection }}.
 */
class MigrationCreator extends LaravelMigrationCreator
{
    /**
     * The ClickHouse connection of the migration that create() writes.
     *
     * @var string
     */
    protected string $connection = Connection::DEFAULT_NAME;

    /**
     * Create a new migration at the given path, with the given ClickHouse connection.
     *
     * @param string $name
     * @param string $path
     * @param string|null $table
     * @param bool $create
     * @param string $connection
     * @return string
     * @throws \Exception
     */
    public function create($name, $path, $table = null, $create = false, string $connection = Connection::DEFAULT_NAME)
    {
        $this->connection = $connection;

        return parent::create($name, $path, $table, $create);
    }

    /**
     * Get the migration stub file: the stub of the application's stubs directory, else the stub of the package.
     *
     * @param string|null $table
     * @param bool $create
     * @return string
     */
    protected function getStub($table, $create)
    {
        $name = match (true) {
            $table === null => 'clickhouse.migration.stub',
            (bool) $create => 'clickhouse.migration.create.stub',
            default => 'clickhouse.migration.update.stub',
        };

        $customPath = $this->customStubPath . '/' . $name;

        return $this->files->get($this->files->exists($customPath) ? $customPath : $this->stubPath() . '/' . $name);
    }

    /**
     * Populate the place-holders in the migration stub: the table, as Laravel does, and the connection, escaped for a
     * string literal in single quotes.
     *
     * @param string $stub
     * @param string|null $table
     * @return string
     */
    protected function populateStub($stub, $table)
    {
        return str_replace(
            ['{{ connection }}', '{{connection}}'],
            addcslashes($this->connection, "'\\"),
            parent::populateStub($stub, $table)
        );
    }

    /**
     * Get the path to the stubs of the package.
     *
     * @return string
     */
    public function stubPath()
    {
        return dirname(__DIR__) . '/resources/stubs';
    }
}
