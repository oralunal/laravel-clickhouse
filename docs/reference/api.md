# API reference

<!-- tests/Unit/Documentation/ApiReference.php writes this page. Run `composer docs:api` after a change of the public API. -->

This page lists the public classes, constants and methods of the package, with their signatures.
The guide pages tell how to use them. A test makes sure that this page agrees with the code.

The methods are in alphabetical order. A class lists only its own methods. The methods of a parent class or a trait of the package are under that class or trait.

## Models

### Eloquent\Builder

Class `Oralunal\LaravelClickHouse\Eloquent\Builder`, extends `Illuminate\Database\Eloquent\Builder`.
See [the guide](/models/eloquent).

```php
delete(?bool $lightweight = null, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null)
forceDelete(?bool $lightweight = null, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null)
getStatement(Enums\Format|string|null $format = null): ClickHouseDB\Statement
insertFiles(array|string $paths, Enums\Format|string $format = Format::Csv, array $columns = []): array
optimize(bool $final = false, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): bool
update(array $values, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null)
```

### Eloquent\Model

Abstract class `Oralunal\LaravelClickHouse\Eloquent\Model`, extends `Illuminate\Database\Eloquent\Model`.
See [the guide](/models/eloquent).

```php
static buffer(array $rowOrRows): void
static bufferCount(): int
static clearBuffer(): void
static flushAllBuffers(bool $silent = false): void
static flushBuffer(): bool
fromDateTime($value)
static getBufferedRows(): array
newEloquentBuilder($query)
static optimize(bool $final = false, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): bool
```

## Query builder

### QueryBuilder

Class `Oralunal\LaravelClickHouse\QueryBuilder`, extends `Illuminate\Database\Query\Builder`.
See [the guide](/query-builder/basics).

```php
aggregate($function, $columns = ['*'])
allInnerJoin($table, $first, $operator = null, $second = null): static
allInnerJoinSub($query, $as, $first, $operator = null, $second = null): static
allLeftJoin($table, $first, $operator = null, $second = null): static
allLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
allRightJoin($table, $first, $operator = null, $second = null): static
allRightJoinSub($query, $as, $first, $operator = null, $second = null): static
antiLeftJoin($table, $first, $operator = null, $second = null): static
antiLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
antiRightJoin($table, $first, $operator = null, $second = null): static
antiRightJoinSub($query, $as, $first, $operator = null, $second = null): static
anyInnerJoin($table, $first, $operator = null, $second = null): static
anyInnerJoinSub($query, $as, $first, $operator = null, $second = null): static
anyLeftJoin($table, $first, $operator = null, $second = null): static
anyLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
anyRightJoin($table, $first, $operator = null, $second = null): static
anyRightJoinSub($query, $as, $first, $operator = null, $second = null): static
arrayJoin($arrays, ?string $as = null): static
arrayJoinSub($query, string $as): static
asofJoin($table, $first, $operator = null, $second = null): static
asofJoinSub($query, $as, $first, $operator = null, $second = null): static
asofLeftJoin($table, $first, $operator = null, $second = null): static
asofLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
delete($id = null, ?bool $lightweight = null, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null)
except($query): static
exceptDistinct($query): static
exists()
final(bool $final = true): static
from($table, $as = null, ?bool $final = null)
fullJoin($table, $first, $operator = null, $second = null): static
fullJoinSub($query, $as, $first, $operator = null, $second = null): static
getStatement(Enums\Format|string|null $format = null): ClickHouseDB\Statement
havingEmpty($columns, $boolean = 'and', $not = false): static
havingNotEmpty($columns, $boolean = 'and'): static
innerJoin($table, $first, $operator = null, $second = null): static
innerJoinSub($query, $as, $first, $operator = null, $second = null): static
insert(array $values, Enums\Format|string|null $format = null)
insertFiles(array|string $paths, Enums\Format|string $format = Format::Csv, array $columns = []): array
insertGetId(array $values, $sequence = null)
intersect($query): static
intersectDistinct($query): static
leftArrayJoin($arrays, ?string $as = null): static
leftArrayJoinSub($query, string $as): static
limitBy(int $count, Illuminate\Contracts\Database\Query\Expression|array|string ...$columns): static
limitByWithOffset(int $count, ?int $offset, Illuminate\Contracts\Database\Query\Expression|array|string ...$columns): static
onCluster(string $cluster): static
optimize(bool $final = false, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): bool
orHavingEmpty($columns): static
orHavingNotEmpty($columns): static
orPreWhere($column, $operator = null, $value = null): static
orPreWhereBetween($column, iterable $values): static
orPreWhereIn($column, $values): static
orPreWhereNotBetween($column, iterable $values): static
orPreWhereNotIn($column, $values): static
orPreWhereNotNull($columns): static
orPreWhereNull($columns): static
orPreWhereRaw($sql, $bindings = []): static
orWhereEmpty($columns): static
orWhereGlobalIn($column, $values): static
orWhereGlobalNotIn($column, $values): static
orWhereNotEmpty($columns): static
pluck($column, $key = null)
preWhere($column, $operator = null, $value = null, $boolean = 'and'): static
preWhereBetween($column, iterable $values, $boolean = 'and', $not = false): static
preWhereIn($column, $values, $boolean = 'and', $not = false): static
preWhereNotBetween($column, iterable $values, $boolean = 'and'): static
preWhereNotIn($column, $values, $boolean = 'and'): static
preWhereNotNull($columns, $boolean = 'and'): static
preWhereNull($columns, $boolean = 'and', $not = false): static
preWhereRaw($sql, $bindings = [], $boolean = 'and'): static
sample(int|float $coefficient, int|float|null $offset = null): static
semiLeftJoin($table, $first, $operator = null, $second = null): static
semiLeftJoinSub($query, $as, $first, $operator = null, $second = null): static
semiRightJoin($table, $first, $operator = null, $second = null): static
semiRightJoinSub($query, $as, $first, $operator = null, $second = null): static
settings(array|string $settings, mixed $value = null): static
unionDistinct($query): static
update(array $values, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null)
whereEmpty($columns, $boolean = 'and', $not = false): static
whereFullText($columns, $value, array $options = [], $boolean = 'and')
whereGlobalIn($column, $values, $boolean = 'and', $not = false): static
whereGlobalNotIn($column, $values, $boolean = 'and'): static
whereNotEmpty($columns, $boolean = 'and'): static
withAlias(string $alias, mixed $value): static
withExpression(string $name, $query): static
withoutOnCluster(): static
withRecursiveExpression(string $name, $query): static
```

