# Upgrade

## Upgrade to 4.x

4.x is the latest version. It is the `oralunal/laravel-clickhouse` package. See [Upgrade to 4.x](/getting-started/upgrading).
The `lc-upgrade-1x-to-4x` coding-agent skill of 4.x changes the package and the namespaces, and does the upgrade.

## Upgrade to 2.x

2.0 contains copies of the query builder, the schema builder and the enum base class in its own namespace. See [Upgrade from 1.x to 2.0](/2.x/getting-started/upgrading#upgrade-from-1-x-to-2-0).

## Upgrade within 1.x

The 1.x releases have no breaking changes. Update to 1.5.0:

```sh
composer require oralunal/phpclickhouse-laravel:^1.5
```

- If you published `config/clickhouse.php` before 1.4.0, examine it before you deploy 1.4.0 or later. Before 1.4.0, the package did not read the file. From 1.4.0, its values replace the defaults of the package.
- From 1.1.0, Laravel finds the service provider. You can remove `PhpClickHouseLaravel\ClickhouseServiceProvider` from `bootstrap/providers.php`.
