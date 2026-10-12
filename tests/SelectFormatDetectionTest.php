<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use ClickHouseDB\Exception\QueryException;
use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\QueryBuilder as Builder;

/**
 * A SELECT whose SQL holds FORMAT and a format name in a value returns its rows. The smi2 client searches the whole
 * SQL for a FORMAT clause, so it read the JSON result of such a query as CSV or TabSeparated, and returned no rows or
 * threw 'Can`t find meta'. getStatement() reads a format that the client does not know, such as XML, in that format.
 */
class SelectFormatDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS sfd_notes SYNC');
        $client->write('CREATE TABLE sfd_notes (id UInt32, note String) ENGINE = MergeTree ORDER BY id');
        $client->write("INSERT INTO sfd_notes VALUES (1, 'export as format csv please'), (2, 'format JSON x'), (3, 'plain')");
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS sfd_notes SYNC');

        parent::tearDown();
    }

    /**
     * @return Builder
     */
    private function notes(): Builder
    {
        return DB::connection('clickhouse')->table('sfd_notes');
    }

    public function test_a_value_that_names_a_format_returns_its_rows(): void
    {
        $query = fn (): Builder => $this->notes()->where('note', 'export as format csv please');

        $this->assertSame([['id' => 1, 'note' => 'export as format csv please']], $query()->get()->all());
        $this->assertSame(1, $query()->count());
        $this->assertTrue($query()->exists());
        $this->assertSame(1, $query()->paginate(10)->total());
        $this->assertCount(1, $query()->paginate(10)->items());
        $this->assertSame([1], $query()->pluck('id')->all());
        $this->assertSame(['id' => 1, 'note' => 'export as format csv please'], $query()->first());
    }

    public function test_a_value_that_names_the_json_format_returns_every_other_row(): void
    {
        $query = fn (): Builder => $this->notes()->where('note', '!=', 'format JSON x')->orderBy('id');

        $this->assertSame([1, 3], array_column($query()->get()->all(), 'id'));
        $this->assertSame(2, $query()->count());
        $this->assertSame(2, $query()->paginate(10)->total());
        $this->assertEquals(4, $query()->sum('id'));
    }

    public function test_such_a_value_with_settings_is_sent_and_logged_as_compiled(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $rows = $this->notes()->select('id')->where('note', 'export as format csv please')->settings(['max_threads' => 1])->get()->all();

        $this->assertSame([['id' => 1]], $rows);
        $this->assertSame(
            ['select "id" from "sfd_notes" where "note" = ? settings max_threads=1'],
            array_column($connection->getQueryLog(), 'query')
        );
    }

    public function test_select_of_the_connection_returns_the_rows_of_such_a_value(): void
    {
        $connection = DB::connection('clickhouse');

        $this->assertSame([['id' => 1]], $connection->select("SELECT id FROM sfd_notes WHERE note = 'export as format csv please'"));
        $this->assertSame(
            [['id' => 1], ['id' => 3]],
            $connection->select("SELECT id FROM sfd_notes WHERE note != 'format JSON x' ORDER BY id")
        );
    }

    public function test_a_format_that_the_client_does_not_know_is_read_in_that_format(): void
    {
        $statement = $this->notes()->select('id')->orderBy('id')->getStatement('XML');

        $this->assertSame('XML', $statement->getFormat());
        $this->assertStringStartsWith('<?xml', $statement->rawData());
        $this->assertStringContainsString('<id>3</id>', $statement->rawData());
    }

    /**
     * JSONCompactEachRow, a format with one JSON value per line, is read as text, as smi2 reads JSONEachRow and CSV:
     * rawData() returns the body, and rows() throws 'Can`t find meta'. smi2 decoded the body as one JSON document, so
     * rawData() was null and rows() empty, without an error. An error of the query is still thrown.
     */
    public function test_a_format_with_one_json_value_per_line_is_read_as_text(): void
    {
        $compact = $this->notes()->select('id')->orderBy('id')->getStatement('JSONCompactEachRow');

        $this->assertSame('JSONCompactEachRow', $compact->getFormat());
        $this->assertSame("[1]\n[2]\n[3]\n", $compact->rawData());
        try {
            $compact->rows();
            $this->fail('rows() did not throw.');
        } catch (QueryException $exception) {
            $this->assertSame('Can`t find meta', $exception->getMessage());
        }

        try {
            $this->notes()->select('missing')->getStatement('JSONCompactEachRow');
            $this->fail('getStatement() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame('UNKNOWN_IDENTIFIER', $exception->getClickHouseExceptionName());
        }
    }

    public function test_an_error_of_such_a_query_is_thrown(): void
    {
        try {
            $this->notes()->where('note', 'format csv')->where('missing', 1)->get();
            $this->fail('get() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertSame('UNKNOWN_IDENTIFIER', $exception->getClickHouseExceptionName());
        }
    }
}
