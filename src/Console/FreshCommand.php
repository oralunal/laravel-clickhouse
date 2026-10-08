<?php

declare(strict_types=1);

namespace PhpClickHouseLaravel\Console;

use Illuminate\Database\Console\Migrations\FreshCommand as BaseFreshCommand;
use PhpClickHouseLaravel\SecondaryConnections;

/**
 * `migrate:fresh` that also empties the secondary ClickHouse connections.
 *
 * Laravel only wipes the connection that holds the migrations table. The
 * migrations and schema dumps of a secondary ClickHouse connection would then
 * try to recreate tables that still exist.
 */
class FreshCommand extends BaseFreshCommand
{
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Confirmed above; the parent must not ask a second time.
        $this->input->setOption('force', true);

        $connections = $this->laravel->make(SecondaryConnections::class);
        foreach ($connections->names($this->option('database')) as $name) {
            $this->components->task(
                "Dropping all tables on [{$name}]",
                function () use ($connections, $name): bool {
                    $connections->wipe($name);

                    return true;
                }
            );
        }

        return parent::handle();
    }
}
