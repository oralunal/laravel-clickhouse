# Helpers, enums and exceptions

## Enums

The enums are PHP backed enums in the `Oralunal\LaravelClickHouse\Enums` namespace.

### Format

`Format` lists the ClickHouse formats that the package names. The value of each case is the name that ClickHouse uses:

| Cases | Values |
| --- | --- |
| `Format::Json`, `Format::JsonCompact`, `Format::JsonEachRow`, `Format::JsonCompactEachRow` | `JSON`, `JSONCompact`, `JSONEachRow`, `JSONCompactEachRow` |
| `Format::Csv`, `Format::CsvWithNames`, `Format::CsvWithNamesAndTypes` | `CSV`, `CSVWithNames`, `CSVWithNamesAndTypes` |
| `Format::Tsv`, `Format::TsvWithNamesAndTypes`, `Format::TabSeparated`, `Format::TabSeparatedRaw`, `Format::TabSeparatedWithNames`, `Format::TabSeparatedWithNamesAndTypes`, `Format::BlockTabSeparated` | `TSV`, `TSVWithNamesAndTypes`, `TabSeparated`, ... |
| `Format::Values`, `Format::Native`, `Format::RowBinary`, `Format::Null` | `Values`, `Native`, `RowBinary`, `Null` |
| `Format::Pretty`, `Format::PrettyCompact`, `Format::PrettyCompactMonoBlock`, `Format::PrettyNoEscapes`, `Format::PrettyCompactNoEscapes`, `Format::PrettySpace`, `Format::PrettySpaceNoEscapes` | `Pretty`, `PrettyCompact`, ... |
| `Format::Tskv`, `Format::Vertical`, `Format::Xml` | `TSKV`, `Vertical`, `XML` |

```php
use Oralunal\LaravelClickHouse\Enums\Format;

Format::Csv->value;              // 'CSV'
Format::from('JSONEachRow');     // Format::JsonEachRow: the exact name
Format::named(' jsoneachrow ');  // Format::JsonEachRow: any letter case, surrounding spaces
Format::tryNamed('csv');         // Format::Csv
Format::tryNamed('Parquet');     // null
Format::named('Parquet');        // Throws a ValueError
```

- `named()` and `tryNamed()` find a case by its name in any letter case. For a name of no case, `named()` throws a `ValueError` and `tryNamed()` returns `null`.
- The methods that take a format, such as `insert()`, `insertFiles()` and `getStatement()`, take a case or a name in any letter case.
- `Connection::getInsertFormat()` returns `Format::Values` or `Format::JsonEachRow`, from the `insert_format` option.

### DateTimePrecision

`DateTimePrecision` has the values of the `datetime_precision` option: `DateTimePrecision::Second` (`'second'`) and `DateTimePrecision::Microsecond` (`'microsecond'`).
`Connection::getDateTimePrecision()` returns the case of the option. The option also takes a case: `'datetime_precision' => DateTimePrecision::Microsecond`.

See [Dates and times](/query-builder/dates).

## Literals

`Connection::newLiteralGrammar()` returns an `Oralunal\LaravelClickHouse\LiteralGrammar`: the class that writes PHP values as ClickHouse literals, as the connection writes them.
The query builder, the bindings of raw SQL, `escape()`, the inserts and `settings()` use it. To write one value into SQL yourself, `DB::connection('clickhouse')->escape($value)` is usually enough.

```php
use Illuminate\Support\Facades\DB;

$grammar = DB::connection('clickhouse')->newLiteralGrammar();

$grammar->compileLiteral("it's");                  // 'it\'s'
$grammar->compileLiteral([1, 'a', null, true]);    // [1, 'a', null, 1]
$grammar->compileLiteral(1 / 3);                   // 0.3333333333333333
$grammar->compileLiteral(NAN);                     // nan
$grammar->compileLiteral(DB::raw('now()'));        // now()
$grammar->compileLiteral(new DateTimeImmutable('2024-01-02 03:04:05.5')); // '2024-01-02 03:04:05'
$grammar->compileSettings(['max_threads' => 2, 'log_comment' => "it's", 'use_uncompressed_cache' => false]);
// max_threads=2, log_comment='it\'s', use_uncompressed_cache=0
```

