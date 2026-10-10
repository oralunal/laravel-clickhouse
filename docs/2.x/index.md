---
layout: home

hero:
  name: phpclickhouse-laravel 2.x
  text: ClickHouse for Laravel
  tagline: The documentation of 2.0.2, the last release of oralunal/phpclickhouse-laravel. 3.0 renamed the package to oralunal/laravel-clickhouse.
  actions:
    - theme: brand
      text: Get started
      link: /2.x/getting-started/introduction
    - theme: alt
      text: Upgrade to 4.x
      link: /getting-started/upgrading
    - theme: alt
      text: Release notes
      link: /2.x/reference/release-notes

features:
  - title: Models
    details: BaseModel with create(), save(), insertBulk(), insertAssoc(), a boolean cast and events.
    link: /2.x/models/defining-models
  - title: Query builder
    details: A ClickHouse query builder with settings(), chunk() and pages.
    link: /2.x/query-builder/basics
  - title: Migrations
    details: Write SQL, or define a MergeTree table in PHP. schema:dump squashes the migrations.
    link: /2.x/schema/migrations
  - title: Clusters
    details: Many nodes, node rotation, retries and replicated tables.
    link: /2.x/advanced/clusters
---
