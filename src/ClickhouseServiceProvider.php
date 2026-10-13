<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Foundation\Events\PublishingStubs;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\ParallelTesting;

/**
 * Service provider to connect Clickhouse driver in Laravel.
 */
class ClickhouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app['config'];

        // A published config/clickhouse.php lands in config('clickhouse'). Its
        // entries win over the packaged defaults and may add new connections.
        // Reading the packaged file directly (rather than mergeConfigFrom) keeps
        // the defaults intact when the app has cached its config without ever
        // publishing ours.
        $packaged = require __DIR__ . '/../config/clickhouse.php';
        $published = $config->get('clickhouse');

        $connections = $packaged;
        foreach (is_array($published) ? $published : [] as $name => $entry) {
            // Key-level merge, so a published entry only has to name what it
            // changes — same granularity as the config/database.php layer below.
            $connections[$name] = is_array($entry) && is_array($packaged[$name] ?? null)
                ? array_merge($packaged[$name], $entry)
                : $entry;
        }
        $config->set('clickhouse', $connections);

        foreach ($connections as $name => $connectionDefaults) {
            if (!is_array($connectionDefaults)) {
                continue;
            }
            $existing = $config->get("database.connections.{$name}", []);
            // config/database.php still wins over everything; shallow merge
            // mirrors mergeConfigFrom semantics.
            $config->set(
                "database.connections.{$name}",
                array_merge($connectionDefaults, $existing)
            );
        }

        // ClickhouseMigrationRepository creates and changes the migrations table as ClickHouse needs it on a
        // ClickHouse connection, and is Laravel's repository on any other. A repository that the app or another
        // package bound in its place is kept.
        $this->app->extend(
            'migration.repository',
            function (MigrationRepositoryInterface $repository): MigrationRepositoryInterface {
                if ($repository instanceof DatabaseMigrationRepository
                    && $repository::class === DatabaseMigrationRepository::class
                ) {
                    return ClickhouseMigrationRepository::fromLaravelRepository($repository);
                }

                return $repository;
            }
        );

        // migrate:fresh must also empty the secondary ClickHouse connections.
        $this->app->extend(
            FreshCommand::class,
            fn (FreshCommand $command, $app) => new Console\FreshCommand($app['migrator'])
        );

        // make:clickhouse-migration writes its migrations with the ClickHouse stubs.
        $this->app->singleton(
            Console\MigrateMakeCommand::class,
            fn ($app) => new Console\MigrateMakeCommand(
                new MigrationCreator($app['files'], $app->basePath('stubs')),
                $app['composer']
            )
        );

        // Parallel test processes each get their own database on those connections.
        $this->app->afterResolving(
            ParallelTesting::class,
            fn (ParallelTesting $parallelTesting, $app) => (new Testing\ParallelTestDatabases($app))->register($parallelTesting)
        );
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/clickhouse.php' => function_exists('config_path')
                ? config_path('clickhouse.php')
                : base_path('config/clickhouse.php'),
        ], 'clickhouse-config');

        // The stubs of make:clickhouse-model and make:clickhouse-migration, which an application can change.
        $this->publishes([__DIR__ . '/../resources/stubs' => base_path('stubs')], 'clickhouse-stubs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallSkillsCommand::class,
                Console\ModelMakeCommand::class,
                Console\MigrateMakeCommand::class,
            ]);
        }

        $db = $this->app->make('db');

        $db->extend('clickhouse', function ($config, $name) {
            $config['name'] = $name;

            return Connection::createWithClient($config);
        });

        $this->app['events']->listen(
            SchemaDumped::class,
            fn (SchemaDumped $event) => $this->app->make(SecondaryConnections::class)->dump($event)
        );
        $this->app['events']->listen(
            PublishingStubs::class,
            function (PublishingStubs $event): void {
                foreach (glob(__DIR__ . '/../resources/stubs/*.stub') ?: [] as $stub) {
                    $event->add((string) realpath($stub), basename($stub));
                }
            }
        );
        $this->app['events']->listen(
            SchemaLoaded::class,
            fn (SchemaLoaded $event) => $this->app->make(SecondaryConnections::class)->load($event)
        );

        $this->app->terminating(static function () {
            Eloquent\Model::flushAllBuffers(silent: true);
        });

        if (!$this->app->runningUnitTests()) {
            register_shutdown_function(static function () {
                Eloquent\Model::flushAllBuffers(silent: true);
            });
        }
    }
}