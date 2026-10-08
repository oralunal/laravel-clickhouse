# Contributing

Thanks for taking the time to contribute. This document covers the development
workflow, commit conventions, and — most importantly — when to update
`CHANGELOG.md` and when to cut a release.

## Development setup

PHP runs on the host via **Orchestra Testbench**; only ClickHouse + Zookeeper
live in containers.

```bash
docker compose -f docker-compose.test.yaml up -d
composer install
composer test
```

See [docs/howto_run_local_test.md](docs/howto_run_local_test.md) for
prerequisites, cluster-test notes, and using `vendor/bin/testbench` during
development. There is no `php artisan` in this repo — it is a package, so use
`vendor/bin/testbench <artisan-command>` instead.

## Branches

Branch off `master` using a `type/short-description` name, matching the commit
type that fits the work:

```
feat/buffered-inserts
fix/cast-null-handling
docs/contributing-guide
chore/boost-guidelines-update
```

## Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/).
Types in use: `feat`, `fix`, `docs`, `ci`, `chore`. Scopes are optional and
lowercase — `fix(tests):`, `feat(config):`, `chore(release):`.

Keep the subject to a single concise line. Add a body only when the change
spans multiple concerns or needs context the subject cannot carry.

## Tests

The full suite must pass before a PR is merged:

```bash
vendor/bin/phpunit
```

`phpunit.xml` sets `failOnRisky` and `failOnWarning`, so risky tests and
warnings are failures — not noise to ignore.

`ClusterTest` needs the `company_cluster` definition and `{replica}` /
`{shard}` macros mounted from `tests/docker/clickhouse0*/config.xml`. These run
locally by default (`CLICKHOUSE_CLUSTER_AVAILABLE=1`) but are gated off in CI,
where service containers cannot mount the configs. **If you change cluster
behavior, verify it locally** — CI will not catch it.

Unit tests under `tests/Unit/` cover grammar and builder SQL generation and run
without Docker.

## CHANGELOG

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

**Update it in the same PR as the change, under `## [Unreleased]` — not at
release time.** Three weeks later you will not remember which edge case a fix
addressed. Entries describe *behavior*, in prose, for someone consuming the
package; they are not a list of commit subjects.

The `[Unreleased]` section accumulates between releases.

### What belongs in it

The filter is: **does this change anything for someone who runs
`composer require oralunal/laravel-clickhouse`?**

Include:

- Anything in `src/` that changes behavior, changes a signature, or adds
  public API — `BaseModel`, `Builder`, the grammars, the service provider
- Changes to the publishable `config/clickhouse.php`, which users copy into
  their own app
- `composer.json` constraint changes: `php`, `laravel/framework`,
  `smi2/phpclickhouse`
- Bug fixes users could have hit, and deprecations

Leave out:

- `tests/`, `workbench/`, `.github/workflows/`
- Agent and tooling files: `AGENTS.md`, `CLAUDE.md`, `.ai/`, `.junie/`,
  `.mcp.json`
- README wording fixes

Internal refactors with no observable behavior change are a grey area. Logging
one under `### Changed` with an explicit "public behavior unchanged" note is
correct — but it does not on its own justify a release.

## Releasing

Releases are cut manually. Packagist picks up the git tag via webhook; there is
no publish step and no `version` field in `composer.json`.

### When to release

| Situation | Decision |
| --- | --- |
| Bug fix affecting users | **Immediately** — people are blocked |
| Completed feature (tested and documented) | When ready; do not batch for long |
| New Laravel or PHP major supported | Immediately — people are waiting to upgrade |
| Docs, CI, or agent files only | **No release.** Let it ride along with the next real change |
| `[Unreleased]` is empty | No release |

A library has nothing to gain from calendar-based releases. Cut one when there
is something to consume.

### Choosing the version

Apply [SemVer](https://semver.org/spec/v2.0.0.html) from the *consumer's*
perspective:

| | When | Example |
| --- | --- | --- |
| **MAJOR** | Removing or renaming public API, incompatible signature change, raising the minimum PHP/Laravel version, changing a default in a breaking way | `php: ^8.5` → `^8.6`; flipping the `fix_default_query_builder` default |
| **MINOR** | New public method, new config option with a safe default, *widening* a supported version range | `buffer()` in 1.2.0; `laravel: ^13` → `^13\|^14` |
| **PATCH** | Bug fix, internal refactor, performance | Fixing a cast in `insertAssoc()` |

One caveat specific to this package: `BaseModel` is designed to be extended, so
**every public method name added to it is part of the API surface**. Adding
`buffer()` can collide with a subclass that already defines its own. That is
MINOR by the letter of SemVer but breaking in practice — choose new public
method names with that in mind.

### Release checklist

```bash
git checkout master && git pull

# Full suite must be green — the one mandatory gate
docker compose -f docker-compose.test.yaml up -d
vendor/bin/phpunit

# In CHANGELOG.md:
#   - rename [Unreleased] to [X.Y.Z] - YYYY-MM-DD
#   - add the [X.Y.Z] compare link to the reference list at the bottom
#   - point the [Unreleased] link at vX.Y.Z...HEAD

git commit -am "chore(release): X.Y.Z"
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin master --follow-tags

# Release notes are the CHANGELOG section for this version, verbatim
gh release create vX.Y.Z --title vX.Y.Z --notes "..."
```

Use annotated tags (`-a`). Earlier tags are inconsistent — `v1.1.0` is
annotated, `v1.0.0` and `v1.2.0` are lightweight — so standardise from here on.
