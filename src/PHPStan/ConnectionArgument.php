<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;

/**
 * Reads the connection that a call such as DB::connection('clickhouse') names, for the return type extensions.
 */
final class ConnectionArgument
{
    /**
     * Determine if every connection that the first argument can name is a ClickHouse connection: a constant string,
     * or the default connection when the argument is left out or null. A name that the analysis cannot know, such as
     * a variable of type string, gives false, so that the return type stays Laravel's.
     *
     * @param list<Arg> $args
     * @param Scope $scope
     * @param ConnectionConfig $config
     * @return bool
     */
    public static function namesClickHouse(array $args, Scope $scope, ConnectionConfig $config): bool
    {
        if ($args === []) {
            return $config->isClickHouse(null);
        }

        $type = $scope->getType($args[0]->value);
        if ($type->isNull()->yes()) {
            return $config->isClickHouse(null);
        }

        $names = $type->getConstantStrings();
        if ($names === [] || !$type->isConstantScalarValue()->yes()) {
            return false;
        }

        foreach ($names as $name) {
            if (!$config->isClickHouse($name->getValue())) {
                return false;
            }
        }

        return true;
    }
}
