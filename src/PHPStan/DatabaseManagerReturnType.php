<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Illuminate\Database\DatabaseManager;
use Oralunal\LaravelClickHouse\Connection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Gives connection() of Laravel's DatabaseManager, as app('db')->connection('clickhouse'), the package's Connection
 * when it names a ClickHouse connection.
 */
final class DatabaseManagerReturnType implements DynamicMethodReturnTypeExtension
{
    /**
     * @param ConnectionConfig $config
     */
    public function __construct(private ConnectionConfig $config)
    {
    }

    public function getClass(): string
    {
        return DatabaseManager::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'connection';
    }

    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope
    ): ?Type {
        return ConnectionArgument::namesClickHouse($methodCall->getArgs(), $scope, $this->config)
            ? new ObjectType(Connection::class)
            : null;
    }
}
