<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Illuminate\Support\Facades\DB;
use Larastan\Larastan\SQL\SqlParser;
use LogicException;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\QueryBuilder;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\Container;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Gives DB::connection() the package's Connection when it names a ClickHouse connection, and DB::table() and
 * DB::query() the package's QueryBuilder when the default connection is a ClickHouse connection. Laravel declares
 * its own Connection and query builder, which have none of the ClickHouse methods, such as final() and settings().
 */
final class DatabaseFacadeReturnType implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * @param ConnectionConfig $config
     * @param Container $container
     * @throws LogicException When Larastan's own parsers are in place, so that the schema dumps and the migrations
     *                        of ClickHouse connections would be read with the types of MySQL (see verifyParsers())
     */
    public function __construct(private ConnectionConfig $config, Container $container)
    {
        self::verifyParsers($container);
    }

    /**
     * Refuse to run when Larastan is loaded and its sqlParser or migrationsParser service is not the parser of this
     * extension. That is so when this extension is included before Larastan's, whose definitions of the services
     * then win: Larastan would skip the schema dumps of ClickHouse connections without an error, and read their
     * migrations with the types of MySQL.
     *
     * @param Container $container
     * @return void
     * @throws LogicException
     */
    private static function verifyParsers(Container $container): void
    {
        if (!interface_exists(SqlParser::class) || !$container->hasService('sqlParser')) {
            return;
        }

        if (!$container->getService('sqlParser') instanceof ClickHouseSqlParser
            || $container->hasService('migrationsParser')
            && !$container->getService('migrationsParser') instanceof ClickHouseMigrationParser
        ) {
            throw new LogicException(
                'The PHPStan extension of oralunal/laravel-clickhouse must be included after the extension of'
                . ' Larastan, so that its parsers read the schema dumps and the migrations of ClickHouse connections:'
                . ' include vendor/larastan/larastan/extension.neon first, then'
                . ' vendor/oralunal/laravel-clickhouse/extension.neon.'
            );
        }
    }

    public function getClass(): string
    {
        return DB::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), ['connection', 'table', 'query'], true);
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope
    ): ?Type {
        if ($methodReflection->getName() === 'connection') {
            return ConnectionArgument::namesClickHouse($methodCall->getArgs(), $scope, $this->config)
                ? new ObjectType(Connection::class)
                : null;
        }

        return $this->config->isClickHouse(null) ? new ObjectType(QueryBuilder::class) : null;
    }
}
