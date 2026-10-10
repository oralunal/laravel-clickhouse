<?php

namespace Tests;

use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Statement;
use Closure;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Throwable;
use Tests\Models\Example;
use Tests\Models\Example3;
use Tests\Models\ExampleNonExistent;
use Tests\Models\ExampleReusingBufferedInserts;
use Tests\Models\ExampleReusingBufferedInsertsChild;
use Tests\Models\ExampleReusingBufferedInsertsNonExistent;
use Tests\Models\ExampleReusingBufferedInsertsWithTableForInserts;
use Tests\Models\ExampleWithTableForInserts;

class BufferedInsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Example::truncate();
        Example3::truncate();
        Example::clearBuffer();
        Example3::clearBuffer();
        ExampleNonExistent::clearBuffer();
        ExampleReusingBufferedInserts::clearBuffer();
        ExampleReusingBufferedInsertsChild::clearBuffer();
        ExampleReusingBufferedInsertsNonExistent::clearBuffer();
        ExampleReusingBufferedInsertsWithTableForInserts::clearBuffer();
        ExampleWithTableForInserts::clearBuffer();
    }

    protected function tearDown(): void
    {
        Example::clearBuffer();
        Example3::clearBuffer();
        ExampleNonExistent::clearBuffer();
        ExampleReusingBufferedInserts::clearBuffer();
        ExampleReusingBufferedInsertsChild::clearBuffer();
        ExampleReusingBufferedInsertsNonExistent::clearBuffer();
        ExampleReusingBufferedInsertsWithTableForInserts::clearBuffer();
        ExampleWithTableForInserts::clearBuffer();
        parent::tearDown();
    }

    public function testBufferAndManualFlush()
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'a']);
        Example::buffer(['f_int' => 2, 'f_string' => 'b']);
        $this->assertEquals(2, Example::bufferCount());

        $statement = Example::flushBuffer();
        $this->assertInstanceOf(Statement::class, $statement);
        $this->assertEquals(0, Example::bufferCount());

        $rows = Example::select()->orderBy('f_int')->getRows();
        $this->assertCount(2, $rows);
        $this->assertEquals('a', $rows[0]['f_string']);
        $this->assertEquals('b', $rows[1]['f_string']);
    }

    public function testFlushEmptyBufferReturnsNull()
    {
        $this->assertNull(Example::flushBuffer());
        $this->assertCount(0, Example::select()->getRows());
    }

    public function testBufferAcceptsSingleRowAndArrayOfRows()
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'x']);
        Example::buffer([
            ['f_int' => 2, 'f_string' => 'y'],
            ['f_int' => 3, 'f_string' => 'z'],
        ]);
        $this->assertEquals(3, Example::bufferCount());

        Example::flushBuffer();
        $this->assertCount(3, Example::select()->getRows());
    }

    public function testBufferIgnoresEmptyArray()
    {
        Example::buffer([]);
        $this->assertEquals(0, Example::bufferCount());
        $this->assertNull(Example::flushBuffer());
    }

    public function testCastsAppliedOnBufferedRows()
    {
        Example3::buffer(['f_int' => 1, 'f_bool' => false]);
        $buffered = Example3::getBufferedRows();
        $this->assertSame(0, $buffered[0]['f_bool']);

        Example3::flushBuffer();
        $rows = Example3::where('f_int', 1)->getRows();
        $this->assertEquals(false, $rows[0]['f_bool']);
    }

    public function testFlushFailurePreservesBuffer()
    {
        ExampleNonExistent::buffer(['f_int' => 1, 'f_string' => 'kept']);

        $threw = false;
        try {
            ExampleNonExistent::flushBuffer();
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'flushBuffer should rethrow ClickHouse errors');
        $this->assertEquals(1, ExampleNonExistent::bufferCount());
        $this->assertEquals('kept', ExampleNonExistent::getBufferedRows()[0]['f_string']);
    }

    public function testMultipleModelsHaveIndependentBuffers()
    {
        Example::buffer(['f_int' => 10, 'f_string' => 'ex']);
        Example3::buffer(['f_int' => 20, 'f_bool' => true]);

        $this->assertEquals(1, Example::bufferCount());
        $this->assertEquals(1, Example3::bufferCount());

        Example::flushBuffer();
        $this->assertEquals(0, Example::bufferCount());
        $this->assertEquals(1, Example3::bufferCount());

        Example3::flushBuffer();
        $this->assertEquals(0, Example3::bufferCount());
    }

    public function testTerminatingTriggersAutoFlush()
    {
        Example::buffer(['f_int' => 99, 'f_string' => 'auto']);
        $this->assertEquals(1, Example::bufferCount());

        $this->app->terminate();

        $this->assertEquals(0, Example::bufferCount());
        $rows = Example::where('f_int', 99)->getRows();
        $this->assertCount(1, $rows);
        $this->assertEquals('auto', $rows[0]['f_string']);
    }

    public function testFlushAllBuffersSilentSwallowsErrors()
    {
        Example::buffer(['f_int' => 7, 'f_string' => 'ok']);
        ExampleNonExistent::buffer(['f_int' => 8, 'f_string' => 'broken']);

        BaseModel::flushAllBuffers(silent: true);

        $this->assertEquals(0, Example::bufferCount());
        $this->assertEquals(1, ExampleNonExistent::bufferCount());

        $rows = Example::where('f_int', 7)->getRows();
        $this->assertCount(1, $rows);
    }

    public function testClearBufferDiscardsWithoutFlushing()
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'discard']);
        Example::clearBuffer();

        $this->assertEquals(0, Example::bufferCount());
        $this->assertNull(Example::flushBuffer());
        $this->assertCount(0, Example::select()->getRows());
    }

    /**
     * A model that uses HasBufferedInserts again runs its own copy of flushBuffer(),
     * in which self is that model, so the flush cannot go through BaseModel's
     * private insert methods. Rows buffered with their keys in another order are
     * still sent as one insert.
     */
    public function testAModelThatUsesTheTraitAgainFlushesIntoItsOwnTable(): void
    {
        ExampleReusingBufferedInserts::buffer(['f_int' => 1, 'f_string' => 'a']);
        ExampleReusingBufferedInserts::buffer(['f_string' => 'b', 'f_int' => 2]);
        $this->assertSame(2, ExampleReusingBufferedInserts::bufferCount());

        $statement = ExampleReusingBufferedInserts::flushBuffer();

        $this->assertInstanceOf(Statement::class, $statement);
        $this->assertSame(0, ExampleReusingBufferedInserts::bufferCount());
        $this->assertSame([[1, 'a'], [2, 'b']], $this->rowsOf(Example::class));
        $this->assertSame([], $this->rowsOf(Example3::class));
    }

    /**
     * The child of a model that uses the trait again flushes only its own rows, into
     * its own table.
     */
    public function testAChildOfAModelThatUsesTheTraitAgainFlushesIntoItsOwnTable(): void
    {
        ExampleReusingBufferedInserts::buffer(['f_int' => 1, 'f_string' => 'parent']);
        ExampleReusingBufferedInsertsChild::buffer([['f_int' => 2, 'f_string' => 'child'], ['f_int' => 3, 'f_string' => 'child']]);

        $statement = ExampleReusingBufferedInsertsChild::flushBuffer();

        $this->assertInstanceOf(Statement::class, $statement);
        $this->assertSame(0, ExampleReusingBufferedInsertsChild::bufferCount());
        $this->assertSame(1, ExampleReusingBufferedInserts::bufferCount(), 'the parent keeps its rows');
        $this->assertSame([[2, 'child'], [3, 'child']], $this->rowsOf(Example3::class));
        $this->assertSame([], $this->rowsOf(Example::class));
    }

    /**
     * A model that uses the trait again keeps its buffers, and its children's, in
     * static properties of its own; flushAllBuffers() called on that model flushes
     * them.
     */
    public function testFlushAllBuffersOfAModelThatUsesTheTraitAgainFlushesItAndItsChild(): void
    {
        ExampleReusingBufferedInserts::buffer(['f_int' => 1, 'f_string' => 'parent']);
        ExampleReusingBufferedInsertsChild::buffer(['f_int' => 2, 'f_string' => 'child']);

        ExampleReusingBufferedInserts::flushAllBuffers();

        $this->assertSame(0, ExampleReusingBufferedInserts::bufferCount());
        $this->assertSame(0, ExampleReusingBufferedInsertsChild::bufferCount());
        $this->assertSame([[1, 'parent']], $this->rowsOf(Example::class));
        $this->assertSame([[2, 'child']], $this->rowsOf(Example3::class));
    }

    /**
     * flushBuffer() inserts into the table for inserts, which a model can set apart
     * from the table it reads, on a model and on a model that uses the trait again.
     */
    public function testFlushBufferInsertsIntoTheTableForInserts(): void
    {
        ExampleWithTableForInserts::buffer(['f_int' => 1, 'f_string' => 'plain']);
        ExampleReusingBufferedInsertsWithTableForInserts::buffer(['f_int' => 2, 'f_string' => 'reusing']);

        ExampleWithTableForInserts::flushBuffer();
        ExampleReusingBufferedInsertsWithTableForInserts::flushBuffer();

        $this->assertSame(0, ExampleWithTableForInserts::bufferCount());
        $this->assertSame(0, ExampleReusingBufferedInsertsWithTableForInserts::bufferCount());
        $this->assertSame([[1, 'plain'], [2, 'reusing']], $this->rowsOf(Example3::class));
        $this->assertSame([], $this->rowsOf(Example::class));
    }

    /**
     * BaseModel::flushAllBuffers() flushes a model that uses the trait again and its
     * child, whose buffers are in that model's own static properties: every copy of
     * the trait registers its models in one registry.
     */
    public function testBaseModelFlushAllBuffersFlushesAModelThatUsesTheTraitAgain(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'plain']);
        ExampleReusingBufferedInserts::buffer(['f_int' => 2, 'f_string' => 'reusing']);
        ExampleReusingBufferedInsertsChild::buffer(['f_int' => 3, 'f_string' => 'child']);

        BaseModel::flushAllBuffers();

        $this->assertSame([0, 0, 0], [
            Example::bufferCount(),
            ExampleReusingBufferedInserts::bufferCount(),
            ExampleReusingBufferedInsertsChild::bufferCount(),
        ]);
        $this->assertSame([[1, 'plain'], [2, 'reusing']], $this->rowsOf(Example::class));
        $this->assertSame([[3, 'child']], $this->rowsOf(Example3::class));
    }

    /**
     * The automatic flush at the end of the request reaches a model that uses the
     * trait again; 3.0.0 left its rows in the buffer, and they were lost at exit.
     */
    public function testTerminatingFlushesAModelThatUsesTheTraitAgain(): void
    {
        ExampleReusingBufferedInserts::buffer(['f_int' => 1, 'f_string' => 'reusing']);
        ExampleReusingBufferedInsertsChild::buffer(['f_int' => 2, 'f_string' => 'child']);

        $this->app->terminate();

        $this->assertSame(0, ExampleReusingBufferedInserts::bufferCount());
        $this->assertSame(0, ExampleReusingBufferedInsertsChild::bufferCount());
        $this->assertSame([[1, 'reusing']], $this->rowsOf(Example::class));
        $this->assertSame([[2, 'child']], $this->rowsOf(Example3::class));
    }

    /**
     * flushAllBuffers() called on a model that uses the trait again flushes every
     * model, also one that BaseModel's copy of the trait buffers.
     */
    public function testFlushAllBuffersOfAModelThatUsesTheTraitAgainFlushesEveryModel(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'plain']);
        Example3::buffer(['f_int' => 2, 'f_string' => 'plain3']);

        ExampleReusingBufferedInserts::flushAllBuffers();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(0, Example3::bufferCount());
        $this->assertSame([[1, 'plain']], $this->rowsOf(Example::class));
        $this->assertSame([[2, 'plain3']], $this->rowsOf(Example3::class));
    }

    /**
     * A silent flush of every model goes on after a model that uses the trait again
     * fails, and that model keeps its rows for a later flush.
     */
    public function testASilentFlushKeepsTheRowsOfAFailingModelThatUsesTheTraitAgain(): void
    {
        ExampleReusingBufferedInsertsNonExistent::buffer(['f_int' => 1, 'f_string' => 'broken']);
        ExampleReusingBufferedInserts::buffer(['f_int' => 2, 'f_string' => 'reusing']);
        Example3::buffer(['f_int' => 3, 'f_string' => 'plain3']);

        BaseModel::flushAllBuffers(silent: true);

        $this->assertSame(1, ExampleReusingBufferedInsertsNonExistent::bufferCount());
        $this->assertSame('broken', ExampleReusingBufferedInsertsNonExistent::getBufferedRows()[0]['f_string']);
        $this->assertSame(0, ExampleReusingBufferedInserts::bufferCount());
        $this->assertSame([[2, 'reusing']], $this->rowsOf(Example::class));
        $this->assertSame([[3, 'plain3']], $this->rowsOf(Example3::class));
    }

    /**
     * A model whose buffer was cleared is not flushed, whichever copy of the trait
     * it uses.
     */
    public function testAClearedModelIsNotFlushed(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'plain']);
        ExampleReusingBufferedInsertsChild::buffer(['f_int' => 2, 'f_string' => 'child']);
        Example::clearBuffer();
        ExampleReusingBufferedInsertsChild::clearBuffer();

        BaseModel::flushAllBuffers();

        $this->assertSame([], $this->rowsOf(Example::class));
        $this->assertSame([], $this->rowsOf(Example3::class));
    }

    /**
     * buffer() refuses a row whose keys differ from those of the first row of the call, or of the rows already
     * buffered, before anything is buffered, so the rows buffered before and after it are flushed. The buffer
     * used to take the rows, and every later flush of the model failed with 'Fields not match'.
     */
    public function testABufferCallWithOtherKeysIsRefusedAndTheOtherRowsAreFlushed(): void
    {
        $refused = fn (int $index, string $keys, string $reference): string => 'Cannot buffer the rows of the model ['
            . Example::class . "]: the row at index {$index} has the keys [{$keys}], and {$reference} [f_int, f_string]."
            . ' Every buffered row of a model must have the same keys, in any order, because the buffer is sent as one'
            . ' insert. Nothing was buffered.';

        $this->assertBufferRefused(
            fn () => Example::buffer([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2]]),
            $refused(1, 'f_int', 'the first row')
        );
        Example::buffer(['f_int' => 3, 'f_string' => 'c']);
        $this->assertBufferRefused(
            fn () => Example::buffer([['f_string' => 'd', 'f_int' => 4], ['f_int' => 5]]),
            $refused(1, 'f_int', 'the rows already buffered')
        );
        $this->assertBufferRefused(fn () => Example::buffer(['f_int' => 6]), $refused(0, 'f_int', 'the rows already buffered'));
        Example::buffer(['f_string' => 'g', 'f_int' => 7]);

        $this->assertSame(2, Example::bufferCount());
        $this->app->terminate();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame([[3, 'c'], [7, 'g']], $this->rowsOf(Example::class));
    }

    /**
     * buffer() refuses a row without keys, with the exception that an insert of no rows throws, and buffers nothing.
     */
    public function testABufferCallWithARowWithoutKeysIsRefused(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'a']);

        try {
            Example::buffer([[]]);
            $this->fail('buffer() did not throw.');
        } catch (ClientQueryException $exception) {
            $this->assertSame(ClientQueryException::class, $exception::class);
            $this->assertSame('Inserting empty values array is not supported in ClickHouse', $exception->getMessage());
        }

        Example::flushBuffer();

        $this->assertSame([[1, 'a']], $this->rowsOf(Example::class));
    }

    /**
     * @param Closure(): mixed $buffer
     * @param string $message
     * @return void
     */
    private function assertBufferRefused(Closure $buffer, string $message): void
    {
        try {
            $buffer();
            $this->fail('buffer() did not throw.');
        } catch (QueryException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param class-string<BaseModel> $model
     * @return array<int, array{int, string}>
     */
    private function rowsOf(string $model): array
    {
        return array_map(
            fn (array $row): array => [(int) $row['f_int'], $row['f_string']],
            $model::select(['f_int', 'f_string'])->orderBy('f_int')->getRows()
        );
    }
}
