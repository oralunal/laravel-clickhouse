# Introduction

laravel-clickhouse connects Laravel to ClickHouse. It sends queries over HTTP with
[smi2/phpClickHouse](https://github.com/smi2/phpClickHouse). It does not use PDO.

The connection works with Laravel's own APIs: the `DB` facade, Laravel's query builder, Eloquent models,
`Schema` and migrations. The package adds the ClickHouse parts to them.

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
| [Models](/models/defining-models) | Eloquent models with casts, events, relations, timestamps and ClickHouse mutations |
| [Inserts](/models/inserting-rows) | Batch inserts, in-memory buffers, Buffer engine tables, JSONEachRow and file inserts |
| [Query builder](/query-builder/basics) | Laravel's query builder with `FINAL`, `PREWHERE`, `SAMPLE`, `WITH`, `ARRAY JOIN`, ClickHouse joins, `INTERSECT`, `EXCEPT` and `SETTINGS` |
| [Read results](/query-builder/reading-results) | `count()`, aggregates, `exists()`, `first()`, `value()`, `pluck()`, `chunk()`, pagination, `cursor()` and results in any format |
| [Mutations](/query-builder/writing-data) | Lightweight `DELETE`, `ALTER TABLE ... DELETE` and `UPDATE`, `OPTIMIZE` and `TRUNCATE`, with `IN PARTITION` and `ON CLUSTER` |
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
Versions 1.x to 3.x bundled code from these MIT-licensed projects. Version 4 uses Laravel's own query builder and
schema builder instead, and bundles none of them:

| Project | Used in |
| --- | --- |
| [the-tinderbox/ClickhouseBuilder](https://github.com/the-tinderbox/ClickhouseBuilder), through the [glushkovds](https://github.com/glushkovds/ClickhouseBuilder) and [oralunal](https://github.com/oralunal/ClickhouseBuilder) forks | The query builder of 1.x to 3.x |
| [glushkovds/php-clickhouse-schema-builder](https://github.com/glushkovds/php-clickhouse-schema-builder) by Denis Glushkov | `createMergeTree()` of 1.x to 3.x |
| [myclabs/php-enum](https://github.com/myclabs/php-enum) by My C-Labs | The enums of 1.x to 3.x |
