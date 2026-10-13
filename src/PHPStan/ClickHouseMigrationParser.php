<?php

declare(strict_types=1);

namespace Oralunal\LaravelClickHouse\PHPStan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use ReflectionClass;
use Throwable;

/**
 * Larastan's parser of the migrations, with the PHP types of the values that the package returns for the columns of
 * ClickHouse tables (see ResultValues). Larastan reads the column methods of a migration with the types of MySQL, and
 * it reads a blueprint method that it does not know as a column. extension.neon puts this parser in place of
 * Larastan's migrationsParser service, so that Larastan reads the changed calls.
 *
 * The callback of a Schema::create() or Schema::table() call is a ClickHouse callback when its parameter has the
 * type SchemaBlueprint, or when the call uses a ClickHouse connection: the connection of Schema::connection(), else
 * the $connection property of the migration, else the default connection. In a ClickHouse callback, and in the
 * callbacks of its after() calls, the calls of the blueprint change as follows:
 * - a column method that Larastan reads with an incorrect type becomes addColumn() with a method that Larastan reads
 *   with the correct type: decimal() is string, a UInt64 column, such as id(), foreignId() or an unsigned
 *   bigInteger(), is mixed, year() is int<0, max>, and the type of rawColumn() is read as in a schema dump (see
 *   ClickHouseSqlParser::convert());
 * - enum() gets the names of the values, which ClickHouse returns, also for an array that gives the numbers;
 * - the morph and foreignIdFor() columns get the types of their ClickHouse columns;
 * - a float() or double() column is nullable when a ClickHouse connection of the config is read-only (see
 *   ConnectionConfig::hasReadOnlyClickHouseConnection());
 * - a column with virtualAs() or storedAs() is nullable, because SELECT * does not read it;
 * - an EPHEMERAL column, which a select cannot read, is dropped, and change()->renameTo() renames the column;
 * - the table clauses, such as engine(), orderBy() and settings(), are removed, so that they add no column.
 *
 * Schema::dropSync() and dropIfExistsSync() become drop() and dropIfExists(). The calls of other connections stay as
 * they are.
 */
final class ClickHouseMigrationParser implements Parser
{
    /**
     * A column method that Larastan does not know: Larastan reads its column as mixed.
     */
    private const MIXED = 'mixed';

    /**
     * The blueprint methods of the table clauses, in lowercase. They add no column.
     *
     * @var list<string>
     */
    private const TABLE_CLAUSES = [
        'engine', 'orderby', 'partitionby', 'sampleby', 'ttl', 'settings', 'replicated', 'ifnotexists',
        'withoutoncluster', 'charset', 'collation', 'comment', 'temporary',
    ];

    /**
     * The column modifiers of a MATERIALIZED or ALIAS column, in lowercase.
     *
     * @var list<string>
     */
    private const GENERATED = ['virtualas', 'storedas', 'virtualasjson', 'storedasjson'];

    /**
     * The column names of the column methods without a name argument, by the method in lowercase.
     *
     * @var array<string, string>
     */
    private const DEFAULT_NAMES = [
        'id' => 'id', 'uuid' => 'uuid', 'ulid' => 'ulid', 'ipaddress' => 'ip_address', 'macaddress' => 'mac_address',
        'softdeletes' => 'deleted_at', 'softdeletestz' => 'deleted_at', 'softdeletesdatetime' => 'deleted_at',
        'remembertoken' => 'remember_token',
    ];

    /**
     * @param Parser $parser The parser that Larastan would use
     * @param ConnectionConfig $config
     */
    public function __construct(private Parser $parser, private ConnectionConfig $config)
    {
    }

    /**
     * @param string $file
     * @return Stmt[]
     */
    public function parseFile(string $file): array
    {
        return $this->rewrite($this->parser->parseFile($file));
    }

    /**
     * @param string $sourceCode
     * @return Stmt[]
     */
    public function parseString(string $sourceCode): array
    {
        return $this->rewrite($this->parser->parseString($sourceCode));
    }

    /**
     * Change the ClickHouse callbacks of the schema calls in the classes of a migration file.
     *
     * @param Stmt[] $statements
     * @return Stmt[]
     */
    private function rewrite(array $statements): array
    {
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf($statements, Class_::class) as $class) {
            $connection = self::connectionOf($class);
            foreach ($finder->findInstanceOf($class->stmts, Expression::class) as $statement) {
                $this->rewriteSchemaCall($statement->expr, $connection);
            }
        }

