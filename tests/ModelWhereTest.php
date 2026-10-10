<?php

namespace Tests;

use Illuminate\Support\Facades\DB;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Models\Example;
use Tests\Models\Example3;

/**
 * A model that reads examples and writes its mutations to examples3.
 */
class ModelWithSourcesTable extends BaseModel
{
    protected $table = 'examples';

    protected $tableSources = 'examples3';
}

class ModelWhereTest extends TestCase
{
    /**
     * @return array<string, array{array<int, mixed>}>
     */
    public static function whereArgumentsProvider(): array
    {
        return [
            'value' => [['f_int', 1]],
            'operator and value' => [['f_int', '>', 1]],
            'null value' => [['f_int', null]],
            'equals null' => [['f_int', '=', null]],
            'not equals null' => [['f_int', '!=', null]],
            'concat operator' => [['f_int', '>', 1, Operator::OR]],
            'closure' => [[fn (Builder $q) => $q->where('f_int', 1)->orWhere('f_int', 2)]],
            'value and explicit null' => [['f_int', 0, null]],
            'null operator and null value' => [['f_int', null, null]],
            'not equals as <>' => [['f_int', '<>', 1]],
        ];
    }

    /**
     * Model::where() must build the same condition as the builder's where() for the same arguments.
     *
     * @param array<int, mixed> $arguments
     */
    #[DataProvider('whereArgumentsProvider')]
    public function testModelWherePassesItsArgumentsToTheBuilderAsGiven(array $arguments): void
    {
        $this->assertSame(
            Example::select()->where(...$arguments)->toSql(),
            Example::where(...$arguments)->toSql()
        );
    }

    /**
     * Model::query() begins the same query as Model::where(), without a condition.
     *
     * @param array<int, mixed> $arguments
     */
    #[DataProvider('whereArgumentsProvider')]
    public function testQueryBeginsTheQueryThatWhereBegins(array $arguments): void
    {
        $this->assertSame(Example::where(...$arguments)->toSql(), Example::query()->where(...$arguments)->toSql());
    }

    /**
     * Any method of the builder can follow query(), such as the Laravel-style where variants.
     */
    public function testQueryReachesEveryBuilderMethod(): void
    {
        $this->assertSame('SELECT * FROM `examples`', Example::query()->toSql());
        $this->assertSame(
            "SELECT * FROM `examples` WHERE toDate32(`created_at`) = '2024-01-05'",
            Example::query()->whereDate('created_at', '2024-01-05')->toSql()
        );
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` = `f_int2`', Example::query()->whereColumn('f_int', 'f_int2')->toSql());
        $this->assertSame('SELECT * FROM `examples` ORDER BY `f_int` DESC LIMIT 2', Example::query()->orderBy('f_int', 'desc')->limit(2)->toSql());
    }

    /**
     * The query that query() begins has the sources table attached, so a mutation writes to it, as with where().
     * The integer column is read as a string, or compared as an int, since ClickHouse 24.8 quotes 64-bit integers in
     * JSON and 25.8 and later do not.
     */
    public function testQueryMutationsWriteToTheSourcesTable(): void
    {
        Example::truncate();
        Example3::truncate();
        Example::insertAssoc([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2, 'f_string' => 'b']]);
        Example3::insertAssoc([['f_int' => 1, 'f_string' => 'a'], ['f_int' => 2, 'f_string' => 'b']]);

        ModelWithSourcesTable::query()->where('f_int', 1)->delete(true);

        $client = DB::connection('clickhouse')->getClient();
        $this->assertSame(['1', '2'], array_column($client->select('SELECT toString(f_int) AS i FROM examples ORDER BY f_int')->rows(), 'i'));
        $this->assertSame(['2'], array_column($client->select('SELECT toString(f_int) AS i FROM examples3 ORDER BY f_int')->rows(), 'i'));
        $this->assertSame([1, 2], array_map('intval', array_column(ModelWithSourcesTable::query()->select(['f_int'])->orderBy('f_int')->getRows(), 'f_int')));
    }

    /**
     * The model used to drop a null value and pass two arguments, so the operator became the value.
     */
    public function testANullValueNoLongerTurnsTheOperatorIntoTheValue(): void
    {
        $this->assertStringNotContainsString("'='", Example::where('f_int', '=', null)->toSql());
        $this->assertStringNotContainsString("'!='", Example::where('f_int', '!=', null)->toSql());
    }

    /**
     * A Laravel-style forwarding helper, fn ($c, $op = null, $v = null) => Model::where($c, $op, $v), passes an
     * explicit null third argument. The second argument is then the value unless it is an operator.
     */
    public function testAnExplicitNullThirdArgumentKeepsTheSecondArgumentAsTheValue(): void
    {
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` = 0', Example::where('f_int', 0, null)->toSql());
        $this->assertSame("SELECT * FROM `examples` WHERE `f_string` = 'x'", Example::where('f_string', 'x', null)->toSql());
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` IN (1, 2)', Example::where('f_int', [1, 2], null)->toSql());
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` IS NULL', Example::where('f_int', null, null)->toSql());
    }

    public function testNotEqualsWrittenAsLessThanGreaterThan(): void
    {
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` != 1', Example::where('f_int', '<>', 1)->toSql());
    }

    public function testACollectionValueIsComparedWithIn(): void
    {
        $this->assertSame('SELECT * FROM `examples` WHERE `f_int` IN (1, 2)', Example::where('f_int', collect([1, 2]))->toSql());
    }

    /**
     * The model used to pass a closure with a null operator, which compiled to "WHERE (...) =".
     */
    public function testClosure(): void
    {
        $this->assertSame(
            'SELECT * FROM `examples` WHERE (`f_int` = 1 OR `f_int` = 2)',
            Example::where(fn (Builder $q) => $q->where('f_int', 1)->orWhere('f_int', 2))->toSql()
        );
    }
}
