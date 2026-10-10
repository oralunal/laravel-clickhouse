# Introduction

phpclickhouse-laravel connects Laravel to [ClickHouse](https://clickhouse.com/). It uses these packages:

| Package | Use | Namespace |
| --- | --- | --- |
| [smi2/phpClickHouse](https://github.com/smi2/phpClickHouse) | HTTP requests and queries | `ClickHouseDB\` |
| [oralunal/clickhouse-builder](https://github.com/oralunal/clickhouse-builder) | The query builder, a fork of `glushkovds/ClickhouseBuilder` and `the-tinderbox/ClickhouseBuilder` | `Tinderbox\ClickhouseBuilder\` |
| [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) | The schema builder of `Migration::createMergeTree()` | `PhpClickHouseSchemaBuilder\` |

The classes of the package are in the `PhpClickHouseLaravel\` namespace. `raw()`, `tp()` and `array_flatten()` are global functions.

This documentation describes 1.5.0, the last release of 1.x. Some features need a later 1.x release. The text shows the release.

## Features

- `BaseModel`, a model like Eloquent's: `create()`, `save()`, `insertBulk()`, `insertAssoc()`, `where()` and pages
- `PhpClickHouseLaravel\Migration`, a base class for ClickHouse migrations, on one node or on a cluster
- `php artisan schema:dump [--prune]` squashes the migrations into a schema file (1.5.0)
- A query builder with `settings()`, `chunk()` and ClickHouse SQL
- A `boolean` cast for inserts
- The model events `creating`, `created` and `saved`
- Retries of failed requests
- Tables with the `Buffer` engine: `$tableForInserts` and `$tableSources`
- Inserts in a buffer in PHP memory: `buffer()` and `flushBuffer()` (1.2.0)
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

## Origin

1.0.0 is a fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel) 2.5.2. It needs PHP 8.5 and Laravel 13.
