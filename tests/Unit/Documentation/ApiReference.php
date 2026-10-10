<?php

namespace Tests\Unit\Documentation;

use ReflectionClass;
use ReflectionClassConstant;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Builds docs/reference/api.md, the list of the public classes, constants, methods and functions of the package,
 * from the code, and finds the public methods that no guide page names.
 *
 * Run `composer docs:api` to write the page again after a change of the public API.
 */
final class ApiReference
{
    public const PAGE = __DIR__ . '/../../../docs/reference/api.md';

    public const DOCS = __DIR__ . '/../../../docs';

    private const SOURCE = __DIR__ . '/../../../src';

    private const NAMESPACE = 'Oralunal\\LaravelClickHouse\\';

    /**
     * The sections of the page, in order: the title, then the classes (relative to the package namespace) or the
     * namespace prefixes (ending with a backslash) that the section holds, each with its guide page or null.
     * A class goes in the first section that names it or a prefix of it.
     *
     * @var array<string, array<string, string|null>>
     */
    private const SECTIONS = [
        'Models' => [
            'BaseModel' => '/models/defining-models',
            'Concerns\\HasAttributes' => '/models/defining-models#attribute-methods',
            'Concerns\\HasEvents' => '/models/defining-models#events',
            'Concerns\\HasBufferedInserts' => '/models/inserting-rows',
            'WithClient' => '/advanced/multiple-connections',
            'RawColumn' => '/query-builder/basics#select-columns',
        ],
        'Query builder' => [
            'Builder' => '/query-builder/basics',
            'BuilderMethodsFromLaravel' => '/query-builder/reading-results',
            'ClickhouseBuilder\\Query\\BaseBuilder' => '/query-builder/basics',
            'ClickhouseBuilder\\Query\\Column' => '/query-builder/basics#column-expressions',
            'ClickhouseBuilder\\Query\\From' => '/query-builder/basics#sub-queries-and-table-functions',
            'ClickhouseBuilder\\Query\\JoinClause' => '/query-builder/clickhouse-sql#join-closures',
            'ClickhouseBuilder\\Query\\ArrayJoinClause' => '/query-builder/clickhouse-sql#array-join',
            'ClickhouseBuilder\\Query\\Limit' => '/query-builder/basics#examine-a-query',
            'ClickhouseBuilder\\Query\\Expression' => '/reference/helpers#functions',
            'QueryBuilder' => '/query-builder/laravel-query-builder',
        ],
        'Schema and migrations' => [
            'Migration' => '/schema/migrations',
            'ClickhouseSchemaBuilder\\Tables\\MergeTree' => '/schema/migrations#table-methods',
            'ClickhouseSchemaBuilder\\AddsColumns' => '/schema/migrations#column-types',
            'ClickhouseSchemaBuilder\\Column' => '/schema/migrations#column-types',
            'ClickhouseSchemaBuilder\\Expression' => '/schema/migrations#column-types',
            'SchemaBuilder' => '/schema/schema-builder',
            'SchemaBlueprint' => '/schema/schema-builder',
            'SchemaColumnDefinition' => '/schema/schema-builder',
            'ClickhouseMigrationRepository' => '/schema/migration-commands#the-migrations-table-on-clickhouse',
        ],
        'Connections' => [
            'Connection' => '/getting-started/configuration#read-the-options',
            'Cluster' => '/advanced/clusters#change-the-active-node',
            'Parallel' => '/advanced/parallel-queries',
        ],
        'Enums and exceptions' => [
            'ClickhouseBuilder\\Query\\Enums\\' => '/reference/helpers#enums',
            'Enum\\' => '/reference/helpers#enums',
            'Exceptions\\' => '/reference/helpers#exceptions',
            'ClickhouseBuilder\\Exceptions\\' => '/reference/helpers#exceptions',
            'ClickhouseSchemaBuilder\\Exceptions\\' => '/reference/helpers#exceptions',
        ],
        'Internal classes' => [
            'ClickhouseBuilder\\Query\\Grammar' => null,
            'ClickhouseBuilder\\Query\\Traits\\' => null,
            'ClickhouseBuilder\\Query\\Identifier' => null,
            'ClickhouseBuilder\\Query\\Tuple' => null,
            'ClickhouseBuilder\\Query\\TwoElementsLogicExpression' => null,
            'ClickhouseSchemaBuilder\\Element' => null,
            'ClickhouseSchemaBuilder\\Engine' => '/schema/migrations#table-methods',
            'ClickhouseSchemaBuilder\\Syntax' => null,
            'ClickhouseSchemaBuilder\\TTL' => null,
            'Grammar' => null,
            'QueryGrammar' => null,
            'SchemaGrammar' => null,
            'QueryProcessor' => null,
            'SchemaState' => '/schema/migration-commands',
            'SecondaryConnections' => '/schema/migration-commands',
            'ClientRequests' => null,
            'CurlerRollingInSession' => '/advanced/sessions',
            'CurlerRollingWithRetries' => '/advanced/timeouts-and-retries#retries',
            'JsonEachRowEncoder' => '/models/inserting-rows#jsoneachrow-inserts',
            'Expressions\\' => null,
            'Concerns\\' => null,
            'ClickhouseServiceProvider' => '/getting-started/installation',
            'Console\\FreshCommand' => '/schema/migration-commands',
            'Console\\InstallSkillsCommand' => '/reference/agent-skills',
            'Testing\\' => '/advanced/testing',
        ],
    ];

