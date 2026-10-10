# Helpers, enums and exceptions

## Functions

The functions are in the `Oralunal\LaravelClickHouse\ClickhouseBuilder` namespace:

| Function | Does |
| --- | --- |
| `raw(string $sql)` | Returns an `Expression`. The query builder writes it as SQL. |
| `tp($value, callable $callback)` | Calls the callback with the value, and returns the value |
| `array_flatten(array $array, $depth = INF)` | Puts the values of nested arrays into one array |

```php
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\array_flatten;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

MyTable::select(raw('count() AS c'));     // SELECT count() AS c FROM `my_table`
array_flatten([1, [2, [3, 4]]]);          // [1, 2, 3, 4]
array_flatten([1, [2, [3, 4]]], 1);       // [1, 2, [3, 4]]
```

`raw()` gives an `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression`. `getValue()` returns its SQL.
For a column with an alias, use `new RawColumn('count()', 'total')`. See [Query builder basics](/query-builder/basics#select-columns).

## Enums

The enums are in the `Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums` namespace:

| Class | Values |
| --- | --- |
| `Operator` | The operators and booleans of conditions, such as `Operator::EQUALS` (`=`), `Operator::GLOBAL_IN` (`GLOBAL IN`), `Operator::IS_NULL` and `Operator::OR` |
| `Format` | The ClickHouse formats, such as `Format::JSON`, `Format::JSON_EACH_ROW` and `Format::CSV` |
| `JoinType` | `INNER`, `LEFT`, `RIGHT`, `FULL`, `CROSS` and `ASOF` |
| `JoinStrict` | `ALL`, `ANY`, `SEMI`, `ANTI` and `ASOF` |
| `OrderDirection` | `ASC` and `DESC` |
| `UnionType` | The keywords of the set operations, which `getUnionTypes()` returns, such as `UnionType::UNION_ALL` |
| `DateTimePrecision` | `second` and `microsecond`, the values of the `datetime_precision` option |

`Format::named('jsoneachrow')` returns the format of a name in any letter case. An unknown name throws an `UnexpectedValueException`.

The enums are classes with constants, not PHP enums. They extend `Oralunal\LaravelClickHouse\Enum\Enum`, a copy of the `myclabs/php-enum` class:

```php
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;

$format = Format::JSON_EACH_ROW();   // Or new Format('JSONEachRow'), or Format::from('JSONEachRow')
$format->getValue();                 // 'JSONEachRow'
$format->getKey();                   // 'JSON_EACH_ROW'
$format->equals(Format::JSON_EACH_ROW()); // true
json_encode($format);                // '"JSONEachRow"'

Format::isValid('CSV');              // true: a value
Format::isValidKey('JSON_EACH_ROW'); // true: a key
Format::search('JSONEachRow');       // 'JSON_EACH_ROW', or false
Format::keys();                      // ['BLOCK_TAB_SEPARATED', 'CSV', ...]
Format::values();                    // ['CSV' => Format, ...]
Format::toArray();                   // ['CSV' => 'CSV', ...]
Format::assertValidValue('jsonl');   // Throws an UnexpectedValueException
```

A value that is not in the enum throws an `UnexpectedValueException`.

## Exceptions

| Exception | Cause |
| --- | --- |
| `Oralunal\LaravelClickHouse\Exceptions\QueryException` | The package refuses a query or a write, or a session fails. It extends the smi2 `ClickHouseDB\Exception\QueryException`. |
| `Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException` | A query of `Parallel::getRows()` fails. See [Parallel queries](/advanced/parallel-queries#errors). |
| `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException` | The query builder cannot write the SQL of a query |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\IncompleteClickHouseDDLException` | `createMergeTree()` has no column, no `ORDER BY` or no engine |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\InvalidClickHouseDDLException` | `enum()` of `createMergeTree()` has no value, or a number that is not an integer |
| `Oralunal\LaravelClickHouse\ClickhouseSchemaBuilder\Exceptions\ClickHouseDDLException` | The parent class of the two exceptions above |
| `Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\BuilderException`, `NotSupportedException` | The package does not throw them. They stay for code that catches them. |

The static methods of the exceptions make the exceptions that the package throws:

| Method | Cause |
| --- | --- |
| `QueryException::cannotDeleteWithoutWhere()`, `cannotUpdateWithoutWhere()` | `delete()` or `update()` without a condition |
| `QueryException::cannotUpdateEmptyValues()` | `update([])` |
| `QueryException::cannotMutateWithClauses()` | A mutation with a clause that ClickHouse ignores. See [Refused clauses](/query-builder/writing-data#refused-clauses). |
| `QueryException::insertKeysDiffer()` | A row of a `JSONEachRow` insert has other keys than the first row |
| `QueryException::cannotCreateTemporaryTableOutsideSession()`, `cannotReachTemporaryTable()` | A temporary table outside a session, or a statement that ClickHouse runs on a database table of the same name. See [Sessions](/advanced/sessions#temporary-tables). |
| `QueryException::cannotRunAsynchronouslyInSession()` | An asynchronous request or `insertFiles()` in a session |
| `QueryException::sessionNotFound()`, `sessionIsLocked()` | The ClickHouse errors `SESSION_NOT_FOUND` and `SESSION_IS_LOCKED`. See [Sessions](/advanced/sessions#errors-in-a-session). |
| `GrammarException::ambiguousJoinKeys()` | A join with `USING` and `ON` |
| `GrammarException::wrongJoin()`, `wrongCrossJoin()` | A join without a table, or without `USING` and `ON`. A cross join with keys or a strictness. |
| `GrammarException::wrongFrom()` | A query without a table |
| `GrammarException::missedTableForInsert()`, `missedWhereForDelete()` | An insert without a table, or a deletion without a condition |
| `BuilderException::cannotDetermineAliasForColumn()`, `noTableStructureProvided()`, `NotSupportedException::transactions()` | Not used by the package |
