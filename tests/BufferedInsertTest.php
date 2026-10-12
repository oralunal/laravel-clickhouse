<?php

namespace Tests;

use Closure;
use Oralunal\LaravelClickHouse\Eloquent\Model;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Tests\Models\Example;
use Tests\Models\Example3;
use Tests\Models\ExampleNonExistent;
use Throwable;

/**
 * buffer() and flushBuffer() of the Eloquent model, against the server.
 */
class BufferedInsertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Example::query()->truncate();
        Example3::query()->truncate();
        $this->clearBuffers();
    }

    protected function tearDown(): void
    {
        $this->clearBuffers();
        parent::tearDown();
    }

    public function testBufferAndManualFlush(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'a']);
        Example::buffer(['f_int' => 2, 'f_string' => 'b']);
        $this->assertSame(2, Example::bufferCount());

        $this->assertTrue(Example::flushBuffer());
        $this->assertSame(0, Example::bufferCount());

        $this->assertSame([[1, 'a'], [2, 'b']], $this->rowsOf(Example::class));
    }

    public function testFlushOfAnEmptyBufferReturnsFalse(): void
    {
        $this->assertFalse(Example::flushBuffer());
        $this->assertSame([], $this->rowsOf(Example::class));
    }

    public function testBufferAcceptsSingleRowAndArrayOfRows(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'x']);
        Example::buffer([
            ['f_int' => 2, 'f_string' => 'y'],
            ['f_int' => 3, 'f_string' => 'z'],
        ]);
        $this->assertSame(3, Example::bufferCount());

        Example::flushBuffer();
        $this->assertSame([[1, 'x'], [2, 'y'], [3, 'z']], $this->rowsOf(Example::class));
    }

    public function testBufferIgnoresEmptyArray(): void
    {
        Example::buffer([]);
        $this->assertSame(0, Example::bufferCount());
        $this->assertFalse(Example::flushBuffer());
    }

    /**
     * The rows are inserted as given, as insert() inserts them: casts and mutators do not apply.
     */
    public function testBufferedRowsAreInsertedAsGiven(): void
    {
        Example3::buffer(['f_int' => 1, 'f_bool' => false]);
        $this->assertSame([['f_int' => 1, 'f_bool' => false]], Example3::getBufferedRows());

        Example3::flushBuffer();
        $this->assertFalse(Example3::where('f_int', 1)->first()->f_bool);
    }

    public function testFlushFailurePreservesBuffer(): void
    {
        ExampleNonExistent::buffer(['f_int' => 1, 'f_string' => 'kept']);

        try {
            ExampleNonExistent::flushBuffer();
            $this->fail('flushBuffer() did not throw the error of ClickHouse.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('UNKNOWN_TABLE', $exception->getMessage());
        }

        $this->assertSame(1, ExampleNonExistent::bufferCount());
        $this->assertSame('kept', ExampleNonExistent::getBufferedRows()[0]['f_string']);
    }

    public function testMultipleModelsHaveIndependentBuffers(): void
    {
        Example::buffer(['f_int' => 10, 'f_string' => 'ex']);
        Example3::buffer(['f_int' => 20, 'f_bool' => true]);

        $this->assertSame(1, Example::bufferCount());
        $this->assertSame(1, Example3::bufferCount());

        Example::flushBuffer();
        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(1, Example3::bufferCount());

        Example3::flushBuffer();
        $this->assertSame(0, Example3::bufferCount());
    }

    public function testTerminatingTriggersAutoFlush(): void
    {
        Example::buffer(['f_int' => 99, 'f_string' => 'auto']);
        $this->assertSame(1, Example::bufferCount());

        $this->app->terminate();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame([[99, 'auto']], $this->rowsOf(Example::class));
    }

    public function testFlushAllBuffersSilentSwallowsErrors(): void
    {
        Example::buffer(['f_int' => 7, 'f_string' => 'ok']);
        ExampleNonExistent::buffer(['f_int' => 8, 'f_string' => 'broken']);

        Model::flushAllBuffers(silent: true);

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame(1, ExampleNonExistent::bufferCount());
        $this->assertSame([[7, 'ok']], $this->rowsOf(Example::class));
    }

    public function testClearBufferDiscardsWithoutFlushing(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'discard']);
        Example::clearBuffer();

        $this->assertSame(0, Example::bufferCount());
        $this->assertFalse(Example::flushBuffer());
        Model::flushAllBuffers();
        $this->assertSame([], $this->rowsOf(Example::class));
    }

    /**
     * Rows buffered with their keys in another order are sent as one insert.
     */
    public function testRowsWithTheirKeysInAnotherOrderAreFlushedTogether(): void
    {
        Example::buffer(['f_int' => 1, 'f_string' => 'a']);
        Example::buffer(['f_string' => 'b', 'f_int' => 2]);

        Example::flushBuffer();

        $this->assertSame([[1, 'a'], [2, 'b']], $this->rowsOf(Example::class));
    }

    /**
     * buffer() refuses a row whose keys differ from those of the first row of the call, or of the rows already
     * buffered, before anything is buffered, so the rows buffered before and after it are flushed.
     */
    public function testABufferCallWithOtherKeysIsRefusedAndTheOtherRowsAreFlushed(): void
    {
        $refused = fn (int $index, string $keys): string => 'Cannot buffer the rows of the model [' . Example::class
            . "]: the row at index {$index} has the keys [{$keys}], and the buffer needs the keys [f_int, f_string]."
            . ' Every buffered row of a model must have the same column keys, in any order, because the buffer is'
            . ' sent as one insert. Nothing was buffered.';

        $this->assertBufferRefused(fn () => Example::buffer([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2]]), $refused(1, 'f_int'));
        Example::buffer(['f_int' => 3, 'f_string' => 'c']);
        $this->assertBufferRefused(fn () => Example::buffer([['f_string' => 'd', 'f_int' => 4], ['f_int' => 5]]), $refused(1, 'f_int'));
        $this->assertBufferRefused(fn () => Example::buffer(['f_int' => 6]), $refused(0, 'f_int'));
        $this->assertBufferRefused(fn () => Example::buffer([[]]), $refused(0, ''));
        Example::buffer(['f_string' => 'g', 'f_int' => 7]);

        $this->assertSame(2, Example::bufferCount());
        $this->app->terminate();

        $this->assertSame(0, Example::bufferCount());
        $this->assertSame([[3, 'c'], [7, 'g']], $this->rowsOf(Example::class));
    }

    /**
     * While the connection pretends, buffer() inserts at once, which logs the INSERT and sends nothing.
     */
    public function testBufferWhilePretendingLogsTheInsert(): void
    {
        $log = Example::query()->getConnection()->pretend(function (): void {
            Example::buffer(['f_int' => 1, 'f_string' => 'a']);
        });

        $this->assertSame(0, Example::bufferCount());
        $this->assertCount(1, $log);
        $this->assertStringStartsWith('insert into "examples"', $log[0]['query']);
        $this->assertSame([], $this->rowsOf(Example::class));
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
     * @param class-string<Model> $model
     * @return array<int, array{int, string}>
     */
    private function rowsOf(string $model): array
    {
        return $model::query()->orderBy('f_int')->get(['f_int', 'f_string'])
            ->map(fn (Model $row): array => [(int) $row->f_int, $row->f_string])
            ->all();
    }

    private function clearBuffers(): void
    {
        Example::clearBuffer();
        Example3::clearBuffer();
        ExampleNonExistent::clearBuffer();
    }
}
