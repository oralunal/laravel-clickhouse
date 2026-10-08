<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Events\SchemaDumped;
use Illuminate\Database\Events\SchemaLoaded;
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

        // migrate:fresh must also empty the secondary ClickHouse connections.
        $this->app->extend(
            FreshCommand::class,
            fn (FreshCommand $command, $app) => new Console\FreshCommand($app['migrator'])
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

        if ($this->app->runningInConsole()) {
            $this->commands([Console\InstallSkillsCommand::class]);
        }

        $db = $this->app->make('db');

        $db->extend('clickhouse', function ($config, $name) {
            $config['name'] = $name;

            return Connection::createWithClient($config);
        });

        BaseModel::setEventDispatcher($this->app['events']);

        $this->app['events']->listen(
            SchemaDumped::class,
            fn (SchemaDumped $event) => $this->app->make(SecondaryConnections::class)->dump($event)
        );
        $this->app['events']->listen(
            SchemaLoaded::class,
            fn (SchemaLoaded $event) => $this->app->make(SecondaryConnections::class)->load($event)
        );

        $this->app->terminating(static function () {
            BaseModel::flushAllBuffers(silent: true);
        });

        if (!$this->app->runningUnitTests()) {
            register_shutdown_function(static function () {
                BaseModel::flushAllBuffers(silent: true);
            });
        }
    }
}