<?php

declare(strict_types=1);

namespace Tests\Unit;

use ClickHouseDB\Statement;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Concerns\BufferedInsertRegistry;
use Oralunal\LaravelClickHouse\Concerns\HasBufferedInserts;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/**
 * The registry that every copy of HasBufferedInserts shares, and how buffer(), flushBuffer(), clearBuffer() and
 * flushAllBuffers() keep it. No ClickHouse server is needed: the models record their flushes instead of sending.
 * The application is booted for report(), which a silent flush of every model calls.
 */
class BufferedInsertRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetEveryModel();
    }

    protected function tearDown(): void
    {
        $this->forgetEveryModel();

        parent::tearDown();
    }

    public function testTheRegistryKeepsOneEntryPerModelInTheOrderTheyFirstBuffered(): void
    {
        BufferedInsertRegistry::add(RegistryPlainModel::class);
        BufferedInsertRegistry::add(RegistryReusingModel::class);
        BufferedInsertRegistry::add(RegistryPlainModel::class);

        $this->assertSame([RegistryPlainModel::class, RegistryReusingModel::class], $this->registered());
    }

    public function testRemoveTakesOnlyThatModelOut(): void
    {
        BufferedInsertRegistry::add(RegistryPlainModel::class);
        BufferedInsertRegistry::add(RegistryReusingModel::class);
        BufferedInsertRegistry::add(RegistryReusingChildModel::class);

        BufferedInsertRegistry::remove(RegistryReusingModel::class);
        BufferedInsertRegistry::remove('Tests\Unit\NoSuchModel');

        $this->assertSame([RegistryPlainModel::class, RegistryReusingChildModel::class], $this->registered());
    }

    /**
     * A model that uses HasBufferedInserts again registers in the same registry as one that does not.
     */
    public function testBufferRegistersEveryCopyOfTheTraitInOneRegistry(): void
    {
        RegistryReusingChildModel::buffer(['a' => 1]);
        RegistryPlainModel::buffer(['a' => 2]);
        RegistryReusingModel::buffer([['a' => 3], ['a' => 4]]);
        RegistryPlainModel::buffer(['a' => 5]);

        $this->assertSame(
            [RegistryReusingChildModel::class, RegistryPlainModel::class, RegistryReusingModel::class],
            $this->registered()
        );
        $this->assertSame([1, 2, 2], [
            RegistryReusingChildModel::bufferCount(),
            RegistryPlainModel::bufferCount(),
            RegistryReusingModel::bufferCount(),
        ]);
    }

    public function testAnEmptyBufferCallRegistersNothing(): void
    {
        RegistryPlainModel::buffer([]);

        $this->assertSame([], $this->registered());
    }

    public function testClearBufferTakesTheModelOutOfTheRegistry(): void
    {
        RegistryPlainModel::buffer(['a' => 1]);
        RegistryReusingModel::buffer(['a' => 2]);

        RegistryReusingModel::clearBuffer();

        $this->assertSame([RegistryPlainModel::class], $this->registered());
        $this->assertSame(0, RegistryReusingModel::bufferCount());
    }

    public function testFlushingAnEmptyBufferTakesTheModelOutOfTheRegistry(): void
    {
        BufferedInsertRegistry::add(RegistryPlainModel::class);

        $this->assertNull(RegistryPlainModel::flushBuffer());
        $this->assertSame([], $this->registered());
    }

    /**
     * BaseModel::flushAllBuffers(), which the automatic flush calls, reaches a model that uses the trait again and
     * its child, whose buffers BaseModel's copy of the trait cannot see.
     */
    public function testBaseModelFlushAllBuffersFlushesEveryCopyOfTheTrait(): void
    {
        RegistryPlainModel::buffer(['a' => 1]);
        RegistryReusingModel::buffer(['a' => 2]);
        RegistryReusingChildModel::buffer(['a' => 3]);

        BaseModel::flushAllBuffers();

        $this->assertSame(
            [
                [RegistryPlainModel::class, [['a' => 1]]],
                [RegistryReusingModel::class, [['a' => 2]]],
                [RegistryReusingChildModel::class, [['a' => 3]]],
            ],
            RegistryFlushes::$flushed
        );
        $this->assertSame([], $this->registered());
    }

    /**
     * flushAllBuffers() called on a model that uses the trait again flushes every model, not only that model and
     * its children.
     */
    public function testFlushAllBuffersOfAModelThatUsesTheTraitAgainFlushesEveryModel(): void
    {
        RegistryPlainModel::buffer(['a' => 1]);
        RegistryReusingChildModel::buffer(['a' => 2]);

        RegistryReusingModel::flushAllBuffers();

        $this->assertSame(
            [[RegistryPlainModel::class, [['a' => 1]]], [RegistryReusingChildModel::class, [['a' => 2]]]],
            RegistryFlushes::$flushed
        );
    }

    public function testAClearedModelIsNotFlushed(): void
    {
        RegistryPlainModel::buffer(['a' => 1]);
        RegistryReusingModel::buffer(['a' => 2]);
        RegistryReusingModel::clearBuffer();

        BaseModel::flushAllBuffers();

        $this->assertSame([[RegistryPlainModel::class, [['a' => 1]]]], RegistryFlushes::$flushed);
    }

    /**
     * With $silent, a failing flush is reported and the others still run; the failing model keeps its rows and
     * stays registered, so a later flush can send them.
     */
    public function testASilentFlushOfEveryModelGoesOnAfterAFailureAndKeepsTheFailedRows(): void
    {
        RegistryFailingReusingModel::buffer(['a' => 1]);
        RegistryPlainModel::buffer(['a' => 2]);

        BaseModel::flushAllBuffers(silent: true);

        $this->assertSame([[RegistryPlainModel::class, [['a' => 2]]]], RegistryFlushes::$flushed);
        $this->assertSame([RegistryFailingReusingModel::class], $this->registered());
        $this->assertSame([['a' => 1]], RegistryFailingReusingModel::getBufferedRows());
    }

    public function testAFlushOfEveryModelThrowsTheFirstFailureWithoutSilent(): void
    {
        RegistryFailingReusingModel::buffer(['a' => 1]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The flush of the test model failed.');

        BaseModel::flushAllBuffers();
    }

    /**
     * @return list<class-string>
     */
    private function registered(): array
    {
        return array_values(array_filter(
            BufferedInsertRegistry::all(),
            fn (string $class): bool => str_starts_with($class, 'Tests\Unit\Registry')
        ));
    }

    private function forgetEveryModel(): void
    {
        foreach ([RegistryPlainModel::class, RegistryReusingModel::class, RegistryReusingChildModel::class, RegistryFailingReusingModel::class] as $model) {
            $model::clearBuffer();
        }
        RegistryFlushes::$flushed = [];
    }
}

