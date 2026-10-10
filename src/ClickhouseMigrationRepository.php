<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Schema\Blueprint;

/**
 * Laravel's migration repository, which keeps the migrations table, with the changes that a ClickHouse connection
 * needs. ClickhouseServiceProvider binds it in place of Laravel's DatabaseMigrationRepository. On any other
 * connection, every method is Laravel's.
 *
 * On a ClickHouse connection:
 * - the migrations table is read with Laravel's query builder (the package's QueryBuilder), also when the
 *   connection sets fix_default_query_builder, whose package Builder returns smi2's Statement from get(), which has
 *   no all(), so that migrate:rollback, with or without --step, --batch or --pretend, failed with 'Call to undefined
 *   method ClickHouseDB\Statement::all()'. The migrations of a batch come back as objects, as Laravel's other
 *   connections give them;
 * - createRepository() creates the columns of 3.0.0 (id Int32, migration String, batch Int32) with an engine that
 *   keeps every row, whatever the connection's exact_integer_types and engine options say (see createRepository());
 * - log() sends its INSERT synchronously, with async_insert = 0, so that the row is written when log() returns, and
 *   with an insert_deduplication_token of its own, so that a migrations table that deduplicates keeps a row that is
 *   identical to an earlier one (see log());
 * - delete() waits until the row is deleted, so that the next migration command no longer reads it (see delete());
 * - deleteRepository() drops the table with SYNC, so that a schema dump can create it again right after (see
 *   deleteRepository()).
 *
 * log() and delete() go to the connection's active node, where the rows are read, without ON CLUSTER: a replicated
 * migrations table, as the schema builder makes it on a connection with a cluster_name and cluster nodes, passes
 * each change on to its other replicas.
 */
class ClickhouseMigrationRepository extends DatabaseMigrationRepository
{
    /**
     * Create the repository that takes the place of Laravel's: with the same connection resolver, migrations table
     * and connection.
     *
     * @param DatabaseMigrationRepository $repository
     * @return self
     */
    public static function fromLaravelRepository(DatabaseMigrationRepository $repository): self
    {
        $clickhouseRepository = new self($repository->getConnectionResolver(), $repository->table);
        $clickhouseRepository->connection = $repository->connection;

        return $clickhouseRepository;
    }

    /**
     * Get the last $steps migrations that ran, the latest first.
     *
     * @param int $steps
     * @return object[]
     */
    public function getMigrations($steps)
    {
        $migrations = parent::getMigrations($steps);

        return $this->usesClickhouse() ? $this->asObjects($migrations) : $migrations;
    }

    /**
     * Get the migrations of a batch, the latest first.
     *
     * @param int $batch
     * @return object[]
     */
    public function getMigrationsByBatch($batch)
    {
        $migrations = parent::getMigrationsByBatch($batch);

        return $this->usesClickhouse() ? $this->asObjects($migrations) : $migrations;
    }

    /**
     * Get the migrations of the last batch, the latest first.
     *
     * @return object[]
     */
    public function getLast()
    {
        $migrations = parent::getLast();

        return $this->usesClickhouse() ? $this->asObjects($migrations) : $migrations;
    }

