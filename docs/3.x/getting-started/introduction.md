# Introduction

laravel-clickhouse connects Laravel to [ClickHouse](https://clickhouse.com/). It sends the queries over HTTP with [smi2/phpClickHouse](https://github.com/smi2/phpClickHouse).
Apart from Laravel, smi2/phpClickHouse is the only dependency. The package contains its query builder, its schema builder and an enum base class. See [Credits](#credits).

This documentation describes 3.0.0, the last release of 3.x.

## Package names

3.0 renamed the package and its namespace:

| | 2.x | 3.x |
| --- | --- | --- |
| Composer package | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
| Namespace | `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

The classes, the configuration, the SQL and the behavior are the same as in 2.0.2. See [Upgrade](/3.x/getting-started/upgrading).

## Features

- `BaseModel`, a model like Eloquent's: `create()`, `save()`, `insertBulk()`, `insertAssoc()`, `where()` and pages
- `Oralunal\LaravelClickHouse\Migration`, a base class for ClickHouse migrations, on one node or on a cluster
- `php artisan schema:dump [--prune]` squashes the migrations into a schema file
- `php artisan clickhouse:install-skills` installs the upgrade skills for coding agents
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

## Credits

The package contains code from these MIT-licensed projects. Each directory keeps the original license.

| In the package | Origin |
| --- | --- |
| `Oralunal\LaravelClickHouse\ClickhouseBuilder` | [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), through the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks (v1.0.0) |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder` | [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) 1.1.1 |
| `Oralunal\LaravelClickHouse\Enum\Enum` | [myclabs/php-enum](https://github.com/myclabs/php-enum) 1.8.5 |

The package is a fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel).
