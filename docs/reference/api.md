# API reference

<!-- tests/Unit/Documentation/ApiReference.php writes this page. Run `composer docs:api` after a change of the public API. -->

This page lists the public classes, constants, methods and functions of the package, with their signatures.
The guide pages tell how to use them. A test makes sure that this page agrees with the code.

The methods are in alphabetical order. A class lists only its own methods. The methods of a parent class or a trait of the package are under that class or trait.

## Models

### BaseModel

Class `Oralunal\LaravelClickHouse\BaseModel`, uses `Illuminate\Database\Eloquent\Concerns\HidesAttributes`, `Concerns\HasAttributes`, `Concerns\HasBufferedInserts`, `Concerns\HasEvents`, `WithClient`.
See [the guide](/models/defining-models).

```php
static create(array $attributes = [])
fill(array $attributes): BaseModel
getTable(): string
getTableForInserts(): string
getTableSources(): string
static insertAssoc(array $rows): ClickHouseDB\Statement
static insertBulk(array $rows, array $columns = []): ClickHouseDB\Statement
static make(array $attributes = [])
static optimize(bool $final = false, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): ClickHouseDB\Statement
static prepareAndInsert(array $rows, array $columns = []): ClickHouseDB\Statement
static prepareAndInsertAssoc(array $rows): ClickHouseDB\Statement
static prepareAndInsertBulk(array $rows, array $columns = []): ClickHouseDB\Statement
static prepareAssocFromRequest(array $row): array
static prepareFromRequest(array $row, array $columns = []): array
static query(): Builder
save(array $options = []): bool
static select($select = ['*']): Builder
static truncate(): ClickHouseDB\Statement
static where($column, $operator = null, $value = null, string $concatOperator = Operator::AND): Builder
static whereRaw(string $expression): Builder
```

### Concerns\HasAttributes