| Method | Returns |
| --- | --- |
| `compileLiteral($value)` | The literal of a value: `null`, `1` or `0` for a bool, all digits of a float, `nan`, `inf` and `-inf`, an escaped string, an array literal, a date at the precision of the grammar, the value of a backed enum, the name of a pure enum, the SQL of `DB::raw()` |
| `compileStringLiteral($string)` | A string literal. A backslash goes before each single quote, double quote, backslash and NUL byte. |
| `quoteString($string)` | A string literal. A backslash goes before each single quote and backslash only. |
| `quoteIdentifier($name)` | One name in backticks, with each backtick and backslash doubled: ``` `we``ird` ```. The name is not split on dots. |
| `escapeInsertColumns($columns)` | The column names with backticks and backslashes doubled, for a client that puts each name in backticks |
| `compileSettings($settings)` | `name=value, ...`, without `SETTINGS`. A `null` value is left out. A name that is not a plain identifier throws an `InvalidArgumentException`. |
| `formatDateTime($date)` | The date without quotes, in its own time zone: `Y-m-d H:i:s`, or `Y-m-d H:i:s.u` at the microsecond precision for a date with a sub-second part |
| `hasSubSecondPart($date)` | `true` when `formatDateTime()` writes the sub-second part of the date |
| `setDateTimePrecision($precision)`, `getDateTimePrecision()` | Set or get the `DateTimePrecision` of the dates |
| `setLaravelGrammarResolver($resolver)` | Set the closure that returns the query grammar, which a Laravel expression such as `DB::raw()` needs |
| `getLaravelExpressionValue($expression)` | The SQL of a Laravel expression |

- `compileLiteral()` throws an `InvalidArgumentException` for a value that it cannot write, such as a collection, an Eloquent model, a closure or a resource. Give a PHP array, or `$model->getKey()`.
- A `LiteralGrammar` that you make with `new LiteralGrammar()` writes dates with seconds and has no resolver: it throws for `DB::raw()`. Use `newLiteralGrammar()`.

## Exceptions

| Exception | Cause |
| --- | --- |
| `Oralunal\LaravelClickHouse\Exceptions\QueryException` | The package refuses a query or a write, or a session fails. It extends the smi2 `ClickHouseDB\Exception\QueryException`. |
| `Oralunal\LaravelClickHouse\Exceptions\ParallelQueryException` | A query of `Parallel::getRows()` or `selectParallelly()` fails. It extends `QueryException`. See [Parallel queries](/advanced/parallel-queries#errors). |

`ParallelQueryException::getResults()` returns the rows of the queries that succeeded, `getErrors()` the error of each query that failed, and `getStatements()` the statement of each query.

The static methods of `QueryException` make the exceptions that the package throws:

| Method | Cause |
| --- | --- |
| `QueryException::cannotDeleteWithoutWhere()`, `cannotUpdateWithoutWhere()` | `delete()` or `update()` without a condition |
| `QueryException::cannotMutateWithClauses()` | A mutation with a clause that ClickHouse ignores. See [Refused clauses](/query-builder/writing-data#refused-clauses). |
| `QueryException::insertKeysDiffer()` | A row of a `JSONEachRow` insert has other keys than the first row |
| `QueryException::cannotCreateTemporaryTableOutsideSession()`, `cannotReachTemporaryTable()` | A temporary table outside a session, or a statement that ClickHouse runs on a database table of the same name. See [Sessions](/advanced/sessions#temporary-tables). |
| `QueryException::cannotRunAsynchronouslyInSession()` | An asynchronous request or `insertFiles()` in a session |
| `QueryException::sessionNotFound()`, `sessionIsLocked()` | The ClickHouse errors `SESSION_NOT_FOUND` and `SESSION_IS_LOCKED`. See [Sessions](/advanced/sessions#errors-in-a-session). |
| `QueryException::cannotUpdateEmptyValues()` | `update([])`, before anything is sent |

The package throws other errors as the smi2 client and PHP do:

- a ClickHouse error is a `ClickHouseDB\Exception\DatabaseException`, with the ClickHouse code and name (`getClickHouseExceptionName()`);
- an insert of a row without keys throws the smi2 `ClickHouseDB\Exception\QueryException` `Inserting empty values array is not supported in ClickHouse`;
- an incorrect argument throws an `InvalidArgumentException`, and a call that a ClickHouse connection cannot do, such as `beginTransaction()`, a `LogicException`.
