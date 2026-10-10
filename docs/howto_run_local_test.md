# Running tests locally

PHP runs on the host (via Orchestra Testbench). Only ClickHouse + Zookeeper
live in containers.

Prerequisites:

- PHP 8.5 with Composer
- A Docker-compatible runtime (Docker Desktop, Colima, etc.) with
  `docker compose` or `docker-compose`.

Steps:

1. `docker compose -f docker-compose.test.yaml up -d`
   (or `docker-compose -f docker-compose.test.yaml up -d` on standalone compose)
2. `composer install`
3. `composer test` (alias for `vendor/bin/phpunit`)

Tear down: `docker compose -f docker-compose.test.yaml down -v`

`docker-compose.test.yaml` runs ClickHouse 24.8. `CLICKHOUSE_VERSION`
picks another release, as in
`CLICKHOUSE_VERSION=26.8 docker compose -f docker-compose.test.yaml up -d`.
To switch a cluster that exists to another release, tear it down first
with `docker compose -f docker-compose.test.yaml down -v`: `up -d` alone
recreates the ClickHouse containers on their data volumes, and 24.8
cannot load the tables that 26.3 or 26.8 wrote there.

## Cluster tests

The tests that need the test cluster, such as `ClusterTest`, need the
`company_cluster` definition and the `{replica}` / `{shard}` macros that
live in `tests/docker/clickhouse01/config.xml` and
`tests/docker/clickhouse02/config.xml`, mounted into the ClickHouse
containers by `docker-compose.test.yaml`, and its ZooKeeper. Locally they
run by default because `phpunit.xml` sets `CLICKHOUSE_CLUSTER_AVAILABLE=1`.

CI runs the suite in two jobs of `.github/workflows/tests.yml`, each on
ClickHouse 24.8, 26.3 and 26.8. The `phpunit` job uses two GitHub Actions
service containers, which cannot mount these configs, so it rewrites
`phpunit.xml` before running the suite to flip the value to `0`, and the
tests that need the cluster `markTestSkipped`. The `cluster` job starts
`docker-compose.test.yaml` with `CLICKHOUSE_VERSION` set to the release
and `CLICKHOUSE_CLUSTER_AVAILABLE=1`, so they run.

## Artisan and Laravel Boost

`vendor/bin/testbench <artisan-command>` gives you a full Laravel console
against the package, e.g. `vendor/bin/testbench tinker`,
`vendor/bin/testbench migrate`, or `vendor/bin/testbench list`.

Laravel Boost's MCP server is wired via `composer boost` (re-runs the
installer). The generated `.mcp.json`, `AGENTS.md`, `CLAUDE.md`, and
`.junie/mcp/mcp.json` are committed.
