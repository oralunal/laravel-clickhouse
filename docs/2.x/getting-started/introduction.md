# Introduction

phpclickhouse-laravel connects Laravel to [ClickHouse](https://clickhouse.com/). It sends the queries over HTTP with [smi2/phpClickHouse](https://github.com/smi2/phpClickHouse).
Apart from Laravel, smi2/phpClickHouse is the only dependency. The package contains its query builder, its schema builder and an enum base class. See [Credits](#credits).

This documentation describes 2.0.2, the last release of 2.x and of the `oralunal/phpclickhouse-laravel` package.
3.0 renamed the package to `oralunal/laravel-clickhouse` and the namespace to `Oralunal\LaravelClickHouse\`.

## What 2.0 changed

1.x used other packages for the query builder and the schema builder. 2.0 contains copies of them in its own namespace:

| 1.x | 2.x |
| --- | --- |
| `Tinderbox\ClickhouseBuilder\` (`oralunal/clickhouse-builder`) | `PhpClickHouseLaravel\ClickhouseBuilder\` |
| `PhpClickHouseSchemaBuilder\` (`glushkovds/php-clickhouse-schema-builder`) | `PhpClickHouseLaravel\ClickhouseSchemaBuilder\` |
| `MyCLabs\Enum\Enum` (`myclabs/php-enum`) | `PhpClickHouseLaravel\Enum\Enum` |
| The global functions `raw()`, `tp()` and `array_flatten()` | The same functions in `PhpClickHouseLaravel\ClickhouseBuilder` |

The SQL does not change. See [Upgrade](/2.x/getting-started/upgrading#upgrade-from-1-x-to-2-0).

## Features

- `BaseModel`, a model like Eloquent's: `create()`, `save()`, `insertBulk()`, `insertAssoc()`, `where()` and pages
- `PhpClickHouseLaravel\Migration`, a base class for ClickHouse migrations, on one node or on a cluster
- `php artisan schema:dump [--prune]` squashes the migrations into a schema file
- `php artisan migrate:fresh` and `php artisan db:wipe` on ClickHouse connections (2.0.1 and later)
- `php artisan clickhouse:install-skills` installs the `plc-upgrade-1x-to-2x` skill for coding agents (2.0.1 and later)
- A query builder with `settings()`, `chunk()` and ClickHouse SQL
- A `boolean` cast for inserts
- The model events `creating`, `created` and `saved`
- Retries of failed requests
- Tables with the `Buffer` engine: `$tableForInserts` and `$tableSources`
- Inserts in a buffer in PHP memory: `buffer()` and `flushBuffer()`
- `OPTIMIZE`, `TRUNCATE`, `ALTER TABLE ... DELETE` and `ALTER TABLE ... UPDATE`
- Many ClickHouse servers, and clusters with node rotation
- A default configuration: `.env` values are enough for most applications

The smi2 client uses curl, not PDO. See [its features](https://github.com/smi2/phpClickHouse#features).

## Requirements

| Software | Version |
| --- | --- |
| PHP | 8.5 or later |
| Laravel | 13 or later |
| ClickHouse | 24.x. Versions from 20 usually work, but the tests do not cover them. |

Use 2.0.2. 2.0.0 does not contain the schema builder and the enum base class. In 2.0.1, parallel test processes drop the ClickHouse tables of other processes.

## Credits

The package contains code from these MIT-licensed projects. Each directory keeps the original license.

| In the package | Origin |
| --- | --- |
| `PhpClickHouseLaravel\ClickhouseBuilder` | [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), through the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks (v1.0.0) |
| `PhpClickHouseLaravel\ClickhouseSchemaBuilder` | [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) 1.1.1 |
| `PhpClickHouseLaravel\Enum\Enum` | [myclabs/php-enum](https://github.com/myclabs/php-enum) 1.8.5 |

The package is a fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel).
