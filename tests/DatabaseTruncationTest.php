<?php

namespace Tests;

use ClickHouseDB\Client;
use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Database\Query\Builder as LaravelBuilder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Laravel's DatabaseTruncation trait on ClickHouse connections.
 *
 * The first test of this class runs migrate:fresh, which rebuilds the default
 * database from the migrations in Tests\TestCase::migratorPaths(). Every later
 * test starts with the tables truncated. The tests can run in any order, so
 * each one checks that the rows the test before it wrote are gone.
 */
class DatabaseTruncationTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @var string[]
     */
    protected array $connectionsToTruncate = ['clickhouse'];

    /**
     * @var array<string, string[]>
     */
    protected array $exceptTables = [];

    /**
     * Tables of the default connection that the previous test of this class wrote rows to.
     *
     * @var string[]
     */
    private static array $tablesWithRows = [];

    /**
     * Whether the setUp of the current test truncated the tables, rather than running migrate:fresh.
     */
    private bool $truncatedBeforeTest = false;

    /**
     * Make the first test of this class run migrate:fresh, whichever test class ran before.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        RefreshDatabaseState::$migrated = false;
        self::$tablesWithRows = [];
    }

    /**
     * Leave no migrated state behind for the test classes that run after this one.
     */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        self::$tablesWithRows = [];

        parent::tearDownAfterClass();
    }

    /**
     * Record whether the tables get truncated or migrate:fresh runs, then empty
     * what the truncation does not reach, as the README recommends: the inner
     * table of a materialized view and the rows still waiting in a Buffer table.
     */
    protected function beforeTruncatingDatabase(): void
    {
        $this->truncatedBeforeTest = RefreshDatabaseState::$migrated;

        $clickhouse = DB::connection('clickhouse');

        if ($clickhouse->getSchemaBuilder()->hasView('truncation_inner_view')) {
            $clickhouse->table('truncation_inner_view')->truncate();
        }

        if ($clickhouse->getSchemaBuilder()->hasTable('truncation_buffer')) {
            $clickhouse->statement('OPTIMIZE TABLE truncation_buffer');
        }
    }

    protected function tearDown(): void
    {
        $this->dropObjects();
        $this->forgetTables();

        parent::tearDown();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function runs(): array
    {
        return ['first run' => [1], 'second run' => [2]];
    }

    #[DataProvider('runs')]
    public function testRowsWrittenByThePreviousTestAreGone(int $run): void
    {
        $this->assertTablesWithRowsWereTruncated();

        $this->assertSame(0, $this->rowCount('examples'));
        $this->client()->write("INSERT INTO examples (f_int, f_int2, f_string) VALUES ({$run}, 0, 'a')");
        $this->client()->write("INSERT INTO examples3 (f_int, f_string, f_bool) VALUES ({$run}, 'a', true)");
        self::$tablesWithRows = ['examples', 'examples3'];

        $this->assertSame(1, $this->rowCount('examples'));
        $this->assertSame(1, $this->rowCount('examples3'));
    }

    public function testTheMigrationsTableKeepsItsRows(): void
    {
        $this->assertTablesWithRowsWereTruncated();
        $migrations = $this->migrations();
        $this->assertContains('2022_01_01_000000_create_examples_table', $migrations);
        $this->client()->write("INSERT INTO examples (f_int, f_int2, f_string) VALUES (1, 0, 'a')");
        self::$tablesWithRows = ['examples'];

        $this->truncateTablesForAllConnections();

        $this->assertSame(0, $this->rowCount('examples'));
        $this->assertSame($migrations, $this->migrations());
    }

    public function testViewsMaterializedViewsAndDictionariesAreLeftAlone(): void
    {
        $this->assertTablesWithRowsWereTruncated();
        $client = $this->client();
        $client->write('CREATE TABLE truncation_events (id UInt64, name String) ENGINE = MergeTree ORDER BY id');
        $client->write('CREATE TABLE truncation_event_ids (id UInt64) ENGINE = MergeTree ORDER BY id');
        $client->write('CREATE VIEW truncation_view AS SELECT id, name FROM truncation_events');
        $client->write('CREATE MATERIALIZED VIEW truncation_inner_view ENGINE = MergeTree ORDER BY id'
            . ' AS SELECT id FROM truncation_events');
        $client->write('CREATE MATERIALIZED VIEW truncation_to_view TO truncation_event_ids'
            . ' AS SELECT id FROM truncation_events');
        $client->write('CREATE DICTIONARY truncation_dictionary (id UInt64, name String) PRIMARY KEY id'
            . " SOURCE(CLICKHOUSE(DB '{$this->database()}' TABLE 'truncation_events')) LIFETIME(0) LAYOUT(FLAT())");
        $client->write("INSERT INTO truncation_events VALUES (1, 'a'), (2, 'b')");
        $this->forgetTables();

        $this->truncateTablesForAllConnections();

        $this->assertSame(0, $this->rowCount('truncation_events'));
        $this->assertSame(0, $this->rowCount('truncation_event_ids'), 'the target of a materialized view is a table');
        $this->assertSame(0, $this->rowCount('truncation_view'));
        $this->assertSame(
            ['truncation_dictionary', 'truncation_inner_view', 'truncation_to_view', 'truncation_view'],
            $this->objectsOtherThanTables()
        );
        $this->assertSame(2, $this->rowCount('truncation_inner_view'), 'the inner table of a materialized view keeps its rows');

        $this->truncateDatabaseTables();

        $this->assertSame(0, $this->rowCount('truncation_inner_view'), 'beforeTruncatingDatabase() empties it');
    }

    public function testBufferTablesBelongInExceptTablesAndAreFlushedFirst(): void
    {
        $this->assertTablesWithRowsWereTruncated();
        $this->client()->write('CREATE TABLE truncation_buffer AS examples ENGINE = Buffer('
            . 'currentDatabase(), examples, 1, 100, 1000, 1000000, 10000000, 100000000, 1000000000)');
        $this->client()->write("INSERT INTO truncation_buffer (f_int, f_int2, f_string) VALUES (1, 0, 'a')");
        self::$tablesWithRows = ['examples'];
        $this->forgetTables();
        $connection = DB::connection('clickhouse');
        $events = $connection->getEventDispatcher();

        try {
            $this->truncateTablesForAllConnections();
            $this->fail('ClickHouse should refuse to truncate a Buffer table');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('Truncate is not supported by storage Buffer', $exception->getMessage());
        } finally {
            $connection->setEventDispatcher($events);
        }

        $this->exceptTables = ['clickhouse' => ['truncation_buffer']];
        $this->truncateTablesForAllConnections();

        $this->assertSame(1, $this->rowCount('truncation_buffer'), 'the row is still waiting in the buffer');

        $this->truncateDatabaseTables();

        $this->assertSame(0, $this->rowCount('truncation_buffer'), 'beforeTruncatingDatabase() flushed the buffer');
        $this->assertSame(0, $this->rowCount('examples'), 'the flushed row was truncated');
    }

    /**
     * The trait truncates $connection->table('<database>.truncation-hyphen'), which
     * only works because the query builder quotes each part of the name.
     */
    public function testTablesWhoseNamesNeedQuotingAreTruncated(): void
    {
        $this->assertTablesWithRowsWereTruncated();
        $this->client()->write('CREATE TABLE `truncation-hyphen` (id UInt64) ENGINE = MergeTree ORDER BY id');
        $this->client()->write('INSERT INTO `truncation-hyphen` VALUES (1), (2)');
        $this->forgetTables();

        $this->truncateTablesForAllConnections();

        $this->assertSame(0, $this->rowCount('`truncation-hyphen`'));
    }

    public function testConnectionsUsingLaravelsQueryBuilderAreTruncatedToo(): void
    {
        $this->assertTablesWithRowsWereTruncated();
        $connection = DB::connection('clickhouse2');
        $this->assertInstanceOf(LaravelBuilder::class, $connection->table('examples2'));
        $connection->getClient()->write("INSERT INTO examples2 (f_int, f_int2, f_string) VALUES (1, 0, 'a')");
        $connection->enableQueryLog();
        $this->connectionsToTruncate = ['clickhouse2'];

        $this->truncateTablesForAllConnections();

        $this->assertSame(
            0,
            (int) $connection->getClient()->select('SELECT count() AS c FROM examples2')->fetchOne('c')
        );
        $queries = array_column($connection->getQueryLog(), 'query');
        $database = $connection->getDatabaseName();
        $this->assertContains("select exists(select * from \"{$database}\".\"examples2\") as \"exists\"", $queries);
        $this->assertContains("truncate table \"{$database}\".\"examples2\"", $queries);
    }

    /**
     * Assert that the rows the previous test wrote are gone, and that the
     * setUp of this test truncated them rather than running migrate:fresh.
     */
    private function assertTablesWithRowsWereTruncated(): void
    {
        foreach (self::$tablesWithRows as $table) {
            $this->assertSame(0, $this->rowCount($table), "{$table} still holds the rows of the previous test");
        }

        if (self::$tablesWithRows !== []) {
            $this->assertTrue($this->truncatedBeforeTest, 'only the first test of the class should run migrate:fresh');
        }
    }

    /**
     * Forget the table list that DatabaseTruncation keeps for the whole class,
     * so that tables created or dropped by a test are seen.
     */
    private function forgetTables(): void
    {
        static::$allTables = [];
    }

    private function client(): Client
    {
        return DB::connection('clickhouse')->getClient();
    }

    private function database(): string
    {
        return DB::connection('clickhouse')->getDatabaseName();
    }

    private function rowCount(string $table): int
    {
        return (int) $this->client()->select("SELECT count() AS c FROM {$table}")->fetchOne('c');
    }

    /**
     * @return string[]
     */
    private function migrations(): array
    {
        return array_column(
            $this->client()->select('SELECT migration FROM migrations ORDER BY migration')->rows(),
            'migration'
        );
    }

    /**
     * Get the views, materialized views and dictionaries this test created.
     *
     * @return string[]
     */
    private function objectsOtherThanTables(): array
    {
        return array_column($this->client()->select(
            "SELECT name FROM system.tables WHERE database = currentDatabase() AND startsWith(name, 'truncation_')"
            . " AND (endsWith(engine, 'View') OR engine = 'Dictionary') ORDER BY name"
        )->rows(), 'name');
    }

    /**
     * Drop the objects the tests of this class create, dependents first.
     */
    private function dropObjects(): void
    {
        $client = $this->client();
        $client->write('DROP DICTIONARY IF EXISTS truncation_dictionary SYNC');
        foreach ([
            'truncation_to_view',
            'truncation_inner_view',
            'truncation_view',
            'truncation_event_ids',
            'truncation_events',
            'truncation_buffer',
            '`truncation-hyphen`',
        ] as $table) {
            $client->write("DROP TABLE IF EXISTS {$table} SYNC");
        }
    }
}
