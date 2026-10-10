---
layout: home

hero:
  name: laravel-clickhouse 3.x
  text: ClickHouse for Laravel
  tagline: The documentation of 3.0.0. 3.0 renamed oralunal/phpclickhouse-laravel to oralunal/laravel-clickhouse.
  actions:
    - theme: brand
      text: Get started
      link: /3.x/getting-started/introduction
    - theme: alt
      text: Upgrade to 4.x
      link: /getting-started/upgrading
    - theme: alt
      text: Release notes
      link: /3.x/reference/release-notes

features:
  - title: Models
    details: BaseModel with create(), save(), insertBulk(), insertAssoc(), a boolean cast and events.
    link: /3.x/models/defining-models
  - title: Query builder
    details: A ClickHouse query builder with settings(), chunk() and pages.
    link: /3.x/query-builder/basics
  - title: Migrations
    details: Write SQL, or define a MergeTree table in PHP. schema:dump squashes the migrations.
    link: /3.x/schema/migrations
  - title: Clusters
    details: Many nodes, node rotation, retries and replicated tables.
    link: /3.x/advanced/clusters
---