        return $statements;
    }

    /**
     * Get the connection of a migration class: the string of its $connection property, null for the default
     * connection, or false when the class does not give it as a constant.
     *
     * @param Class_ $class
     * @return string|false|null
     */
    private static function connectionOf(Class_ $class): string|false|null
    {
        foreach ($class->stmts as $statement) {
            if ($statement instanceof ClassMethod && $statement->name->toLowerString() === 'getconnection') {
                return false;
            }
        }

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() !== 'connection') {
                    continue;
                }

                return match (true) {
                    $item->default === null => null,
                    $item->default instanceof String_ => $item->default->value,
                    $item->default instanceof ConstFetch && $item->default->name->toLowerString() === 'null' => null,
                    default => false,
                };
            }
        }

        return null;
    }

    /**
     * Change a Schema facade call: the callback of create() and table() on a ClickHouse connection, and the name of
     * dropSync() and dropIfExistsSync(). The calls are found as Larastan finds them: Schema::<method>(...) and
     * Schema::connection(...)-><method>(...).
     *
     * @param Expr $expression
     * @param string|false|null $classConnection See connectionOf()
     * @return void
     */
    private function rewriteSchemaCall(Expr $expression, string|false|null $classConnection): void
    {
        if ($expression instanceof MethodCall && $expression->var instanceof StaticCall
            && self::isSchemaFacade($expression->var) && $expression->var->name instanceof Identifier
            && in_array($expression->var->name->toLowerString(), ['connection', 'setconnection'], true)
        ) {
            $call = $expression;
            $connection = self::connectionArgument($expression->var, $classConnection);
        } elseif ($expression instanceof StaticCall && self::isSchemaFacade($expression)) {
            $call = $expression;
            $connection = $classConnection;
        } else {
            return;
        }

        if (!$call->name instanceof Identifier) {
            return;
        }

        $method = $call->name->toLowerString();
        if ($method === 'dropsync' || $method === 'dropifexistssync') {
            $call->name = new Identifier($method === 'dropsync' ? 'drop' : 'dropIfExists');

            return;
        }

        $callback = self::argument($call->args, 1);
        if (!in_array($method, ['create', 'table'], true) || !$callback instanceof Closure
            || !isset($callback->params[0]) || !$this->isClickHouse($callback->params[0], $connection)
        ) {
            return;
        }

        $this->rewriteCallback($callback);
    }

    /**
     * Determine if a static call is on the Schema facade, by its alias or its class.
     *
     * @param StaticCall $call
     * @return bool
     */
    private static function isSchemaFacade(StaticCall $call): bool
    {
        if (!$call->class instanceof Name) {
            return false;
        }

        $class = $call->class->toString();

        return $class === 'Schema' || is_a($class, Schema::class, true);
    }

    /**
     * Get the connection of a Schema::connection() call: a constant string, the $connection property of the
     * migration, or false.
     *
     * @param StaticCall $call
     * @param string|false|null $classConnection
     * @return string|false|null
     */
    private static function connectionArgument(StaticCall $call, string|false|null $classConnection): string|false|null
    {
        $argument = self::argument($call->args, 0);

        return match (true) {
            $argument instanceof String_ => $argument->value,
            $argument instanceof PropertyFetch && $argument->var instanceof Variable && $argument->var->name === 'this'
                && $argument->name instanceof Identifier && $argument->name->toString() === 'connection'
                => $classConnection,
            default => false,
        };
    }

    /**
     * Determine if a schema callback is a ClickHouse callback: its parameter has the type SchemaBlueprint, or its
     * connection is a ClickHouse connection.
     *
     * @param Param $parameter
     * @param string|false|null $connection
     * @return bool
     */
    private function isClickHouse(Param $parameter, string|false|null $connection): bool
    {
        if ($parameter->type instanceof Name && is_a($parameter->type->toString(), SchemaBlueprint::class, true)) {
            return true;
        }

        return $connection !== false && $this->config->isClickHouse($connection);
    }

    /**
     * Change the blueprint calls of a ClickHouse callback.
     *
     * @param Closure $callback
     * @return void
     */
    private function rewriteCallback(Closure $callback): void
    {
        $variable = $callback->params[0]->var;
        if ($variable instanceof Variable && is_string($variable->name)) {
            $callback->stmts = $this->rewriteStatements($callback->stmts, $variable->name);
        }
    }

    /**
     * Change the blueprint calls of a list of statements, and of the if statements in it, which Larastan also reads.
     *
     * @param Stmt[] $statements
     * @param string $blueprint The name of the blueprint variable
     * @return Stmt[]
     */
    private function rewriteStatements(array $statements, string $blueprint): array
    {
        $result = [];
        foreach ($statements as $statement) {
            if ($statement instanceof If_) {
                $statement->stmts = $this->rewriteStatements($statement->stmts, $blueprint);
                foreach ($statement->elseifs as $elseIf) {
                    $elseIf->stmts = $this->rewriteStatements($elseIf->stmts, $blueprint);
                }
                if ($statement->else !== null) {
                    $statement->else->stmts = $this->rewriteStatements($statement->else->stmts, $blueprint);
                }
            }

            array_push(
                $result,
                ...($statement instanceof Expression ? $this->rewriteCall($statement, $blueprint) : [$statement])
            );
        }

        return $result;
    }

    /**
     * Change one blueprint call, such as $table->decimal('price', 10, 2)->nullable(), into the statements that
     * Larastan reads with the ClickHouse types.
     *
     * @param Expression $statement
     * @param string $blueprint
     * @return list<Stmt>
     */
    private function rewriteCall(Expression $statement, string $blueprint): array
    {
        $calls = [];
        $node = $statement->expr;
        while ($node instanceof MethodCall) {
            $calls[] = $node;
            $node = $node->var;
        }

        $root = end($calls);
        if (!$node instanceof Variable || $node->name !== $blueprint || $root === false
            || !$root->name instanceof Identifier
        ) {
            return [$statement];
        }

        $method = $root->name->toLowerString();
        if (in_array($method, self::TABLE_CLAUSES, true)) {
            return [];
        }

        $after = self::argument($root->args, 1);
        if ($method === 'after' && $after instanceof Closure) {
            $this->rewriteCallback($after);

            return [$statement];
        }

        $modifiers = [];
        foreach (array_slice($calls, 0, -1) as $call) {
            if ($call->name instanceof Identifier) {
                $modifiers[$call->name->toLowerString()] = $call;
            }
        }

        $columns = $this->columnsOf($method, $root->args, isset($modifiers['unsigned']));
        if ($columns === null) {
            return [$statement];
        }

        if (isset($modifiers['ephemeral']) && !self::isFalse(self::argument($modifiers['ephemeral']->args, 0))) {
            return array_map(
                static fn (array $column): Stmt => self::blueprintCall($blueprint, 'dropColumn', [$column['name']]),
                $columns
            );
        }

        if (count($columns) > 1) {
            return array_map(static fn (array $column): Stmt => self::columnStatement($blueprint, $column), $columns);
        }

        $column = $columns[0];
        $newName = isset($modifiers['renameto']) ? self::argument($modifiers['renameto']->args, 0) : null;
        $nullable = $column['nullable'] || array_intersect(array_keys($modifiers), self::GENERATED) !== [];
        if ($column['method'] === null && !$nullable && !$newName instanceof String_) {
            return [$statement];
        }

        $expression = $statement->expr;
        if ($column['method'] !== null) {
            $call = self::columnCall($blueprint, $column);
            if (count($calls) === 1) {
                $expression = $call;
            } else {
                $calls[count($calls) - 2]->var = $call;
            }
        }

        $result = [new Expression($nullable ? new MethodCall($expression, 'nullable') : $expression)];
        if ($newName instanceof String_) {
            $result[] = self::blueprintCall($blueprint, 'renameColumn', [$column['name'], $newName->value]);
        }

        return $result;
    }

    /**
     * Get the columns that a blueprint method adds, with the method that Larastan reads with their ClickHouse type,
     * or null as the method when Larastan reads the call with that type already. Null for a method that adds no
     * column, or whose column name is not a constant.
     *
     * @param string $method The blueprint method, in lowercase
     * @param array<int, Arg|\PhpParser\Node\VariadicPlaceholder> $arguments
     * @param bool $unsigned Whether the call has the unsigned() modifier
     * @return non-empty-list<array{name: string, method: string|null, values: list<string>|null, nullable: bool}>|null
     */
    private function columnsOf(string $method, array $arguments, bool $unsigned): ?array
    {
        if (in_array($method, ['morphs', 'nullablemorphs'], true)) {
            $method = match (Builder::$defaultMorphKeyType) {
                'uuid' => str_replace('morphs', 'uuidmorphs', $method),
                'ulid' => str_replace('morphs', 'ulidmorphs', $method),
                default => str_replace('morphs', 'numericmorphs', $method),
            };
        }

        $name = self::argument($arguments, 0);
        if (preg_match('/^(nullable)?(numeric|uuid|ulid)morphs$/', $method, $match) === 1) {
            return $name instanceof String_ ? [
                self::column($name->value . '_type', 'string', $match[1] !== ''),
                self::column($name->value . '_id', $match[2] === 'numeric' ? self::MIXED : 'string', $match[1] !== ''),
            ] : null;
        }

        if ($method === 'foreignidfor') {
            $column = self::foreignIdForColumn($name, self::argument($arguments, 1));

            return $column === null ? null : [$column];
        }

        if ($method === 'addcolumn') {
            $type = $name;
            $name = self::argument($arguments, 1);
            $parameters = self::constantArray(self::argument($arguments, 2));
            if (!$type instanceof String_ || !$name instanceof String_) {
                return null;
            }

            $method = strtolower($type->value);
            $unsigned = $unsigned || ($parameters['unsigned'] ?? null) === true;
            $values = is_array($parameters['allowed'] ?? null) ? $parameters['allowed'] : null;
        } else {
            $values = self::constantArray(self::argument($arguments, 1));
            $unsigned = $unsigned || self::isTrue(self::argument($arguments, 2, 'unsigned'));
        }

        $columnName = match (true) {
            $name instanceof String_ => $name->value,
            $name === null && isset(self::DEFAULT_NAMES[$method]) => self::DEFAULT_NAMES[$method],
            default => null,
        };
        if ($columnName === null) {
            return null;
        }

        if ($method === 'rawcolumn') {
            $definition = self::argument($arguments, 1);

            return [$definition instanceof String_
                ? $this->rawColumn($columnName, $definition->value)
                : self::column($columnName, self::MIXED)];
        }

        if ($method === 'enum' && $values !== null) {
            return [self::column($columnName, 'enum', false, self::enumNames($values))];
        }

        $floatsNullable = $this->config->hasReadOnlyClickHouseConnection();

        return [match (true) {
            in_array($method, ['id', 'bigincrements', 'unsignedbiginteger', 'foreignid'], true),
            $method === 'biginteger' && $unsigned => self::column($columnName, self::MIXED),
            in_array($method, ['integer', 'tinyinteger', 'smallinteger', 'mediuminteger'], true) && $unsigned,
            $method === 'year' => self::column($columnName, 'unsignedInteger'),
            in_array($method, ['decimal', 'unsigneddecimal', 'ipv4', 'ipv6', 'date32', 'timestamptz', 'timetz'], true)
            => self::column($columnName, 'string'),
            in_array($method, ['float', 'double'], true) && $floatsNullable
            => self::column($columnName, 'float', true),
            default => self::column($columnName, null),
        }];
    }

    /**
     * Get the column of foreignIdFor(): a foreignId() for a model with an integer key, which is UInt64, else a
     * foreignUuid() or foreignUlid(), which is a string. A model that cannot be loaded gives a mixed column with the
     * name that Larastan gives it. Null when the model is not a constant, as Larastan then reads no column.
     *
     * @param Expr|null $model
     * @param Expr|null $column
     * @return array{name: string, method: string|null, values: list<string>|null, nullable: bool}|null
     */
    private static function foreignIdForColumn(?Expr $model, ?Expr $column): ?array
    {
        $class = match (true) {
            $model instanceof ClassConstFetch && $model->class instanceof Name => $model->class->toString(),
            $model instanceof String_ => $model->value,
            default => null,
        };
        if ($class === null) {
            return null;
        }

        $instance = null;
        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            try {
                $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            } catch (Throwable) {
                $instance = null;
            }
        }

        $name = match (true) {
            $column instanceof String_ => $column->value,
            $instance instanceof Model => $instance->getForeignKey(),
            default => Str::snake(class_basename($class)) . '_id',
        };

        return self::column($name, $instance instanceof Model && $instance->getKeyType() !== 'int' ? 'string' : self::MIXED);
    }

    /**
     * Get the column of rawColumn(), with the type that a schema dump gives the same ClickHouse type.
     *
     * @param string $name
     * @param string $type
     * @return array{name: string, method: string|null, values: list<string>|null, nullable: bool}
     */
    private function rawColumn(string $name, string $type): array
    {
        [$larastanType, $options, $nullable, $values] = ClickHouseSqlParser::convert(
            trim($type),
            $this->config->hasReadOnlyClickHouseConnection()
        );

        return match ($larastanType) {
            'INT' => self::column($name, in_array('unsigned', $options, true) ? 'unsignedInteger' : 'integer', $nullable),
            'DOUBLE' => self::column($name, 'float', $nullable),
            'BOOLEAN' => self::column($name, 'boolean', $nullable),
            'ENUM' => self::column($name, 'enum', $nullable, $values),
            'VARCHAR' => self::column($name, 'string', $nullable),
            default => self::column($name, self::MIXED, $nullable),
        };
    }

    /**
     * Get the statement of a new column, with nullable() when the column is nullable.
     *
     * @param string $blueprint
     * @param array{name: string, method: string|null, values: list<string>|null, nullable: bool} $column
     * @return Stmt
     */
    private static function columnStatement(string $blueprint, array $column): Stmt
    {
        $call = self::columnCall($blueprint, $column);

        return new Expression($column['nullable'] ? new MethodCall($call, 'nullable') : $call);
    }

    /**
     * Get the call of the column method that Larastan reads with the type of the column: enum() with the names of
     * the values, else addColumn() with the method, as $table->addColumn('string', 'price').
     *
     * @param string $blueprint
     * @param array{name: string, method: string|null, values: list<string>|null, nullable: bool} $column
     * @return MethodCall
     */
    private static function columnCall(string $blueprint, array $column): MethodCall
    {
        if ($column['method'] === 'enum') {
            return new MethodCall(new Variable($blueprint), 'enum', [
                new Arg(new String_($column['name'])),
                new Arg(new Array_(array_map(
                    static fn (string $value): ArrayItem => new ArrayItem(new String_($value)),
                    $column['values'] ?? []
                ))),
            ]);
        }

        return new MethodCall(new Variable($blueprint), 'addColumn', [
            new Arg(new String_($column['method'] ?? self::MIXED)),
            new Arg(new String_($column['name'])),
        ]);
    }

    /**
     * @param string $blueprint
     * @param string $method
     * @param list<string> $arguments
     * @return Expression
     */
    private static function blueprintCall(string $blueprint, string $method, array $arguments): Expression
    {
        return new Expression(new MethodCall(
            new Variable($blueprint),
            $method,
            array_map(static fn (string $argument): Arg => new Arg(new String_($argument)), $arguments)
        ));
    }

    /**
     * @param string $name
     * @param string|null $method
     * @param bool $nullable
     * @param list<string>|null $values
     * @return array{name: string, method: string|null, values: list<string>|null, nullable: bool}
     */
    private static function column(string $name, ?string $method, bool $nullable = false, ?array $values = null): array
    {
        return ['name' => $name, 'method' => $method, 'values' => $values, 'nullable' => $nullable];
    }

    /**
     * Get the names of the values of an enum(), as SchemaGrammar::typeEnum() writes them: the keys when one key is a
     * string, else the values.
     *
     * @param array<int|string, mixed> $values
     * @return list<string>
     */
    private static function enumNames(array $values): array
    {
        $names = array_any($values, static fn (mixed $value, int|string $key): bool => is_string($key))
            ? array_keys($values)
            : array_values($values);

        return array_values(array_unique(array_map(
            static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
            $names
        )));
    }

    /**
     * Get the value of an argument by its position, or by its name when it has one.
     *
     * @param array<int, Arg|\PhpParser\Node\VariadicPlaceholder> $arguments
     * @param int $position
     * @param string|null $name
     * @return Expr|null
     */
    private static function argument(array $arguments, int $position, ?string $name = null): ?Expr
    {
        foreach ($arguments as $index => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return null;
            }

            if ($argument->name !== null ? $argument->name->toString() === $name : $index === $position) {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * Get the constant values of an array literal of strings, integers and booleans, by key, or null for any other
     * expression.
     *
     * @param Expr|null $expression
     * @return array<int|string, mixed>|null
     */
    private static function constantArray(?Expr $expression): ?array
    {
        if (!$expression instanceof Array_) {
            return null;
        }

        $result = [];
        $next = 0;
        foreach ($expression->items as $item) {
            $key = match (true) {
                $item->key === null => $next,
                $item->key instanceof String_ => $item->key->value,
                $item->key instanceof Int_ => $item->key->value,
                default => null,
            };
            $value = match (true) {
                $item->value instanceof String_, $item->value instanceof Int_ => $item->value->value,
                self::isTrue($item->value) => true,
                self::isFalse($item->value) => false,
                $item->value instanceof Array_ => self::constantArray($item->value),
                default => null,
            };
            if ($key === null || $item->unpack) {
                return null;
            }

            $result[$key] = $value;
            if (is_int($key) || is_numeric($key) && (string) (int) $key === $key) {
                $next = max($next, (int) $key + 1);
            }
        }

        return $result;
    }

    /**
     * @param Expr|null $expression
     * @return bool
     */
    private static function isTrue(?Expr $expression): bool
    {
        return $expression instanceof ConstFetch && $expression->name->toLowerString() === 'true';
    }

    /**
     * @param Expr|null $expression
     * @return bool
     */
    private static function isFalse(?Expr $expression): bool
    {
        return $expression instanceof ConstFetch && $expression->name->toLowerString() === 'false';
    }
}
