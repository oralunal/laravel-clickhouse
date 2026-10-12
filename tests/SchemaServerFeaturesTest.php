<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Oralunal\LaravelClickHouse\SchemaBlueprint;
use RuntimeException;

/**
 * time(), fullText() and vectorIndex() against the server: they create a Time column, a text index and a vector
 * similarity index where the server turns their settings on, as ClickHouse 26.3 and 26.8 do, and are refused before
 * anything is sent where it does not, as on 24.8, which reads Time as Int64. JSON generated columns work on every
 * version. tests/Unit/SchemaBlueprintSqlTest.php checks the statements.
 */
class SchemaServerFeaturesTest extends TestCase
{
    private const TABLE = 'schema_server_features';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('clickhouse')->dropIfExists(self::TABLE);
    }

    protected function tearDown(): void
    {
        Schema::connection('clickhouse')->dropIfExists(self::TABLE);

        parent::tearDown();
    }

    public function testTimeColumns(): void
    {
        $create = fn () => Schema::connection('clickhouse')->create(self::TABLE, function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->time('opens_at');
            $table->time('closes_at', 3)->nullable();
            $table->orderBy('id');
        });

        if (!$this->serverTurnsOn('enable_time_time64_type')) {
            $this->assertRefusedBeforeAnythingIsSent($create, 'ClickHouse has no time-of-day type for time() (column [opens_at]) on this server');

            return;
        }

        $create();
        DB::connection('clickhouse')->table(self::TABLE)->insert([
            ['id' => 1, 'opens_at' => '09:00:00', 'closes_at' => '17:30:00.250'],
            ['id' => 2, 'opens_at' => '23:59:59', 'closes_at' => null],
        ]);

        $this->assertSame(['Time', 'Nullable(Time64(3))'], $this->types(['opens_at', 'closes_at']));
        $this->assertSame(
            [[1, '09:00:00', '17:30:00.250'], [2, '23:59:59', null]],
            $this->select('SELECT id, opens_at, closes_at FROM ' . self::TABLE . ' ORDER BY id')
        );
        $this->assertSame([2], DB::connection('clickhouse')->table(self::TABLE)->where('opens_at', '>', '12:00:00')->pluck('id')->all());
    }

    /**
     * A text index speeds up whereFullText(), and a vector similarity index the vector queries.
     */
    public function testTextAndVectorIndexes(): void
    {
        $create = fn () => Schema::connection('clickhouse')->create(self::TABLE, function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->text('body')->fulltext();
            $table->vector('embedding', 3)->index();
            $table->orderBy('id');
        });

        if (!$this->serverTurnsOn('enable_full_text_index')) {
            $this->assertRefusedBeforeAnythingIsSent($create, 'ClickHouse has no text index for fullText() (index [schema_server_features_body_fulltext]');

            return;
        }

        $create();
        DB::connection('clickhouse')->table(self::TABLE)->insert([
            ['id' => 1, 'body' => 'The quick brown fox', 'embedding' => [1.0, 0.0, 0.0]],
            ['id' => 2, 'body' => 'Lazy dogs sleep', 'embedding' => [0.0, 1.0, 0.0]],
            ['id' => 3, 'body' => 'A quick dog', 'embedding' => [0.9, 0.1, 0.0]],
        ]);

        $this->assertSame(
            [
                ['schema_server_features_body_fulltext', 'text'],
                ['schema_server_features_embedding_vectorindex', 'vector_similarity'],
            ],
            $this->select(
                'SELECT name, type FROM system.data_skipping_indices WHERE database = currentDatabase() AND table = '
                . "'" . self::TABLE . "' ORDER BY name"
            )
        );
        $query = fn () => DB::connection('clickhouse')->table(self::TABLE);
        $this->assertSame([1, 3], $query()->whereFullText('body', 'quick')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 3], $query()->orderByVectorDistance('embedding', [1, 0, 0])->limit(2)->pluck('id')->all());

        Schema::connection('clickhouse')->table(self::TABLE, function (SchemaBlueprint $table) {
            $table->dropFullText(['body']);
            $table->dropVectorIndex(['embedding']);
        });
        $this->assertSame([], $this->select(
            "SELECT name FROM system.data_skipping_indices WHERE database = currentDatabase() AND table = '" . self::TABLE . "'"
        ));

        Schema::connection('clickhouse')->table(self::TABLE, function (SchemaBlueprint $table) {
            $table->fullText('body', 'body_ngrams', 'ngrams(3)')->materialize();
            $table->vectorIndex('embedding')->dimensions(3);
        });
        $this->assertSame([['body_ngrams'], ['schema_server_features_embedding_vectorindex']], $this->select(
            "SELECT name FROM system.data_skipping_indices WHERE database = currentDatabase() AND table = '"
            . self::TABLE . "' ORDER BY name"
        ));
    }

    /**
     * virtualAsJson() and storedAsJson() read the value of a JSON selector as the column's type.
     */
    public function testJsonGeneratedColumns(): void
    {
        Schema::connection('clickhouse')->create(self::TABLE, function (SchemaBlueprint $table) {
            $table->unsignedInteger('id');
            $table->json('payload');
            $table->string('color')->virtualAsJson('payload->color');
            $table->integer('size')->nullable()->storedAsJson('payload->dims->size');
            $table->orderBy('id');
        });
        DB::connection('clickhouse')->table(self::TABLE)->insert([
            ['id' => 1, 'payload' => '{"color":"red","dims":{"size":3}}'],
            ['id' => 2, 'payload' => '{"dims":{"size":"large"}}'],
            ['id' => 3, 'payload' => ''],
        ]);

        $this->assertSame(
            [[1, 'red', 3], [2, '', null], [3, '', null]],
            $this->select('SELECT id, color, size FROM ' . self::TABLE . ' ORDER BY id')
        );
    }

    /**
     * Determine if the server turns on a setting.
     */
    private function serverTurnsOn(string $setting): bool
    {
        return $this->select("SELECT value FROM system.settings WHERE name = '{$setting}'") === [[1]];
    }

    /**
     * @param \Closure(): mixed $create
     */
    private function assertRefusedBeforeAnythingIsSent(\Closure $create, string $message): void
    {
        try {
            $create();
            $this->fail('Schema::create() should throw');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith($message, $exception->getMessage());
        }

        $this->assertFalse(Schema::connection('clickhouse')->hasTable(self::TABLE));
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function types(array $columns): array
    {
        return array_map(fn (string $column): string => (string) $this->select(
            "SELECT type FROM system.columns WHERE database = currentDatabase() AND table = '" . self::TABLE
            . "' AND name = '{$column}'"
        )[0][0], $columns);
    }

    /**
     * @return list<list<mixed>>
     */
    private function select(string $sql): array
    {
        return array_map(
            fn (array $row): array => array_map(fn (mixed $value): mixed => is_string($value) && ctype_digit($value) ? (int) $value : $value, array_values($row)),
            DB::connection('clickhouse')->getClient()->select($sql)->rows()
        );
    }
}
