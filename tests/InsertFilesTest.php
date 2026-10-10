<?php

declare(strict_types=1);

namespace Tests;

use ClickHouseDB\Exception\DatabaseException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Tests\Models\Example;

/**
 * Builder::insertFiles(): each file is its own INSERT with quoted column names, in a format named in any letter case.
 */
class InsertFilesTest extends TestCase
{
    private string $directory;

    private Filesystem $files;

    protected function setUp(): void
    {
        parent::setUp();

        $client = DB::connection('clickhouse')->getClient();
        $client->write('DROP TABLE IF EXISTS if_rows SYNC');
        $client->write('CREATE TABLE if_rows (k UInt32, `we``ird` String) ENGINE = MergeTree ORDER BY k');

        $this->files = new Filesystem();
        $this->directory = sys_get_temp_dir() . '/phpch-insert-files-' . bin2hex(random_bytes(4));
        $this->files->ensureDirectoryExists($this->directory);
        $this->files->put($this->path('rows.csv'), "k,we`ird\n1,a\n2,b\n");
        $this->files->put($this->path('rows.json'), "{\"k\":3,\"we`ird\":\"c\"}\n");
        $this->files->put($this->path('bad.csv'), "k,we`ird\nnot a number,x\n");
    }

    protected function tearDown(): void
    {
        DB::connection('clickhouse')->getClient()->write('DROP TABLE IF EXISTS if_rows SYNC');
        $this->files->deleteDirectory($this->directory);

        parent::tearDown();
    }

    private function path(string $file): string
    {
        return $this->directory . '/' . $file;
    }

    private function rows(): Builder
    {
        return DB::connection('clickhouse')->table('if_rows');
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function storedRows(): array
    {
        return array_map(
            'array_values',
            DB::connection('clickhouse')->getClient()->select('SELECT k, `we``ird` FROM if_rows ORDER BY k')->rows()
        );
    }

    public function test_files_in_formats_named_in_any_letter_case_are_inserted_into_quoted_columns(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $csv = $this->rows()->insertFiles($this->path('rows.csv'), 'csvwithnames', ['k', 'we`ird']);
        $json = $this->rows()->insertFiles([$this->path('rows.json')], 'JSONEACHROW', ['k', 'we`ird']);

        $this->assertSame('2', $csv[$this->path('rows.csv')]->summary('written_rows'));
        $this->assertSame('1', $json[$this->path('rows.json')]->summary('written_rows'));
        $this->assertSame([[1, 'a'], [2, 'b'], [3, 'c']], $this->storedRows());
        $this->assertSame([
            'INSERT INTO `if_rows` ( `k`,`we``ird` ) FORMAT CSVWithNames',
            'INSERT INTO `if_rows` ( `k`,`we``ird` ) FORMAT JSONEachRow',
        ], array_column($connection->getQueryLog(), 'query'));
    }

    public function test_files_are_inserted_into_the_table_of_a_model(): void
    {
        Example::truncate();
        $this->files->put($this->path('examples.csv'), "f_int,f_string\n1,zz\n2,aa\n");
        $this->files->put($this->path('examples.json'), "{\"f_int\":3,\"f_string\":\"bb\"}\n");
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        $statements = Example::query()->insertFiles([$this->path('examples.csv')], 'CSVWithNames', ['f_int', 'f_string']);
        $statements += Example::query()->insertFiles([$this->path('examples.json')], 'JSONEachRow', ['f_int', 'f_string']);

        $this->assertSame(['2', '1'], array_values(array_map(fn ($statement) => $statement->summary('written_rows'), $statements)));
        $this->assertSame([
            'INSERT INTO examples ( `f_int`,`f_string` ) FORMAT CSVWithNames',
            'INSERT INTO examples ( `f_int`,`f_string` ) FORMAT JSONEachRow',
        ], array_column($connection->getQueryLog(), 'query'));
        $this->assertSame([1 => 'zz', 2 => 'aa', 3 => 'bb'], Example::query()->orderBy('f_int')->pluck('f_string', 'f_int')->all());
        Example::truncate();
    }

    /**
     * smi2's Client::insertBatchFiles() checks each file while it queues them, so a missing file after a readable
     * one left the readable one queued on the connection's client: the next insertFiles() threw 'Queue must be
     * empty', and the next executeAsync() inserted the file that the caller saw refused.
     */
    public function test_a_path_that_is_not_a_readable_file_sends_nothing_and_leaves_nothing_queued(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();
        $missing = $this->path('missing.csv');

        try {
            $this->rows()->insertFiles([$this->path('rows.csv'), $missing], 'CSVWithNames', ['k', 'we`ird']);
            $this->fail('insertFiles() did not throw.');
        } catch (QueryException $exception) {
            $this->assertSame("Cannot insert the file [{$missing}]: it is not a readable file. No file was sent.", $exception->getMessage());
        }

        $this->assertSame(0, $connection->getClient()->getCountPendingQueue());
        $this->assertSame([], $connection->getQueryLog());
        $this->assertSame([], $this->storedRows());

        $this->rows()->insertFiles($this->path('rows.csv'), 'CSVWithNames', ['k', 'we`ird']);

        $this->assertSame([[1, 'a'], [2, 'b']], $this->storedRows());
    }

    public function test_a_format_that_cannot_be_sent_from_a_file_throws_before_anything_is_sent(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->enableQueryLog();

        try {
            $this->rows()->insertFiles($this->path('rows.csv'), 'XML');
            $this->fail('insertFiles() did not throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringStartsWith('insertFiles() cannot send the format [XML]', $exception->getMessage());
        }

        $this->assertSame([], $connection->getQueryLog());
        $this->assertSame([], $this->storedRows());
    }

    public function test_a_file_that_cannot_be_parsed_throws_an_error_that_names_its_insert(): void
    {
        try {
            $this->rows()->insertFiles($this->path('bad.csv'), 'CSVWithNames', ['k', 'we`ird']);
            $this->fail('insertFiles() did not throw.');
        } catch (DatabaseException $exception) {
            $this->assertStringContainsString('IN:INSERT INTO `if_rows` ( `k`,`we``ird` ) FORMAT CSVWithNames', $exception->getMessage());
        }

        $this->assertSame([], $this->storedRows());
    }

    public function test_nothing_is_sent_while_the_connection_pretends(): void
    {
        $connection = DB::connection('clickhouse');

        $log = $connection->pretend(fn () => $this->rows()->insertFiles($this->path('rows.csv'), 'CSVWithNames', ['k', 'we`ird']));

        $this->assertSame(['INSERT INTO `if_rows` ( `k`,`we``ird` ) FORMAT CSVWithNames'], array_column($log, 'query'));
        $this->assertSame([], $this->storedRows());
    }

    public function test_a_client_with_a_session_is_refused_before_anything_is_sent(): void
    {
        $connection = DB::connection('clickhouse');
        $connection->getClient()->useSession();

        try {
            $this->rows()->insertFiles($this->path('rows.csv'), 'CSVWithNames', ['k', 'we`ird']);
            $this->fail('insertFiles() did not throw.');
        } catch (QueryException $exception) {
            $this->assertStringStartsWith('Cannot insert files in a ClickHouse session', $exception->getMessage());
        } finally {
            DB::purge('clickhouse');
        }

        $this->assertSame([], $this->storedRows());
    }
}
