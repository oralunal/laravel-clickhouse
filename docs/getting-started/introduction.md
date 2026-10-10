# Introduction

laravel-clickhouse connects Laravel to ClickHouse. It sends queries over HTTP with
[smi2/phpClickHouse](https://github.com/smi2/phpClickHouse). It does not use PDO.

## Requirements

| Item | Version |
| --- | --- |
| PHP | 8.5 or later |
| Laravel | 13 or later |
| ClickHouse | 24.8 or later |

The test suite runs on ClickHouse 24.8, 26.3 and 26.8. Older versions are not tested.
Newer versions return some results differently. See [ClickHouse versions](/reference/clickhouse-versions).

## Features

| Area | Features |
| --- | --- |
| [Models](/models/defining-models) | `BaseModel` with `create()`, `save()`, casts, accessors, mutators and events |
| [Inserts](/models/inserting-rows) | Batch inserts, in-memory buffers, the Buffer engine, JSONEachRow and file inserts |
| [Eloquent models](/models/eloquent) | An Eloquent model with relations, eager loads, timestamps and ClickHouse mutations |
| [Query builder](/query-builder/basics) | Laravel's query methods, `PREWHERE`, `SAMPLE`, `WITH`, `ARRAY JOIN`, ClickHouse joins, `INTERSECT`, `EXCEPT` and `SETTINGS` |
| [Read results](/query-builder/reading-results) | `count()`, aggregates, `exists()`, `first()`, `value()`, `pluck()`, `chunk()`, pagination and `cursor()` |
| [Mutations](/query-builder/writing-data) | Lightweight `DELETE`, `ALTER TABLE ... DELETE` and `UPDATE`, `OPTIMIZE` and `TRUNCATE`, with `IN PARTITION` and `ON CLUSTER` |
| [Laravel's query builder](/query-builder/laravel-query-builder) | An option to use Laravel's own query builder, with `FINAL`, `PREWHERE`, `ARRAY JOIN`, ClickHouse joins, `SETTINGS` and the other ClickHouse clauses |
| [Schema](/schema/schema-builder) | `Schema::create()` and `Schema::table()` with ClickHouse types, engines, keys, TTLs, settings and indexes |
| [Migrations](/schema/migration-commands) | `migrate`, `migrate:rollback`, `migrate:status`, `migrate:fresh` and `schema:dump` on ClickHouse |
| [Raw SQL](/advanced/raw-sql) | `?` bindings that the package writes as escaped ClickHouse literals |
| [Sessions](/advanced/sessions) | Queries in one ClickHouse session, with temporary tables |
| [Parallel queries](/advanced/parallel-queries) | `SELECT` queries at the same time, across connections |
| [Clusters](/advanced/clusters) | Node rotation, retries, `ON CLUSTER` DDL and replicated tables |
| [Tests](/advanced/testing) | Laravel's `DatabaseTruncation` trait, SQLite with ClickHouse, and one database for each parallel test process |
| [Coding-agent skills](/reference/agent-skills) | Skills that upgrade an application to 4.x |

## Credits

The package is a fork of [glushkovds/phpclickhouse-laravel](https://github.com/glushkovds/phpclickhouse-laravel).
It bundles code from these MIT-licensed projects. Each bundled directory keeps its license file.

| Bundled as | Origin |
| --- | --- |
| `Oralunal\LaravelClickHouse\ClickhouseBuilder` | [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), through the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks (v1.0.0) |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder` | [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) v1.1.1 by Denis Glushkov |
| `Oralunal\LaravelClickHouse\Enum\Enum` | [myclabs/php-enum](https://github.com/myclabs/php-enum) 1.8.5 by My C-Labs |
