<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\ClickhouseServiceProvider;

class CastFirstColumn extends BaseModel
{
    protected $table = 'regression_cast';
    protected $casts = ['b' => 'boolean'];
}

class BufferKeyOrder extends BaseModel
{
    protected $table = 'regression_buffer';
}

class RegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = DB::connection('clickhouse')->getClient();
        $client->write('CREATE TABLE IF NOT EXISTS regression_cast (b UInt8, p Int64) ENGINE = MergeTree() ORDER BY (p)');
        $client->write('CREATE TABLE IF NOT EXISTS regression_buffer (k1 String, k2 String) ENGINE = MergeTree() ORDER BY (k1)');
        $client->write('TRUNCATE TABLE regression_cast');
        $client->write('TRUNCATE TABLE regression_buffer');
    }

    protected function tearDown(): void
    {
        BufferKeyOrder::clearBuffer();
        parent::tearDown();
    }

    private function castRows(): array
    {
        return DB::connection('clickhouse')->getClient()
            ->select('SELECT p, b FROM regression_cast ORDER BY p')->rows();
    }

    /**
     * insertBulk() used to drop the cast when the cast column was the first one:
     * array_search() returns index 0, which the truthiness check treated as "not found".
     */
    public function testInsertBulkCastsFirstColumn(): void
    {
        CastFirstColumn::insertBulk([[false, 1]], ['b', 'p']);

        $this->assertSame(
            [['p' => '1', 'b' => 0]],
            $this->castRows(),
            'boolean cast must apply to the first column too'
        );
    }

    /** The same cast in a non-zero position must keep working. */
    public function testInsertBulkCastsLaterColumn(): void
    {
        CastFirstColumn::insertBulk([[2, false]], ['p', 'b']);

        $this->assertSame([['p' => '2', 'b' => 0]], $this->castRows());
    }

    /** A column with no cast configured must be left alone. */
    public function testInsertBulkLeavesUncastColumnsAlone(): void
    {
        CastFirstColumn::insertBulk([[1, 3]], ['b', 'p']);

        $this->assertSame([['p' => '3', 'b' => 1]], $this->castRows());
    }

    /**
     * Rows buffered one at a time are prepared in isolation, so their key order
     * is only comparable at flush time. Without normalization there, flushing a
     * buffer whose rows carry the same keys in a different order threw
     * "Fields not match".
     */
    public function testFlushBufferNormalizesKeyOrderAcrossCalls(): void
    {
        BufferKeyOrder::clearBuffer();
        BufferKeyOrder::buffer(['k1' => 'a', 'k2' => 'b']);
        BufferKeyOrder::buffer(['k2' => 'y', 'k1' => 'x']);

        BufferKeyOrder::flushBuffer();

        $this->assertSame(
            [['k1' => 'a', 'k2' => 'b'], ['k1' => 'x', 'k2' => 'y']],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT k1, k2 FROM regression_buffer ORDER BY k1')->rows()
        );
    }

    /**
     * A buffered row that omits a column must NOT be silently completed with
     * fabricated values — the mismatched insert has to keep failing loudly.
     */
    public function testFlushBufferStillFailsLoudlyOnMismatchedKeySets(): void
    {
        BufferKeyOrder::clearBuffer();
        BufferKeyOrder::buffer(['k1' => 'a', 'k2' => 'b']);
        BufferKeyOrder::buffer(['k1' => 'only-k1']);

        try {
            BufferKeyOrder::flushBuffer();
            $this->fail('flushBuffer() must not invent a value for the missing column');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Fields not match', $e->getMessage());
        } finally {
            BufferKeyOrder::clearBuffer();
        }

        $this->assertSame(
            [],
            DB::connection('clickhouse')->getClient()
                ->select('SELECT k1, k2 FROM regression_buffer')->rows()
        );
    }

    /** A published entry only has to name the keys it changes. */
    public function testPublishedConfigMergesPerKeyWithPackagedDefaults(): void
    {
        config()->set('database.connections.clickhouse', []);
        config()->set('clickhouse', ['clickhouse' => ['host' => 'partial-host']]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('partial-host', config('database.connections.clickhouse.host'));
        $this->assertSame('clickhouse', config('database.connections.clickhouse.driver'));
        $this->assertNotNull(config('database.connections.clickhouse.database'));
    }

    /** A published config/clickhouse.php must be able to add a connection. */
    public function testPublishedConfigCanAddConnection(): void
    {
        config()->set('clickhouse', [
            'clickhouse-published' => [
                'driver' => 'clickhouse', 'host' => '127.0.0.1', 'port' => '18124',
                'database' => 'default', 'username' => 'default', 'password' => '',
            ],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('18124', config('database.connections.clickhouse-published.port'));
    }

    /** A published config/clickhouse.php must be able to override a packaged default. */
    public function testPublishedConfigOverridesPackagedDefault(): void
    {
        config()->set('database.connections.clickhouse', []);
        config()->set('clickhouse', [
            'clickhouse' => [
                'driver' => 'clickhouse', 'host' => 'published-host', 'port' => '8123',
                'database' => 'default', 'username' => 'default', 'password' => '',
            ],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('published-host', config('database.connections.clickhouse.host'));
    }

    /** config/database.php still outranks a published config/clickhouse.php. */
    public function testDatabaseConfigStillWinsOverPublishedConfig(): void
    {
        config()->set('database.connections.clickhouse', ['host' => 'from-database-php']);
        config()->set('clickhouse', [
            'clickhouse' => ['driver' => 'clickhouse', 'host' => 'from-published', 'port' => '8123'],
        ]);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('from-database-php', config('database.connections.clickhouse.host'));
    }

    /** With nothing published, the packaged defaults still register. */
    public function testPackagedDefaultsApplyWithoutPublishedConfig(): void
    {
        config()->set('clickhouse', null);
        config()->set('database.connections.clickhouse', []);

        (new ClickhouseServiceProvider($this->app))->register();

        $this->assertSame('clickhouse', config('database.connections.clickhouse.driver'));
    }
}
