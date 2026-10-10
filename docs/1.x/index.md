---
layout: home

hero:
  name: phpclickhouse-laravel 1.x
  text: ClickHouse for Laravel
  tagline: The documentation of 1.5.0. 1.x uses the oralunal/clickhouse-builder and glushkovds/php-clickhouse-schema-builder packages.
  actions:
    - theme: brand
      text: Get started
      link: /1.x/getting-started/introduction
    - theme: alt
      text: Upgrade to 4.x
      link: /getting-started/upgrading
    - theme: alt
      text: Release notes
      link: /1.x/reference/release-notes

features:
  - title: Models
    details: BaseModel with create(), save(), insertBulk(), insertAssoc(), a boolean cast and events.
    link: /1.x/models/defining-models
  - title: Query builder
    details: A ClickHouse query builder with settings(), chunk() and pages.
    link: /1.x/query-builder/basics
  - title: Migrations
    details: Write SQL, or define a MergeTree table in PHP. schema:dump squashes the migrations (1.5.0).
    link: /1.x/schema/migrations
  - title: Clusters
    details: Many nodes, node rotation, retries and replicated tables.
    link: /1.x/advanced/clusters
---
