# Upgrade

## Upgrade to 4.x

4.x is the latest version. See [Upgrade to 4.x](/getting-started/upgrading). The `lc-upgrade-3x-to-4x` coding-agent skill of 4.x does the upgrade.

## Upgrade from 2.x to 3.0

3.0 renames the package and its namespace. The PHP (`^8.5`) and Laravel (`^13`) requirements do not change.

| | 2.x | 3.0 |
| --- | --- | --- |
| Composer package | `oralunal/phpclickhouse-laravel` | `oralunal/laravel-clickhouse` |
| Namespace | `PhpClickHouseLaravel\` | `Oralunal\LaravelClickHouse\` |

Nothing else changes. The class names below the namespace, the folders, the configuration, the SQL and the behavior are the same as in 2.0.2.
For example, `PhpClickHouseLaravel\ClickhouseSchemaBuilder\Tables\MergeTree` becomes `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`.

2.0.2 is the last release of `oralunal/phpclickhouse-laravel`. That package is abandoned. 3.0 conflicts with it, so Composer does not install both.

On 1.x, do the [upgrade from 1.x to 2.0](/2.x/getting-started/upgrading#upgrade-from-1-x-to-2-0) first.

### With a coding agent

1. Change the package and install the skills:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
   php artisan clickhouse:install-skills
   ```

2. In your agent, run `/lc-upgrade-2x-to-3x`.
   If your agent does not show skills as slash commands, ask it to "upgrade phpclickhouse-laravel to laravel-clickhouse 3.x".

The agent renames the namespace, clears the Laravel caches and examines the result. Then it compares the test results with the results before the upgrade.

`clickhouse:install-skills` finds your agents from their project files, such as `.claude/`, `.cursor/` and `AGENTS.md`. To select the agents, give `--agent=claude_code`.

If `php artisan` fails after step 1 with a class-not-found error for a `PhpClickHouseLaravel\…` class, your application uses the 2.x namespace when it starts. Copy the skill yourself:

```sh
mkdir -p .claude/skills
cp -r vendor/oralunal/laravel-clickhouse/resources/skills/lc-upgrade-2x-to-3x .claude/skills/
```

### By hand

1. Change the package:

   ```sh
   composer remove oralunal/phpclickhouse-laravel --no-update
   composer require oralunal/laravel-clickhouse:^3.0 --with-all-dependencies
   ```

   The `artisan` scripts of Composer can fail with a class-not-found error until you complete step 2.

2. Rename the namespace in your code, migrations, config and tests. This command keeps the escapes of strings such as `'PhpClickHouseLaravel\\BaseModel'`:

   ```sh
   grep -rlI 'PhpClickHouseLaravel' . \
       --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=storage \
       --exclude-dir=.git --exclude-dir=cache --exclude=composer.lock \
     | xargs perl -pi -e 's/PhpClickHouseLaravel(\\+)/Oralunal$1LaravelClickHouse$1/g'
   ```

   Then search for `PhpClickHouseLaravel` again, and examine the remaining text, such as comments.

3. Run `php artisan package:discover` and `php artisan optimize:clear`.
   If they fail with `Class "PhpClickHouseLaravel\ClickhouseServiceProvider" not found`, delete `bootstrap/cache/packages.php` and `bootstrap/cache/services.php`. Then run `php artisan package:discover` again.

4. 3.0 cannot unserialize values with the 2.x class names: cache entries, queued jobs and sessions that hold objects of the package, such as `RawColumn` or the enums of the builder.
   If your application stores such values, let the queues become empty before you deploy, and clear the cache after you deploy.