## Schema and migrations

### ClickhouseMigrationRepository

Class `Oralunal\LaravelClickHouse\ClickhouseMigrationRepository`, extends `Illuminate\Database\Migrations\DatabaseMigrationRepository`.
See [the guide](/schema/migration-commands#the-migrations-table-on-clickhouse).

```php
createRepository(): void
delete($migration): void
deleteRepository(): void
static fromLaravelRepository(Illuminate\Database\Migrations\DatabaseMigrationRepository $repository): ClickhouseMigrationRepository
getLast()
getMigrations($steps)
getMigrationsByBatch($batch)
log($file, $batch): void
```

### SchemaBlueprint

Class `Oralunal\LaravelClickHouse\SchemaBlueprint`, extends `Illuminate\Database\Schema\Blueprint`.
See [the guide](/schema/schema-builder).

```php
addColumn($type, $name, array $parameters = []): SchemaColumnDefinition
array(string $column, string $type): SchemaColumnDefinition
date32(string $column): SchemaColumnDefinition
ifNotExists(bool $ifNotExists = true): void
ipv4(string $column = 'ip_address'): SchemaColumnDefinition
ipv6(string $column = 'ip_address'): SchemaColumnDefinition
map(string $column, string $keyType, string $valueType): SchemaColumnDefinition
orderBy(Illuminate\Contracts\Database\Query\Expression|array|string ...$columns): Illuminate\Support\Fluent
partitionBy(Illuminate\Contracts\Database\Query\Expression|string $expression): Illuminate\Support\Fluent
replicated(bool $replicated = true): void
sampleBy(Illuminate\Contracts\Database\Query\Expression|string $expression): Illuminate\Support\Fluent
settings(array $settings): Illuminate\Support\Fluent
ttl(Illuminate\Contracts\Database\Query\Expression|string $expression): Illuminate\Support\Fluent
withoutOnCluster(): void
```

### SchemaBuilder

Class `Oralunal\LaravelClickHouse\SchemaBuilder`, extends `Illuminate\Database\Schema\Builder`.
See [the guide](/schema/schema-builder).

```php
createDatabase($name): bool
disableForeignKeyConstraints(): bool
dropAllTables(): void
dropAllViews(): void
dropDatabaseIfExists($name): bool
dropIfExistsSync(string $table): void
dropSync(string $table): void
dropTemporary(string $table): void
dropTemporaryIfExists(string $table): void
enableForeignKeyConstraints(): bool
getCurrentSchemaListing(): array
hasDictionary(string $dictionary): bool
hasTable($table): bool
hasTemporaryTable(string $table): bool
```

### SchemaColumnDefinition

Class `Oralunal\LaravelClickHouse\SchemaColumnDefinition`, extends `Illuminate\Database\Schema\ColumnDefinition`.
See [the guide](/schema/schema-builder).

It has no public constants or methods of its own.

## Connections

### Cluster

Class `Oralunal\LaravelClickHouse\Cluster`.
See [the guide](/advanced/clusters#change-the-active-node).

```php
new Cluster(array $nodeConfigs)
getActiveNode(): ClickHouseDB\Client
isActiveNodePinned(): bool
pinActiveNode(): void
slideNode(): void
unpinActiveNode(): void
write(string $sql, array $bindings = [], bool $exception = true): ?ClickHouseDB\Statement
```

### Connection

Class `Oralunal\LaravelClickHouse\Connection`, extends `Illuminate\Database\Connection`.
See [the guide](/getting-started/configuration#read-the-options).

```php
const DEFAULT_NAME = 'clickhouse';

affectingStatement($query, $bindings = []): int
beginTransaction(): never
commit(): never
static createWithClient(array $config): Connection
cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): Generator
escape($value, $binary = false)
formatDateTime(DateTimeInterface $value): string
getClient(): ClickHouseDB\Client
getCluster(): Cluster
getClusterName(): ?string
getDateTimePrecision(): Enums\DateTimePrecision
getDefaultCluster(): ?string
getDriverTitle(): string
getInsertFormat(): Enums\Format
getSchemaBuilder()
getSchemaState(?Illuminate\Filesystem\Filesystem $files = null, ?callable $processFactory = null): SchemaState
getServerVersion(): string
hasClusterNodes(): bool
hasTemporaryTable(string $table): bool
inSession(): bool
newLiteralGrammar(): LiteralGrammar
prepareBindings(array $bindings)
prepareSelectForClient(string $query, array $bindings): array
query()
runBeforeExecutingCallbacks(string $query, array $bindings): void
select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
selectParallelly(array $queries, int $concurrency = Parallel::DEFAULT_CONCURRENCY): array
selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): never
selectStatement(string $query, array $bindings = []): ClickHouseDB\Statement
session(callable $callback, int $timeout = 60): mixed
statement($query, $bindings = []): bool
statementOnEveryNode(string $query): bool
table($table, $as = null, ?bool $final = null)
transaction(Closure $callback, $attempts = 1): never
unprepared($query): bool
withoutOnCluster(callable $callback): mixed
```

### LiteralGrammar

Class `Oralunal\LaravelClickHouse\LiteralGrammar`.
See [the guide](/reference/helpers#literals).

```php
compileLiteral(mixed $value): string
compileSettings(array $settings): string
compileStringLiteral(string $value): string
escapeInsertColumns(array $columns): array
formatDateTime(DateTimeInterface $value): string
getDateTimePrecision(): Enums\DateTimePrecision
getLaravelExpressionValue(Illuminate\Contracts\Database\Query\Expression $expression): string|int|float
hasSubSecondPart(DateTimeInterface $value): bool
quoteIdentifier(string $name): string
quoteString(string $value): string
setDateTimePrecision(Enums\DateTimePrecision $precision): static
setLaravelGrammarResolver(?Closure $resolver): static
```

### Parallel

Class `Oralunal\LaravelClickHouse\Parallel`.
See [the guide](/advanced/parallel-queries).

```php
const DEFAULT_CONCURRENCY = 10;

static get(array $queries, int $concurrency = self::DEFAULT_CONCURRENCY): array
static getRows(array $queries, int $concurrency = self::DEFAULT_CONCURRENCY): array
```

## Enums and exceptions

### Enums\DateTimePrecision

Enum `Oralunal\LaravelClickHouse\Enums\DateTimePrecision`, implements `BackedEnum`, `UnitEnum`.
See [the guide](/reference/helpers#enums).

```php
const Second = new Enums\DateTimePrecision();
const Microsecond = new Enums\DateTimePrecision();
```

### Enums\Format

Enum `Oralunal\LaravelClickHouse\Enums\Format`, implements `BackedEnum`, `UnitEnum`.
See [the guide](/reference/helpers#enums).

```php
const BlockTabSeparated = new Enums\Format();
const Csv = new Enums\Format();
const CsvWithNames = new Enums\Format();
const CsvWithNamesAndTypes = new Enums\Format();
const Json = new Enums\Format();
const JsonCompact = new Enums\Format();
const JsonCompactEachRow = new Enums\Format();
const JsonEachRow = new Enums\Format();
const Native = new Enums\Format();
const Null = new Enums\Format();
const Pretty = new Enums\Format();
const PrettyCompact = new Enums\Format();
const PrettyCompactMonoBlock = new Enums\Format();
const PrettyNoEscapes = new Enums\Format();
const PrettyCompactNoEscapes = new Enums\Format();
const PrettySpaceNoEscapes = new Enums\Format();
const PrettySpace = new Enums\Format();
const RowBinary = new Enums\Format();
const TabSeparated = new Enums\Format();
const TabSeparatedRaw = new Enums\Format();
const TabSeparatedWithNames = new Enums\Format();
const TabSeparatedWithNamesAndTypes = new Enums\Format();
const Tskv = new Enums\Format();
const Tsv = new Enums\Format();
const TsvWithNamesAndTypes = new Enums\Format();
const Values = new Enums\Format();
const Vertical = new Enums\Format();
const Xml = new Enums\Format();

static named(string $name): Enums\Format
static tryNamed(string $name): ?Enums\Format
```

### Exceptions\ParallelQueryException

Class `Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException`, extends `Exceptions\QueryException`.
See [the guide](/reference/helpers#exceptions).

```php
new ParallelQueryException(array $results, array $errors, array $statements = [])
getErrors(): array
getResults(): array
getStatements(): array
```

### Exceptions\QueryException

Class `Oralunal\LaravelClickHouse\Exceptions\QueryException`, extends `ClickHouseDB\Exception\QueryException`.
See [the guide](/reference/helpers#exceptions).

```php
static cannotCreateTemporaryTableOutsideSession(string $table): Exceptions\QueryException
static cannotDeleteWithoutWhere(): Exceptions\QueryException
static cannotInsertIntoJsonPath(string $column): Exceptions\QueryException
static cannotMutateWithClauses(string $statement, array $clauses, array $methodsThatLeaveALimit = [], ?string $methodThatAddsALimit = null, array $refusedSettings = []): Exceptions\QueryException
static cannotReachTemporaryTable(string $statement, string $table, string $alternative): Exceptions\QueryException
static cannotRunAsynchronouslyInSession(): Exceptions\QueryException
static cannotUpdateEmptyValues(): Exceptions\QueryException
static cannotUpdateJsonPath(string $selector, string $reason): Exceptions\QueryException
static cannotUpdateWithoutWhere(): Exceptions\QueryException
static insertKeysDiffer(string|int $index, array $keys, array $expectedKeys, string $format = 'JSONEachRow'): Exceptions\QueryException
static sessionIsLocked(string $sessionId, Throwable $previous): Exceptions\QueryException
static sessionNotFound(string $sessionId, int $timeout, Throwable $previous): Exceptions\QueryException
```

## Internal classes

The package and Laravel call the methods of these classes. The guide pages do not describe all of them.

### ClickhouseServiceProvider

Class `Oralunal\LaravelClickHouse\ClickhouseServiceProvider`, extends `Illuminate\Support\ServiceProvider`.
See [the guide](/getting-started/installation).

```php
boot(): void
register(): void
```

### ClientRequests

Final class `Oralunal\LaravelClickHouse\ClientRequests`.

```php
static clientSelectsAsIntended(string $sql, ?string $format): bool
static encodeJsonEachRow(array $rows, JsonEachRowEncoder $encoder): array
static pretendedStatement(string $sql): ClickHouseDB\Statement
static select(ClickHouseDB\Client $client, string $sql, array $bindings = [], ?string $format = null): ClickHouseDB\Statement
static selectIntoStream(ClickHouseDB\Client $client, string $sql, array $bindings, mixed $stream): ClickHouseDB\Statement
static selectRequest(ClickHouseDB\Client $client, string $sql, array $bindings = [], ?string $format = null): ClickHouseDB\Transport\CurlerRequest
static selectStatement(ClickHouseDB\Transport\CurlerRequest $request): ClickHouseDB\Statement
static sendInsert(ClickHouseDB\Client $client, string $head, string $body, string $format): ClickHouseDB\Statement
static write(ClickHouseDB\Client $client, Connection $connection, string $sql, array $bindings = []): ClickHouseDB\Statement
```

### Concerns\CompilesJsonPaths

Trait `Oralunal\LaravelClickHouse\Concerns\CompilesJsonPaths`.

It has no public constants or methods of its own.

### Concerns\SubstitutesBindings

Trait `Oralunal\LaravelClickHouse\Concerns\SubstitutesBindings`.

```php
compileLiteral(mixed $value): string
prepareQueryForClient(string $query, array $bindings): array
substituteBindings(string $sql, array $bindings): string
substituteBindingsIntoRawSql($sql, $bindings)
```

### Console\FreshCommand

Class `Oralunal\LaravelClickHouse\Console\FreshCommand`, extends `Illuminate\Database\Console\Migrations\FreshCommand`.
See [the guide](/schema/migration-commands).

```php
handle()
```

### Console\InstallSkillsCommand

Class `Oralunal\LaravelClickHouse\Console\InstallSkillsCommand`, extends `Illuminate\Console\Command`.
See [the guide](/reference/agent-skills).

```php
const AGENTS = [/* 14 values */];
const RETIRED_SKILLS = ['plc-upgrade-1x-to-2x', 'lc-upgrade-2x-to-3x'];

new InstallSkillsCommand(Illuminate\Filesystem\Filesystem $files)
handle(): int
```

### CurlerRollingInSession

Class `Oralunal\LaravelClickHouse\CurlerRollingInSession`, extends `CurlerRollingWithRetries`.
See [the guide](/advanced/sessions).

```php
new CurlerRollingInSession(int $retries = 0)
addQueLoop(ClickHouseDB\Transport\CurlerRequest $req, bool $checkMultiAdd = true, bool $force = false): bool
setRetryOn(string $retryOn): void
```

### CurlerRollingWithRetries

Class `Oralunal\LaravelClickHouse\CurlerRollingWithRetries`, extends `ClickHouseDB\Transport\CurlerRolling`.
See [the guide](/advanced/timeouts-and-retries#retries).

```php
const RETRY_ON_ANY = 'any';
const RETRY_ON_READS = 'reads';
const RETRY_ON_UNSENT = 'unsent';

execOne(ClickHouseDB\Transport\CurlerRequest $request, bool $auto_close = false): int
getRetries(): int
getRetryOn(): string
mayRetry(ClickHouseDB\Transport\CurlerRequest $request): bool
setRetries(int $retries): void
setRetryOn(string $retryOn): void
```

### JsonEachRowEncoder

Final class `Oralunal\LaravelClickHouse\JsonEachRowEncoder`.
See [the guide](/models/inserting-rows#jsoneachrow-inserts).

```php
const JSON_FLAGS = 4194624;

new JsonEachRowEncoder(Closure $formatDateTime)
encodeCompactRows(array $rows): string
encodeRows(array $rows): string
normalizeValue(mixed $value): mixed
```

### QueryGrammar

Class `Oralunal\LaravelClickHouse\QueryGrammar`, extends `Illuminate\Database\Query\Grammars\Grammar`, uses `Concerns\CompilesJsonPaths`, `Concerns\SubstitutesBindings`.

```php
const MUTATION_SETTINGS = [/* 20 values */];

compileDelete(Illuminate\Database\Query\Builder $query)
compileExists(Illuminate\Database\Query\Builder $query)
compileInsert(Illuminate\Database\Query\Builder $query, array $values)
compileInsertUsing(Illuminate\Database\Query\Builder $query, array $columns, string $sql)
compileLiteral(mixed $value): string
compileOptimize(QueryBuilder $query, bool $final, Illuminate\Contracts\Database\Query\Expression|string|int|null $partition): string
compileRandom($seed)
compileSelect(Illuminate\Database\Query\Builder $query)
compileThreadCount()
compileTruncate(Illuminate\Database\Query\Builder $query)
compileUpdate(Illuminate\Database\Query\Builder $query, array $values)
compileVectorDistanceExpression($column)
getFullTextFunction(array $options): string
hasAnOrderThatAddsRows(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatCallsArrayJoin(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatDefinesAnAlias(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatLimitsRows(Illuminate\Database\Query\Builder $query): bool
parameter($value)
prepareBindingForJsonContains($binding)
prepareBindingsForDelete(array $bindings)
prepareBindingsForUpdate(array $bindings, array $values)
refuseJsonPathsInInsert(array $columns): void
selectsAnAliasOrAnExpression(Illuminate\Database\Query\Builder $query): bool
supportsVectorDistance()
whereFullText(Illuminate\Database\Query\Builder $query, $where)
```

### QueryProcessor

Class `Oralunal\LaravelClickHouse\QueryProcessor`, extends `Illuminate\Database\Query\Processors\Processor`.

```php
processColumns($results): array
processIndexes($results): array
processInsertGetId(Illuminate\Database\Query\Builder $query, $sql, $values, $sequence = null): never
static typeAcceptsNull(string $type): bool
```

### SchemaGrammar

Class `Oralunal\LaravelClickHouse\SchemaGrammar`, extends `Illuminate\Database\Schema\Grammars\Grammar`, uses `Concerns\CompilesJsonPaths`.

```php
compileAdd(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileChange(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): array
compileColumns($schema, $table): string
compileCreate(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDictionaryExists(?string $schema, string $dictionary): string
compileDrop(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropColumn(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropForeign(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropFullText(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileDropIfExists(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileDropPrimary(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileDropSpatialIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropUnique(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropVectorIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileForeign(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileForeignKeys($schema, $table): string
compileFulltext(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): array
compileIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?array
compileIndexes($schema, $table): string
compileOrderBy(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compilePartitionBy(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compilePrimary(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileRename(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileRenameColumn(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileRenameIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileSampleBy(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileSettings(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileSpatialIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileTableComment(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileTableExists($schema, $table): string
compileTables($schema): string
compileTemporaryTableExists(string $table): string
compileTtl(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileUnique(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileVectorIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): array
compileViews($schema): string
static quoteIdentifier(string $identifier): string
quoteString($value): string
wrap($value): string
```

### SchemaState

Class `Oralunal\LaravelClickHouse\SchemaState`, extends `Illuminate\Database\Schema\SchemaState`.
See [the guide](/schema/migration-commands).

```php
dump(Illuminate\Database\Connection $connection, $path): void
load($path): void
```

### SecondaryConnections

Class `Oralunal\LaravelClickHouse\SecondaryConnections`.
See [the guide](/schema/migration-commands).

```php
new SecondaryConnections(Illuminate\Contracts\Foundation\Application $app, Illuminate\Filesystem\Filesystem $files)
dump(Illuminate\Database\Events\SchemaDumped $event): void
load(Illuminate\Database\Events\SchemaLoaded $event): void
names(?string $primary = null): array
wipe(string $name): void
```

### Testing\ParallelTestDatabases

Class `Oralunal\LaravelClickHouse\Testing\ParallelTestDatabases`.
See [the guide](/advanced/testing).

```php
new ParallelTestDatabases(Illuminate\Contracts\Foundation\Application $app)
register(Illuminate\Testing\ParallelTesting $parallelTesting): void
```

