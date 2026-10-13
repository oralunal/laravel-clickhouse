<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Console\ConnectionOption;
use PHPUnit\Framework\TestCase;

/**
 * The --database option of make:clickhouse-model and make:clickhouse-migration.
 */
class ConnectionOptionTest extends TestCase
{
    private const CONNECTIONS = [
        'mysql' => ['driver' => 'mysql'],
        'analytics' => ['driver' => 'clickhouse'],
        'clickhouse' => ['driver' => 'clickhouse'],
        'broken' => 'not an array',
    ];

    public function test_the_option_names_a_clickhouse_connection(): void
    {
        $this->assertSame('analytics', ConnectionOption::resolve(self::CONNECTIONS, 'mysql', 'analytics'));
    }

    public function test_without_the_option_the_default_connection_comes_first(): void
    {
        $this->assertSame('analytics', ConnectionOption::resolve(self::CONNECTIONS, 'analytics', null));
        $this->assertSame('clickhouse', ConnectionOption::resolve(self::CONNECTIONS, 'mysql', ''));
        $this->assertSame('analytics', ConnectionOption::resolve(['analytics' => ['driver' => 'clickhouse']], 'mysql', null));
    }

    public function test_an_option_that_names_another_connection_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The connection [mysql] is not a ClickHouse connection of config/database.php.');

        ConnectionOption::resolve(self::CONNECTIONS, 'mysql', 'mysql');
    }

    public function test_a_config_without_a_clickhouse_connection_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('config/database.php has no ClickHouse connection');

        ConnectionOption::resolve(['mysql' => ['driver' => 'mysql']], 'mysql', null);
    }
}
