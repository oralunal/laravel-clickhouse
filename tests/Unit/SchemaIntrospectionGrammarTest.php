<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Database\Connection;
use Oralunal\LaravelClickHouse\SchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * SQL of the schema introspection queries. The integration tests in
 * tests/SchemaIntrospectionTest.php run them against ClickHouse.
 */
class SchemaIntrospectionGrammarTest extends TestCase
{
    private const TABLE_FILTER = "is_temporary = 0 and not startsWith(name, '.inner')"
        . " and not endsWith(engine, 'View') and engine != 'Dictionary'";

    public function testQuoteStringEscapesBackslashesAndSingleQuotes(): void
    {
        $grammar = $this->grammar();

        $this->assertSame("'plain'", $grammar->quoteString('plain'));
        $this->assertSame("'it\\'s'", $grammar->quoteString("it's"));
        $this->assertSame("'a\\\\b'", $grammar->quoteString('a\\b'));
        $this->assertSame("'a\\\\\\'b'", $grammar->quoteString("a\\'b"));
        $this->assertSame("'a', 'b\\'c'", $grammar->quoteString(['a', "b'c"]));
    }

    /**
     * hasTable() leaves out the same objects as getTables(): views,
     * materialized views with their inner tables, dictionaries and temporary tables.
     */
    public function testTableExistsEscapesTheNamesAndLeavesOutWhatIsNotATable(): void
    {
        $this->assertSame(
            "select name from system.tables where database = 'my\\'db' and name = 'it\\'s' and " . self::TABLE_FILTER,
            $this->grammar()->compileTableExists("my'db", "it's")
        );
    }

    public function testTableExistsDefaultsToTheConnectionsDatabase(): void
    {
        $this->assertStringStartsWith(
            "select name from system.tables where database = 'analytics' and name = 'events' and ",
            $this->grammar()->compileTableExists(null, 'events')
        );
    }

    public function testDictionaryExists(): void
    {
        $grammar = $this->grammar();

        $this->assertSame(
            "select name from system.tables where database = 'my\\'db' and name = 'it\\'s' and engine = 'Dictionary'",
            $grammar->compileDictionaryExists("my'db", "it's")
        );
        $this->assertSame(
            "select name from system.tables where database = 'analytics' and name = 'names' and engine = 'Dictionary'",
            $grammar->compileDictionaryExists(null, 'names')
        );
    }

    /**
     * The size of a Merge table is NULL, because total_bytes of a Merge table repeats the bytes of its source tables.
     */
    public function testTablesDefaultToTheConnectionsDatabase(): void
    {
        $this->assertSame(
            "select name, database as schema, if(engine = 'Merge', NULL, total_bytes) as size,"
            . " nullIf(comment, '') as comment, engine"
            . " from system.tables where database = 'analytics' and " . self::TABLE_FILTER
            . ' order by database, name',
            $this->grammar()->compileTables(null)
        );
        $this->assertSame($this->grammar()->compileTables(null), $this->grammar()->compileTables([]));
    }

    public function testTablesOfOneDatabase(): void
    {
        $this->assertStringContainsString(
            " where database = 'other\\'db' and " . self::TABLE_FILTER . ' order by',
            $this->grammar()->compileTables("other'db")
        );
    }

    public function testTablesOfSeveralDatabases(): void
    {
        $this->assertStringContainsString(
            " where database in ('analytics', 'other') and " . self::TABLE_FILTER . ' order by',
            $this->grammar()->compileTables(['analytics', 'other'])
        );
    }

    public function testViews(): void
    {
        $grammar = $this->grammar();

        $this->assertSame(
            'select name, database as schema, as_select as definition from system.tables'
            . " where database = 'analytics' and endsWith(engine, 'View') order by database, name",
            $grammar->compileViews(null)
        );
        $this->assertStringContainsString(
            " where database in ('a', 'b') and endsWith(engine, 'View')",
            $grammar->compileViews(['a', 'b'])
        );
    }

    public function testColumns(): void
    {
        $grammar = $this->grammar();

        $this->assertSame(
            'select name, type, default_kind, default_expression, comment from system.columns'
            . " where database = 'analytics' and table = 'it\\'s' order by position",
            $grammar->compileColumns(null, "it's")
        );
        $this->assertStringContainsString(
            " where database = 'other' and table = 'events' ",
            $grammar->compileColumns('other', 'events')
        );
    }

    public function testIndexesListThePrimaryKeyBeforeTheDataSkippingIndices(): void
    {
        $this->assertSame(
            'select index_name as name, expression, index_type as type, is_primary from ('
            . "select 'primary' as index_name, primary_key as expression, 'primary' as index_type,"
            . ' 1 as is_primary, 0 as position'
            . " from system.tables where database = 'analytics' and name = 'events' and primary_key != ''"
            . ' union all select name, expr, type, 0, rowNumberInAllBlocks() + 1'
            . " from system.data_skipping_indices where database = 'analytics' and table = 'events'"
            . ') order by is_primary desc, position',
            $this->grammar()->compileIndexes(null, 'events')
        );
    }

    public function testIndexesOfAnotherDatabase(): void
    {
        $sql = $this->grammar()->compileIndexes("o'db", 'events');

        $this->assertStringContainsString("from system.tables where database = 'o\\'db' and name = 'events'", $sql);
        $this->assertStringContainsString(
            "from system.data_skipping_indices where database = 'o\\'db' and table = 'events'",
            $sql
        );
    }

    public function testForeignKeysQueryReturnsNoRows(): void
    {
        $this->assertSame('select 1 where 0', $this->grammar()->compileForeignKeys(null, 'events'));
    }

    private function grammar(): SchemaGrammar
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabaseName')->willReturn('analytics');

        return new SchemaGrammar($connection);
    }
}
