---
layout: home

hero:
  name: laravel-clickhouse
  text: ClickHouse for Laravel
  tagline: Models, query builders, the schema builder and migrations for ClickHouse, on the smi2/phpClickHouse HTTP client.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started/introduction
    - theme: alt
      text: Upgrade to 4.x
      link: /getting-started/upgrading
    - theme: alt
      text: GitHub
      link: https://github.com/oralunal/laravel-clickhouse

features:
  - title: Models
    details: BaseModel with casts, accessors, mutators and events, or an Eloquent model with relations. Insert rows in batches, in a buffer or as JSONEachRow.
    link: /models/defining-models
  - title: Query builder
    details: Laravel's query methods, and ClickHouse SQL such as PREWHERE, SAMPLE, ARRAY JOIN, WITH, set operations and SETTINGS.
    link: /query-builder/basics
  - title: Safe mutations
    details: Lightweight DELETE, ALTER TABLE mutations, IN PARTITION and ON CLUSTER. The package refuses clauses that ClickHouse ignores.
    link: /query-builder/writing-data
  - title: Schema builder and migrations
    details: Schema::create() and Schema::table() write ClickHouse DDL. migrate, rollback, status and schema:dump work on ClickHouse.
    link: /schema/schema-builder
  - title: Sessions and parallel queries
    details: Run queries in one session with temporary tables. Run SELECTs at the same time across connections.
    link: /advanced/sessions
  - title: Clusters
    details: Node rotation, retries, ON CLUSTER DDL and replicated tables. Tested on ClickHouse 24.8, 26.3 and 26.8.
    link: /advanced/clusters
---