/**
 * The flushes that the registry test models recorded, in order.
 */
final class RegistryFlushes
{
    /**
     * @var list<array{class-string, array<int, array<string, mixed>>}>
     */
    public static array $flushed = [];
}

/**
 * A model that records its flush, with its rows, instead of sending them.
 */
class RegistryPlainModel extends BaseModel
{
    public static function flushBuffer(): ?Statement
    {
        $rows = static::getBufferedRows();
        if ($rows !== []) {
            RegistryFlushes::$flushed[] = [static::class, $rows];
        }
        static::clearBuffer();

        return null;
    }
}

/**
 * A model that uses HasBufferedInserts again and records its flush, as RegistryPlainModel does.
 */
class RegistryReusingModel extends BaseModel
{
    use HasBufferedInserts;

    public static function flushBuffer(): ?Statement
    {
        $rows = static::getBufferedRows();
        if ($rows !== []) {
            RegistryFlushes::$flushed[] = [static::class, $rows];
        }
        static::clearBuffer();

        return null;
    }
}

/**
 * A child of the model that uses the trait again: it buffers in its parent's copy of the trait.
 */
class RegistryReusingChildModel extends RegistryReusingModel
{
}

/**
 * A model that uses the trait again and whose flush fails, keeping its rows.
 */
class RegistryFailingReusingModel extends BaseModel
{
    use HasBufferedInserts;

    public static function flushBuffer(): ?Statement
    {
        throw new RuntimeException('The flush of the test model failed.');
    }
}