Trait `Oralunal\LaravelClickHouse\Concerns\HasAttributes`.
See [the guide](/models/defining-models#attribute-methods).

```php
append($attributes)
attributesToArray()
static cacheMutatedAttributes($classOrInstance)
static currentEncrypter()
discardChanges()
static encryptUsing($encrypter)
except($attributes)
fillJsonAttribute($key, $value)
fromDateTime($value)
fromEncryptedString($value)
fromFloat($value)
fromJson($value, $asObject = false)
getAppends()
getAttribute($key)
getAttributes()
getAttributeValue($key)
getCasts(): array
getChanges()
getDateFormat()
getDates()
getDirty()
getMutatedAttributes()
getOriginal($key = null, $default = null)
getPrevious()
getRawOriginal($key = null, $default = null)
hasAnyGetMutator($key)
hasAppended($attribute)
hasAttribute($key)
hasAttributeGetMutator($key)
hasAttributeMutator($key)
hasAttributeSetMutator($key)
hasCast($key, $types = null)
hasGetMutator($key)
hasSetMutator($key)
isClean($attributes = null)
isDirty($attributes = null)
mergeAppends(array $appends)
mergeCasts($casts)
only($attributes)
originalIsEquivalent($key)
static preventsAccessingMissingAttributes()
setAppends(array $appends)
setAttribute($key, $value)
setDateFormat($format)
setRawAttributes(array $attributes, $sync = false)
syncChanges()
syncOriginal()
syncOriginalAttribute($attribute)
syncOriginalAttributes($attributes)
toArray()
wasChanged($attributes = null)
withoutAppends()
```

### Concerns\HasBufferedInserts

Trait `Oralunal\LaravelClickHouse\Concerns\HasBufferedInserts`.
See [the guide](/models/inserting-rows).

```php
static buffer(array $rowOrRows): void
static bufferCount(): int
static clearBuffer(): void
static flushAllBuffers(bool $silent = false): void
static flushBuffer(): ?ClickHouseDB\Statement
static getBufferedRows(): array
```

### Concerns\HasEvents

Trait `Oralunal\LaravelClickHouse\Concerns\HasEvents`.
See [the guide](/models/defining-models#events).

```php
static getEventDispatcher(): ?Illuminate\Contracts\Events\Dispatcher
static setEventDispatcher(Illuminate\Contracts\Events\Dispatcher $dispatcher): void
static unsetEventDispatcher(): void
static withoutEvents(callable $callback): mixed
```

### RawColumn

Class `Oralunal\LaravelClickHouse\RawColumn`, extends `ClickhouseBuilder\Query\Expression`.
See [the guide](/query-builder/basics#select-columns).

```php
new RawColumn($value, $alias = null)
```

### WithClient

Trait `Oralunal\LaravelClickHouse\WithClient`.
See [the guide](/advanced/multiple-connections).

```php
static getClient(): ClickHouseDB\Client
getThisClient(): ClickHouseDB\Client
resolveConnection(): Connection
```

## Query builder

### Builder

Class `Oralunal\LaravelClickHouse\Builder`, extends `ClickhouseBuilder\Query\BaseBuilder`, uses `BuilderMethodsFromLaravel`, `WithClient`.
See [the guide](/query-builder/basics).

```php
new Builder(?ClickHouseDB\Client $client = null, ?string $connection = null)
chunk(int $count, callable $callback): void
count(): int
delete(?bool $lightweight = null, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): ClickHouseDB\Statement
doesntExist(): bool
exists(): bool
first(array|string $columns = ['*']): ?array
followConnectionOptions(Connection $connection): static
get(array $bindings = []): ClickHouseDB\Statement
getCollection(array $bindings = []): Illuminate\Support\Collection
getGrammar(): Grammar
getQueryClient(): ClickHouseDB\Client
getQueryForCount(): Builder
getQueryForExists(): Builder
getQueryForPage(int $perPage, int $page = 1): Builder
getQueryToSend(): Builder
getRows(array $bindings = []): array
getSettings(): array
newQuery(): Builder
paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
setSourcesTable(string $table): Builder
settings(array|string $settings, mixed $value = null): Builder
simplePaginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
truncate(): ClickHouseDB\Statement
update(array $values, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|int|null $partition = null): ClickHouseDB\Statement
value(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column): mixed
withoutOnCluster(): static
```

### BuilderMethodsFromLaravel

Trait `Oralunal\LaravelClickHouse\BuilderMethodsFromLaravel`.
See [the guide](/query-builder/reading-results).

```php
aggregate($function, $columns = ['*'])
average($column)
avg($column)
insert(array $values, ?string $format = null): ClickHouseDB\Statement
insertFiles(array|string $paths, string $format = Format::CSV, array $columns = []): array
max($column)
min($column)
pluck($column, $key = null)
sum($column)
useWritePdo()
```

### ClickhouseBuilder\Query\ArrayJoinClause

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\ArrayJoinClause`.
See [the guide](/query-builder/clickhouse-sql#array-join).

```php
new ArrayJoinClause(ClickhouseBuilder\Query\BaseBuilder $query)
array($arrayIdentifier): ClickhouseBuilder\Query\ArrayJoinClause
getArrayIdentifier()
getArrays(): array
getType(): ?ClickhouseBuilder\Query\Enums\JoinType
left(): ClickhouseBuilder\Query\ArrayJoinClause
type(string $type): ClickhouseBuilder\Query\ArrayJoinClause
```

### ClickhouseBuilder\Query\BaseBuilder

Abstract class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder`, uses `Illuminate\Support\Traits\Conditionable`.
See [the guide](/query-builder/basics).

```php
const WITH_EXPRESSION = 'expression';
const WITH_ALIAS = 'alias';

addGroupBy(...$columns)
addSelect(...$columns)
addSelectDict(string $dict, string $attribute, $key, ?string $as = null)
alias(string $alias)
allInnerJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
allLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
allRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
antiLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
antiRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
anyInnerJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
anyLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null)
anyRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
arrayJoin($arrayIdentifier)
as(string $alias)
asofJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
asofLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
asyncWithQuery($asyncQueries = null)
cloneWithout(array $except)
crossJoin($table, bool $global = false, ?string $alias = null): static
except($query): static
exceptDistinct($query): static
final(bool $final = true)
format(string $format)
from($table, ?string $alias = null, ?bool $isFinal = null)
fullJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null): static
getArrayJoin(): ?ClickhouseBuilder\Query\ArrayJoinClause
getAsyncQueries(): array
getColumns(): array
getCountQuery()
getFormat(): ?ClickhouseBuilder\Query\Enums\Format
getFrom(): ?ClickhouseBuilder\Query\From
getGroups(): array
getHavings(): array
getJoins(): ?array
getLimit(): ?ClickhouseBuilder\Query\Limit
getLimitBy(): ?ClickhouseBuilder\Query\Limit
getOnCluster(): ?string
getOrders(): array
getPreWheres(): array
getSample(): ?float
getSampleOffset(): ?float
getUnions(): array
getUnionTypes(): array
getWheres(): array
getWiths(): array
groupBy(...$columns)
having($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
havingBetween($column, array $values, $boolean = Operator::AND, $not = false)
havingBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
havingEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
havingIn($column, $values, $boolean = Operator::AND, $not = false)
havingNotBetween($column, array $values, $boolean = Operator::AND)
havingNotBetweenColumns($column, array $values, $boolean = Operator::AND)
havingNotEmpty($columns, string $boolean = Operator::AND): static
havingNotIn($column, $values, $boolean = Operator::AND)
havingNotNull($columns, string $boolean = Operator::AND): static
havingNull($columns, string $boolean = Operator::AND, bool $not = false): static
havingRaw(string $expression)
innerJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null)
inRandomOrder(string|int|null $seed = ''): static
intersect($query): static
intersectDistinct($query): static
join($table, ?string $strict = null, ?string $type = null, ?array $using = null, bool $global = false, ?string $alias = null)
latest(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column = 'created_at'): static
leftArrayJoin($arrayIdentifier)
leftJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null)
limit(int $limit, ?int $offset = null)
limitBy(int $count, ...$columns)
oldest(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column = 'created_at'): static
onCluster(string $clusterName)
orderBy($column, string $direction = 'asc', ?string $collate = null)
orderByAsc($column, ?string $collate = null)
orderByDesc($column, ?string $collate = null)
orderByRaw(string $expression)
orHaving($column, $operator = null, $value = null)
orHavingBetween($column, array $values)
orHavingBetweenColumns($column, array $values)
orHavingEmpty($columns): static
orHavingIn($column, $values)
orHavingNotBetween($column, array $values)
orHavingNotBetweenColumns($column, array $values)
orHavingNotEmpty($columns): static
orHavingNotIn($column, $values, $boolean = Operator::OR)
orHavingNotNull($columns): static
orHavingNull($columns): static
orHavingRaw(string $expression)
orPreWhere($column, $operator = null, $value = null)
orPreWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
orPreWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
orPreWhereBetween($column, array $values)
orPreWhereBetweenColumns($column, array $values)
orPreWhereColumn(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|array|string $first, mixed $operator = null, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|null $second = null): static
orPreWhereDate(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orPreWhereDay(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orPreWhereEmpty($columns): static
orPreWhereIn($column, $values)
orPreWhereMonth(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orPreWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
orPreWhereNotBetween($column, array $values)
orPreWhereNotBetweenColumns($column, array $values)
orPreWhereNotEmpty($columns): static
orPreWhereNotIn($column, $values, $boolean = Operator::OR)
orPreWhereNotNull($columns): static
orPreWhereNull($columns): static
orPreWhereRaw(string $expression)
orPreWhereTime(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orPreWhereYear(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orWhere($column, $operator = null, $value = null)
orWhereAll(array $columns, mixed $operator = null, mixed $value = null): static
orWhereAny(array $columns, mixed $operator = null, mixed $value = null): static
orWhereBetween($column, array $values)
orWhereBetweenColumns($column, array $values)
orWhereColumn(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|array|string $first, mixed $operator = null, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|null $second = null): static
orWhereDate(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orWhereDay(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orWhereDict(string $dict, string $attribute, $key, $operator = null, $value = null)
orWhereEmpty($columns): static
orWhereExists(mixed $query, bool $not = false): static
orWhereGlobalIn($column, $values)
orWhereGlobalNotIn($column, $values, $boolean = Operator::OR)
orWhereIn($column, $values)
orWhereLike(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, string $value, bool $caseSensitive = false): static
orWhereMonth(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orWhereNone(array $columns, mixed $operator = null, mixed $value = null): static
orWhereNotBetween($column, array $values)
orWhereNotBetweenColumns($column, array $values)
orWhereNotEmpty($columns): static
orWhereNotExists(mixed $query): static
orWhereNotIn($column, $values, $boolean = Operator::OR)
orWhereNotLike(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, string $value, bool $caseSensitive = false): static
orWhereNotNull($columns): static
orWhereNull($columns): static
orWhereRaw(string $expression)
orWhereTime(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
orWhereYear(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null): static
preWhere($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
preWhereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
preWhereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
preWhereBetween($column, array $values, $boolean = Operator::AND, $not = false)
preWhereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
preWhereColumn(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|array|string $first, mixed $operator = null, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|null $second = null, string $boolean = Operator::AND): static
preWhereDate(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
preWhereDay(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
preWhereEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
preWhereIn($column, $values, $boolean = Operator::AND, $not = false)
preWhereMonth(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
preWhereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
preWhereNotBetween($column, array $values, $boolean = Operator::AND)
preWhereNotBetweenColumns($column, array $values, $boolean = Operator::AND)
preWhereNotEmpty($columns, string $boolean = Operator::AND): static
preWhereNotIn($column, $values, $boolean = Operator::AND)
preWhereNotNull($columns, string $boolean = Operator::AND): static
preWhereNull($columns, string $boolean = Operator::AND, bool $not = false): static
preWhereRaw(string $expression)
preWhereTime(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
preWhereYear(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
rightJoin($table, ?string $strict = null, ?array $using = null, bool $global = false, ?string $alias = null): static
sample(float $coefficient, ?float $offset = null)
select(...$columns)
semiLeftJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
semiRightJoin($table, ?array $using = null, bool $global = false, ?string $alias = null): static
table($table, ?string $alias = null, ?bool $isFinal = null)
take(int $limit, ?int $offset = null)
takeBy(int $count, ...$columns)
toAsyncQueries(): array
toAsyncSqls(): array
toSql(): string
unionAll($query)
unionDistinct($query): static
where($column, $operator = null, $value = null, string $concatOperator = Operator::AND)
whereAll(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
whereAny(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
whereBetween($column, array $values, $boolean = Operator::AND, $not = false)
whereBetweenColumns($column, array $values, $boolean = Operator::AND, $not = false)
whereColumn(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|array|string $first, mixed $operator = null, ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string|null $second = null, string $boolean = Operator::AND): static
whereDate(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
whereDay(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
whereDict(string $dict, string $attribute, $key, $operator = null, $value = null, string $concatOperator = Operator::AND)
whereEmpty($columns, string $boolean = Operator::AND, bool $not = false): static
whereExists(mixed $query, string $boolean = Operator::AND, bool $not = false): static
whereGlobalIn($column, $values, $boolean = Operator::AND, $not = false)
whereGlobalNotIn($column, $values, $boolean = Operator::AND)
whereIn($column, $values, $boolean = Operator::AND, $not = false)
whereLike(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, string $value, bool $caseSensitive = false, string $boolean = Operator::AND, bool $not = false): static
whereMonth(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
whereNone(array $columns, mixed $operator = null, mixed $value = null, string $boolean = Operator::AND): static
whereNotBetween($column, array $values, $boolean = Operator::AND)
whereNotBetweenColumns($column, array $values, $boolean = Operator::AND)
whereNotEmpty($columns, string $boolean = Operator::AND): static
whereNotExists(mixed $query, string $boolean = Operator::AND): static
whereNotIn($column, $values, $boolean = Operator::AND)
whereNotLike(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, string $value, bool $caseSensitive = false, string $boolean = Operator::AND): static
whereNotNull($columns, string $boolean = Operator::AND): static
whereNull($columns, string $boolean = Operator::AND, bool $not = false): static
whereRaw(string $expression)
whereTime(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
whereYear(ClickhouseBuilder\Query\Expression|Illuminate\Contracts\Database\Query\Expression|string $column, mixed $operator, mixed $value = null, string $boolean = Operator::AND): static
withAlias(string $alias, $expression): static
withExpression(string $name, $query): static
withRecursiveExpression(string $name, $query): static
```

### ClickhouseBuilder\Query\Column

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Column`.
See [the guide](/query-builder/basics#column-expressions).

```php
new Column(ClickhouseBuilder\Query\BaseBuilder $query)
alias(string $alias): ClickhouseBuilder\Query\Column
as(string $alias): ClickhouseBuilder\Query\Column
count()
distinct()
getAlias(): ?ClickhouseBuilder\Query\Identifier
getColumnName()
getFunctions(): array
getSubQuery(): ?ClickhouseBuilder\Query\BaseBuilder
max($columnName = null): ClickhouseBuilder\Query\Column
multiple($value)
name($columnName): ClickhouseBuilder\Query\Column
plus($value)
query($query = null)
round(int $decimals = 0): ClickhouseBuilder\Query\Column
runningDifference()
subQuery(): ClickhouseBuilder\Query\BaseBuilder
sum($columnName = null): ClickhouseBuilder\Query\Column
sumIf($expression = [])
```

### ClickhouseBuilder\Query\Expression

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression`, implements `Stringable`.
See [the guide](/reference/helpers#functions).

```php
new Expression($value)
getValue()
```

### ClickhouseBuilder\Query\From

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\From`.
See [the guide](/query-builder/basics#sub-queries-and-table-functions).

```php
new From(ClickhouseBuilder\Query\BaseBuilder $query)
as(string $alias): ClickhouseBuilder\Query\From
final(bool $isFinal = true): ClickhouseBuilder\Query\From
getAlias(): ?ClickhouseBuilder\Query\Identifier
getFinal(): ?bool
getQueryBuilder(): ?ClickhouseBuilder\Query\BaseBuilder
getSubQuery(): ?ClickhouseBuilder\Query\BaseBuilder
getTable()
merge(string $database, string $regexp): ClickhouseBuilder\Query\From
query($query = null)
remote(string $expression, string $database, string $table, ?string $user = null, ?string $password = null): ClickhouseBuilder\Query\From
subQuery(): ClickhouseBuilder\Query\BaseBuilder
table($table): ClickhouseBuilder\Query\From
```

### ClickhouseBuilder\Query\JoinClause

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause`.
See [the guide](/query-builder/clickhouse-sql#join-closures).

```php
new JoinClause(ClickhouseBuilder\Query\BaseBuilder $query)
addUsing(...$columns): ClickhouseBuilder\Query\JoinClause
all(): ClickhouseBuilder\Query\JoinClause
anti(): ClickhouseBuilder\Query\JoinClause
any(): ClickhouseBuilder\Query\JoinClause
as(string $alias): ClickhouseBuilder\Query\JoinClause
asof(): ClickhouseBuilder\Query\JoinClause
cross(): ClickhouseBuilder\Query\JoinClause
distributed(bool $global = false): ClickhouseBuilder\Query\JoinClause
full(): ClickhouseBuilder\Query\JoinClause
getAlias(): ?ClickhouseBuilder\Query\Identifier
getOnClauses(): ?array
getQueryBuilder(): ?ClickhouseBuilder\Query\BaseBuilder
getStrict(): ?ClickhouseBuilder\Query\Enums\JoinStrict
getSubQuery(): ?ClickhouseBuilder\Query\BaseBuilder
getTable()
getType(): ?ClickhouseBuilder\Query\Enums\JoinType
getUsing(): ?array
inner(): ClickhouseBuilder\Query\JoinClause
isDistributed(): bool
left(): ClickhouseBuilder\Query\JoinClause
on($first, string $operator, $second, string $concatOperator = Operator::AND): ClickhouseBuilder\Query\JoinClause
query($query = null)
right(): ClickhouseBuilder\Query\JoinClause
semi(): ClickhouseBuilder\Query\JoinClause
strict(string $strict): ClickhouseBuilder\Query\JoinClause
subQuery(?string $alias = null): ClickhouseBuilder\Query\BaseBuilder
table($table): ClickhouseBuilder\Query\JoinClause
type(string $type): ClickhouseBuilder\Query\JoinClause
using(...$columns): ClickhouseBuilder\Query\JoinClause
```

### ClickhouseBuilder\Query\Limit

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Limit`.
See [the guide](/query-builder/basics#examine-a-query).

```php
new Limit(int $limit, ?int $offset = null, array $by = [])
getBy(): array
getLimit(): ?int
getOffset(): ?int
```

### QueryBuilder

Class `Oralunal\LaravelClickHouse\QueryBuilder`, extends `Illuminate\Database\Query\Builder`.
See [the guide](/query-builder/laravel-query-builder).

```php
aggregate($function, $columns = ['*'])
insert(array $values, ?string $format = null)
insertGetId(array $values, $sequence = null)
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

### ClickhouseSchemaBuilder\AddsColumns

Trait `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\AddsColumns`.
See [the guide](/schema/migrations#column-types).

```php
bool(string $name): ClickhouseSchemaBuilder\Column
date(string $name): ClickhouseSchemaBuilder\Column
datetime(string $name, ?int $precision = null, ?string $timezone = null): ClickhouseSchemaBuilder\Column
decimal(string $name, int $precision, int $scale): ClickhouseSchemaBuilder\Column
decimal128(string $name, int $scale): ClickhouseSchemaBuilder\Column
decimal256(string $name, int $scale): ClickhouseSchemaBuilder\Column
decimal32(string $name, int $scale): ClickhouseSchemaBuilder\Column
decimal64(string $name, int $scale): ClickhouseSchemaBuilder\Column
enum(string $name, array $values): ClickhouseSchemaBuilder\Column
float(string $name, int $bits = 32): ClickhouseSchemaBuilder\Column
float32(string $name): ClickhouseSchemaBuilder\Column
float64(string $name): ClickhouseSchemaBuilder\Column
int128(string $name): ClickhouseSchemaBuilder\Column
int16(string $name): ClickhouseSchemaBuilder\Column
int256(string $name): ClickhouseSchemaBuilder\Column
int32(string $name): ClickhouseSchemaBuilder\Column
int64(string $name): ClickhouseSchemaBuilder\Column
int8(string $name): ClickhouseSchemaBuilder\Column
integer(string $name, int $bits = 32, bool $withSign = true): ClickhouseSchemaBuilder\Column
string(string $name): ClickhouseSchemaBuilder\Column
uInt128(string $name): ClickhouseSchemaBuilder\Column
uInt16(string $name): ClickhouseSchemaBuilder\Column
uInt256(string $name): ClickhouseSchemaBuilder\Column
uInt32(string $name): ClickhouseSchemaBuilder\Column
uInt64(string $name): ClickhouseSchemaBuilder\Column
uInt8(string $name): ClickhouseSchemaBuilder\Column
uuid(string $name): ClickhouseSchemaBuilder\Column
```

### ClickhouseSchemaBuilder\Column

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Column`, implements `ClickhouseSchemaBuilder\Element`.
See [the guide](/schema/migrations#column-types).

```php
new Column(string $name, string $type)
comment(?string $comment): static
compile(): string
default(mixed $default = null): static
nullable($nullable = true): static
```

### ClickhouseSchemaBuilder\Expression

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Expression`.
See [the guide](/schema/migrations#column-types).

```php
new Expression(string $value)
```

### ClickhouseSchemaBuilder\Tables\MergeTree

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Tables\MergeTree`, implements `ClickhouseSchemaBuilder\Element`, uses `ClickhouseSchemaBuilder\AddsColumns`.
See [the guide](/schema/migrations#table-methods).

```php
new MergeTree(string $name)
column(string $name, string $type, array $typeParams = []): ClickhouseSchemaBuilder\Column
columns(callable|array $columns): static
compile(): string
dbName(?string $dbName): static
engine(ClickhouseSchemaBuilder\Engine|string $engine, ...$params): static
getEngine(): ClickhouseSchemaBuilder\Engine
ifNotExists(bool $addIfNotExistsClause = true): static
onCluster(?string $clusterName): static
orderBy(...$orderBy): static
partition(?string $partition): static
settings(array $settings): static
ttl(string $columnName, string $interval): static
```

### Migration

Class `Oralunal\LaravelClickHouse\Migration`, extends `Illuminate\Database\Migrations\Migration`, uses `WithClient`.
See [the guide](/schema/migrations).

It has no public constants or methods of its own.

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
getDateTimePrecision(): string
getDefaultCluster(): ?string
getDriverTitle(): string
getInsertFormat(): string
getSchemaBuilder()
getSchemaState(?Illuminate\Filesystem\Filesystem $files = null, ?callable $processFactory = null): SchemaState
getServerVersion(): string
hasClusterNodes(): bool
hasTemporaryTable(string $table): bool
inSession(): bool
newBuilderGrammar(): Grammar
prepareBindings(array $bindings)
prepareSelectForClient(string $query, array $bindings): array
query()
runBeforeExecutingCallbacks(string $query, array $bindings): void
select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
selectParallelly(array $queries, int $concurrency = Parallel::DEFAULT_CONCURRENCY): array
selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): never
session(callable $callback, int $timeout = 60): mixed
statement($query, $bindings = []): bool
statementOnEveryNode(string $query): bool
transaction(Closure $callback, $attempts = 1): never
unprepared($query): bool
withoutOnCluster(callable $callback): mixed
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

### ClickhouseBuilder\Exceptions\BuilderException

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\BuilderException`, extends `ClickhouseBuilder\Exceptions\Exception`.
See [the guide](/reference/helpers#exceptions).

```php
static cannotDetermineAliasForColumn()
static noTableStructureProvided()
```

### ClickhouseBuilder\Exceptions\Exception

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\Exception`, extends `Exception`.
See [the guide](/reference/helpers#exceptions).

It has no public constants or methods of its own.

### ClickhouseBuilder\Exceptions\GrammarException

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException`, extends `ClickhouseBuilder\Exceptions\Exception`.
See [the guide](/reference/helpers#exceptions).

```php
static ambiguousJoinKeys(): ClickhouseBuilder\Exceptions\GrammarException
static missedTableForInsert(): ClickhouseBuilder\Exceptions\GrammarException
static missedWhereForDelete(): ClickhouseBuilder\Exceptions\GrammarException
static wrongCrossJoin(): ClickhouseBuilder\Exceptions\GrammarException
static wrongFrom(): ClickhouseBuilder\Exceptions\GrammarException
static wrongJoin(ClickhouseBuilder\Query\JoinClause $joinClause): ClickhouseBuilder\Exceptions\GrammarException
```

### ClickhouseBuilder\Exceptions\NotSupportedException

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\NotSupportedException`, extends `ClickhouseBuilder\Exceptions\Exception`.
See [the guide](/reference/helpers#exceptions).

```php
static transactions()
static update()
```

### ClickhouseBuilder\Query\Enums\DateTimePrecision

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const SECOND = 'second';
const MICROSECOND = 'microsecond';
```

### ClickhouseBuilder\Query\Enums\Format

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const BLOCK_TAB_SEPARATED = 'BlockTabSeparated';
const CSV = 'CSV';
const CSV_WITH_NAMES = 'CSVWithNames';
const JSON = 'JSON';
const JSON_COMPACT = 'JSONCompact';
const JSON_COMPACT_EACH_ROW = 'JSONCompactEachRow';
const JSON_EACH_ROW = 'JSONEachRow';
const NATIVE = 'Native';
const NULL = 'Null';
const PRETTY = 'Pretty';
const PRETTY_COMPACT = 'PrettyCompact';
const PRETTY_COMPACT_MONO_BLOCK = 'PrettyCompactMonoBlock';
const PRETTY_NO_ESCAPES = 'PrettyNoEscapes';
const PRETTY_COMPACT_NO_ESCAPES = 'PrettyCompactNoEscapes';
const PRETTY_SPACE_NO_ESCAPES = 'PrettySpaceNoEscapes';
const PRETTY_SPACE = 'PrettySpace';
const ROW_BINARY = 'RowBinary';
const TAB_SEPARATED = 'TabSeparated';
const TAB_SEPARATED_RAW = 'TabSeparatedRaw';
const TAB_SEPARATED_WITH_NAMES = 'TabSeparatedWithNames';
const TAB_SEPARATED_WITH_NAMES_AND_TYPES = 'TabSeparatedWithNamesAndTypes';
const TSKV = 'TSKV';
const VALUES = 'Values';
const VERTICAL = 'Vertical';
const XML = 'XML';
const TSV = 'TSV';

static named(string $name): ClickhouseBuilder\Query\Enums\Format
```

### ClickhouseBuilder\Query\Enums\JoinStrict

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinStrict`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const ALL = 'ALL';
const ANY = 'ANY';
const SEMI = 'SEMI';
const ANTI = 'ANTI';
const ASOF = 'ASOF';
```

### ClickhouseBuilder\Query\Enums\JoinType

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const INNER = 'INNER';
const LEFT = 'LEFT';
const RIGHT = 'RIGHT';
const FULL = 'FULL';
const CROSS = 'CROSS';
const ASOF = 'ASOF';
```

### ClickhouseBuilder\Query\Enums\Operator

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const EQUALS = '=';
const NOT_EQUALS = '!=';
const LESS_OR_EQUALS = '<=';
const GREATER_OR_EQUALS = '>=';
const LESS = '<';
const GREATER = '>';
const LIKE = 'LIKE';
const ILIKE = 'ILIKE';
const NOT_LIKE = 'NOT LIKE';
const NOT_ILIKE = 'NOT ILIKE';
const BETWEEN = 'BETWEEN';
const NOT_BETWEEN = 'NOT BETWEEN';
const IN = 'IN';
const NOT_IN = 'NOT IN';
const GLOBAL_IN = 'GLOBAL IN';
const GLOBAL_NOT_IN = 'GLOBAL NOT IN';
const IS_NULL = 'IS NULL';
const IS_NOT_NULL = 'IS NOT NULL';
const AND = 'AND';
const OR = 'OR';
const CONCAT = '||';
const LAMBDA = '->';
const DIVIDE = '/';
const MODULO = '%';
const MULTIPLE = '*';
const PLUS = '+';
const MINUS = '-';
```

### ClickhouseBuilder\Query\Enums\OrderDirection

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\OrderDirection`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const ASC = 'ASC';
const DESC = 'DESC';
```

### ClickhouseBuilder\Query\Enums\UnionType

Final class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\UnionType`, extends `Enum\Enum`.
See [the guide](/reference/helpers#enums).

```php
const UNION_ALL = 'UNION ALL';
const UNION_DISTINCT = 'UNION DISTINCT';
const INTERSECT = 'INTERSECT';
const INTERSECT_DISTINCT = 'INTERSECT DISTINCT';
const EXCEPT = 'EXCEPT';
const EXCEPT_DISTINCT = 'EXCEPT DISTINCT';
```

### ClickhouseSchemaBuilder\Exceptions\ClickHouseDDLException

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\ClickHouseDDLException`, extends `Exception`.
See [the guide](/reference/helpers#exceptions).

It has no public constants or methods of its own.

### ClickhouseSchemaBuilder\Exceptions\IncompleteClickHouseDDLException

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\IncompleteClickHouseDDLException`, extends `ClickhouseSchemaBuilder\Exceptions\ClickHouseDDLException`.
See [the guide](/reference/helpers#exceptions).

It has no public constants or methods of its own.

### ClickhouseSchemaBuilder\Exceptions\InvalidClickHouseDDLException

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\InvalidClickHouseDDLException`, extends `ClickhouseSchemaBuilder\Exceptions\ClickHouseDDLException`.
See [the guide](/reference/helpers#exceptions).

It has no public constants or methods of its own.

### Enum\Enum

Abstract class `Oralunal\LaravelClickHouse\Enum\Enum`, implements `JsonSerializable`, `Stringable`.
See [the guide](/reference/helpers#enums).

```php
new Enum($value)
static assertValidValue($value): void
equals($variable = null): bool
static from($value): Enum\Enum
getKey()
getValue()
static isValid($value)
static isValidKey($key)
jsonSerialize()
static keys()
static search($value)
static toArray()
static values()
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
static cannotMutateWithClauses(string $statement, array $clauses, array $methodsThatLeaveALimit = [], ?string $methodThatAddsALimit = null): Exceptions\QueryException
static cannotReachTemporaryTable(string $statement, string $table, string $alternative): Exceptions\QueryException
static cannotRunAsynchronouslyInSession(): Exceptions\QueryException
static cannotUpdateEmptyValues(): Exceptions\QueryException
static cannotUpdateWithoutWhere(): Exceptions\QueryException
static insertKeysDiffer(string|int $index, array $keys, array $expectedKeys): Exceptions\QueryException
static sessionIsLocked(string $sessionId, Throwable $previous): Exceptions\QueryException
static sessionNotFound(string $sessionId, int $timeout, Throwable $previous): Exceptions\QueryException
```

## Internal classes

The package and Laravel call the methods of these classes. The guide pages do not describe all of them.

### ClickhouseBuilder\Query\Grammar

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar`, uses `ClickhouseBuilder\Query\Traits\ArrayCompiler`, `ClickhouseBuilder\Query\Traits\ArrayJoinComponentCompiler`, `ClickhouseBuilder\Query\Traits\ColumnsComponentCompiler`, `ClickhouseBuilder\Query\Traits\FormatComponentCompiler`, `ClickhouseBuilder\Query\Traits\FromComponentCompiler`, `ClickhouseBuilder\Query\Traits\GroupsComponentCompiler`, `ClickhouseBuilder\Query\Traits\HavingsComponentCompiler`, `ClickhouseBuilder\Query\Traits\JoinComponentCompiler`, `ClickhouseBuilder\Query\Traits\LimitByComponentCompiler`, `ClickhouseBuilder\Query\Traits\LimitComponentCompiler`, `ClickhouseBuilder\Query\Traits\OrdersComponentCompiler`, `ClickhouseBuilder\Query\Traits\PreWheresComponentCompiler`, `ClickhouseBuilder\Query\Traits\SampleComponentCompiler`, `ClickhouseBuilder\Query\Traits\TupleCompiler`, `ClickhouseBuilder\Query\Traits\TwoElementsLogicExpressionsCompiler`, `ClickhouseBuilder\Query\Traits\UnionsComponentCompiler`, `ClickhouseBuilder\Query\Traits\WheresComponentCompiler`, `ClickhouseBuilder\Query\Traits\WithsComponentCompiler`.

```php
compileCreateTable($tableName, string $engine, array $structure, bool $ifNotExists = false, ?string $clusterName = null, ?string $extraOptions = null): string
compileDelete(ClickhouseBuilder\Query\BaseBuilder $query)
compileDropTable($tableName, bool $ifExists = false, ?string $clusterName = null): string
compileInsert(ClickhouseBuilder\Query\BaseBuilder $query, $values): string
compileInsertValues($values)
compileLiteral(mixed $value): string
compileSelect(ClickhouseBuilder\Query\BaseBuilder $query)
compileStringLiteral(string $value): string
compileTableStructure(array $structure): string
formatDateTime(DateTimeInterface $value): string
getDateTimePrecision(): string
getLaravelExpressionValue(Illuminate\Contracts\Database\Query\Expression $expression): string|int|float
quoteIdentifier(string $name): string
setDateTimePrecision(ClickhouseBuilder\Query\Enums\DateTimePrecision|string $precision): static
setLaravelGrammarResolver(?Closure $resolver): static
wrap($value)
```

### ClickhouseBuilder\Query\Identifier

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier`, implements `Stringable`.

```php
new Identifier($value)
```

### ClickhouseBuilder\Query\Traits\ArrayCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ArrayCompiler`.

```php
compileArray(array $elements): string
```

### ClickhouseBuilder\Query\Traits\ArrayJoinComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ArrayJoinComponentCompiler`.

It has no public constants or methods of its own.

### ClickhouseBuilder\Query\Traits\ColumnCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ColumnCompiler`.

```php
compileColumn(ClickhouseBuilder\Query\Column $column): string
```

### ClickhouseBuilder\Query\Traits\ColumnsComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\ColumnsComponentCompiler`, uses `ClickhouseBuilder\Query\Traits\ColumnCompiler`.

It has no public constants or methods of its own.

### ClickhouseBuilder\Query\Traits\FormatComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\FormatComponentCompiler`.

```php
compileFormatComponent(ClickhouseBuilder\Query\BaseBuilder $builder, $format): string
```

### ClickhouseBuilder\Query\Traits\FromComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\FromComponentCompiler`.

```php
compileFromComponent(ClickhouseBuilder\Query\BaseBuilder $builder, ClickhouseBuilder\Query\From $from): string
```

### ClickhouseBuilder\Query\Traits\GroupsComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\GroupsComponentCompiler`.

It has no public constants or methods of its own.

### ClickhouseBuilder\Query\Traits\HavingsComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\HavingsComponentCompiler`.

```php
compileHavingsComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $havings): string
```

### ClickhouseBuilder\Query\Traits\JoinComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\JoinComponentCompiler`.

It has no public constants or methods of its own.

### ClickhouseBuilder\Query\Traits\LimitByComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\LimitByComponentCompiler`.

```php
compileLimitByComponent(ClickhouseBuilder\Query\BaseBuilder $builder, ClickhouseBuilder\Query\Limit $limit): string
```

### ClickhouseBuilder\Query\Traits\LimitComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\LimitComponentCompiler`.

```php
compileLimitComponent(ClickhouseBuilder\Query\BaseBuilder $builder, ClickhouseBuilder\Query\Limit $limit): string
```

### ClickhouseBuilder\Query\Traits\OrdersComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\OrdersComponentCompiler`.

```php
compileOrdersComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $orders): string
```

### ClickhouseBuilder\Query\Traits\PreWheresComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\PreWheresComponentCompiler`.

```php
compilePrewheresComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $preWheres): string
```

### ClickhouseBuilder\Query\Traits\SampleComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\SampleComponentCompiler`.

```php
compileSampleComponent(ClickhouseBuilder\Query\BaseBuilder $builder, ?float $sample = null): string
```

### ClickhouseBuilder\Query\Traits\TupleCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\TupleCompiler`.

```php
compileTuple(ClickhouseBuilder\Query\Tuple $tuple): string
```

### ClickhouseBuilder\Query\Traits\TwoElementsLogicExpressionsCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\TwoElementsLogicExpressionsCompiler`.

It has no public constants or methods of its own.

### ClickhouseBuilder\Query\Traits\UnionsComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\UnionsComponentCompiler`.

```php
compileUnionsComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $unions): string
```

### ClickhouseBuilder\Query\Traits\WheresComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\WheresComponentCompiler`.

```php
compileWheresComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $wheres): string
```

### ClickhouseBuilder\Query\Traits\WithsComponentCompiler

Trait `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Traits\WithsComponentCompiler`.

```php
compileWithsComponent(ClickhouseBuilder\Query\BaseBuilder $builder, array $withs): string
```

### ClickhouseBuilder\Query\Tuple

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Tuple`.

```php
new Tuple(array $elements = [])
addElements(...$elements): ClickhouseBuilder\Query\Tuple
getElements(): array
```

### ClickhouseBuilder\Query\TwoElementsLogicExpression

Class `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\TwoElementsLogicExpression`.

```php
new TwoElementsLogicExpression(ClickhouseBuilder\Query\BaseBuilder $query)
concatOperator(string $operator)
firstElement($element)
firstElementQuery($query): ClickhouseBuilder\Query\TwoElementsLogicExpression
getConcatenationOperator(): ClickhouseBuilder\Query\Enums\Operator
getFirstElement()
getOperator(): ?ClickhouseBuilder\Query\Enums\Operator
getSecondElement()
operator(string $operator)
secondElement($element)
secondElementQuery($query): ClickhouseBuilder\Query\TwoElementsLogicExpression
```

### ClickhouseSchemaBuilder\Element

Interface `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Element`.

```php
compile(): string
```

### ClickhouseSchemaBuilder\Engine

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Engine`, implements `ClickhouseSchemaBuilder\Element`.
See [the guide](/schema/migrations#table-methods).

```php
const MERGE_TREE = 'MergeTree';
const REPLACING_MERGE_TREE = 'ReplacingMergeTree';

new Engine(string $type = self::MERGE_TREE)
compile(): string
isReplicated(): bool
params(...$params): ClickhouseSchemaBuilder\Engine
replicated(bool $isReplicated = true): static
setDbName(?string $dbName): static
setReplicaName(string $replicaName): ClickhouseSchemaBuilder\Engine
setTableName(?string $tableName): static
setType(string $type): ClickhouseSchemaBuilder\Engine
```

### ClickhouseSchemaBuilder\Syntax

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Syntax`.

```php
const RESERVED_WORDS = [/* 358 values */];

static escapeName(string $elementName): string
static escapeParam(mixed $value)
static quoteName(string $name): string
static quoteString(string $value): string
static quoteTableName(string $name): string
```

### ClickhouseSchemaBuilder\TTL

Class `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\TTL`, implements `ClickhouseSchemaBuilder\Element`.

```php
new TTL(string $columnName, string $interval)
compile(): string
```

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
static compileInsertWithFormat(string $table, array $columns, string $format): string
static compileValuesInsert(Grammar $grammar, string $table, array $rows, array $columns): string
static encodeJsonEachRow(array $rows, JsonEachRowEncoder $encoder): array
static insertJsonCompactEachRow(ClickHouseDB\Client $client, Connection $connection, string $table, array $rows, JsonEachRowEncoder $encoder, array $columns = []): ClickHouseDB\Statement
static insertJsonEachRow(ClickHouseDB\Client $client, Connection $connection, string $table, array $rows, JsonEachRowEncoder $encoder): ClickHouseDB\Statement
static pretendedStatement(string $sql): ClickHouseDB\Statement
static select(ClickHouseDB\Client $client, string $sql, array $bindings = [], ?string $format = null): ClickHouseDB\Statement
static selectIntoStream(ClickHouseDB\Client $client, string $sql, array $bindings, mixed $stream): ClickHouseDB\Statement
static selectRequest(ClickHouseDB\Client $client, string $sql, array $bindings = [], ?string $format = null): ClickHouseDB\Transport\CurlerRequest
static selectStatement(ClickHouseDB\Transport\CurlerRequest $request): ClickHouseDB\Statement
static sendInsert(ClickHouseDB\Client $client, string $head, string $body, string $format): ClickHouseDB\Statement
static write(ClickHouseDB\Client $client, Connection $connection, string $sql, array $bindings = []): ClickHouseDB\Statement
```

### Concerns\BufferedInsertRegistry

Final class `Oralunal\LaravelClickHouse\Concerns\BufferedInsertRegistry`.

```php
static add(string $model): void
static all(): array
static remove(string $model): void
```

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
const RETRY_ON_UNSENT = 'unsent';

execOne(ClickHouseDB\Transport\CurlerRequest $request, bool $auto_close = false): int
getRetries(): int
getRetryOn(): string
mayRetry(ClickHouseDB\Transport\CurlerRequest $request): bool
setRetries(int $retries): void
setRetryOn(string $retryOn): void
```

### Expressions\InsertArray

Class `Oralunal\LaravelClickHouse\Expressions\InsertArray`, implements `ClickHouseDB\Query\Expression\Expression`.

```php
const TYPE_STRING = 'string';
const TYPE_STRING_ESCAPE = 'string_e';
const TYPE_DECIMAL = 'decimal';
const TYPE_INT = 'int';

new InsertArray(array $items, string $type = self::TYPE_STRING)
getItems(): array
getValue(): string
needsEncoding(): bool
```

### Grammar

Class `Oralunal\LaravelClickHouse\Grammar`, extends `ClickhouseBuilder\Query\Grammar`, uses `Concerns\SubstitutesBindings`.

```php
new Grammar()
compileSettingsComponent($_, array $settings): string
escapeInsertColumns(array $columns): array
quoteString(string $value): string
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

Class `Oralunal\LaravelClickHouse\QueryGrammar`, extends `Illuminate\Database\Query\Grammars\Grammar`, uses `Concerns\SubstitutesBindings`.

```php
const PARAMETER_SIGN = '#@?';

compileDelete(Illuminate\Database\Query\Builder $query)
compileExists(Illuminate\Database\Query\Builder $query)
compileLiteral(mixed $value): string
compileRandom($seed)
compileSelect(Illuminate\Database\Query\Builder $query)
compileThreadCount()
compileTruncate(Illuminate\Database\Query\Builder $query)
compileUpdate(Illuminate\Database\Query\Builder $query, array $values)
hasAnOrderThatAddsRows(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatCallsArrayJoin(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatDefinesAnAlias(Illuminate\Database\Query\Builder $query): bool
hasAnOrderThatLimitsRows(Illuminate\Database\Query\Builder $query): bool
prepareBindingsForDelete(array $bindings)
prepareBindingsForUpdate(array $bindings, array $values)
static prepareParameters(string $sql): string
selectsAnAliasOrAnExpression(Illuminate\Database\Query\Builder $query): bool
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

Class `Oralunal\LaravelClickHouse\SchemaGrammar`, extends `Illuminate\Database\Schema\Grammars\Grammar`.

```php
compileAdd(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileChange(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): array
compileColumns($schema, $table): string
compileCreate(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDictionaryExists(?string $schema, string $dictionary): string
compileDrop(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropColumn(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropForeign(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropFullText(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileDropIfExists(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): string
compileDropIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): ?string
compileDropPrimary(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileDropSpatialIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropUnique(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileDropVectorIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
compileForeign(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): null
compileForeignKeys($schema, $table): string
compileFulltext(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
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
compileVectorIndex(Illuminate\Database\Schema\Blueprint $blueprint, Illuminate\Support\Fluent $command): never
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

## Functions

The functions are in the `Oralunal\LaravelClickHouse\ClickhouseBuilder` namespace. See [Helpers](/reference/helpers#functions).

```php
function array_flatten($array, $depth = INF): array
function raw(string $expr): ClickhouseBuilder\Query\Expression
function tp($value, $callback)
```
