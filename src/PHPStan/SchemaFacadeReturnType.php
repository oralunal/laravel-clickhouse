<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBuilder;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Gives Schema::connection() the package's SchemaBuilder when it names a ClickHouse connection: Laravel declares its
 * own schema builder, which has none of the ClickHouse methods, such as hasDictionary() and dropTemporary().
 */
final class SchemaFacadeReturnType implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * @param ConnectionConfig $config
     */
    public function __construct(private ConnectionConfig $config)
    {
    }

    public function getClass(): string
    {
        return Schema::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'connection';
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope
    ): ?Type {
        return ConnectionArgument::namesClickHouse($methodCall->getArgs(), $scope, $this->config)
            ? new ObjectType(SchemaBuilder::class)
            : null;
    }
}