    /**
     * The section of the classes whose methods the guide pages do not have to name.
     */
    private const INTERNAL_SECTION = 'Internal classes';

    /**
     * Get the Markdown of docs/reference/api.md.
     *
     * @return string
     */
    public static function generate(): string
    {
        $lines = [
            '# API reference',
            '',
            '<!-- tests/Unit/Documentation/ApiReference.php writes this page. Run `composer docs:api` after a change of the public API. -->',
            '',
            'This page lists the public classes, constants, methods and functions of the package, with their signatures.',
            'The guide pages tell how to use them. A test makes sure that this page agrees with the code.',
            '',
            'The methods are in alphabetical order. A class lists only its own methods. The methods of a parent class or a trait of the package are under that class or trait.',
            '',
        ];

        foreach (self::classesBySection() as $section => $classes) {
            $lines[] = "## {$section}";
            $lines[] = '';

            if ($section === self::INTERNAL_SECTION) {
                $lines[] = 'The package and Laravel call the methods of these classes. The guide pages do not describe all of them.';
                $lines[] = '';
            }

            foreach ($classes as $class => $guide) {
                array_push($lines, ...self::describeClass(new ReflectionClass($class), $guide));
            }
        }

        $lines[] = '## Functions';
        $lines[] = '';
        $lines[] = 'The functions are in the `Oralunal\\LaravelClickHouse\\ClickhouseBuilder` namespace. See [Helpers](/reference/helpers#functions).';
        $lines[] = '';
        $lines[] = '```php';
        foreach (self::functions() as $function) {
            $lines[] = 'function ' . self::signature($function);
        }
        $lines[] = '```';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Get the public methods of the classes outside the internal section that no guide page names as `name(`,
     * such as `where()` or `->where(`, as Class::method.
     *
     * A method that overrides or implements a method of a class or an interface outside the package, such as
     * Laravel's Connection::prepareBindings() or JsonSerializable::jsonSerialize(), and a method with an
     * `@internal` tag do not need a guide page.
     *
     * @param string $guides The text of the guide pages
     *
     * @return list<string>
     */
    public static function methodsWithoutGuide(string $guides): array
    {
        $missing = [];

        foreach (self::classesBySection() as $section => $classes) {
            if ($section === self::INTERNAL_SECTION) {
                continue;
            }

            foreach (array_keys($classes) as $class) {
                $reflection = new ReflectionClass($class);

                foreach (self::ownMethods($reflection) as $method) {
                    $name = $method->getName();

                    if (
                        $name === '__construct'
                        || self::overridesForeignMethod($reflection, $name)
                        || str_contains((string) $method->getDocComment(), '@internal')
                        || preg_match('/(?<![\w$])' . preg_quote($name, '/') . '\(/', $guides) === 1
                    ) {
                        continue;
                    }

                    $missing[] = self::relativeName($class) . '::' . $name;
                }
            }
        }

        return $missing;
    }

    /**
     * Get the text of the guide pages of the latest version: every Markdown file of docs/ except this reference, the
     * build output and the directories of the earlier versions, such as docs/3.x.
     *
     * @return string
     */
    public static function guides(): string
    {
        $text = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DOCS, \FilesystemIterator::SKIP_DOTS));
        $paths = [];

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (
                $file->getExtension() === 'md'
                && !str_contains($path, '/node_modules/')
                && !str_contains($path, '/.vitepress/')
                && preg_match('#/docs/\d+\.x/#', $path) !== 1
                && realpath($path) !== realpath(self::PAGE)
            ) {
                $paths[] = $path;
            }
        }

        sort($paths);
        foreach ($paths as $path) {
            $text .= file_get_contents($path) . "\n";
        }

        return $text;
    }

    /**
     * Get every class, trait, interface and enum of the package by section, each with its guide page or null.
     *
     * @return array<string, array<class-string, string|null>>
     */
    public static function classesBySection(): array
    {
        $result = array_fill_keys(array_keys(self::SECTIONS), []);
        $source = (string) realpath(self::SOURCE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        $names = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'functions.php') {
                continue;
            }

            $names[] = str_replace(DIRECTORY_SEPARATOR, '\\', substr($file->getPathname(), strlen($source) + 1, -4));
        }

        sort($names);
        foreach ($names as $name) {
            [$section, $guide] = self::sectionOf($name);
            $result[$section][self::NAMESPACE . $name] = $guide;
        }

        return array_filter($result);
    }

    /**
     * Find the section and the guide page of a class.
     *
     * @param string $name The class name relative to the package namespace
     *
     * @return array{0: string, 1: string|null}
     */
    private static function sectionOf(string $name): array
    {
        foreach (self::SECTIONS as $section => $classes) {
            foreach ($classes as $pattern => $guide) {
                if ($pattern === $name || (str_ends_with($pattern, '\\') && str_starts_with($name, $pattern))) {
                    return [$section, $guide];
                }
            }
        }

        return [self::INTERNAL_SECTION, null];
    }

    /**
     * Describe a class: its heading, kind, parent, interfaces, traits, guide page, constants and methods.
     *
     * @param ReflectionClass<object> $class
     * @param string|null $guide
     *
     * @return list<string>
     */
    private static function describeClass(ReflectionClass $class, ?string $guide): array
    {
        $kind = match (true) {
            $class->isTrait() => 'Trait',
            $class->isInterface() => 'Interface',
            $class->isEnum() => 'Enum',
            $class->isAbstract() => 'Abstract class',
            $class->isFinal() => 'Final class',
            default => 'Class',
        };

        $facts = ["{$kind} `{$class->getName()}`"];

        if ($parent = $class->getParentClass()) {
            $facts[] = 'extends `' . self::relativeName($parent->getName()) . '`';
        }

        $interfaces = array_diff($class->getInterfaceNames(), $class->getParentClass() ? $class->getParentClass()->getInterfaceNames() : []);
        sort($interfaces);
        if ($interfaces !== []) {
            $facts[] = 'implements ' . implode(', ', array_map(fn (string $name): string => '`' . self::relativeName($name) . '`', $interfaces));
        }

        $traits = $class->getTraitNames();
        sort($traits);
        if ($traits !== []) {
            $facts[] = 'uses ' . implode(', ', array_map(fn (string $name): string => '`' . self::relativeName($name) . '`', $traits));
        }

        $lines = ['### ' . self::relativeName($class->getName()), '', implode(', ', $facts) . '.'];

        if ($guide !== null) {
            $lines[] = "See [the guide]({$guide}).";
        }

        $lines[] = '';

        $body = [];
        foreach ($class->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class->getName()) {
                $body[] = 'const ' . $constant->getName() . ' = ' . self::export($constant->getValue()) . ';';
            }
        }

        $methods = self::ownMethods($class);
        usort($methods, fn (ReflectionMethod $a, ReflectionMethod $b): int => [$a->getName() !== '__construct', strtolower($a->getName())] <=> [$b->getName() !== '__construct', strtolower($b->getName())]);

        if ($body !== [] && $methods !== []) {
            $body[] = '';
        }

        foreach ($methods as $method) {
            $signature = self::signature($method);
            $body[] = $method->isConstructor()
                ? 'new ' . $class->getShortName() . substr($signature, strlen('__construct'))
                : ($method->isStatic() ? 'static ' : '') . $signature;
        }

        if ($body === []) {
            $lines[] = 'It has no public constants or methods of its own.';
            $lines[] = '';

            return $lines;
        }

        return [...$lines, '```php', ...$body, '```', ''];
    }

    /**
     * Get the public methods that the file of a class declares: the constructor and the methods other than the
     * magic ones, without the methods of its traits and parents.
     *
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionMethod>
     */
    private static function ownMethods(ReflectionClass $class): array
    {
        return array_values(array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $class->getFileName()
                && ($method->getName() === '__construct' || !str_starts_with($method->getName(), '__'))
        ));
    }

    /**
     * Determine if a method overrides or implements a method of a class or an interface outside the package. For a
     * trait, the classes of the package that use it are checked.
     *
     * @param ReflectionClass<object> $class
     * @param string $method
     *
     * @return bool
     */
    private static function overridesForeignMethod(ReflectionClass $class, string $method): bool
    {
        $owners = $class->isTrait() ? self::classesUsing($class->getName()) : [$class];

        foreach ($owners as $owner) {
            $ancestors = $owner->getInterfaceNames();
            for ($parent = $owner->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                $ancestors[] = $parent->getName();
            }

            foreach ($ancestors as $ancestor) {
                if (!str_starts_with($ancestor, self::NAMESPACE) && method_exists($ancestor, $method)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the classes of the package that use a trait, also through a parent class.
     *
     * @param string $trait
     *
     * @return list<ReflectionClass<object>>
     */
    private static function classesUsing(string $trait): array
    {
        $users = [];

        foreach (self::classesBySection() as $classes) {
            foreach (array_keys($classes) as $class) {
                $reflection = new ReflectionClass($class);
                for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
                    if (in_array($trait, $current->getTraitNames(), true)) {
                        $users[] = $reflection;
                        break;
                    }
                }
            }
        }

        return $users;
    }

    /**
     * Get the functions of the package.
     *
     * @return list<ReflectionFunction>
     */
    private static function functions(): array
    {
        $functions = array_filter(
            get_defined_functions()['user'],
            fn (string $name): bool => str_starts_with($name, strtolower(self::NAMESPACE))
        );
        sort($functions);

        return array_map(fn (string $name): ReflectionFunction => new ReflectionFunction($name), array_values($functions));
    }

    /**
     * Write the signature of a method or a function: its name, parameters and return type.
     *
     * @param ReflectionFunctionAbstract $function
     *
     * @return string
     */
    private static function signature(ReflectionFunctionAbstract $function): string
    {
        $parameters = array_map(self::parameter(...), $function->getParameters());
        $return = $function->hasReturnType() ? ': ' . self::type((string) $function->getReturnType()) : '';

        return $function->getShortName() . '(' . implode(', ', $parameters) . ')' . $return;
    }

    /**
     * Write a parameter: its type, name and default value.
     *
     * @param ReflectionParameter $parameter
     *
     * @return string
     */
    private static function parameter(ReflectionParameter $parameter): string
    {
        $text = $parameter->hasType() ? self::type((string) $parameter->getType()) . ' ' : '';
        $text .= ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = ' . ($parameter->isDefaultValueConstant()
                ? self::constant((string) $parameter->getDefaultValueConstantName())
                : self::export($parameter->getDefaultValue()));
        }

        return $text;
    }

    /**
     * Write a type with the names of the package's classes relative to the package namespace, and the names of other
     * classes in full: ?Oralunal\LaravelClickHouse\Connection becomes ?Connection.
     *
     * @param string $type
     *
     * @return string
     */
    private static function type(string $type): string
    {
        return (string) preg_replace_callback(
            '/[\w\\\\]+/',
            fn (array $match): string => self::relativeName($match[0]),
            $type
        );
    }

    /**
     * Write the name of a constant with the short name of its class: Oralunal\...\Enums\Operator::AND becomes
     * Operator::AND.
     *
     * @param string $constant
     *
     * @return string
     */
    private static function constant(string $constant): string
    {
        return (string) preg_replace('/(?:\w+\\\\)+(\w+)/', '$1', $constant);
    }

    /**
     * Write a value as PHP code. A list of more than eight values is written as its size.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            if (count($value) > 8) {
                return '[/* ' . count($value) . ' values */]';
            }

            $items = array_map(
                fn ($key, $item): string => (array_is_list($value) ? '' : self::export($key) . ' => ') . self::export($item),
                array_keys($value),
                $value
            );

            return '[' . implode(', ', $items) . ']';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_float($value) && is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        if (is_object($value)) {
            return 'new ' . self::relativeName($value::class) . '()';
        }

        return var_export($value, true);
    }

    /**
     * Write a class name relative to the package namespace, or as it is for a class outside the package.
     *
     * @param string $name
     *
     * @return string
     */
    private static function relativeName(string $name): string
    {
        return str_starts_with($name, self::NAMESPACE) ? substr($name, strlen(self::NAMESPACE)) : $name;
    }
}
