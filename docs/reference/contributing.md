# Contribute

For branches, commits, the changelog and releases, see [CONTRIBUTING.md](https://github.com/oralunal/laravel-clickhouse/blob/master/CONTRIBUTING.md).

## Run the tests

PHP runs on the host, with Orchestra Testbench. ClickHouse and ZooKeeper run in containers.

You need:

- PHP 8.5 and Composer;
- a Docker runtime, such as Docker Desktop or Colima, with `docker compose`.

1. Start the test cluster:

   ```sh
   docker compose -f docker-compose.test.yaml up -d
   ```

2. Install the dependencies:

   ```sh
   composer install
   ```

3. Run the tests:

   ```sh
   composer test
   ```

To stop the cluster and remove its data: `docker compose -f docker-compose.test.yaml down -v`.

## Other ClickHouse versions

The test cluster runs ClickHouse 24.8. `CLICKHOUSE_VERSION` selects another release:

```sh
CLICKHOUSE_VERSION=26.8 docker compose -f docker-compose.test.yaml up -d
```

::: warning
To change the version of a cluster that exists, run `docker compose -f docker-compose.test.yaml down -v` first.
`up -d` keeps the data volumes, and 24.8 cannot read the tables that 26.3 or 26.8 wrote there.
:::

## Cluster tests

Some tests need the test cluster: the `company_cluster` definition, the `{replica}` and `{shard}` macros of `tests/docker/clickhouse0*/config.xml`, the second node and ZooKeeper.
They run when `CLICKHOUSE_CLUSTER_AVAILABLE=1`, which `phpunit.xml` sets.

CI runs the test suite in two jobs, on ClickHouse 24.8, 26.3 and 26.8:

| Job | Servers | Cluster tests |
| --- | --- | --- |
| Two standalone servers | Two GitHub Actions service containers | Skipped (`CLICKHOUSE_CLUSTER_AVAILABLE=0`) |
| Two-node cluster with ZooKeeper | `docker-compose.test.yaml` | Run |

## Artisan

`vendor/bin/testbench <command>` runs an Artisan command for the package, for example `vendor/bin/testbench tinker` or `vendor/bin/testbench migrate`.

## Documentation

The documentation site is in `docs/`. It uses [VitePress](https://vitepress.dev/).

```sh
cd docs
npm ci
npm run dev     # Local server
npm run build   # Build to docs/.vitepress/dist
```

Write the documentation in [ASD-STE100 Simplified Technical English](https://www.asd-ste100.org/): short sentences, active voice, one instruction in each sentence.

### Versions

The root of `docs/` has the documentation of the latest version. `docs/3.x`, `docs/2.x` and `docs/1.x` have the earlier versions. Each version is a locale of VitePress in `docs/.vitepress/config.mts`, with its own menu, sidebar and search index.

- Change the pages of an earlier version only to correct them.
- Before the next major release, copy the pages of the latest version to a directory such as `docs/4.x`. Add the version to `VERSIONS` in `config.mts`. Then change the root pages for the new version.

### Public API

`tests/Unit/Documentation/DocumentationTest.php` makes sure that the documentation covers the public API:

- [API reference](/reference/api) agrees with the code. After you add, remove or change a public class, constant, method or function, run `composer docs:api` and commit `docs/reference/api.md`.
- A guide page names each public method, for example `` `whereDict()` ``. The internal classes, the methods with an `@internal` tag, and the methods that implement a Laravel or PHP contract are exceptions.