    /**
     * Log that a migration ran.
     *
     * On a ClickHouse connection, the row is sent with insert into `migrations` (`migration`, `batch`) settings
     * insert_deduplication_token = '<token>', async_insert = 0 values (...), with a token made of the migration, the
     * batch and random bytes (see newDeduplicationToken()).
     *
     * async_insert = 0 makes the insert synchronous, whatever the connection's settings or the user's profile say.
     * ClickHouse 26.x inserts asynchronously by default (async_insert = 1), and with wait_for_async_insert = 0 such an
     * insert returns before the row is written, so that the next read of the migrations table, by the migrator or the
     * next migration command, could miss the migration and run it again.
     *
     * The token keeps a row that is identical to an earlier one, such as the row that a migration logs again after it
     * was rolled back, in a table that drops such an inserted block: a MergeTree table with a
     * non_replicated_deduplication_window, or a replicated table without replicated_deduplication_window = 0, which
     * the schema builder sets on the replicated tables that it makes but which an engine option such as
     * ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}', '{replica}') lacks (24.8, 26.3 and 26.8
     * checked). A block with a token is compared by its token, which no other block has, so the row is kept. A table
     * that does not deduplicate ignores the token. On ClickHouse 26.3, whose deduplicate_insert = 'enable' also
     * deduplicates asynchronous inserts, a replicated table drops an identical asynchronous insert by
     * replicated_deduplication_window_for_async_inserts, even with replicated_deduplication_window = 0; the
     * synchronous insert of log() is not affected (26.3.46 checked).
     *
     * @param string $file
     * @param int $batch
     * @return void
     */
    public function log($file, $batch): void
    {
        $connection = $this->getConnection();
        if (! $connection instanceof Connection) {
            parent::log($file, $batch);

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $connection->insert(
            'insert into ' . $grammar->wrapTable($this->table) . ' (' . $grammar->columnize(['migration', 'batch']) . ')'
            . ' settings insert_deduplication_token = ?, async_insert = 0 values (?, ?)',
            [$this->newDeduplicationToken((string) $file, $batch), $file, $batch]
        );
    }

    /**
     * Remove a migration from the log.
     *
     * On a ClickHouse connection, the rows are deleted with alter table `migrations` delete where `migration` = ?
     * settings mutations_sync = 1, which returns once the active node, where the repository reads the rows, has
     * deleted them; a replicated table's other replicas delete them as well, without being waited for, so that a
     * replica that is down does not make the rollback fail after its down() ran. Without mutations_sync, ClickHouse
     * deletes the rows in the background after the statement has returned, so migrate:refresh, which migrates right
     * after it has rolled back, could still read a migration as run and skip it, although its down() had run (24.8
     * checked).
     *
     * The wait takes about 20 ms on ClickHouse 24.8 and 26.3. ClickHouse 26.8, on a server with little RAM whose
     * config does not set background_pool_size, lowers it from 16 to the RAM in GiB (3 with 3.8 GiB) and postpones a
     * mutation while the pool has too few idle threads, so the wait takes up to about 2 seconds. A delete that runs
     * past the connection's timeout_query, 2 seconds in the packaged configuration, makes the migration command fail
     * after down() ran, while the server still deletes the row (26.8.21 checked).
     *
     * @param object{id?: int, migration: string, batch?: int} $migration
     * @return void
     */
    public function delete($migration): void
    {
        $connection = $this->getConnection();
        if (! $connection instanceof Connection) {
            parent::delete($migration);

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $connection->delete(
            'alter table ' . $grammar->wrapTable($this->table) . ' delete where ' . $grammar->wrap('migration')
            . ' = ? settings mutations_sync = 1',
            [$migration->migration]
        );
    }

    /**
     * Create the migrations table.
     *
     * On a ClickHouse connection, the table gets the columns that 3.0.0 gave it, id Int32, migration String and batch
     * Int32, also with exact_integer_types, which would make Laravel's increments('id') a UInt32. Its engine is the
     * connection's engine option when that is MergeTree, ReplicatedMergeTree or SharedMergeTree with its arguments
     * alone (see keepsEveryRow()), and MergeTree() otherwise: an engine such as ReplacingMergeTree would merge the
     * rows, which all have the id 0, into one, and Memory would lose them when the server restarts. The schema
     * builder's other rules apply, so on a connection with a cluster_name the table is created ON CLUSTER, and with
     * cluster nodes as well, MergeTree() becomes ReplicatedMergeTree() with replicated_deduplication_window = 0.
     *
     * @return void
     */
    public function createRepository(): void
    {
        $connection = $this->getConnection();
        if (! $connection instanceof Connection) {
            parent::createRepository();

            return;
        }

        $engine = $connection->getConfig('engine');
        $engine = is_string($engine) && $this->keepsEveryRow($engine) ? trim($engine) : 'MergeTree()';

        $connection->getSchemaBuilder()->create($this->table, function (Blueprint $table) use ($engine): void {
            $table->engine($engine);
            $table->integer('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    /**
     * Drop the migrations table.
     *
     * On a ClickHouse connection, whose schema builder is the package's SchemaBuilder, the table is dropped with
     * DROP TABLE `migrations` SYNC, with ON CLUSTER '<cluster_name>' before SYNC on a connection with a cluster_name
     * (see SchemaBuilder::dropSync()). Laravel's migrate drops the table right before a schema dump creates it again.
     * Without SYNC, the Atomic database keeps the dropped table for database_atomic_delay_before_drop_table_sec (480
     * seconds by default), and a replicated table keeps its replica in ClickHouse Keeper as long. With a fixed replica
     * path, as an engine option such as ReplicatedMergeTree('/clickhouse/tables/{shard}/{database}/{table}',
     * '{replica}') gives the table and the dump writes it, the dump's CREATE TABLE would then fail with
     * REPLICA_ALREADY_EXISTS (24.8 checked).
     *
     * @return void
     */
    public function deleteRepository(): void
    {
        $schema = $this->getConnection()->getSchemaBuilder();
        if (! $schema instanceof SchemaBuilder) {
            parent::deleteRepository();

            return;
        }

        $schema->dropSync($this->table);
    }

    /**
     * Get a query builder for the migrations table: Laravel's query builder, as the package's QueryBuilder, on a
     * ClickHouse connection, whatever its fix_default_query_builder option says, and the connection's own query
     * builder on any other.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    protected function table()
    {
        $connection = $this->getConnection();
        if (! $connection instanceof Connection) {
            return parent::table();
        }

        return (new QueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor()))
            ->from($this->table)
            ->useWritePdo();
    }

    /**
     * Get a token for the insert_deduplication_token setting of log(): the migration, the batch and 32 random hex
     * digits, such as 2024_01_01_000000_create_users_table/3/9f86d081884c7d659a2feaa0c55ad015.
     *
     * @param string $migration
     * @param int|string $batch
     * @return string
     */
    protected function newDeduplicationToken(string $migration, int|string $batch): string
    {
        return $migration . '/' . $batch . '/' . bin2hex(random_bytes(16));
    }

    /**
     * Determine if an engine keeps every inserted row, so that createRepository() may give it to the migrations
     * table: MergeTree, ReplicatedMergeTree or SharedMergeTree, with or without arguments, and nothing after them.
     * The other engines of the MergeTree family merge the rows that have the same sorting key, and engines such as
     * Memory, Log or Null do not keep them or cannot delete them. An engine followed by a clause, such as
     * MergeTree() SETTINGS ..., is not taken either, since the schema builder writes the sorting key after it, where
     * ClickHouse refuses it (24.8 checked).
     *
     * @param string $engine
     * @return bool
     */
    protected function keepsEveryRow(string $engine): bool
    {
        return preg_match(
            '/\A\s*(?:Replicated|Shared)?MergeTree\s*(?:\((?:[^()\'"]|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")*\))?\s*\z/',
            $engine
        ) === 1;
    }

    /**
     * Determine if the repository's connection is a ClickHouse connection.
     *
     * @return bool
     */
    protected function usesClickhouse(): bool
    {
        return $this->getConnection() instanceof Connection;
    }

    /**
     * Turn the rows of the migrations table, which a ClickHouse connection gives as arrays, into objects, as Laravel's
     * repository returns them on other connections.
     *
     * @param array<int, array<string, mixed>|object> $rows
     * @return object[]
     */
    protected function asObjects(array $rows): array
    {
        return array_map(fn (array|object $row): object => (object) $row, $rows);
    }
}
