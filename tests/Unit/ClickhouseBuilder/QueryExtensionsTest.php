<?php

declare(strict_types=1);

namespace Tests\Unit\ClickhouseBuilder;

use ArrayIterator;
use Carbon\Carbon;
use ClickHouseDB\Client;
use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Database\Query\Grammars\Grammar as LaravelQueryGrammar;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use IteratorAggregate;
use Oralunal\LaravelClickHouse\Builder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Exceptions\GrammarException;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\BaseBuilder;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinStrict;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\JoinType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Operator;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\UnionType;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Grammar;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Identifier;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\JoinClause;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Tuple;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use stdClass;
use Stringable;
use UnexpectedValueException;
use function Oralunal\LaravelClickHouse\ClickhouseBuilder\raw;

class QueryExtensionsTest extends TestCase
{
    private function builder(): TestBuilder
    {
        return new TestBuilder();
    }

    private function selectId(string $table): TestBuilder
    {
        return $this->builder()->select('id')->from($table);
    }

    private function moment(): DateTimeImmutable
    {
        return new DateTimeImmutable('2024-01-02 03:04:05.678');
    }

    private function stringable(string $value): Stringable
    {
        return new class ($value) implements Stringable {
            public function __construct(private string $value)
            {
            }

            public function __toString(): string
            {
                return $this->value;
            }
        };
    }

    public function test_wrap_renders_date_times_bools_enums_and_stringables(): void
    {
        $grammar = new Grammar();

        $this->assertSame("'2024-01-02 03:04:05'", $grammar->wrap($this->moment()));
        $this->assertSame("'2024-01-02 03:04:05'", $grammar->wrap(new DateTime('2024-01-02 03:04:05')));
        $this->assertSame(
            "'2024-01-02 03:04:05'",
            $grammar->wrap(new DateTimeImmutable('2024-01-02 03:04:05', new DateTimeZone('Asia/Istanbul')))
        );
        $this->assertSame(1, $grammar->wrap(true));
        $this->assertSame(0, $grammar->wrap(false));
        $this->assertSame("'active'", $grammar->wrap(StringBackedEnumFixture::Active));
        $this->assertSame(3, $grammar->wrap(IntBackedEnumFixture::High));
        $this->assertSame("'Hearts'", $grammar->wrap(UnitEnumFixture::Hearts));
        $this->assertSame("'it\\'s'", $grammar->wrap($this->stringable("it's")));
    }

    public function test_wrap_keeps_existing_renderings(): void
    {
        $grammar = new Grammar();

        $this->assertSame('raw', $grammar->wrap(new Expression('raw')));
        $this->assertSame('`db`.`col` AS `c`', $grammar->wrap(new Identifier('db.col as c')));
        $this->assertSame("'text'", $grammar->wrap('text'));
        $this->assertSame("'10'", $grammar->wrap('10'));
        $this->assertSame(10, $grammar->wrap(10));
        $this->assertSame('1.5', $grammar->wrap(1.5));
        $this->assertSame('null', $grammar->wrap(null));
        $this->assertSame(["'a'", 1, 0], $grammar->wrap(['a', 1, false]));
    }

    public function test_wrap_rejects_values_it_cannot_render(): void
    {
        $grammar = new Grammar();

        foreach ([new stdClass(), function () {
        }, new \Illuminate\Database\Query\Expression('count()')] as $value) {
            try {
                $grammar->wrap($value);
                $this->fail('Expected an exception for '.get_debug_type($value));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(get_debug_type($value), $exception->getMessage());
            }
        }
    }

    /**
     * A collection is Stringable as JSON, so it used to be quoted as a JSON string literal.
     */
    public function test_wrap_rejects_collections_and_other_iterables(): void
    {
        $grammar = new Grammar();
        $iterableArrayable = new class () implements Arrayable, IteratorAggregate {
            public function toArray(): array
            {
                return ['a'];
            }

            public function getIterator(): ArrayIterator
            {
                return new ArrayIterator(['a']);
            }
        };

        foreach ([collect(['a']), LazyCollection::make(['a']), new ArrayIterator(['a']), $iterableArrayable] as $value) {
            try {
                $grammar->wrap($value);
                $this->fail('Expected an exception for '.get_debug_type($value));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(get_debug_type($value), $exception->getMessage());
                $this->assertStringContainsString('Pass a PHP array instead', $exception->getMessage());
            }
        }
    }

    public function test_wrap_still_quotes_string_objects_that_are_not_collections(): void
    {
        $grammar = new Grammar();

        $this->assertSame("'a'", $grammar->wrap(Str::of('a')));
        $this->assertSame("'it\\'s'", $grammar->wrap(Str::of("it's")));
        $this->assertSame(
            "'0f1e2d3c-4b5a-4968-8776-655443322110'",
            $grammar->wrap(Uuid::fromString('0f1e2d3c-4b5a-4968-8776-655443322110'))
        );
        $this->assertSame("'2024-01-02 03:04:05'", $grammar->wrap(Carbon::parse('2024-01-02 03:04:05')));
        $this->assertSame("'active'", $grammar->wrap(StringBackedEnumFixture::Active));
    }

    public function test_a_collection_that_reaches_the_grammar_throws(): void
    {
        $builders = [
            $this->builder()->withAlias('ids', collect([1, 2]))->select('ids'),
            $this->builder()->from('t')->whereIn('id', [collect([1, 2])]),
        ];

        foreach ($builders as $builder) {
            try {
                $builder->toSql();
                $this->fail('Expected an InvalidArgumentException');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Illuminate\Support\Collection', $exception->getMessage());
            }
        }
    }

    public function test_conditions_render_date_times_bools_enums_and_stringables(): void
    {
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `created_at` > '2024-01-02 03:04:05'",
            $this->builder()->from('t')->where('created_at', '>', $this->moment())->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `flag` = 1 OR `flag` = 0',
            $this->builder()->from('t')->where('flag', true)->orWhere('flag', false)->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` PREWHERE `status` = 'active' OR `suit` != 'Hearts'",
            $this->builder()->from('t')
                ->preWhere('status', StringBackedEnumFixture::Active)
                ->orPreWhere('suit', '!=', UnitEnumFixture::Hearts)
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` GROUP BY `priority` HAVING `priority` >= 3 OR `name` = 'it\\'s'",
            $this->builder()->from('t')->groupBy('priority')
                ->having('priority', '>=', IntBackedEnumFixture::High)
                ->orHaving('name', $this->stringable("it's"))
                ->toSql()
        );
    }

    public function test_in_and_between_lists_render_date_times_bools_and_enums(): void
    {
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `created_at` IN ('2024-01-02 03:04:05', '2024-02-03 00:00:00')",
            $this->builder()->from('t')
                ->whereIn('created_at', [$this->moment(), new DateTime('2024-02-03')])
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `status` NOT IN ('active', 3, 'Hearts')",
            $this->builder()->from('t')
                ->whereNotIn('status', [StringBackedEnumFixture::Active, IntBackedEnumFixture::High, UnitEnumFixture::Hearts])
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `flag` GLOBAL IN (1, 0)',
            $this->builder()->from('t')->whereGlobalIn('flag', [true, false])->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `created_at` BETWEEN '2024-01-02 03:04:05' AND '2024-02-03 00:00:00'",
            $this->builder()->from('t')
                ->whereBetween('created_at', [$this->moment(), new DateTime('2024-02-03')])
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `flag` BETWEEN 0 AND 1',
            $this->builder()->from('t')->whereBetween('flag', [false, true])->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` PREWHERE `status` IN ('paused') GROUP BY `status` HAVING `status` IN ('paused')",
            $this->builder()->from('t')
                ->preWhereIn('status', [StringBackedEnumFixture::Paused])
                ->groupBy('status')
                ->havingIn('status', [StringBackedEnumFixture::Paused])
                ->toSql()
        );
    }

    public function test_in_methods_accept_collections(): void
    {
        $values = collect(['u1', 'u2']);

        $this->assertEquals(
            "SELECT * FROM `t` WHERE `uuid` IN ('u1', 'u2')",
            $this->builder()->from('t')->whereIn('uuid', $values)->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `uuid` NOT IN ('u1', 'u2')",
            $this->builder()->from('t')->whereNotIn('uuid', $values)->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `a` = 1 OR `uuid` IN ('u1', 'u2') OR `uuid` NOT IN ('u1', 'u2')",
            $this->builder()->from('t')->where('a', 1)->orWhereIn('uuid', $values)->orWhereNotIn('uuid', $values)->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `uuid` GLOBAL IN ('u1', 'u2') AND `uuid` GLOBAL NOT IN ('u1') "
            ."OR `uuid` GLOBAL IN ('u2') OR `uuid` GLOBAL NOT IN ('u2')",
            $this->builder()->from('t')
                ->whereGlobalIn('uuid', $values)
                ->whereGlobalNotIn('uuid', collect(['u1']))
                ->orWhereGlobalIn('uuid', collect(['u2']))
                ->orWhereGlobalNotIn('uuid', collect(['u2']))
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` PREWHERE `uuid` IN ('u1') AND `uuid` NOT IN ('u2') OR `uuid` IN ('u2') OR `uuid` NOT IN ('u1')",
            $this->builder()->from('t')
                ->preWhereIn('uuid', collect(['u1']))
                ->preWhereNotIn('uuid', collect(['u2']))
                ->orPreWhereIn('uuid', collect(['u2']))
                ->orPreWhereNotIn('uuid', collect(['u1']))
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` GROUP BY `uuid` HAVING `uuid` IN ('u1') AND `uuid` NOT IN ('u2') OR `uuid` IN ('u2') OR `uuid` NOT IN ('u1')",
            $this->builder()->from('t')->groupBy('uuid')
                ->havingIn('uuid', collect(['u1']))
                ->havingNotIn('uuid', collect(['u2']))
                ->orHavingIn('uuid', collect(['u2']))
                ->orHavingNotIn('uuid', collect(['u1']))
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `uuid` IN ('u1') AND `id` IN (1, 2) AND `status` IN ('active', 3)",
            $this->builder()->from('t')
                ->whereIn('uuid', LazyCollection::make(['u1']))
                ->whereIn('id', collect(['a' => 1, 'b' => 2]))
                ->whereIn('status', collect([StringBackedEnumFixture::Active, IntBackedEnumFixture::High]))
                ->toSql()
        );
    }

    public function test_an_empty_collection_works_like_an_empty_array(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE 0 = 1', $this->builder()->from('t')->whereIn('uuid', collect())->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1 AND 1 = 1',
            $this->builder()->from('t')->where('a', 1)->whereNotIn('uuid', collect())->toSql()
        );
    }

    public function test_where_converts_a_collection_value_to_an_array(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `id` IN (1, 2)', $this->builder()->from('t')->where('id', collect([1, 2]))->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `id` IN (1, 2) AND `id` NOT IN (3)',
            $this->builder()->from('t')
                ->where('id', Operator::IN, collect([1, 2]))
                ->where('id', Operator::NOT_IN, collect([3]))
                ->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE `id` IN (1, 2)', $this->builder()->from('t')->where('id', collect([1, 2]), null)->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1 OR `id` IN (1, 2)',
            $this->builder()->from('t')->where('a', 1)->orWhere('id', collect([1, 2]))->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE `id` IN (1) GROUP BY `id` HAVING `id` IN (2)',
            $this->builder()->from('t')->preWhere('id', collect([1]))->groupBy('id')->having('id', collect([2]))->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `id` IN (1, 2) AND `id` NOT IN (3)',
            $this->builder()->from('t')
                ->where('id', LazyCollection::make([1, 2]))
                ->where('id', Operator::NOT_IN, new EloquentCollection([3]))
                ->toSql()
        );
    }

    /**
     * Only a collection is a list of values in where(), preWhere() and having(). Any other Arrayable, such as an
     * Eloquent model, would become the list of its attribute values, so it reaches Grammar::wrap(), which throws.
     * The In methods still convert any Arrayable, as Laravel's whereIn() does.
     */
    public function test_a_where_value_that_is_arrayable_but_not_a_collection_throws(): void
    {
        $arrayable = new class () implements Arrayable {
            public function toArray(): array
            {
                return [1, 2];
            }
        };

        $builders = [
            $this->builder()->from('t')->where('user_id', $arrayable),
            $this->builder()->from('t')->where('user_id', $arrayable, null),
            $this->builder()->from('t')->where('user_id', '=', $arrayable),
            $this->builder()->from('t')->where('user_id', Operator::IN, $arrayable),
            $this->builder()->from('t')->where('a', 1)->orWhere('user_id', $arrayable),
            $this->builder()->from('t')->preWhere('user_id', $arrayable),
            $this->builder()->from('t')->groupBy('user_id')->having('user_id', $arrayable),
        ];

        foreach ($builders as $builder) {
            try {
                $builder->toSql();
                $this->fail('Expected an InvalidArgumentException');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Arrayable@anonymous', $exception->getMessage());
                $this->assertStringContainsString(
                    'Pass a scalar value, such as $model->getKey(), or a PHP array.',
                    $exception->getMessage()
                );
            }
        }

        $this->assertEquals(
            'SELECT * FROM `t` WHERE `user_id` IN (1, 2) OR `user_id` NOT IN (1, 2)',
            $this->builder()->from('t')->whereIn('user_id', $arrayable)->orWhereNotIn('user_id', $arrayable)->toSql()
        );
    }

    public function test_a_model_as_a_where_value_throws_with_a_hint_to_pass_its_key(): void
    {
        $model = new class (['id' => 5]) extends Model {
            protected $guarded = [];
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot render a value of type Illuminate\Database\Eloquent\Model@anonymous in a ClickHouse query.'
            .' Pass a scalar value, such as $model->getKey(), or a PHP array.'
        );

        $this->builder()->from('t')->where('user_id', $model)->toSql();
    }

    /**
     * An empty list compiles as in Laravel: IN matches no row (0 = 1), NOT IN matches every row (1 = 1), and the
     * condition keeps the AND or OR of the call.
     */
    public function test_an_empty_list_in_an_in_method_keeps_the_boolean_of_the_call(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `b` = 1 OR 0 = 1',
            $this->builder()->from('t')->where('b', 1)->orWhereIn('a', [])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `b` = 1 OR 1 = 1',
            $this->builder()->from('t')->where('b', 1)->orWhereNotIn('a', collect())->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE 1 = 1 OR `b` = 1',
            $this->builder()->from('t')->whereNotIn('a', [])->orWhere('b', 1)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (`b` = 1 OR 0 = 1) AND `c` = 2',
            $this->builder()->from('t')
                ->where(function (BaseBuilder $query) {
                    $query->where('b', 1)->orWhereIn('a', collect());
                })
                ->where('c', 2)
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE 0 = 1 AND 1 = 1 OR 0 = 1 OR 1 = 1',
            $this->builder()->from('t')
                ->whereGlobalIn('a', [])
                ->whereGlobalNotIn('a', collect())
                ->orWhereGlobalIn('a', collect())
                ->orWhereGlobalNotIn('a', [])
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE 0 = 1 AND 1 = 1 OR 0 = 1 OR 1 = 1',
            $this->builder()->from('t')
                ->preWhereIn('a', [])
                ->preWhereNotIn('a', collect())
                ->orPreWhereIn('a', collect())
                ->orPreWhereNotIn('a', [])
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING 0 = 1 AND 1 = 1 OR 0 = 1 OR 1 = 1',
            $this->builder()->from('t')->groupBy('a')
                ->havingIn('a', [])
                ->havingNotIn('a', collect())
                ->orHavingIn('a', collect())
                ->orHavingNotIn('a', [])
                ->toSql()
        );
    }

    public function test_an_empty_list_as_a_where_value_compiles_like_the_in_methods(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE 0 = 1', $this->builder()->from('t')->where('a', [])->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE 0 = 1', $this->builder()->from('t')->where('a', collect())->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `b` = 1 OR 0 = 1 OR 1 = 1',
            $this->builder()->from('t')->where('b', 1)->orWhere('a', [])->orWhere('a', Operator::NOT_IN, collect())->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE 0 = 1 AND 1 = 1 AND 0 = 1 AND 1 = 1',
            $this->builder()->from('t')
                ->where('a', Operator::IN, [])
                ->where('a', Operator::NOT_IN, collect())
                ->where('a', Operator::GLOBAL_IN, [])
                ->where('a', Operator::GLOBAL_NOT_IN, collect())
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE 0 = 1 OR 1 = 1 GROUP BY `a` HAVING 0 = 1 OR 1 = 1',
            $this->builder()->from('t')
                ->preWhere('a', [])
                ->orPreWhere('a', Operator::NOT_IN, collect())
                ->groupBy('a')
                ->having('a', collect())
                ->orHaving('a', Operator::NOT_IN, [])
                ->toSql()
        );
    }

    public function test_global_in_operators_take_a_list_like_in(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` GLOBAL IN (1, 2)',
            $this->builder()->from('t')->where('a', Operator::GLOBAL_IN, [1, 2])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` GLOBAL IN (1, 2)',
            $this->builder()->from('t')->where('a', Operator::GLOBAL_IN, collect([1, 2]))->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `b` = 1 OR `a` GLOBAL NOT IN (3, 4)',
            $this->builder()->from('t')->where('b', 1)->orWhere('a', Operator::GLOBAL_NOT_IN, collect([3, 4]))->toSql()
        );
    }

    public function test_an_array_value_with_an_operator_that_takes_no_list_throws(): void
    {
        $calls = [
            'The = operator' => fn () => $this->builder()->from('t')->where('a', '=', [1]),
            'The != operator' => fn () => $this->builder()->from('t')->where('a', '!=', collect([1, 2])),
            'The > operator' => fn () => $this->builder()->from('t')->orWhere('a', '>', []),
            'The LIKE operator' => fn () => $this->builder()->from('t')->preWhere('a', 'LIKE', ['x%']),
            'The <= operator' => fn () => $this->builder()->from('t')->groupBy('a')->having('a', '<=', collect([1])),
            'A condition without an operator' => fn () => $this->builder()->from('t')->where('a', null, [1, 2]),
        ];

        foreach ($calls as $subject => $call) {
            try {
                $call();
                $this->fail("Expected an InvalidArgumentException for {$subject}");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    "{$subject} does not take a list of values, but an array was given. Use IN or NOT IN, or whereIn(), to compare with a list.",
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * Operators are read in any letter case, so a lowercase IN takes a list like IN. An operator that the Operator
     * enum does not have is still rejected, and named as it was given.
     */
    public function test_a_lowercase_in_operator_takes_a_list(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` IN (1, 2) OR `b` NOT IN (3)',
            $this->builder()->from('t')->where('a', 'in', [1, 2])->orWhere('b', 'not in', collect([3]))->toSql()
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'within' is not part of the enum");

        $this->builder()->from('t')->where('a', 'within', [1, 2]);
    }

    /**
     * As in Laravel, BETWEEN takes the first two values of the list, in order, and leaves out any further value; a
     * list of fewer than two values throws.
     */
    public function test_between_operators_take_the_first_two_values(): void
    {
        foreach ([[Operator::NOT_BETWEEN, [1]], [Operator::BETWEEN, []], [Operator::BETWEEN, ['from' => 1]]] as [$operator, $values]) {
            try {
                $this->builder()->from('t')->where('a', $operator, $values);
                $this->fail("Expected an InvalidArgumentException for {$operator} with ".count($values).' values');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    "The {$operator} operator takes two values, the lower and the upper bound, but the array has "
                    .count($values).'.',
                    $exception->getMessage()
                );
            }
        }

        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` BETWEEN 1 AND 2 OR NOT ( `b` BETWEEN 5 AND 3 )',
            $this->builder()->from('t')
                ->where('a', Operator::BETWEEN, [1, 2, 3])
                ->orWhere('b', Operator::NOT_BETWEEN, ['x' => 5, 'y' => 3, 'z' => 1])
                ->toSql()
        );

        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` BETWEEN 1 AND 2 OR NOT ( `b` BETWEEN 5 AND 9 )',
            $this->builder()->from('t')
                ->where('a', Operator::BETWEEN, collect([1, 2]))
                ->orWhere('b', Operator::NOT_BETWEEN, collect([5, 1, 9])->filter(fn (int $value) => $value > 1))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` BETWEEN 1 AND 2',
            $this->builder()->from('t')->where('a', Operator::BETWEEN, ['from' => 1, 'to' => 2])->toSql()
        );
    }

    public function test_nested_arrays_in_an_in_list_compile_as_tuples(): void
    {
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE (id, name) IN ((1, 'a'), (2, 'b'))",
            $this->builder()->select('id')->from('t')->whereIn(raw('(id, name)'), [[1, 'a'], [2, 'b']])->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` WHERE (id, (name, flag)) NOT IN ((1, ('a', 1)))",
            $this->builder()->from('t')->whereNotIn(raw('(id, (name, flag))'), [[1, ['a', true]]])->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` PREWHERE (id, name) IN ((1, 'a')) GROUP BY `id`, `name` HAVING (id, name) IN ((2, 'b'))",
            $this->builder()->from('t')
                ->preWhereIn(raw('(id, name)'), collect([collect([1, 'a'])]))
                ->groupBy('id', 'name')
                ->havingIn(raw('(id, name)'), [[2, 'b']])
                ->toSql()
        );
        $this->assertSame("(1, 'a'), 'b'", (new Grammar())->compileTuple(new Tuple([[1, 'a'], 'b'])));
    }

    public function test_a_value_that_cannot_be_rendered_throws_instead_of_producing_broken_sql(): void
    {
        $builder = $this->builder()->from('t')->where('a', '=', new stdClass());

        $this->expectException(InvalidArgumentException::class);

        $builder->toSql();
    }

    public function test_insert_values_render_date_times_bools_enums_and_arrays(): void
    {
        $sql = (new Grammar())->compileInsert($this->builder()->table('t'), [
            [
                'created_at' => $this->moment(),
                'flag'       => true,
                'status'     => StringBackedEnumFixture::Active,
                'tags'       => ['a', "b'c"],
                'scores'     => [1.5, 2],
                'other'      => [],
                'name'       => null,
            ],
        ]);

        $this->assertEquals(
            "INSERT INTO `t` (`created_at`, `flag`, `status`, `tags`, `scores`, `other`, `name`) FORMAT Values "
            ."('2024-01-02 03:04:05', 1, 'active', ['a', 'b\\'c'], [1.5, 2], [], null)",
            $sql
        );
    }

    public function test_insert_without_a_table_still_throws_a_grammar_exception(): void
    {
        $this->expectException(GrammarException::class);
        $this->expectExceptionMessage('Missed table for insert statement.');

        (new Grammar())->compileInsert($this->builder()->from(null), [['column' => 'value']]);
    }

    public function test_where_null_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `deleted_at` IS NULL',
            $this->builder()->from('t')->whereNull('deleted_at')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` IS NULL AND `b` IS NULL',
            $this->builder()->from('t')->whereNull(['a', 'b'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` = 1 OR `a` IS NULL OR `b`.`c` IS NULL',
            $this->builder()->from('t')->where('x', 1)->orWhereNull(['a', 'b.c'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE arrayElement(arr, 1) IS NOT NULL AND `a` IS NOT NULL',
            $this->builder()->from('t')->whereNotNull([new Expression('arrayElement(arr, 1)'), 'a'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` = 1 OR `a` IS NOT NULL',
            $this->builder()->from('t')->where('x', 1)->orWhereNotNull('a')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` = 1',
            $this->builder()->from('t')->where('x', 1)->whereNull([])->toSql()
        );
    }

    public function test_pre_where_null_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE `a` IS NULL OR `b` IS NULL AND `c` IS NOT NULL OR `d` IS NOT NULL',
            $this->builder()->from('t')
                ->preWhereNull('a')
                ->orPreWhereNull('b')
                ->preWhereNotNull('c')
                ->orPreWhereNotNull('d')
                ->toSql()
        );
    }

    public function test_having_null_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING `m` IS NULL OR `n` IS NULL AND `o` IS NOT NULL OR `p` IS NOT NULL',
            $this->builder()->from('t')->groupBy('a')
                ->havingNull('m')
                ->orHavingNull('n')
                ->havingNotNull('o')
                ->orHavingNotNull('p')
                ->toSql()
        );
    }

    public function test_comparing_with_an_explicit_null_compiles_to_is_null(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `status` IS NULL', $this->builder()->from('t')->where('status', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `status` IS NULL', $this->builder()->from('t')->where('status', '=', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `status` IS NOT NULL', $this->builder()->from('t')->where('status', '!=', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `status` IS NOT NULL', $this->builder()->from('t')->where('status', '<>', null)->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1 OR `status` IS NULL OR `name` IS NOT NULL',
            $this->builder()->from('t')->where('a', 1)->orWhere('status', null)->orWhere('name', '!=', null)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE `status` IS NULL OR `name` IS NOT NULL',
            $this->builder()->from('t')->preWhere('status', null)->orPreWhere('name', '<>', null)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING `m` IS NULL OR `n` IS NOT NULL',
            $this->builder()->from('t')->groupBy('a')->having('m', '=', null)->orHaving('n', '!=', null)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE arrayElement(arr, 1) IS NULL AND `x` IS NOT NULL',
            $this->builder()->from('t')
                ->where(new Expression('arrayElement(arr, 1)'), null)
                ->where(new Identifier('x'), '!=', null)
                ->toSql()
        );
    }

    public function test_only_an_explicit_null_compiles_to_is_null(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `a` = 0', $this->builder()->from('t')->where('a', 0)->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `a` = ''", $this->builder()->from('t')->where('a', '')->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `a` = 0', $this->builder()->from('t')->where('a', '=', false)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `a` != 0', $this->builder()->from('t')->where('a', '!=', 0)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `a`', $this->builder()->from('t')->where('a')->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE a = 1 OR b = 2', $this->builder()->from('t')->whereRaw('a = 1')->orWhereRaw('b = 2')->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (`a` = 1 OR `b` IS NULL)',
            $this->builder()->from('t')->where(function (BaseBuilder $query) {
                $query->where('a', 1)->orWhere('b', null);
            })->toSql()
        );
    }

    public function test_an_operator_argument_that_is_not_an_operator_is_the_value_when_the_value_is_null(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `processed` = 0', $this->builder()->from('t')->where('processed', 0, null)->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `status` = 'active'", $this->builder()->from('t')->where('status', 'active', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `id` IN (1, 2)', $this->builder()->from('t')->where('id', [1, 2], null)->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `created_at` = '2024-01-02 03:04:05'",
            $this->builder()->from('t')->where('created_at', $this->moment(), null)->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE `flag` = 0', $this->builder()->from('t')->where('flag', false, null)->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `status` = 'active'",
            $this->builder()->from('t')->where('status', StringBackedEnumFixture::Active, null)->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE `status` IS NULL', $this->builder()->from('t')->where('status', null, null)->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1 OR `b` = 0 OR `c` IS NULL',
            $this->builder()->from('t')
                ->where('a', 1)
                ->where('b', 0, null, Operator::OR)
                ->where('c', null, null, Operator::OR)
                ->toSql()
        );
    }

    public function test_a_laravel_style_forwarding_helper_keeps_its_condition(): void
    {
        $filter = function (string $column, $operator = null, $value = null): TestBuilder {
            return $this->builder()->from('t')->where($column, $operator, $value);
        };

        $this->assertEquals('SELECT * FROM `t` WHERE `processed` = 0', $filter('processed', 0)->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `status` = 'active'", $filter('status', 'active')->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `processed` > 0', $filter('processed', '>', 0)->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `name` LIKE 'a%'", $filter('name', 'LIKE', 'a%')->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `deleted_at` IS NULL', $filter('deleted_at', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `deleted_at` IS NULL', $filter('deleted_at', '=', null)->toSql());
        $this->assertEquals('SELECT * FROM `t` WHERE `deleted_at` IS NOT NULL', $filter('deleted_at', '!=', null)->toSql());
    }

    public function test_or_variants_take_the_value_from_the_operator_argument_like_their_statements(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1 OR `b` = 0 OR `c` = 0 OR `d` IS NULL OR `e` IS NULL',
            $this->builder()->from('t')
                ->where('a', 1)
                ->orWhere('b', 0, null)
                ->orWhere('c', 0)
                ->orWhere('d', null, null)
                ->orWhere('e', null)
                ->toSql()
        );
        $this->assertEquals(
            "SELECT * FROM `t` PREWHERE `a` = 0 OR `b` = 'x' OR `c` IN (1, 2) OR `d` = 0",
            $this->builder()->from('t')
                ->preWhere('a', 0, null)
                ->orPreWhere('b', 'x', null)
                ->orPreWhere('c', [1, 2], null)
                ->orPreWhere('d', 0)
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING `c` = 0 OR `d` IN (1, 2) OR `e` IS NULL OR `f` = 1',
            $this->builder()->from('t')->groupBy('a')
                ->having('c', 0, null)
                ->orHaving('d', [1, 2], null)
                ->orHaving('e', null, null)
                ->orHaving('f', 1)
                ->toSql()
        );
    }

    /**
     * Operators are recognised in any letter case, so a lowercase operator is not taken as the value: it compiles
     * as the upper-case operator does.
     */
    public function test_a_lowercase_operator_is_not_taken_as_the_value(): void
    {
        $sql = $this->builder()->from('t')->where('name', 'like', null)->toSql();

        $this->assertSame($this->builder()->from('t')->where('name', Operator::LIKE, null)->toSql(), $sql);
        $this->assertStringNotContainsString("'like'", $sql);
        $this->assertEquals('SELECT * FROM `t` WHERE `name` IS NULL', $this->builder()->from('t')->where('name', 'is null', null)->toSql());
    }

    public function test_not_equals_can_be_written_as_less_than_greater_than(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `a` != 1', $this->builder()->from('t')->where('a', '<>', 1)->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `a` = 1 OR `b` != 'x'",
            $this->builder()->from('t')->where('a', 1)->orWhere('b', '<>', 'x')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE `a` != 1 OR `b` != 2 GROUP BY `a` HAVING `c` != 3 OR `d` != 4',
            $this->builder()->from('t')
                ->preWhere('a', '<>', 1)
                ->orPreWhere('b', '<>', 2)
                ->groupBy('a')
                ->having('c', '<>', 3)
                ->orHaving('d', '<>', 4)
                ->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE `a` IS NOT NULL', $this->builder()->from('t')->where('a', '<>', null)->toSql());
    }

    public function test_less_than_greater_than_as_the_second_of_two_arguments_is_the_value(): void
    {
        $this->assertEquals("SELECT * FROM `t` WHERE `a` = '<>'", $this->builder()->from('t')->where('a', '<>')->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `a` = 1 OR `b` = '<>'",
            $this->builder()->from('t')->where('a', 1)->orWhere('b', '<>')->toSql()
        );
    }

    public function test_is_null_operators_with_a_value_throw(): void
    {
        $calls = [
            fn () => $this->builder()->from('t')->where('a', Operator::IS_NULL, 5),
            fn () => $this->builder()->from('t')->where('a', Operator::IS_NOT_NULL, 'x'),
            fn () => $this->builder()->from('t')->orWhere('a', Operator::IS_NULL, 0),
            fn () => $this->builder()->from('t')->preWhere('a', Operator::IS_NULL, false),
            fn () => $this->builder()->from('t')->having('a', Operator::IS_NOT_NULL, 0),
            fn () => $this->builder()->from('t')->where('a', 'is null', 1),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected an InvalidArgumentException');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('operator does not take a value', $exception->getMessage());
            }
        }

        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` IS NULL OR `b` IS NOT NULL',
            $this->builder()->from('t')->where('a', Operator::IS_NULL, null)->orWhere('b', Operator::IS_NOT_NULL, null)->toSql()
        );
    }

    public function test_calls_without_an_operator_and_a_value_stay_bare_conditions(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `a` OR `b`', $this->builder()->from('t')->where('a')->orWhere('b')->toSql());
        $this->assertEquals('SELECT * FROM `t` PREWHERE `a` OR `b`', $this->builder()->from('t')->preWhere('a')->orPreWhere('b')->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING `a` OR `b`',
            $this->builder()->from('t')->groupBy('a')->having('a')->orHaving('b')->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE a = 1 OR b = 2', $this->builder()->from('t')->whereRaw('a = 1')->orWhereRaw('b = 2')->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE a = 1 OR b = 2',
            $this->builder()->from('t')->preWhereRaw('a = 1')->orPreWhereRaw('b = 2')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING a = 1 OR b = 2',
            $this->builder()->from('t')->groupBy('a')->havingRaw('a = 1')->orHavingRaw('b = 2')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE empty(`s`) OR notEmpty(`u`)',
            $this->builder()->from('t')->whereEmpty('s')->orWhereNotEmpty('u')->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` WHERE flag', $this->builder()->from('t')->where(raw('flag'), null, null)->toSql());
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a`, dictGetString('d', 'b', 'k') as `b` FROM `t` WHERE `a` OR `b`",
            $this->builder()->from('t')->whereDict('d', 'a', 'k')->orWhereDict('d', 'b', 'k')->toSql()
        );
    }

    public function test_where_dict_takes_its_operator_and_value_like_where(): void
    {
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a`, dictGetString('d', 'b', 'k') as `b`, dictGetString('d', 'c', 'k') as `c`, "
            ."dictGetString('d', 'e', 'k') as `e` FROM `t` WHERE `a` = 5 OR `b` = 0 OR `c` IN (1, 2) AND `e` != 'x'",
            $this->builder()->from('t')
                ->whereDict('d', 'a', 'k', 5)
                ->orWhereDict('d', 'b', 'k', 0, null)
                ->whereDict('d', 'c', 'k', collect([1, 2]), null, Operator::OR)
                ->whereDict('d', 'e', 'k', '<>', 'x')
                ->toSql()
        );
    }

    /**
     * The dictionary and attribute names are escaped string literals and the alias is a quoted identifier, so a
     * quote, a backslash or a backtick in them stays part of the name. Before, they were written as given.
     */
    public function test_dictionary_attribute_and_alias_names_are_escaped(): void
    {
        $this->assertEquals(
            "SELECT dictGetString('it\\'s', 'o\\'k', 'k') as `a'b` FROM `t`",
            $this->builder()->from('t')->addSelectDict("it's", "o'k", 'k', "a'b")->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d\\\\', 'a\\\\b', 'k') as `x\\\\` FROM `t`",
            $this->builder()->from('t')->addSelectDict('d\\', 'a\\b', 'k', 'x\\')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d`', 'a`b', 'k') as `x``` FROM `t`",
            $this->builder()->from('t')->addSelectDict('d`', 'a`b', 'k', 'x`')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d\\'\\\\`', 'a\\'b\\\\c`', 'k') as `a'b\\\\c``` FROM `t`",
            $this->builder()->from('t')->addSelectDict("d'\\`", "a'b\\c`", 'k')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d\\'x', 'a\\'b\\\\c`', tuple('k', 1)) as `a'b\\\\c```, dictGetString('d\\\\', 'e`f', 'k') as `e``f` "
            ."FROM `t` WHERE `a'b\\\\c``` = 1 OR `e``f` != 'x'",
            $this->builder()->from('t')
                ->whereDict("d'x", "a'b\\c`", ['k', 1], 1)
                ->orWhereDict('d\\', 'e`f', 'k', '!=', 'x')
                ->toSql()
        );
    }

    /**
     * The condition names the attribute as one identifier, the alias it selects, so an attribute name with a dot or
     * ' as ' is not split. Before, a.b was compared as `a`.`b` and x as y as `x` AS `y`.
     */
    public function test_where_dict_compares_a_dotted_or_aliased_attribute_name_as_one_identifier(): void
    {
        $this->assertEquals(
            "SELECT dictGetString('d', 'a.b', 'k') as `a.b` FROM `t` WHERE `a.b` = 1",
            $this->builder()->from('t')->whereDict('d', 'a.b', 'k', 1)->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a.b', 'k') as `a.b` FROM `t` WHERE `x` = 1 OR `a.b` != 'v'",
            $this->builder()->from('t')->where('x', 1)->orWhereDict('d', 'a.b', 'k', '!=', 'v')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'x as y', 'k') as `x as y` FROM `t` WHERE `x as y` = 'v'",
            $this->builder()->from('t')->whereDict('d', 'x as y', 'k', 'v')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'x AS y', 'k') as `x AS y` FROM `t` WHERE `x` = 1 OR `x AS y` IN (1, 2)",
            $this->builder()->from('t')->where('x', 1)->orWhereDict('d', 'x AS y', 'k', [1, 2])->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a.b as c', 'k') as `a.b as c`, dictGetString('d', 'e.f', 'k') as `e.f` "
            ."FROM `t` WHERE `a.b as c` OR `e.f`",
            $this->builder()->from('t')->whereDict('d', 'a.b as c', 'k')->orWhereDict('d', 'e.f', 'k')->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a.b\\'c`', 'k') as `a.b'c``` FROM `t` WHERE `a.b'c``` = 1",
            $this->builder()->from('t')->whereDict('d', "a.b'c`", 'k', 1)->toSql()
        );
    }

    /**
     * As in where() with a column given as a string, a null operator with a null value is the value.
     */
    public function test_where_dict_with_a_null_operator_and_a_null_value_compiles_to_is_null(): void
    {
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a` FROM `t` WHERE `a` IS NULL",
            $this->builder()->from('t')->whereDict('d', 'a', 'k', null, null)->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a` FROM `t` WHERE `x` = 1 OR `a` IS NULL",
            $this->builder()->from('t')->where('x', 1)->whereDict('d', 'a', 'k', null, null, Operator::OR)->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a.b', 'k') as `a.b` FROM `t` WHERE `x` = 1 OR `a.b` IS NULL",
            $this->builder()->from('t')->where('x', 1)->orWhereDict('d', 'a.b', 'k', null, null)->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a` FROM `t` WHERE `a` IS NULL",
            $this->builder()->from('t')->whereDict('d', 'a', 'k', null)->toSql()
        );
        $this->assertEquals(
            "SELECT dictGetString('d', 'a', 'k') as `a` FROM `t` WHERE `a` IS NOT NULL",
            $this->builder()->from('t')->whereDict('d', 'a', 'k', '<>', null)->toSql()
        );
    }

    public function test_where_empty_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE empty(`name`)',
            $this->builder()->from('t')->whereEmpty('name')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE notEmpty(`tags`) AND notEmpty(`e`.`arr`)',
            $this->builder()->from('t')->whereNotEmpty(['tags', 'e.arr'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` = 1 OR empty(`a`) OR empty(`b`)',
            $this->builder()->from('t')->where('x', 1)->orWhereEmpty(['a', 'b'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` = 1 OR notEmpty(arrayFilter(x -> x > 1, arr))',
            $this->builder()->from('t')->where('x', 1)->orWhereNotEmpty(new Expression('arrayFilter(x -> x > 1, arr)'))->toSql()
        );
    }

    public function test_pre_where_empty_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE empty(`a`) OR empty(`b`) AND notEmpty(`c`) OR notEmpty(`d`)',
            $this->builder()->from('t')
                ->preWhereEmpty('a')
                ->orPreWhereEmpty('b')
                ->preWhereNotEmpty('c')
                ->orPreWhereNotEmpty('d')
                ->toSql()
        );
    }

    public function test_having_empty_family(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a` HAVING empty(`m`) OR empty(`n`) AND notEmpty(`o`) OR notEmpty(`p`)',
            $this->builder()->from('t')->groupBy('a')
                ->havingEmpty('m')
                ->orHavingEmpty('n')
                ->havingNotEmpty('o')
                ->orHavingNotEmpty('p')
                ->toSql()
        );
    }

    public function test_sample_with_offset(): void
    {
        $builder = $this->builder()->from('t')->sample(0.1, 0.5);

        $this->assertEquals('SELECT * FROM `t` SAMPLE 0.1 OFFSET 0.5', $builder->toSql());
        $this->assertSame(0.1, $builder->getSample());
        $this->assertSame(0.5, $builder->getSampleOffset());

        $this->assertEquals('SELECT * FROM `t` SAMPLE 0.5 OFFSET 0', $this->builder()->from('t')->sample(0.5, 0)->toSql());
    }

    public function test_sample_without_offset_is_unchanged(): void
    {
        $builder = $this->builder()->from('t')->sample(0.3);

        $this->assertEquals('SELECT * FROM `t` SAMPLE 0.3', $builder->toSql());
        $this->assertNull($builder->getSampleOffset());

        $builder->sample(0.1, 0.5)->sample(0.2);

        $this->assertEquals('SELECT * FROM `t` SAMPLE 0.2', $builder->toSql());
        $this->assertNull($builder->getSampleOffset());
    }

    public function test_each_set_operation(): void
    {
        $operations = [
            'unionAll'          => 'UNION ALL',
            'unionDistinct'     => 'UNION DISTINCT',
            'intersect'         => 'INTERSECT',
            'intersectDistinct' => 'INTERSECT DISTINCT',
            'except'            => 'EXCEPT',
            'exceptDistinct'    => 'EXCEPT DISTINCT',
        ];

        foreach ($operations as $method => $operator) {
            $builder = $this->builder()->select('id')->from('a')->{$method}($this->builder()->select('id')->from('b'));

            $this->assertEquals("SELECT `id` FROM `a` {$operator} SELECT `id` FROM `b`", $builder->toSql());
            $this->assertSame([$operator], $builder->getUnionTypes());
        }
    }

    public function test_set_operations_keep_call_order_without_parentheses(): void
    {
        $second = $this->builder()->from('b');
        $builder = $this->builder()->from('a')
            ->unionAll($second)
            ->intersect(function (BaseBuilder $query) {
                $query->from('c')->where('x', 1);
            })
            ->except($this->builder()->from('d'))
            ->unionDistinct($this->builder()->from('e'))
            ->intersectDistinct($this->builder()->from('f'))
            ->exceptDistinct($this->builder()->from('g'));

        $this->assertEquals(
            'SELECT * FROM `a` UNION ALL SELECT * FROM `b` INTERSECT SELECT * FROM `c` WHERE `x` = 1 '
            .'EXCEPT SELECT * FROM `d` UNION DISTINCT SELECT * FROM `e` INTERSECT DISTINCT SELECT * FROM `f` '
            .'EXCEPT DISTINCT SELECT * FROM `g`',
            $builder->toSql()
        );
        $this->assertCount(6, $builder->getUnions());
        $this->assertSame($second, $builder->getUnions()[0]);
        $this->assertContainsOnlyInstancesOf(BaseBuilder::class, $builder->getUnions());
        $this->assertSame([
            UnionType::UNION_ALL,
            UnionType::INTERSECT,
            UnionType::EXCEPT,
            UnionType::UNION_DISTINCT,
            UnionType::INTERSECT_DISTINCT,
            UnionType::EXCEPT_DISTINCT,
        ], $builder->getUnionTypes());
    }

    public function test_union_all_output_is_unchanged(): void
    {
        $builder = $this->builder()->from('table')->unionAll($this->builder()->from('table2'));

        $this->assertEquals('SELECT * FROM `table` UNION ALL SELECT * FROM `table2`', $builder->toSql());
        $this->assertSame([UnionType::UNION_ALL], $builder->getUnionTypes());
    }

    public function test_replaced_unions_without_operators_compile_as_union_all(): void
    {
        $builder = $this->builder()->from('a')->intersect($this->builder()->from('b'))->except($this->builder()->from('c'));

        $clone = $builder->cloneWithout(['unions' => [$this->builder()->from('d'), $this->builder()->from('e')]]);

        $this->assertEquals('SELECT * FROM `a` UNION ALL SELECT * FROM `d` UNION ALL SELECT * FROM `e`', $clone->toSql());
        $this->assertSame([UnionType::UNION_ALL, UnionType::UNION_ALL], $clone->getUnionTypes());
        $this->assertEquals('SELECT * FROM `a` INTERSECT SELECT * FROM `b` EXCEPT SELECT * FROM `c`', $builder->toSql());
    }

    /**
     * An operand that has set operations of its own is put in parentheses, unless they cannot change the result:
     * see the UNION ALL tests below.
     */
    public function test_a_set_operation_argument_with_its_own_set_operations_is_one_operand(): void
    {
        $this->assertEquals(
            'SELECT `id` FROM `a` EXCEPT (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`)',
            $this->builder()->select('id')->from('a')
                ->except($this->builder()->select('id')->from('b')->unionAll($this->builder()->select('id')->from('c')))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `a` INTERSECT (SELECT `id` FROM `b` EXCEPT SELECT `id` FROM `c`) UNION ALL SELECT `id` FROM `d`',
            $this->builder()->select('id')->from('a')
                ->intersect(function (BaseBuilder $query) {
                    $query->select('id')->from('b')->except($this->builder()->select('id')->from('c'));
                })
                ->unionAll($this->builder()->select('id')->from('d'))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL (SELECT `id` FROM `b` EXCEPT (SELECT `id` FROM `c` INTERSECT SELECT `id` FROM `d`))',
            $this->builder()->select('id')->from('a')
                ->unionAll(
                    $this->builder()->select('id')->from('b')->except(
                        $this->builder()->select('id')->from('c')->intersect($this->builder()->select('id')->from('d'))
                    )
                )
                ->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `id` IN (SELECT `id` FROM `a` EXCEPT (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`))',
            $this->builder()->from('t')
                ->whereIn(
                    'id',
                    $this->builder()->select('id')->from('a')->except(
                        $this->builder()->select('id')->from('b')->unionAll($this->builder()->select('id')->from('c'))
                    )
                )
                ->toSql()
        );
    }

    /**
     * Parentheses around a UNION ALL operand whose own set operations are all UNION ALL cannot change the rows,
     * so they are left out and the SQL is that of 3.0.0, where every set operation was UNION ALL.
     */
    public function test_a_union_all_operand_with_only_union_all_of_its_own_compiles_without_parentheses(): void
    {
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`',
            $this->selectId('a')->unionAll($this->selectId('b')->unionAll($this->selectId('c')))->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` UNION ALL SELECT `id` FROM `d`',
            $this->selectId('a')
                ->unionAll(function (BaseBuilder $query) {
                    $query->select('id')->from('b')->unionAll($this->selectId('c')->unionAll($this->selectId('d')));
                })
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL (SELECT `id` FROM `c` EXCEPT SELECT `id` FROM `d`)',
            $this->selectId('a')->unionAll($this->selectId('b')->unionAll($this->selectId('c')->except($this->selectId('d'))))->toSql()
        );
        $this->assertEquals(
            'WITH 1 AS `one` SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`',
            $this->builder()->withAlias('one', 1)->select('id')->from('a')
                ->unionAll($this->selectId('b')->unionAll($this->selectId('c')))
                ->toSql()
        );
    }

    /**
     * INTERSECT binds tighter than UNION and EXCEPT, so only an INTERSECT after the operand would take its last
     * SELECT alone. The set operations before the operand do not matter.
     */
    public function test_a_union_all_operand_keeps_its_parentheses_only_before_intersect(): void
    {
        $operators = [
            'unionAll'       => 'UNION ALL',
            'unionDistinct'  => 'UNION DISTINCT',
            'except'         => 'EXCEPT',
            'exceptDistinct' => 'EXCEPT DISTINCT',
        ];

        foreach ($operators as $method => $operator) {
            $this->assertEquals(
                "SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` {$operator} SELECT `id` FROM `d`",
                $this->selectId('a')->unionAll($this->selectId('b')->unionAll($this->selectId('c')))->{$method}($this->selectId('d'))->toSql()
            );
            $this->assertEquals(
                "SELECT `id` FROM `a` {$operator} SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` UNION ALL SELECT `id` FROM `d`",
                $this->selectId('a')->{$method}($this->selectId('b'))->unionAll($this->selectId('c')->unionAll($this->selectId('d')))->toSql()
            );
        }

        foreach (['intersect' => 'INTERSECT', 'intersectDistinct' => 'INTERSECT DISTINCT'] as $method => $operator) {
            $this->assertEquals(
                "SELECT `id` FROM `a` UNION ALL (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`) {$operator} SELECT `id` FROM `d`",
                $this->selectId('a')->unionAll($this->selectId('b')->unionAll($this->selectId('c')))->{$method}($this->selectId('d'))->toSql()
            );
            $this->assertEquals(
                "SELECT `id` FROM `a` {$operator} SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` UNION ALL SELECT `id` FROM `d`",
                $this->selectId('a')->{$method}($this->selectId('b'))->unionAll($this->selectId('c')->unionAll($this->selectId('d')))->toSql()
            );
        }
    }

    public function test_an_operand_joined_with_an_operator_other_than_union_all_keeps_its_parentheses(): void
    {
        $operators = [
            'unionDistinct'     => 'UNION DISTINCT',
            'intersect'         => 'INTERSECT',
            'intersectDistinct' => 'INTERSECT DISTINCT',
            'except'            => 'EXCEPT',
            'exceptDistinct'    => 'EXCEPT DISTINCT',
        ];

        foreach ($operators as $method => $operator) {
            $this->assertEquals(
                "SELECT `id` FROM `a` {$operator} (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`)",
                $this->selectId('a')->{$method}($this->selectId('b')->unionAll($this->selectId('c')))->toSql()
            );
        }
    }

    public function test_an_operand_with_set_operations_of_its_own_other_than_union_all_keeps_its_parentheses(): void
    {
        $operators = [
            'unionDistinct'     => 'UNION DISTINCT',
            'intersect'         => 'INTERSECT',
            'intersectDistinct' => 'INTERSECT DISTINCT',
            'except'            => 'EXCEPT',
            'exceptDistinct'    => 'EXCEPT DISTINCT',
        ];

        foreach ($operators as $method => $operator) {
            $this->assertEquals(
                "SELECT `id` FROM `a` UNION ALL (SELECT `id` FROM `b` {$operator} SELECT `id` FROM `c`)",
                $this->selectId('a')->unionAll($this->selectId('b')->{$method}($this->selectId('c')))->toSql()
            );
            $this->assertEquals(
                "SELECT `id` FROM `a` UNION ALL (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` {$operator} SELECT `id` FROM `d`)",
                $this->selectId('a')->unionAll($this->selectId('b')->unionAll($this->selectId('c'))->{$method}($this->selectId('d')))->toSql()
            );
        }
    }

    /**
     * ClickHouse applies the WITH clause of the first SELECT of a set operation to every SELECT, but the WITH
     * clause of a later SELECT to that SELECT only.
     */
    public function test_a_union_all_operand_with_its_own_with_clause_keeps_its_parentheses(): void
    {
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL (WITH 1 AS `one` SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`)',
            $this->selectId('a')
                ->unionAll($this->builder()->withAlias('one', 1)->select('id')->from('b')->unionAll($this->selectId('c')))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL (WITH `w` AS (SELECT 1) SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`)',
            $this->selectId('a')
                ->unionAll($this->builder()->withExpression('w', 'SELECT 1')->select('id')->from('b')->unionAll($this->selectId('c')))
                ->toSql()
        );
    }

    /**
     * The next set operator is read from the next query, also when the keys of the queries have gaps.
     */
    public function test_the_operator_after_an_operand_is_that_of_the_next_query(): void
    {
        $builder = $this->selectId('a')->cloneWithout([
            'unions'     => [3 => $this->selectId('b')->unionAll($this->selectId('c')), 7 => $this->selectId('d')],
            'unionTypes' => [3 => UnionType::UNION_ALL, 7 => UnionType::INTERSECT],
        ]);

        $this->assertEquals(
            'SELECT `id` FROM `a` UNION ALL (SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c`) INTERSECT SELECT `id` FROM `d`',
            $builder->toSql()
        );
    }

    /**
     * ClickHouse 24.8 rejects SETTINGS after a parenthesized last operand in a sub-query. A nested UNION ALL with
     * settings compiles flat, as in 3.0.0, so it works as a sub-query and as an operand.
     */
    public function test_a_nested_union_all_with_settings_compiles_flat_as_a_sub_query(): void
    {
        $client = $this->createMock(Client::class);
        $selectId = fn (string $table): Builder => (new Builder($client))->select('id')->from($table);
        $query = fn (): Builder => $selectId('a')->unionAll($selectId('b')->unionAll($selectId('c')))->settings(['max_threads' => 1]);
        $sql = 'SELECT `id` FROM `a` UNION ALL SELECT `id` FROM `b` UNION ALL SELECT `id` FROM `c` SETTINGS max_threads=1';

        $this->assertEquals($sql, $query()->toSql());
        $this->assertEquals("SELECT * FROM ({$sql}) ORDER BY `id` ASC", (new Builder($client))->from($query())->orderBy('id')->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `id` IN ({$sql})", (new Builder($client))->from('t')->whereIn('id', $query())->toSql());
        $this->assertEquals("WITH `w` AS ({$sql}) SELECT * FROM `w`", (new Builder($client))->withExpression('w', $query())->from('w')->toSql());
        $this->assertEquals("SELECT `id` FROM `d` UNION ALL {$sql}", $selectId('d')->unionAll($query())->toSql());
    }

    public function test_set_operations_reject_other_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Argument for intersect must be closure or builder instance.');

        $this->builder()->from('a')->intersect('b');
    }

    public function test_with_expression(): void
    {
        $this->assertEquals(
            'WITH `t` AS (SELECT * FROM `events` WHERE `id` = 1) SELECT `id` FROM `t`',
            $this->builder()->withExpression('t', $this->builder()->from('events')->where('id', 1))->select('id')->from('t')->toSql()
        );
        $this->assertEquals(
            'WITH `t` AS (SELECT `id` FROM `events`) SELECT * FROM `t`',
            $this->builder()->withExpression('t', function (BaseBuilder $query) {
                $query->select('id')->from('events');
            })->from('t')->toSql()
        );
        $this->assertEquals(
            'WITH `t` AS (SELECT 1 AS x) SELECT `x` FROM `t`',
            $this->builder()->withExpression('t', 'SELECT 1 AS x')->select('x')->from('t')->toSql()
        );
        $this->assertEquals(
            'WITH `t` AS (SELECT 2 AS x) SELECT `x` FROM `t`',
            $this->builder()->withExpression('t', raw('SELECT 2 AS x'))->select('x')->from('t')->toSql()
        );
    }

    public function test_with_alias(): void
    {
        $this->assertEquals('WITH 5 AS `five` SELECT `five`', $this->builder()->withAlias('five', 5)->select('five')->toSql());
        $this->assertEquals(
            'WITH (SELECT count() FROM `events`) AS `total` SELECT `total`',
            $this->builder()->withAlias('total', function (BaseBuilder $query) {
                $query->select(raw('count()'))->from('events');
            })->select('total')->toSql()
        );
        $this->assertEquals(
            'WITH (SELECT max(`id`) FROM `events`) AS `top` SELECT `top`',
            $this->builder()->withAlias('top', $this->builder()->select(raw('max(`id`)'))->from('events'))->select('top')->toSql()
        );
        $this->assertEquals(
            "WITH [1, 2] AS `ids`, ['a', 'b\\'c'] AS `names`, [[1, 2], [3]] AS `nested`, [] AS `none` SELECT *",
            $this->builder()
                ->withAlias('ids', [1, 2])
                ->withAlias('names', ['a', "b'c"])
                ->withAlias('nested', [[1, 2], [3]])
                ->withAlias('none', [])
                ->toSql()
        );
        $this->assertEquals(
            "WITH 'x' AS `label`, 1.5 AS `ratio`, 1 AS `flag`, null AS `nothing`, '2024-01-02 03:04:05' AS `since`, "
            ."'active' AS `status` SELECT *",
            $this->builder()
                ->withAlias('label', 'x')
                ->withAlias('ratio', 1.5)
                ->withAlias('flag', true)
                ->withAlias('nothing', null)
                ->withAlias('since', $this->moment())
                ->withAlias('status', StringBackedEnumFixture::Active)
                ->toSql()
        );
        $this->assertEquals(
            "WITH toDateTime('2024-01-02 00:00:00') AS `since` SELECT count() FROM `events` WHERE `created_at` >= `since`",
            $this->builder()
                ->withAlias('since', raw("toDateTime('2024-01-02 00:00:00')"))
                ->select(raw('count()'))
                ->from('events')
                ->where('created_at', '>=', new Identifier('since'))
                ->toSql()
        );
    }

    public function test_with_entries_accumulate_in_call_order(): void
    {
        $builder = $this->builder()
            ->withExpression('t', $this->builder()->select('id')->from('events'))
            ->withAlias('five', 5)
            ->withAlias('mx', $this->builder()->select(raw('max(`id`)'))->from('t'))
            ->select('five', 'mx', 'id')
            ->from('t');

        $this->assertEquals(
            'WITH `t` AS (SELECT `id` FROM `events`), 5 AS `five`, (SELECT max(`id`) FROM `t`) AS `mx` '
            .'SELECT `five`, `mx`, `id` FROM `t`',
            $builder->toSql()
        );

        $withs = $builder->getWiths();
        $this->assertCount(3, $withs);
        $this->assertSame(['type', 'name', 'value', 'recursive'], array_keys($withs[0]));
        $this->assertSame(BaseBuilder::WITH_EXPRESSION, $withs[0]['type']);
        $this->assertSame('t', $withs[0]['name']);
        $this->assertInstanceOf(BaseBuilder::class, $withs[0]['value']);
        $this->assertFalse($withs[0]['recursive']);
        $this->assertSame(BaseBuilder::WITH_ALIAS, $withs[1]['type']);
        $this->assertSame('five', $withs[1]['name']);
        $this->assertSame(5, $withs[1]['value']);
        $this->assertSame([], $this->builder()->getWiths());
    }

    public function test_with_recursive_expression(): void
    {
        $recursive = function (BaseBuilder $query) {
            $query->select(raw('1 AS n'))->unionAll(function (BaseBuilder $query) {
                $query->select(raw('n + 1'))->from('r')->where('n', '<', 3);
            });
        };

        $this->assertEquals(
            'WITH RECURSIVE `r` AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM `r` WHERE `n` < 3) SELECT * FROM `r`',
            $this->builder()->withRecursiveExpression('r', $recursive)->from('r')->toSql()
        );

        $builder = $this->builder()->withAlias('five', 5)->withRecursiveExpression('r', $recursive)->select('n', 'five')->from('r');

        $this->assertEquals(
            'WITH RECURSIVE 5 AS `five`, `r` AS (SELECT 1 AS n UNION ALL SELECT n + 1 FROM `r` WHERE `n` < 3) '
            .'SELECT `n`, `five` FROM `r`',
            $builder->toSql()
        );
        $this->assertTrue($builder->getWiths()[1]['recursive']);
    }

    public function test_sub_queries_render_their_own_with_clause(): void
    {
        $sub = function (): TestBuilder {
            return $this->builder()->withAlias('two', 2)->select(raw('`two` AS x'));
        };

        $this->assertEquals(
            'SELECT * FROM (WITH 2 AS `two` SELECT `two` AS x)',
            $this->builder()->from($sub())->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `x` IN (WITH 2 AS `two` SELECT `two` AS x)',
            $this->builder()->from('t')->whereIn('x', $sub())->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` ALL INNER JOIN (WITH 2 AS `two` SELECT `two` AS x) AS `j` USING `x`',
            $this->builder()->from('t')->allInnerJoin($sub(), ['x'], false, 'j')->toSql()
        );
        $this->assertEquals(
            'SELECT 1 AS x UNION ALL WITH 2 AS `two` SELECT `two` AS x',
            $this->builder()->select(raw('1 AS x'))->unionAll($sub())->toSql()
        );
        $this->assertEquals(
            'WITH 1 AS `one` SELECT `one` UNION ALL WITH 2 AS `two` SELECT `two` AS x',
            $this->builder()->withAlias('one', 1)->select('one')->unionAll($sub())->toSql()
        );
    }

    public function test_with_clause_is_placed_before_select_and_kept_by_count_query(): void
    {
        $this->assertEquals(
            'WITH 5 AS `five` SELECT `five` FORMAT JSON',
            $this->builder()->withAlias('five', 5)->select('five')->format(Format::JSON)->toSql()
        );
        $this->assertEquals(
            'WITH `t` AS (SELECT * FROM `events`) SELECT count() as `count` FROM `t`',
            $this->builder()->withExpression('t', $this->builder()->from('events'))->from('t')->limit(10)->getCountQuery()->toSql()
        );
    }

    public function test_with_clause_comes_before_select_on_the_package_builder_with_settings(): void
    {
        $builder = (new Builder($this->createMock(Client::class)))
            ->withAlias('five', 5)
            ->select('five')
            ->settings(['max_threads' => 1]);

        $this->assertEquals('WITH 5 AS `five` SELECT `five` SETTINGS max_threads=1', $builder->toSql());
    }

    public function test_with_expression_rejects_other_arguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Argument for withRecursiveExpression must be closure, builder instance, Expression, Laravel database expression, such as DB::raw(), or string.'
        );

        $this->builder()->withRecursiveExpression('r', 123);
    }

    public function test_with_names_are_quoted_as_one_identifier(): void
    {
        $this->assertEquals('WITH 1 AS `a.b` SELECT 1', $this->builder()->withAlias('a.b', 1)->select(raw('1'))->toSql());
        $this->assertEquals(
            'WITH `c as d` AS (SELECT 1) SELECT * FROM `t`',
            $this->builder()->withExpression('c as d', 'SELECT 1')->from('t')->toSql()
        );
        $this->assertEquals(
            'WITH RECURSIVE `db.cte` AS (SELECT 1) SELECT * FROM `t`',
            $this->builder()->withRecursiveExpression('db.cte', 'SELECT 1')->from('t')->toSql()
        );
        $this->assertEquals('WITH 1 AS `a``b` SELECT 1', $this->builder()->withAlias('a`b', 1)->select(raw('1'))->toSql());
    }

    /**
     * ClickHouse reads a backslash in a back-quoted identifier as an escape character, so it is doubled like a
     * backtick. Before, a name that ended in a backslash escaped its closing backtick.
     */
    public function test_quote_identifier_escapes_backslashes_and_backticks(): void
    {
        $grammar = new Grammar();

        $this->assertSame('`name`', $grammar->quoteIdentifier('name'));
        $this->assertSame('`a``b`', $grammar->quoteIdentifier('a`b'));
        $this->assertSame('`a\\\\b`', $grammar->quoteIdentifier('a\\b'));
        $this->assertSame('`ends\\\\`', $grammar->quoteIdentifier('ends\\'));
        $this->assertSame('`a\\\\```', $grammar->quoteIdentifier('a\\`'));
        $this->assertSame('`db.t as c`', $grammar->quoteIdentifier('db.t as c'));
    }

    public function test_table_column_and_with_names_with_backslashes_and_backticks_are_escaped(): void
    {
        $this->assertEquals(
            'SELECT `a\\\\b`, `c``d` AS `e\\\\` FROM `db\\\\`.`t``\\\\` WHERE `ends\\\\` = 1 ORDER BY `a\\\\b` ASC',
            $this->builder()->select('a\\b', 'c`d as e\\')->from('db\\.t`\\')->where('ends\\', 1)->orderBy('a\\b')->toSql()
        );
        $this->assertEquals(
            'WITH 1 AS `w\\\\` SELECT `w\\\\`',
            $this->builder()->withAlias('w\\', 1)->select('w\\')->toSql()
        );
        $this->assertEquals(
            'WITH `c\\\\``d` AS (SELECT 1) SELECT * FROM `c\\\\``d`',
            $this->builder()->withExpression('c\\`d', 'SELECT 1')->from('c\\`d')->toSql()
        );
        $this->assertEquals(
            'WITH RECURSIVE `r\\\\` AS (SELECT 1) SELECT * FROM `r\\\\`',
            $this->builder()->withRecursiveExpression('r\\', 'SELECT 1')->from('r\\')->toSql()
        );
        $this->assertEquals(
            "INSERT INTO `t\\\\` (`a\\\\b`, `c``d`) FORMAT Values (1, 'x')",
            (new Grammar())->compileInsert($this->builder()->table('t\\'), [['a\\b' => 1, 'c`d' => 'x']])
        );
    }

    public function test_semi_anti_and_right_joins(): void
    {
        $joins = [
            'semiLeftJoin'  => 'SEMI LEFT JOIN',
            'semiRightJoin' => 'SEMI RIGHT JOIN',
            'antiLeftJoin'  => 'ANTI LEFT JOIN',
            'antiRightJoin' => 'ANTI RIGHT JOIN',
            'anyRightJoin'  => 'ANY RIGHT JOIN',
            'allRightJoin'  => 'ALL RIGHT JOIN',
            'asofJoin'      => 'ASOF JOIN',
            'asofLeftJoin'  => 'ASOF LEFT JOIN',
        ];

        foreach ($joins as $method => $join) {
            $this->assertEquals(
                "SELECT * FROM `e` {$join} `u` USING `user_id`",
                $this->builder()->from('e')->{$method}('u', ['user_id'])->toSql()
            );
            $this->assertEquals(
                "SELECT * FROM `e` GLOBAL {$join} `u` AS `x` USING `user_id`",
                $this->builder()->from('e')->{$method}('u', ['user_id'], true, 'x')->toSql()
            );
        }
    }

    public function test_right_and_full_joins_default_to_all_strictness(): void
    {
        $this->assertEquals(
            'SELECT * FROM `e` ALL RIGHT JOIN `u` USING `user_id`',
            $this->builder()->from('e')->rightJoin('u', null, ['user_id'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` ANY RIGHT JOIN `u` USING `user_id`',
            $this->builder()->from('e')->rightJoin('u', 'any', ['user_id'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` ALL FULL JOIN `u` USING `user_id`',
            $this->builder()->from('e')->fullJoin('u', null, ['user_id'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` GLOBAL ALL FULL JOIN `u` AS `x` USING `user_id`',
            $this->builder()->from('e')->fullJoin('u', null, ['user_id'], true, 'x')->toSql()
        );
    }

    public function test_asof_join_on_an_inequality(): void
    {
        $builder = $this->builder()->from('events', 'e')->asofLeftJoin(function (JoinClause $join) {
            $join->table('prices')->as('p')
                ->on('e.user_id', '=', 'p.user_id')
                ->on('e.created_at', '>=', 'p.ts');
        });

        $this->assertEquals(
            'SELECT * FROM `events` AS `e` ASOF LEFT JOIN `prices` AS `p` ON `e`.`user_id` = `p`.`user_id` AND `e`.`created_at` >= `p`.`ts`',
            $builder->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` ASOF JOIN `p` USING `user_id`, `ts`',
            $this->builder()->from('e')->join('p', null, 'asof', ['user_id', 'ts'])->toSql()
        );
    }

    public function test_join_clause_strictness_and_type_helpers(): void
    {
        $join = new JoinClause($this->builder());

        $this->assertSame(JoinStrict::SEMI, (string) $join->semi()->getStrict());
        $this->assertSame(JoinStrict::ANTI, (string) $join->anti()->getStrict());
        $this->assertSame(JoinStrict::ASOF, (string) $join->asof()->getStrict());
        $this->assertSame(JoinType::RIGHT, (string) $join->right()->getType());
        $this->assertSame(JoinType::FULL, (string) $join->full()->getType());
        $this->assertSame(JoinType::CROSS, (string) $join->cross()->getType());

        $this->assertEquals(
            'SELECT * FROM `e` ANTI LEFT JOIN `u` USING `user_id`',
            $this->builder()->from('e')->join(function (JoinClause $join) {
                $join->table('u')->anti()->left()->using('user_id');
            })->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` SEMI RIGHT JOIN `u` USING `user_id`',
            $this->builder()->from('e')->join('u', 'semi', 'right', ['user_id'])->toSql()
        );
    }

    public function test_cross_join(): void
    {
        $this->assertEquals('SELECT * FROM `e` CROSS JOIN `u`', $this->builder()->from('e')->crossJoin('u')->toSql());
        $this->assertEquals(
            'SELECT * FROM `e` GLOBAL CROSS JOIN `u` AS `x`',
            $this->builder()->from('e')->crossJoin('u', true, 'x')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` CROSS JOIN (SELECT `id` FROM `u`) AS `x`',
            $this->builder()->from('e')->crossJoin($this->builder()->select('id')->from('u'), false, 'x')->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` CROSS JOIN `u` ALL LEFT JOIN `p` USING `id`',
            $this->builder()->from('e')->crossJoin('u')->leftJoin('p', null, ['id'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `e` CROSS JOIN `u`',
            $this->builder()->from('e')->join(function (JoinClause $join) {
                $join->table('u')->cross();
            })->toSql()
        );
    }

    public function test_cross_join_with_keys_or_strictness_throws(): void
    {
        $invalidJoins = [
            $this->builder()->from('e')->crossJoin(function (JoinClause $join) {
                $join->table('u')->using('id');
            }),
            $this->builder()->from('e')->crossJoin(function (JoinClause $join) {
                $join->table('u')->on('e.id', '=', 'u.id');
            }),
            $this->builder()->from('e')->join('u', 'all', 'cross'),
        ];

        foreach ($invalidJoins as $builder) {
            try {
                $builder->toSql();
                $this->fail('Expected a GrammarException');
            } catch (GrammarException $exception) {
                $this->assertSame(GrammarException::wrongCrossJoin()->getMessage(), $exception->getMessage());
            }
        }
    }

    public function test_cross_join_without_table_names_only_the_table(): void
    {
        $this->assertSame(
            "Missed required segments for 'JOIN' section. Missed: table or subquery",
            GrammarException::wrongJoin((new JoinClause($this->builder()))->cross())->getMessage()
        );
    }

    public function test_array_join_several_arrays(): void
    {
        $this->assertEquals(
            'SELECT `id`, `tag` FROM `t` ARRAY JOIN `tags` AS `tag`, `scores` AS `score`, `other`',
            $this->builder()->select('id', 'tag')->from('t')->arrayJoin(['tag' => 'tags', 'score' => 'scores', 'other'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` LEFT ARRAY JOIN `tags` AS `tag`, `scores` AS `score`',
            $this->builder()->from('t')->leftArrayJoin(['tag' => 'tags', 'score' => 'scores'])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` ARRAY JOIN arrayEnumerate(tags) AS `n`, `tags`',
            $this->builder()->from('t')->arrayJoin(['n' => raw('arrayEnumerate(tags)'), 'tags'])->toSql()
        );

        $arrayJoin = $this->builder()->from('t')->arrayJoin(['tag' => 'tags', 'other'])->getArrayJoin();

        $this->assertEquals('tags', (string) $arrayJoin->getArrayIdentifier());
        $this->assertCount(2, $arrayJoin->getArrays());
        $this->assertEquals('tags', (string) $arrayJoin->getArrays()[0]['array']);
        $this->assertEquals('tag', (string) $arrayJoin->getArrays()[0]['alias']);
        $this->assertEquals('other', (string) $arrayJoin->getArrays()[1]['array']);
        $this->assertNull($arrayJoin->getArrays()[1]['alias']);
    }

    public function test_array_join_of_one_array_is_unchanged(): void
    {
        $builder = $this->builder()->from('t')->arrayJoin('tags');

        $this->assertEquals('SELECT * FROM `t` ARRAY JOIN `tags`', $builder->toSql());
        $this->assertInstanceOf(Identifier::class, $builder->getArrayJoin()->getArrayIdentifier());
        $this->assertEquals('SELECT * FROM `t` ARRAY JOIN `tags` AS `tag`', $this->builder()->from('t')->arrayJoin('tags as tag')->toSql());
        $this->assertEquals('SELECT * FROM `t` LEFT ARRAY JOIN range(3)', $this->builder()->from('t')->leftArrayJoin(raw('range(3)'))->toSql());
    }

    public function test_array_join_needs_an_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ARRAY JOIN needs at least one array.');

        $this->builder()->from('t')->arrayJoin([]);
    }

    public function test_existing_join_outputs_are_unchanged(): void
    {
        $this->assertEquals('SELECT * FROM `t` ALL LEFT JOIN `u` USING `id`', $this->builder()->from('t')->leftJoin('u', null, ['id'])->toSql());
        $this->assertEquals('SELECT * FROM `t` ALL INNER JOIN `u` USING `id`', $this->builder()->from('t')->innerJoin('u', null, ['id'])->toSql());
        $this->assertEquals('SELECT * FROM `t` ANY LEFT JOIN `u` USING `id`', $this->builder()->from('t')->anyLeftJoin('u', ['id'])->toSql());
        $this->assertEquals('SELECT * FROM `t` ALL FULL JOIN `u` USING `id`', $this->builder()->from('t')->join('u', 'all', 'full', ['id'])->toSql());
    }

    /**
     * A builder whose grammar writes Laravel database expressions, such as DB::raw(), with a Laravel query grammar,
     * as the builders of a ClickHouse connection do. The builders of its closures and sub-queries get one too.
     */
    private function builderWithLaravelGrammar(): TestBuilder
    {
        $laravelGrammar = new LaravelQueryGrammar($this->createStub(Connection::class));

        return new class ($laravelGrammar) extends TestBuilder {
            public function __construct(private LaravelQueryGrammar $laravelGrammar)
            {
                parent::__construct();
                $this->grammar->setLaravelGrammarResolver(fn (): LaravelQueryGrammar => $this->laravelGrammar);
            }

            public function newQuery(): static
            {
                return new static($this->laravelGrammar);
            }
        };
    }

    /**
     * @return array<string, array{Closure(TestBuilder): TestBuilder, string}>
     */
    public static function lowerCaseOperatorProvider(): array
    {
        return [
            'like' => [fn (TestBuilder $q) => $q->where('s', 'like', 'x%'), "WHERE `s` LIKE 'x%'"],
            'not like in mixed case' => [fn (TestBuilder $q) => $q->where('s', 'Not Like', 'x%'), "WHERE `s` NOT LIKE 'x%'"],
            'ilike' => [fn (TestBuilder $q) => $q->where('s', 'ilike', 'x%'), "WHERE `s` ILIKE 'x%'"],
            'not ilike' => [fn (TestBuilder $q) => $q->where('s', 'not ilike', 'x%'), "WHERE `s` NOT ILIKE 'x%'"],
            'in' => [fn (TestBuilder $q) => $q->where('id', 'in', [1, 2]), 'WHERE `id` IN (1, 2)'],
            'not in' => [fn (TestBuilder $q) => $q->where('id', 'not in', [1, 2]), 'WHERE `id` NOT IN (1, 2)'],
            'global in' => [fn (TestBuilder $q) => $q->where('id', 'global in', [1]), 'WHERE `id` GLOBAL IN (1)'],
            'global not in' => [fn (TestBuilder $q) => $q->where('id', 'global not in', [1]), 'WHERE `id` GLOBAL NOT IN (1)'],
            'empty list with in' => [fn (TestBuilder $q) => $q->where('id', 'in', []), 'WHERE 0 = 1'],
            'between' => [fn (TestBuilder $q) => $q->where('id', 'between', [1, 2]), 'WHERE `id` BETWEEN 1 AND 2'],
            'not between' => [fn (TestBuilder $q) => $q->where('id', 'not between', [1, 2]), 'WHERE NOT ( `id` BETWEEN 1 AND 2 )'],
            'is null' => [fn (TestBuilder $q) => $q->where('nd', 'is null', null), 'WHERE `nd` IS NULL'],
            'is not null' => [fn (TestBuilder $q) => $q->where('nd', 'is not null', null), 'WHERE `nd` IS NOT NULL'],
            'less than greater than' => [fn (TestBuilder $q) => $q->where('a', '<>', 1), 'WHERE `a` != 1'],
            'or where' => [fn (TestBuilder $q) => $q->where('id', 1)->orWhere('s', 'like', 'x%'), "WHERE `id` = 1 OR `s` LIKE 'x%'"],
            'prewhere' => [fn (TestBuilder $q) => $q->preWhere('s', 'ilike', 'x%'), "PREWHERE `s` ILIKE 'x%'"],
            'or prewhere' => [fn (TestBuilder $q) => $q->preWhere('id', 1)->orPreWhere('id', 'in', [2]), 'PREWHERE `id` = 1 OR `id` IN (2)'],
            'having' => [fn (TestBuilder $q) => $q->groupBy('s')->having('s', 'like', 'x%'), "GROUP BY `s` HAVING `s` LIKE 'x%'"],
            'or having' => [
                fn (TestBuilder $q) => $q->groupBy('s')->having('s', 'x')->orHaving('s', 'not between', ['a', 'b']),
                "GROUP BY `s` HAVING `s` = 'x' OR NOT ( `s` BETWEEN 'a' AND 'b' )",
            ],
        ];
    }

    /**
     * @param Closure(TestBuilder): TestBuilder $query
     */
    #[DataProvider('lowerCaseOperatorProvider')]
    public function test_operators_are_read_in_any_letter_case(Closure $query, string $expectedClauses): void
    {
        $this->assertEquals("SELECT * FROM `t` {$expectedClauses}", $query($this->builder()->from('t'))->toSql());
    }

    /**
     * @return array<string, array{Closure(TestBuilder): TestBuilder, string}>
     */
    public static function lowerCaseBooleanProvider(): array
    {
        return [
            'where' => [fn (TestBuilder $q) => $q->where('a', '=', 2, 'or'), 'OR `a` = 2'],
            'where in mixed case' => [fn (TestBuilder $q) => $q->where('a', '=', 2, 'Or'), 'OR `a` = 2'],
            'where with and' => [fn (TestBuilder $q) => $q->where('a', '=', 2, 'and'), 'AND `a` = 2'],
            'whereIn' => [fn (TestBuilder $q) => $q->whereIn('a', [2], 'or'), 'OR `a` IN (2)'],
            'whereIn with an empty list' => [fn (TestBuilder $q) => $q->whereIn('a', [], 'or'), 'OR 0 = 1'],
            'whereNotIn' => [fn (TestBuilder $q) => $q->whereNotIn('a', [2], 'or'), 'OR `a` NOT IN (2)'],
            'whereNull' => [fn (TestBuilder $q) => $q->whereNull('a', 'or'), 'OR `a` IS NULL'],
            'whereBetween' => [fn (TestBuilder $q) => $q->whereBetween('a', [1, 2], 'or'), 'OR `a` BETWEEN 1 AND 2'],
            'whereNotBetween' => [fn (TestBuilder $q) => $q->whereNotBetween('a', [1, 2], 'or'), 'OR NOT ( `a` BETWEEN 1 AND 2 )'],
            'whereEmpty' => [fn (TestBuilder $q) => $q->whereEmpty('a', 'or'), 'OR empty(`a`)'],
            'whereColumn' => [fn (TestBuilder $q) => $q->whereColumn('a', '=', 'b', 'or'), 'OR `a` = `b`'],
            'whereAll' => [fn (TestBuilder $q) => $q->whereAll(['a', 'b'], '=', 2, 'or'), 'OR (`a` = 2 AND `b` = 2)'],
            'whereNone' => [fn (TestBuilder $q) => $q->whereNone(['a', 'b'], '=', 2, 'or'), 'OR NOT (`a` = 2 OR `b` = 2)'],
            'whereDate' => [fn (TestBuilder $q) => $q->whereDate('d', '=', '2024-01-05', 'or'), "OR toDate32(`d`) = '2024-01-05'"],
            'whereLike' => [fn (TestBuilder $q) => $q->whereLike('s', 'x%', false, 'or'), "OR `s` ILIKE 'x%'"],
            'whereExists' => [fn (TestBuilder $q) => $q->whereExists(fn (TestBuilder $s) => $s->from('u'), 'or'), 'OR EXISTS (SELECT * FROM `u`)'],
        ];
    }

    /**
     * @param Closure(TestBuilder): TestBuilder $query
     */
    #[DataProvider('lowerCaseBooleanProvider')]
    public function test_booleans_are_read_in_any_letter_case(Closure $query, string $expectedCondition): void
    {
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `id` = 1 {$expectedCondition}",
            $query($this->builder()->from('t')->where('id', 1))->toSql()
        );
    }

    public function test_an_operator_given_as_the_value_of_two_arguments_is_kept_as_it_is(): void
    {
        $this->assertEquals("SELECT * FROM `t` WHERE `s` = 'like'", $this->builder()->from('t')->where('s', 'like')->toSql());
        $this->assertEquals("SELECT * FROM `t` WHERE `s` = 'in'", $this->builder()->from('t')->where('s', 'in')->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `id` = 1 OR `s` = 'not like'",
            $this->builder()->from('t')->where('id', 1)->orWhere('s', 'not like')->toSql()
        );
        $this->assertEquals("SELECT * FROM `t` PREWHERE `s` = 'Between'", $this->builder()->from('t')->preWhere('s', 'Between')->toSql());
    }

    public function test_an_unknown_operator_is_named_as_it_was_given(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'similar to' is not part of the enum");

        $this->builder()->from('t')->where('s', 'similar to', 'x');
    }

    public function test_not_ilike_is_an_operator(): void
    {
        $this->assertSame('NOT ILIKE', Operator::NOT_ILIKE);
        $this->assertTrue(Operator::isValid('NOT ILIKE'));
        $this->assertEquals(
            "SELECT * FROM `t` WHERE `s` NOT ILIKE 'x%'",
            $this->builder()->from('t')->where('s', Operator::NOT_ILIKE, 'x%')->toSql()
        );
    }

    public function test_join_on_reads_its_operators_in_any_letter_case(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` ALL INNER JOIN `u` ON `t`.`a` = `u`.`a` OR `t`.`s` LIKE `u`.`s`',
            $this->builder()->from('t')->allInnerJoin(function (JoinClause $join) {
                $join->table('u')->on('t.a', '=', 'u.a')->on('t.s', 'like', 'u.s', 'or');
            })->toSql()
        );
    }

    /**
     * A group that adds no condition is left out, as in Laravel. It used to compile to WHERE (), which ClickHouse
     * rejects, so building a group from optional filters failed when none was given.
     */
    public function test_a_group_that_adds_no_condition_is_left_out(): void
    {
        $filters = [];
        $optionalFilters = function (BaseBuilder $query) use ($filters): void {
            foreach ($filters as $column => $value) {
                $query->where($column, $value);
            }
        };

        $this->assertEquals('SELECT * FROM `t`', $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query)->toSql());
        $this->assertEquals('SELECT * FROM `t`', $this->builder()->from('t')->where($optionalFilters)->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1',
            $this->builder()->from('t')->where('a', 1)->where($optionalFilters)->orWhere($optionalFilters)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` = 1',
            $this->builder()->from('t')->where($optionalFilters)->where('a', 1)->toSql()
        );
        $this->assertEquals('SELECT * FROM `t`', $this->builder()->from('t')->preWhere($optionalFilters)->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE `a` = 1',
            $this->builder()->from('t')->preWhere('a', 1)->orPreWhere($optionalFilters)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` GROUP BY `a`',
            $this->builder()->from('t')->groupBy('a')->having($optionalFilters)->orHaving($optionalFilters)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t`',
            $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query->orderBy('a'))->toSql(),
            'a group compiles only its conditions, so a group with only an ORDER BY adds nothing'
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (`a` = 1)',
            $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query->where('a', 1)->where($optionalFilters))->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (`a` = 1 OR `b` = 2)',
            $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query->where('a', 1)->orWhere('b', 2))->toSql()
        );
    }

    /**
     * A group whose conditions belong to another section still compiles to an empty group, which ClickHouse
     * rejects, rather than leaving those conditions out.
     */
    public function test_a_group_with_conditions_of_another_section_is_not_left_out(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` PREWHERE () WHERE `a` = 1',
            $this->builder()->from('t')->where('a', 1)->preWhere(fn (BaseBuilder $query) => $query->where('id', 2))->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE ()',
            $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query->having('id', 2))->toSql()
        );
    }

    public function test_a_closure_that_builds_a_sub_query_is_still_a_sub_query(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (SELECT max(`a`) FROM `u`) = 5',
            $this->builder()->from('t')->where(fn (BaseBuilder $query) => $query->select(raw('max(`a`)'))->from('u'), 5)->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE (SELECT * FROM `u`) BETWEEN 1 AND 2',
            $this->builder()->from('t')->whereBetween(fn (BaseBuilder $query) => $query->from('u'), [1, 2])->toSql()
        );
    }

    /**
     * Laravel's array form of where() is a group: each entry is a [column, value] or [column, operator, value]
     * array, or a column => value pair, joined with the boolean of the call, as in Laravel 11 and later. It used to
     * throw the PHP Error 'Call to a member function getFirstElement() on int'.
     */
    public function test_where_takes_laravels_array_form(): void
    {
        $cases = [
            "SELECT * FROM `t` WHERE (`a` = 1 AND `b` = 'x')" => fn (TestBuilder $q) => $q->where(['a' => 1, 'b' => 'x']),
            "SELECT * FROM `t` WHERE (`a` > 1 AND `b` = 2 AND `c` LIKE 'x%')" => fn (TestBuilder $q) => $q->where([['a', '>', 1], ['b', 2], ['c', 'like', 'x%']]),
            'SELECT * FROM `t` WHERE `id` = 1 OR (`a` = 1 OR `b` IS NULL)' => fn (TestBuilder $q) => $q->where('id', 1)->orWhere(['a' => 1, 'b' => null]),
            'SELECT * FROM `t` WHERE (`a` = 1 OR `b` = 2)' => fn (TestBuilder $q) => $q->where(['a' => 1, 'b' => 2], null, null, 'or'),
            'SELECT * FROM `t` WHERE (`a` = 1 AND `b` = 2)' => fn (TestBuilder $q) => $q->where([['a', 1], 'b' => 2]),
            'SELECT * FROM `t` WHERE (`a` = 1 OR `b` = 2) AND `c` = 3' => fn (TestBuilder $q) => $q->where([['a', 1], ['b', '=', 2, 'or']])->where('c', 3),
            'SELECT * FROM `t` WHERE (`a` IN (1, 2) AND `b` IN (3))' => fn (TestBuilder $q) => $q->where([['a', 'in', [1, 2]], ['b', [3]]]),
            'SELECT * FROM `t` WHERE (`a` = (SELECT `id` FROM `u`))' => fn (TestBuilder $q) => $q->where(['a' => fn (BaseBuilder $s) => $s->select('id')->from('u')]),
            'SELECT * FROM `t` WHERE (a > 1)' => fn (TestBuilder $q) => $q->where([[raw('a > 1')]]),
            'SELECT * FROM `t` PREWHERE (`a` = 1 AND `b` = 2)' => fn (TestBuilder $q) => $q->preWhere(['a' => 1, 'b' => 2]),
            'SELECT * FROM `t` PREWHERE `id` = 1 OR (`a` = 1 OR `b` = 2)' => fn (TestBuilder $q) => $q->preWhere('id', 1)->orPreWhere([['a', 1], ['b', 2]]),
            'SELECT * FROM `t` GROUP BY `a` HAVING (`a` > 1)' => fn (TestBuilder $q) => $q->groupBy('a')->having([['a', '>', 1]]),
            'SELECT * FROM `t`' => fn (TestBuilder $q) => $q->where([]),
            'SELECT * FROM `t` WHERE `a` = 1' => fn (TestBuilder $q) => $q->where('a', 1)->where([])->orWhere([]),
            'SELECT * FROM `t` WHERE (`a` = 1 OR `b` = 2) AND `id` = 3' => fn (TestBuilder $q) => $q
                ->where($this->builder()->where('a', 1)->orWhere('b', 2)->getWheres())
                ->where('id', 3),
        ];

        foreach ($cases as $expectedSql => $query) {
            $this->assertEquals($expectedSql, $query($this->builder()->from('t'))->toSql());
        }
    }

    public function test_where_refuses_an_array_entry_of_another_kind(): void
    {
        foreach ([['a', 'b'], [[]], [1 => 'x'], [fn (BaseBuilder $q) => $q->where('a', 1)]] as $conditions) {
            try {
                $this->builder()->from('t')->where($conditions);
                $this->fail('Expected an InvalidArgumentException for '.json_encode($conditions));
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'where() takes a list of [column, value] or [column, operator, value] arrays, or of column => value pairs.',
                    $exception->getMessage()
                );
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The = operator does not take a list of values');

        $this->builder()->from('t')->where(['a' => [1, 2]]);
    }

    /**
     * @return array<string, array{Closure(TestBuilder, array<int|string, mixed>): TestBuilder, string, bool}>
     */
    public static function betweenMethodProvider(): array
    {
        $where = fn (TestBuilder $q): TestBuilder => $q->where('id', 1);
        $preWhere = fn (TestBuilder $q): TestBuilder => $q->preWhere('id', 1);
        $having = fn (TestBuilder $q): TestBuilder => $q->groupBy('a')->having('id', 1);

        return [
            'whereBetween' => [fn (TestBuilder $q, array $v) => $q->whereBetween('a', $v), 'WHERE `a` BETWEEN %s AND %s', false],
            'orWhereBetween' => [fn (TestBuilder $q, array $v) => $where($q)->orWhereBetween('a', $v), 'WHERE `id` = 1 OR `a` BETWEEN %s AND %s', false],
            'whereNotBetween' => [fn (TestBuilder $q, array $v) => $q->whereNotBetween('a', $v), 'WHERE NOT ( `a` BETWEEN %s AND %s )', false],
            'orWhereNotBetween' => [fn (TestBuilder $q, array $v) => $where($q)->orWhereNotBetween('a', $v), 'WHERE `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', false],
            'whereBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->whereBetweenColumns('a', $v), 'WHERE `a` BETWEEN %s AND %s', true],
            'orWhereBetweenColumns' => [fn (TestBuilder $q, array $v) => $where($q)->orWhereBetweenColumns('a', $v), 'WHERE `id` = 1 OR `a` BETWEEN %s AND %s', true],
            'whereNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->whereNotBetweenColumns('a', $v), 'WHERE NOT ( `a` BETWEEN %s AND %s )', true],
            'orWhereNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $where($q)->orWhereNotBetweenColumns('a', $v), 'WHERE `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', true],
            'preWhereBetween' => [fn (TestBuilder $q, array $v) => $q->preWhereBetween('a', $v), 'PREWHERE `a` BETWEEN %s AND %s', false],
            'orPreWhereBetween' => [fn (TestBuilder $q, array $v) => $preWhere($q)->orPreWhereBetween('a', $v), 'PREWHERE `id` = 1 OR `a` BETWEEN %s AND %s', false],
            'preWhereNotBetween' => [fn (TestBuilder $q, array $v) => $q->preWhereNotBetween('a', $v), 'PREWHERE NOT ( `a` BETWEEN %s AND %s )', false],
            'orPreWhereNotBetween' => [fn (TestBuilder $q, array $v) => $preWhere($q)->orPreWhereNotBetween('a', $v), 'PREWHERE `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', false],
            'preWhereBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->preWhereBetweenColumns('a', $v), 'PREWHERE `a` BETWEEN %s AND %s', true],
            'orPreWhereBetweenColumns' => [fn (TestBuilder $q, array $v) => $preWhere($q)->orPreWhereBetweenColumns('a', $v), 'PREWHERE `id` = 1 OR `a` BETWEEN %s AND %s', true],
            'preWhereNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->preWhereNotBetweenColumns('a', $v), 'PREWHERE NOT ( `a` BETWEEN %s AND %s )', true],
            'orPreWhereNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $preWhere($q)->orPreWhereNotBetweenColumns('a', $v), 'PREWHERE `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', true],
            'havingBetween' => [fn (TestBuilder $q, array $v) => $q->groupBy('a')->havingBetween('a', $v), 'GROUP BY `a` HAVING `a` BETWEEN %s AND %s', false],
            'orHavingBetween' => [fn (TestBuilder $q, array $v) => $having($q)->orHavingBetween('a', $v), 'GROUP BY `a` HAVING `id` = 1 OR `a` BETWEEN %s AND %s', false],
            'havingNotBetween' => [fn (TestBuilder $q, array $v) => $q->groupBy('a')->havingNotBetween('a', $v), 'GROUP BY `a` HAVING NOT ( `a` BETWEEN %s AND %s )', false],
            'orHavingNotBetween' => [fn (TestBuilder $q, array $v) => $having($q)->orHavingNotBetween('a', $v), 'GROUP BY `a` HAVING `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', false],
            'havingBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->groupBy('a')->havingBetweenColumns('a', $v), 'GROUP BY `a` HAVING `a` BETWEEN %s AND %s', true],
            'orHavingBetweenColumns' => [fn (TestBuilder $q, array $v) => $having($q)->orHavingBetweenColumns('a', $v), 'GROUP BY `a` HAVING `id` = 1 OR `a` BETWEEN %s AND %s', true],
            'havingNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $q->groupBy('a')->havingNotBetweenColumns('a', $v), 'GROUP BY `a` HAVING NOT ( `a` BETWEEN %s AND %s )', true],
            'orHavingNotBetweenColumns' => [fn (TestBuilder $q, array $v) => $having($q)->orHavingNotBetweenColumns('a', $v), 'GROUP BY `a` HAVING `id` = 1 OR NOT ( `a` BETWEEN %s AND %s )', true],
        ];
    }

    /**
     * The BETWEEN methods read their list as where() reads a BETWEEN list: the first two values in order, whatever
     * their keys. A keyed list and a list of one used to raise an 'Undefined array key' warning.
     *
     * @param Closure(TestBuilder, array<int|string, mixed>): TestBuilder $method
     */
    #[DataProvider('betweenMethodProvider')]
    public function test_between_methods_take_the_first_two_values_of_any_list(Closure $method, string $clauses, bool $columns): void
    {
        [$lower, $upper, $third] = $columns ? ['b', 'c', 'd'] : [1, 2, 3];
        $expected = $columns ? sprintf("SELECT * FROM `t` {$clauses}", '`b`', '`c`') : sprintf("SELECT * FROM `t` {$clauses}", 1, 2);

        $this->assertEquals($expected, $method($this->builder()->from('t'), [$lower, $upper])->toSql());
        $this->assertEquals($expected, $method($this->builder()->from('t'), ['from' => $lower, 'to' => $upper])->toSql());
        $this->assertEquals($expected, $method($this->builder()->from('t'), [5 => $lower, 1 => $upper])->toSql());
        $this->assertEquals($expected, $method($this->builder()->from('t'), [$lower, $upper, $third])->toSql());

        foreach ([[$lower], []] as $values) {
            try {
                $method($this->builder()->from('t'), $values);
                $this->fail('Expected an InvalidArgumentException for a list of '.count($values));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringEndsWith(
                    'operator takes two values, the lower and the upper bound, but the array has '.count($values).'.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_between_columns_take_raw_sql_as_it_is(): void
    {
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` BETWEEN `t`.`b` AND c + 1',
            $this->builder()->from('t')->whereBetweenColumns('a', ['min' => 't.b', 'max' => raw('c + 1')])->toSql()
        );
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `a` BETWEEN b * 2 AND `c`',
            $this->builderWithLaravelGrammar()->from('t')->whereBetweenColumns('a', [new LaravelExpression('b * 2'), 'c'])->toSql()
        );
    }

    /**
     * A null bound is written as NULL, as Laravel binds it, so the condition matches no row. It used to be left
     * out, which gave the invalid `a` BETWEEN AND 5.
     */
    public function test_a_null_bound_of_between_is_written_as_null(): void
    {
        $this->assertEquals('SELECT * FROM `t` WHERE `a` BETWEEN NULL AND 5', $this->builder()->from('t')->whereBetween('a', [null, 5])->toSql());
        $this->assertEquals(
            'SELECT * FROM `t` WHERE `id` = 1 OR NOT ( `a` BETWEEN 1 AND NULL )',
            $this->builder()->from('t')->where('id', 1)->orWhereNotBetween('a', ['from' => 1, 'to' => null])->toSql()
        );
        $this->assertEquals('SELECT * FROM `t` PREWHERE `a` BETWEEN NULL AND NULL', $this->builder()->from('t')->preWhere('a', 'between', [null, null])->toSql());
        $this->assertEquals(
            "SELECT * FROM `t` WHERE toDate32(`d`) BETWEEN NULL AND '2024-01-01'",
            $this->builder()->from('t')->whereDate('d', 'between', [null, '2024-01-01'])->toSql()
        );
    }

    public function test_where_column(): void
    {
        $cases = [
            'SELECT * FROM `t` WHERE `a` = `b`' => fn (TestBuilder $q) => $q->whereColumn('a', 'b'),
            'SELECT * FROM `t` WHERE `a` < `b`' => fn (TestBuilder $q) => $q->whereColumn('a', '<', 'b'),
            'SELECT * FROM `t` WHERE `a` = `c`' => fn (TestBuilder $q) => $q->whereColumn('a', 'c', null),
            'SELECT * FROM `t` WHERE `a` != `b`' => fn (TestBuilder $q) => $q->whereColumn('a', '<>', 'b'),
            'SELECT * FROM `t` WHERE `a` LIKE `b`' => fn (TestBuilder $q) => $q->whereColumn('a', 'like', 'b'),
            'SELECT * FROM `t` WHERE `t`.`a` > `t`.`b`' => fn (TestBuilder $q) => $q->whereColumn('t.a', '>', 't.b'),
            'SELECT * FROM `t` WHERE `a` < b + 1' => fn (TestBuilder $q) => $q->whereColumn('a', '<', raw('b + 1')),
            'SELECT * FROM `t` WHERE a * 2 >= `b`' => fn (TestBuilder $q) => $q->whereColumn(raw('a * 2'), '>=', 'b'),
            'SELECT * FROM `t` WHERE `a` = b + 1' => fn (TestBuilder $q) => $q->whereColumn('a', raw('b + 1')),
            'SELECT * FROM `t` WHERE (`a` = `b` AND `a` <= `b`)' => fn (TestBuilder $q) => $q->whereColumn([['a', 'b'], ['a', '<=', 'b']]),
            'SELECT * FROM `t` WHERE (`a` = `b` AND `c` = `d`)' => fn (TestBuilder $q) => $q->whereColumn(['a' => 'b', 'c' => 'd']),
            'SELECT * FROM `t` WHERE (`a` = `b` OR `c` > `d`)' => fn (TestBuilder $q) => $q->whereColumn([['a', 'b'], ['c', '>', 'd', 'or']]),
            'SELECT * FROM `t` WHERE `id` = 3 OR `a` = `b`' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn('a', 'b'),
            'SELECT * FROM `t` WHERE `id` = 3 OR `a` > `b`' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn('a', '>', 'b'),
            'SELECT * FROM `t` WHERE `id` = 3 OR (`a` = `b`)' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn([['a', 'b']]),
            'SELECT * FROM `t` WHERE `id` = 3 OR (`a` = `b` OR `c` > `d`)' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn([['a', 'b'], ['c', '>', 'd']]),
            'SELECT * FROM `t` WHERE `id` = 3 OR (`a` = `b` OR `c` = `d`)' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn(['a' => 'b', 'c' => 'd']),
            'SELECT * FROM `t` WHERE (`a` = `b` OR `c` = `d`)' => fn (TestBuilder $q) => $q->whereColumn(['a' => 'b', 'c' => 'd'], null, null, 'or'),
            'SELECT * FROM `t` WHERE `id` = 3 OR (`a` = `b` AND `c` > `d`)' => fn (TestBuilder $q) => $q->where('id', 3)->orWhereColumn([['a', 'b'], ['c', '>', 'd', 'and']]),
            'SELECT * FROM `t` PREWHERE `id` = 3 OR (`a` = `b` OR `c` < `d`)' => fn (TestBuilder $q) => $q->preWhere('id', 3)->orPreWhereColumn([['a', 'b'], ['c', '<', 'd']]),
            'SELECT * FROM `t` PREWHERE `a` = `b`' => fn (TestBuilder $q) => $q->preWhereColumn('a', 'b'),
            'SELECT * FROM `t` PREWHERE (`a` = `b`)' => fn (TestBuilder $q) => $q->preWhereColumn(['a' => 'b']),
            'SELECT * FROM `t` PREWHERE `id` = 3 OR `a` < `b`' => fn (TestBuilder $q) => $q->preWhere('id', 3)->orPreWhereColumn('a', '<', 'b'),
        ];

        foreach ($cases as $expectedSql => $query) {
            $this->assertEquals($expectedSql, $query($this->builder()->from('t'))->toSql());
        }
    }

    public function test_where_column_refuses_a_missing_column_and_lists_of_another_kind(): void
    {
        $calls = [
            'whereColumn() compares two columns, but only one was given.' => [
                fn () => $this->builder()->from('t')->whereColumn('a'),
                fn () => $this->builder()->from('t')->whereColumn('a', '<', null),
                fn () => $this->builder()->from('t')->orWhereColumn('a', null, null),
                fn () => $this->builder()->from('t')->whereColumn([['a']]),
                fn () => $this->builder()->from('t')->preWhereColumn([['a', '<', null]]),
            ],
            'whereColumn() needs at least one pair of columns, but an empty list was given.' => [
                fn () => $this->builder()->from('t')->whereColumn([]),
                fn () => $this->builder()->from('t')->orPreWhereColumn([]),
            ],
            'whereColumn() takes a list of [first, second] or [first, operator, second] arrays, or of first => second pairs.' => [
                fn () => $this->builder()->from('t')->whereColumn(['a', 'b']),
                fn () => $this->builder()->from('t')->whereColumn(['a' => ['b']]),
            ],
        ];

        foreach ($calls as $message => $callsWithMessage) {
            foreach ($callsWithMessage as $call) {
                try {
                    $call();
                    $this->fail("Expected an InvalidArgumentException: {$message}");
                } catch (InvalidArgumentException $exception) {
                    $this->assertSame($message, $exception->getMessage());
                }
            }
        }
    }

    public function test_where_column_takes_operators_as_where_does(): void
    {
        try {
            $this->builder()->from('t')->whereColumn('a', 'is null', 'b');
            $this->fail('Expected an InvalidArgumentException for IS NULL with a second column');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('The IS NULL operator does not take a value', $exception->getMessage());
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Value 'within' is not part of the enum");

        $this->builder()->from('t')->whereColumn('a', 'within', 'b');
    }

    /**
     * An operand of a set operation used in the EXISTS tests: SELECT `a`, `b` FROM `t` WHERE `id` = 1.
     */
    private function operand(?BaseBuilder $query = null): BaseBuilder
    {
        return ($query ?? $this->builder())->select('a', 'b')->from('t')->where('id', 1);
    }

    public function test_where_exists(): void
    {
        $subQuery = fn (BaseBuilder $query) => $query->from('u')->where('v', 'p');

        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->whereExists($subQuery)->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE EXISTS (SELECT `v` FROM `u`)',
            $this->selectId('t')->whereExists($this->builder()->select('v')->from('u'))->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE NOT EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->whereNotExists($subQuery)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 5 OR EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->where('a', 5)->orWhereExists($subQuery)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 5 OR NOT EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->where('a', 5)->orWhereExists($subQuery, true)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 5 OR NOT EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->where('a', 5)->orWhereNotExists($subQuery)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 5 AND NOT EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->where('a', 5)->whereExists($subQuery, Operator::AND, true)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 5 OR NOT EXISTS (SELECT * FROM `u` WHERE `v` = 'p')",
            $this->selectId('t')->where('a', 5)->whereNotExists($subQuery, 'or')->toSql()
        );
    }

    public function test_where_exists_compiles_the_sub_query_when_it_is_called(): void
    {
        $subQuery = $this->builder()->from('u');
        $query = $this->selectId('t')->whereExists($subQuery);
        $subQuery->where('v', 'p');

        $this->assertEquals('SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `u`)', $query->toSql());
    }

    /**
     * ClickHouse leaves columns out of the queries that INTERSECT and EXCEPT compare inside EXISTS, so EXISTS over
     * <a> EXCEPT <b> was false while <a> EXCEPT <b> returned a row. Such a sub-query is wrapped to read every column
     * it returns, and a query that reads rows from one gets NOT ignore(*), which reads every column of its FROM
     * clause and its joins.
     *
     * @return array<string, array{Closure(QueryExtensionsTest): BaseBuilder, string}>
     */
    public static function intersectOrExceptSubQueryProvider(): array
    {
        $except = 'SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, b + 100 FROM `t` WHERE `id` = 1';

        return [
            'except' => [
                fn (self $test) => $test->operand()->except(fn (BaseBuilder $e) => $e->select('a', raw('b + 100'))->from('t')->where('id', 1)),
                "SELECT * FROM ({$except}) WHERE NOT ignore(*)",
            ],
            'intersect' => [
                fn (self $test) => $test->operand()->intersect($test->operand()),
                'SELECT * FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 INTERSECT SELECT `a`, `b` FROM `t` WHERE `id` = 1) WHERE NOT ignore(*)',
            ],
            'intersect distinct' => [
                fn (self $test) => $test->operand()->intersectDistinct($test->operand()),
                'SELECT * FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 INTERSECT DISTINCT SELECT `a`, `b` FROM `t` WHERE `id` = 1) WHERE NOT ignore(*)',
            ],
            'except distinct' => [
                fn (self $test) => $test->operand()->exceptDistinct($test->operand()),
                'SELECT * FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT DISTINCT SELECT `a`, `b` FROM `t` WHERE `id` = 1) WHERE NOT ignore(*)',
            ],
            'except in from()' => [
                fn (self $test) => $test->builder()->select('a')->from($test->operand()->except($test->operand())),
                'SELECT `a` FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1) WHERE NOT ignore(*)',
            ],
            'except in from() with a where of its own' => [
                fn (self $test) => $test->builder()->select('a')->from($test->operand()->except($test->operand()))->where('a', '>', 0)->orWhere('a', '<', -1),
                'SELECT `a` FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1) WHERE `a` > 0 OR `a` < -1 AND NOT ignore(*)',
            ],
            'except in a join' => [
                fn (self $test) => $test->builder()->select('v')->from('u')->crossJoin($test->operand()->except($test->operand()), false, 'x'),
                'SELECT `v` FROM `u` CROSS JOIN (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1) AS `x` WHERE NOT ignore(*)',
            ],
            'except in a withExpression()' => [
                fn (self $test) => $test->builder()->withExpression('w', $test->operand()->except($test->operand()))->select('a')->from('w'),
                'WITH `w` AS (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1) SELECT `a` FROM `w` WHERE NOT ignore(*)',
            ],
            'union all of a query with a join on an except' => [
                fn (self $test) => $test->builder()->select('v')->from('u')->unionAll(
                    $test->builder()->select('v')->from('u')->crossJoin($test->operand()->except($test->operand()), false, 'x')
                ),
                'SELECT `v` FROM `u` UNION ALL SELECT `v` FROM `u` CROSS JOIN (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1) AS `x` WHERE NOT ignore(*)',
            ],
            'union all with an except operand' => [
                fn (self $test) => $test->builder()->select('a', 'b')->from('t')->unionAll($test->operand()->except($test->operand())),
                'SELECT * FROM (SELECT `a`, `b` FROM `t` UNION ALL (SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1)) WHERE NOT ignore(*)',
            ],
            'union all alone' => [
                fn (self $test) => $test->builder()->from('u')->unionAll($test->builder()->from('w')),
                'SELECT * FROM `u` UNION ALL SELECT * FROM `w`',
            ],
            'union distinct in from()' => [
                fn (self $test) => $test->builder()->select('a')->from($test->operand()->unionDistinct($test->operand())),
                'SELECT `a` FROM (SELECT `a`, `b` FROM `t` WHERE `id` = 1 UNION DISTINCT SELECT `a`, `b` FROM `t` WHERE `id` = 1)',
            ],
        ];
    }

    /**
     * @param Closure(QueryExtensionsTest): BaseBuilder $subQuery
     */
    #[DataProvider('intersectOrExceptSubQueryProvider')]
    public function test_where_exists_reads_every_column_of_intersect_and_except(Closure $subQuery, string $expectedSubQuery): void
    {
        $query = $subQuery($this);
        $sqlBefore = $query->toSql();

        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE EXISTS ({$expectedSubQuery})",
            $this->selectId('t')->whereExists($query)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE `a` = 1 OR NOT EXISTS ({$expectedSubQuery})",
            $this->selectId('t')->where('a', 1)->orWhereNotExists($subQuery($this))->toSql()
        );
        $this->assertSame($sqlBefore, $query->toSql(), 'the sub-query itself is not changed');
    }

    /**
     * A sub-query that reads, by its name, a withExpression() that uses INTERSECT or EXCEPT, of the query or of a
     * query it is nested in through a closure, gets NOT ignore(*) as well. It used to be compiled as it is, so on
     * ClickHouse 24.8 EXISTS over such an EXCEPT returned no row and NOT EXISTS every row.
     */
    public function test_where_exists_reads_every_column_of_an_outer_intersect_or_except_read_by_its_name(): void
    {
        $except = 'SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1';
        $with = "WITH `w` AS ({$except}) SELECT `id` FROM `t` WHERE";
        $query = fn (): BaseBuilder => $this->selectId('t')->withExpression('w', $this->operand()->except($this->operand()));
        $fromW = fn (BaseBuilder $sub): BaseBuilder => $sub->from('w');

        $cases = [
            "{$with} EXISTS (SELECT * FROM `w` WHERE NOT ignore(*))" => fn () => $query()->whereExists($fromW),
            "{$with} NOT EXISTS (SELECT * FROM `w` WHERE NOT ignore(*))" => fn () => $query()->whereNotExists($this->builder()->from('w')),
            "{$with} `a` = 1 OR EXISTS (SELECT * FROM `w` AS `x` WHERE NOT ignore(*))" => fn () => $query()->where('a', 1)->orWhereExists(fn (BaseBuilder $sub) => $sub->from('w', 'x')),
            "{$with} EXISTS (SELECT `v` FROM `u` CROSS JOIN `w` WHERE NOT ignore(*))" => fn () => $query()->whereExists(fn (BaseBuilder $sub) => $sub->select('v')->from('u')->crossJoin('w')),
            "{$with} EXISTS (SELECT `a` FROM `u` UNION ALL SELECT `a` FROM `w` WHERE NOT ignore(*))" => fn () => $query()->whereExists(
                fn (BaseBuilder $sub) => $sub->select('a')->from('u')->unionAll(fn (BaseBuilder $operand) => $operand->select('a')->from('w'))
            ),
            "{$with} (EXISTS (SELECT * FROM `w` WHERE NOT ignore(*)) OR `a` = 1)" => fn () => $query()->where(
                fn (BaseBuilder $group) => $group->whereExists($fromW)->orWhere('a', 1)
            ),
            "{$with} EXISTS (SELECT * FROM `u` WHERE EXISTS (SELECT * FROM `w` WHERE NOT ignore(*)))" => fn () => $query()->whereExists(
                fn (BaseBuilder $sub) => $sub->from('u')->whereExists($fromW)
            ),
            "{$with} `id` IN (SELECT `id` FROM `u` WHERE EXISTS (SELECT * FROM `w` WHERE NOT ignore(*)))" => fn () => $query()->whereIn(
                'id',
                fn (BaseBuilder $sub) => $sub->select('id')->from('u')->whereExists($fromW)
            ),
            "{$with} `a` = 1 UNION ALL SELECT `id` FROM `u` WHERE EXISTS (SELECT * FROM `w` WHERE NOT ignore(*))" => fn () => $query()->where('a', 1)->unionAll(
                fn (BaseBuilder $operand) => $operand->select('id')->from('u')->whereExists($fromW)
            ),
            "WITH `w` AS ({$except}), `v` AS (SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `w` WHERE NOT ignore(*))) SELECT * FROM `v`" => fn () => $this->builder()
                ->withExpression('w', $this->operand()->except($this->operand()))
                ->withExpression('v', fn (BaseBuilder $v) => $v->select('id')->from('t')->whereExists($fromW))
                ->from('v'),
            'WITH `w` AS (SELECT `a`, `b` FROM `t` WHERE `id` = 1 INTERSECT SELECT `a`, `b` FROM `t` WHERE `id` = 1) SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `w` WHERE NOT ignore(*))' => fn () => $this->selectId('t')
                ->withExpression('w', fn (BaseBuilder $w) => $this->operand($w)->intersect($this->operand()))
                ->whereExists($fromW),
        ];

        foreach ($cases as $expectedSql => $build) {
            $this->assertEquals($expectedSql, $build()->toSql());
        }
    }

    /**
     * Only a table named after such a query gets NOT ignore(*). A withExpression() added after whereExists() is not
     * known when the sub-query is compiled.
     */
    public function test_where_exists_compiles_other_sub_queries_of_a_query_with_a_with_clause_as_they_are(): void
    {
        $exceptQuery = fn (): BaseBuilder => $this->operand()->except($this->operand());
        $except = 'SELECT `a`, `b` FROM `t` WHERE `id` = 1 EXCEPT SELECT `a`, `b` FROM `t` WHERE `id` = 1';

        $this->assertEquals(
            'WITH `w` AS (SELECT `a`, `b` FROM `t` WHERE `id` = 1) SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `w`)',
            $this->selectId('t')->withExpression('w', $this->operand())->whereExists(fn (BaseBuilder $sub) => $sub->from('w'))->toSql()
        );
        $this->assertEquals(
            "WITH `w` AS ({$except}) SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `u`) AND EXISTS (SELECT * FROM `db`.`w`)",
            $this->selectId('t')
                ->withExpression('w', $exceptQuery())
                ->whereExists(fn (BaseBuilder $sub) => $sub->from('u'))
                ->whereExists(fn (BaseBuilder $sub) => $sub->from('db.w'))
                ->toSql()
        );
        $this->assertEquals(
            "WITH `w` AS ({$except}) SELECT `id` FROM `t` WHERE EXISTS (SELECT * FROM `w`)",
            $this->selectId('t')->whereExists(fn (BaseBuilder $sub) => $sub->from('w'))->withExpression('w', $exceptQuery())->toSql()
        );
    }

    public function test_where_exists_takes_only_a_closure_or_a_builder(): void
    {
        foreach (['SELECT 1', raw('SELECT 1'), null] as $query) {
            try {
                $this->selectId('t')->whereExists($query);
                $this->fail('Expected an InvalidArgumentException for '.get_debug_type($query));
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'whereExists() takes a closure or a query builder of this package, but a value of type '
                    .get_debug_type($query).' was given.',
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_where_all_any_and_none(): void
    {
        $cases = [
            'SELECT `id` FROM `t` WHERE (`a` > 1 AND `b` > 1)' => fn (TestBuilder $q) => $q->whereAll(['a', 'b'], '>', 1),
            'SELECT `id` FROM `t` WHERE (`a` = 1 AND `b` = 1)' => fn (TestBuilder $q) => $q->whereAll(['a', 'b'], 1),
            'SELECT `id` FROM `t` WHERE (`a` = 3 OR `b` = 3)' => fn (TestBuilder $q) => $q->whereAny(['a', 'b'], 3),
            'SELECT `id` FROM `t` WHERE NOT (`a` = 1 OR `b` = 1)' => fn (TestBuilder $q) => $q->whereNone(['a', 'b'], 1),
            'SELECT `id` FROM `t` WHERE `id` = 1 OR NOT (`a` > 1 OR `b` > 1)' => fn (TestBuilder $q) => $q->where('id', 1)->orWhereNone(['a', 'b'], '>', 1),
            'SELECT `id` FROM `t` WHERE `id` = 1 OR (`a` > 1 AND `b` > 1)' => fn (TestBuilder $q) => $q->where('id', 1)->orWhereAll(['a', 'b'], '>', 1),
            'SELECT `id` FROM `t` WHERE `id` = 1 OR (`a` = 2 OR `b` = 2)' => fn (TestBuilder $q) => $q->where('id', 1)->orWhereAny(['a', 'b'], 2),
            'SELECT `id` FROM `t` WHERE `id` = 1 OR (`a` = 2 AND `b` = 2)' => fn (TestBuilder $q) => $q->where('id', 1)->whereAll(['a', 'b'], '=', 2, 'or'),
            'SELECT `id` FROM `t` WHERE NOT (`nd` IS NULL)' => fn (TestBuilder $q) => $q->whereNone(['nd'], null),
            'SELECT `id` FROM `t` WHERE (`a` IS NOT NULL AND `b` IS NOT NULL)' => fn (TestBuilder $q) => $q->whereAll(['a', 'b'], '!=', null),
            'SELECT `id` FROM `t` WHERE (`a` IN (1, 2) OR `b` IN (1, 2))' => fn (TestBuilder $q) => $q->whereAny(['a', 'b'], [1, 2]),
            'SELECT `id` FROM `t` WHERE (`a` NOT IN (1) AND `b` NOT IN (1))' => fn (TestBuilder $q) => $q->whereAll(['a', 'b'], 'not in', collect([1])),
            'SELECT `id` FROM `t` WHERE (0 = 1 OR 0 = 1)' => fn (TestBuilder $q) => $q->whereAny(['a', 'b'], 'in', []),
            "SELECT `id` FROM `t` WHERE (`s` LIKE 'x%' OR `u` LIKE 'x%')" => fn (TestBuilder $q) => $q->whereAny(['s', 'u'], 'like', 'x%'),
            "SELECT `id` FROM `t` WHERE (`s` = 'x')" => fn (TestBuilder $q) => $q->whereAll(['s'], 'x', null),
            'SELECT `id` FROM `t` WHERE (a + 1 != 0 AND `t`.`b` != 0)' => fn (TestBuilder $q) => $q->whereAll([raw('a + 1'), 't.b'], '<>', 0),
            'SELECT `id` FROM `t` WHERE (`a` BETWEEN 1 AND 2 OR `b` BETWEEN 1 AND 2)' => fn (TestBuilder $q) => $q->whereAny(['a', 'b'], 'between', [1, 2]),
            'SELECT `id` FROM `t` PREWHERE (`a` = 5 OR `b` = 5)' => fn (TestBuilder $q) => $q->preWhereAny(['a', 'b'], 5),
            'SELECT `id` FROM `t` PREWHERE (`a` > 5 AND `b` > 5)' => fn (TestBuilder $q) => $q->preWhereAll(['a', 'b'], '>', 5),
            'SELECT `id` FROM `t` PREWHERE NOT (`a` = 5 OR `b` = 5)' => fn (TestBuilder $q) => $q->preWhereNone(['a', 'b'], 5),
            'SELECT `id` FROM `t` PREWHERE `id` = 1 OR (`a` = 5 AND `b` = 5)' => fn (TestBuilder $q) => $q->preWhere('id', 1)->orPreWhereAll(['a', 'b'], 5),
            'SELECT `id` FROM `t` PREWHERE `id` = 1 OR (`a` = 5 OR `b` = 5)' => fn (TestBuilder $q) => $q->preWhere('id', 1)->orPreWhereAny(['a', 'b'], 5),
            'SELECT `id` FROM `t` PREWHERE `id` = 1 OR NOT (`a` = 5 OR `b` = 5)' => fn (TestBuilder $q) => $q->preWhere('id', 1)->orPreWhereNone(['a', 'b'], 5),
        ];

        foreach ($cases as $expectedSql => $query) {
            $this->assertEquals($expectedSql, $query($this->selectId('t'))->toSql());
        }
    }

    /**
     * Laravel adds no condition for an empty list. A missing condition in delete() or update() would change more
     * rows, so the methods throw instead.
     */
    public function test_where_all_any_and_none_need_a_column(): void
    {
        $methods = ['whereAll', 'orWhereAll', 'whereAny', 'orWhereAny', 'whereNone', 'orWhereNone', 'preWhereAll', 'orPreWhereAll', 'preWhereAny', 'orPreWhereAny', 'preWhereNone', 'orPreWhereNone'];

        foreach ($methods as $method) {
            try {
                $this->builder()->from('t')->{$method}([], 1);
                $this->fail("Expected an InvalidArgumentException from {$method}()");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('whereAll(), whereAny() and whereNone() need at least one column.', $exception->getMessage());
            }
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function datePartMethodProvider(): array
    {
        $parts = [
            'Date' => ["toDate32(`created_at`) %s '2024-01-05'", '2024-01-05'],
            'Time' => ["formatDateTime(`created_at`, '%%H:%%i:%%S') %s '10:20:00'", '10:20'],
            'Day' => ['toDayOfMonth(`created_at`) %s 5', '05'],
            'Month' => ['toMonth(`created_at`) %s 2', '02'],
            'Year' => ['toYear(`created_at`) %s 2024', '2024'],
        ];
        $dataSets = [];

        foreach ($parts as $part => [$condition, $value]) {
            $dataSets["where{$part}"] = ["where{$part}", "WHERE {$condition}", $value];
            $dataSets["orWhere{$part}"] = ["orWhere{$part}", "WHERE `id` = 1 OR {$condition}", $value];
            $dataSets["preWhere{$part}"] = ["preWhere{$part}", "PREWHERE {$condition}", $value];
            $dataSets["orPreWhere{$part}"] = ["orPreWhere{$part}", "PREWHERE `id` = 1 OR {$condition}", $value];
        }

        return $dataSets;
    }

    #[DataProvider('datePartMethodProvider')]
    public function test_date_part_methods_take_two_or_three_arguments(string $method, string $clause, string $value): void
    {
        $query = fn (): TestBuilder => str_starts_with($method, 'orPre')
            ? $this->selectId('t')->preWhere('id', 1)
            : (str_starts_with($method, 'or') ? $this->selectId('t')->where('id', 1) : $this->selectId('t'));

        $this->assertEquals("SELECT `id` FROM `t` ".sprintf($clause, '='), $query()->{$method}('created_at', $value)->toSql());
        $this->assertEquals("SELECT `id` FROM `t` ".sprintf($clause, '>='), $query()->{$method}('created_at', '>=', $value)->toSql());
        $this->assertEquals("SELECT `id` FROM `t` ".sprintf($clause, '='), $query()->{$method}('created_at', $value, null)->toSql());
    }

    public function test_where_date(): void
    {
        $utc = new DateTimeImmutable('2024-02-15 23:00:00', new DateTimeZone('UTC'));

        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE toDate32(`created_at`) >= '2024-02-15'",
            $this->selectId('t')->whereDate('created_at', '>=', Carbon::parse('2024-02-15 23:00:00'))->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE toDate32(`created_at`) = '2024-02-15' AND toDate32(`created_at`) = '2024-02-16'",
            $this->selectId('t')
                ->whereDate('created_at', $utc)
                ->whereDate('created_at', $utc->setTimezone(new DateTimeZone('Asia/Tokyo')))
                ->toSql(),
            'a date is written in its own time zone'
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE toDate32(`created_at`) BETWEEN '2024-01-01' AND '2024-12-31'",
            $this->selectId('t')->whereDate('created_at', 'between', ['from' => '2024-01-01', 'to' => new DateTime('2024-12-31 10:00:00')])->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE toDate32(`d`) IN ('2024-01-05', '2024-03-01')",
            $this->selectId('t')->whereDate('d', 'in', collect([Carbon::parse('2024-01-05 08:00:00'), '2024-03-01']))->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE toDate32(`nd`) IS NULL OR toDate32(`nd`) IS NOT NULL',
            $this->selectId('t')->whereDate('nd', null)->orWhereDate('nd', '!=', null)->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE toDate32(`e`.`created_at`) = '2024-01-05'",
            $this->selectId('t')->whereDate('e.created_at', Str::of('2024-01-05'))->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE toDate32(created_at + 1) = today()',
            $this->selectId('t')->whereDate(raw('created_at + 1'), raw('today()'))->toSql()
        );
    }

    public function test_where_date_takes_a_list_only_with_in_and_between(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The = operator does not take a list of values');

        $this->selectId('t')->whereDate('d', ['2024-01-05', '2024-01-06']);
    }

    public function test_where_time(): void
    {
        $cases = [
            "= '09:05:00'" => '9:05',
            "= '10:20:30'" => '10:20:30.123456',
            "= '07:08:09'" => Str::of('7:08:09'),
            "= '23:00:00'" => new DateTimeImmutable('2024-02-15 23:00:00.5', new DateTimeZone('Asia/Tokyo')),
        ];

        foreach ($cases as $condition => $value) {
            $this->assertEquals(
                "SELECT `id` FROM `t` WHERE formatDateTime(`created_at`, '%H:%i:%S') {$condition}",
                $this->selectId('t')->whereTime('created_at', $value)->toSql()
            );
        }

        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE formatDateTime(`created_at`, '%H:%i:%S') BETWEEN '09:00:00' AND '17:30:00'",
            $this->selectId('t')->whereTime('created_at', 'between', ['9:00', '17:30'])->toSql()
        );
        $this->assertEquals(
            "SELECT `id` FROM `t` WHERE formatDateTime(`created_at`, '%H:%i:%S') IS NULL",
            $this->selectId('t')->whereTime('created_at', null)->toSql()
        );

        $invalid = [
            "'noon'" => 'noon',
            "'10'" => '10',
            "'10:2'" => '10:2',
            "'10:20\n'" => "10:20\n",
            'a value of type int' => 1020,
            'a value of type bool' => true,
        ];

        foreach ($invalid as $description => $value) {
            try {
                $this->selectId('t')->whereTime('created_at', '>', $value);
                $this->fail("Expected an InvalidArgumentException for {$description}");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    "whereTime() takes a time of day, such as '10:20' or '10:20:30', or a date, but {$description} was given.",
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_where_day_month_and_year(): void
    {
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE toDayOfMonth(`created_at`) = 5 AND toMonth(`created_at`) > 1 AND toYear(`created_at`) = 2024',
            $this->selectId('t')
                ->whereDay('created_at', '05')
                ->whereMonth('created_at', '>', '01')
                ->whereYear('created_at', Carbon::parse('2024-06-01'))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE toDayOfMonth(`created_at`) = 16 AND toMonth(`created_at`) IN (1, 2, 3) AND toYear(`created_at`) BETWEEN 1960 AND 2200',
            $this->selectId('t')
                ->whereDay('created_at', (new DateTimeImmutable('2024-02-15 23:00:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Tokyo')))
                ->whereMonth('created_at', 'in', [1, '02', Carbon::parse('2024-03-01')])
                ->whereYear('created_at', 'between', collect([1960, Str::of('2200')]))
                ->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE toDayOfMonth(`nd`) IS NULL OR toDayOfMonth(`created_at`) = toDayOfMonth(today())',
            $this->selectId('t')->whereDay('nd', null)->orWhereDay('created_at', raw('toDayOfMonth(today())'))->toSql()
        );

        $invalid = [
            ['whereDay', 'fifth', "'fifth'"],
            ['whereMonth', '-1', "'-1'"],
            ['whereYear', 2024.0, 'a value of type float'],
            ['orWhereMonth', true, 'a value of type bool'],
            ['preWhereYear', ' 2024', "' 2024'"],
            ['whereDay', "5\n", "'5\n'"],
        ];

        foreach ($invalid as [$method, $value, $description]) {
            $part = preg_replace('/^(or)?(pre)?where/i', '', $method);

            try {
                $this->selectId('t')->{$method}('created_at', $value);
                $this->fail("Expected an InvalidArgumentException from {$method}() for {$description}");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    "where{$part}() takes an integer, a string of digits or a date, but {$description} was given.",
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_in_random_order(): void
    {
        $query = $this->selectId('t')->where('a', 1)->inRandomOrder();

        $this->assertEquals('SELECT `id` FROM `t` WHERE `a` = 1 ORDER BY rand()', $query->toSql());
        $this->assertEquals('SELECT `id` FROM `t` ORDER BY rand()', $this->selectId('t')->inRandomOrder(null)->toSql());
        $this->assertEquals(
            'SELECT count() as `count` FROM `t` WHERE `a` = 1',
            $query->getCountQuery()->toSql(),
            'the count query drops the order'
        );

        foreach ([42, 'seed'] as $seed) {
            try {
                $this->selectId('t')->inRandomOrder($seed);
                $this->fail('Expected an InvalidArgumentException for a seed');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'ClickHouse has no seeded random function, so inRandomOrder() cannot take a seed. For a repeatable '
                    ."order, order by a hash of the seed and a key: orderByRaw('cityHash64(42, id)').",
                    $exception->getMessage()
                );
            }
        }
    }

    public function test_latest_and_oldest(): void
    {
        $this->assertEquals('SELECT `id` FROM `t` ORDER BY `created_at` DESC', $this->selectId('t')->latest()->toSql());
        $this->assertEquals('SELECT `id` FROM `t` ORDER BY `created_at` ASC', $this->selectId('t')->oldest()->toSql());
        $this->assertEquals(
            'SELECT `id` FROM `t` ORDER BY `t`.`updated_at` DESC, toDate(at) ASC',
            $this->selectId('t')->latest('t.updated_at')->oldest(raw('toDate(at)'))->toSql()
        );
        $this->assertEquals(
            'SELECT `id` FROM `t` ORDER BY a % 2 DESC',
            $this->builderWithLaravelGrammar()->select('id')->from('t')->latest(new LaravelExpression('a % 2'))->toSql()
        );
    }

    public function test_when_and_unless(): void
    {
        $filter = fn (BaseBuilder $query, mixed $value): BaseBuilder => $query->where('a', $value);
        $fallback = fn (BaseBuilder $query): BaseBuilder => $query->where('b', 0);

        $this->assertEquals('SELECT `id` FROM `t` WHERE `a` = 5', $this->selectId('t')->when(5, $filter)->toSql());
        $this->assertEquals('SELECT `id` FROM `t`', $this->selectId('t')->when(0, $filter)->toSql());
        $this->assertEquals('SELECT `id` FROM `t` WHERE `b` = 0', $this->selectId('t')->when(null, $filter, $fallback)->toSql());
        $this->assertEquals('SELECT `id` FROM `t` WHERE `a` = 0', $this->selectId('t')->unless(0, $filter)->toSql());
        $this->assertEquals('SELECT `id` FROM `t` WHERE `b` = 0', $this->selectId('t')->unless(true, $filter, $fallback)->toSql());
        $this->assertEquals(
            'SELECT `id` FROM `t` WHERE `a` = 1 ORDER BY `id` ASC',
            $this->selectId('t')->when(true, fn (BaseBuilder $query) => null)->where('a', 1)->when(true)->orderBy('id')->toSql()
        );
    }

    public function test_where_like(): void
    {
        $cases = [
            "SELECT `id` FROM `t` WHERE `s` ILIKE 'X%'" => fn (TestBuilder $q) => $q->whereLike('s', 'X%'),
            "SELECT `id` FROM `t` WHERE `s` LIKE 'x%'" => fn (TestBuilder $q) => $q->whereLike('s', 'x%', true),
            "SELECT `id` FROM `t` WHERE `s` NOT ILIKE 'X%'" => fn (TestBuilder $q) => $q->whereNotLike('s', 'X%'),
            "SELECT `id` FROM `t` WHERE `s` NOT LIKE 'x%'" => fn (TestBuilder $q) => $q->whereNotLike('s', 'x%', true),
            "SELECT `id` FROM `t` WHERE `s` NOT LIKE 'x%' OR `s` NOT ILIKE 'it\\'s'" => fn (TestBuilder $q) => $q->whereLike('s', 'x%', true, Operator::AND, true)->whereNotLike('s', "it's", false, 'or'),
            "SELECT `id` FROM `t` WHERE `id` = 1 OR `s` ILIKE 'X%'" => fn (TestBuilder $q) => $q->where('id', 1)->orWhereLike('s', 'X%'),
            "SELECT `id` FROM `t` WHERE `id` = 1 OR `s` LIKE 'x%'" => fn (TestBuilder $q) => $q->where('id', 1)->orWhereLike('s', 'x%', true),
            "SELECT `id` FROM `t` WHERE `id` = 1 OR `s` NOT ILIKE 'X%'" => fn (TestBuilder $q) => $q->where('id', 1)->orWhereNotLike('s', 'X%'),
            "SELECT `id` FROM `t` WHERE `id` = 1 OR `s` NOT LIKE 'x%'" => fn (TestBuilder $q) => $q->where('id', 1)->orWhereNotLike('s', 'x%', true),
            "SELECT `id` FROM `t` WHERE lower(s) ILIKE 'x%'" => fn (TestBuilder $q) => $q->whereLike(raw('lower(s)'), 'x%'),
        ];

        foreach ($cases as $expectedSql => $query) {
            $this->assertEquals($expectedSql, $query($this->selectId('t'))->toSql());
        }
    }

    public function test_a_laravel_expression_needs_the_grammar_of_a_connection(): void
    {
        $calls = [
            fn () => $this->builder()->from('t')->where(new LaravelExpression('a + 1'), 2)->toSql(),
            fn () => $this->builder()->select(new LaravelExpression('count()'))->from('t')->toSql(),
            fn () => $this->builder()->from(new LaravelExpression('numbers(2)'))->toSql(),
            fn () => (new Grammar())->wrap(new LaravelExpression('a')),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected an InvalidArgumentException without a Laravel grammar resolver');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringStartsWith(
                    'Cannot write the Laravel database expression Illuminate\Database\Query\Expression without the grammar of a database connection.',
                    $exception->getMessage()
                );
            }
        }
    }

    /**
     * @return array<string, array{Closure(TestBuilder): TestBuilder, string}>
     */
    public static function laravelExpressionProvider(): array
    {
        $raw = fn (string $sql): LaravelExpression => new LaravelExpression($sql);

        return [
            'select' => [fn (TestBuilder $q) => $q->select($raw('count() AS c'))->from('t'), 'SELECT count() AS c FROM `t`'],
            'where column' => [fn (TestBuilder $q) => $q->from('t')->where($raw('a + 1'), 2), 'SELECT * FROM `t` WHERE a + 1 = 2'],
            'where value' => [fn (TestBuilder $q) => $q->from('t')->where('a', $raw('b')), 'SELECT * FROM `t` WHERE `a` = b'],
            'where null' => [fn (TestBuilder $q) => $q->from('t')->where($raw('nd'), null), 'SELECT * FROM `t` WHERE nd IS NULL'],
            'where not null' => [fn (TestBuilder $q) => $q->from('t')->where($raw('nd'), '!=', null), 'SELECT * FROM `t` WHERE nd IS NOT NULL'],
            'where bare' => [fn (TestBuilder $q) => $q->from('t')->where($raw('a > 1')), 'SELECT * FROM `t` WHERE a > 1'],
            'whereIn' => [fn (TestBuilder $q) => $q->from('t')->whereIn('id', [$raw('1 + 1'), 3]), 'SELECT * FROM `t` WHERE `id` IN (1 + 1, 3)'],
            'whereNull' => [fn (TestBuilder $q) => $q->from('t')->whereNull($raw('arr[1]')), 'SELECT * FROM `t` WHERE arr[1] IS NULL'],
            'preWhere' => [fn (TestBuilder $q) => $q->from('t')->preWhere($raw('a'), '>', 1), 'SELECT * FROM `t` PREWHERE a > 1'],
            'having' => [fn (TestBuilder $q) => $q->from('t')->groupBy('a')->having($raw('count()'), '>', 1), 'SELECT * FROM `t` GROUP BY `a` HAVING count() > 1'],
            'orderBy' => [fn (TestBuilder $q) => $q->from('t')->orderBy($raw('a * -1')), 'SELECT * FROM `t` ORDER BY a * -1 ASC'],
            'groupBy' => [fn (TestBuilder $q) => $q->from('t')->groupBy($raw('a % 2')), 'SELECT * FROM `t` GROUP BY a % 2'],
            'table' => [fn (TestBuilder $q) => $q->table($raw('numbers(2)')), 'SELECT * FROM numbers(2)'],
            'join table' => [fn (TestBuilder $q) => $q->from('t')->crossJoin($raw('numbers(2)')), 'SELECT * FROM `t` CROSS JOIN numbers(2)'],
            'join on' => [
                fn (TestBuilder $q) => $q->from('t')->allInnerJoin(fn (JoinClause $join) => $join->table('u')->on('t.a', '=', $raw('u.a + 1'))),
                'SELECT * FROM `t` ALL INNER JOIN `u` ON `t`.`a` = u.a + 1',
            ],
            'arrayJoin' => [fn (TestBuilder $q) => $q->from('t')->arrayJoin(['x' => $raw('range(3)')]), 'SELECT * FROM `t` ARRAY JOIN range(3) AS `x`'],
            'withAlias' => [fn (TestBuilder $q) => $q->withAlias('now', $raw('now()'))->select('now'), 'WITH now() AS `now` SELECT `now`'],
            'withExpression' => [fn (TestBuilder $q) => $q->withExpression('w', $raw('SELECT 1 AS x'))->select('x')->from('w'), 'WITH `w` AS (SELECT 1 AS x) SELECT `x` FROM `w`'],
            'withRecursiveExpression' => [
                fn (TestBuilder $q) => $q->withRecursiveExpression('r', $raw('SELECT 1 AS n'))->from('r'),
                'WITH RECURSIVE `r` AS (SELECT 1 AS n) SELECT * FROM `r`',
            ],
            'whereColumn' => [fn (TestBuilder $q) => $q->from('t')->whereColumn('a', '<', $raw('b + 1')), 'SELECT * FROM `t` WHERE `a` < b + 1'],
            'whereDate' => [fn (TestBuilder $q) => $q->from('t')->whereDate($raw('at'), $raw('today()')), 'SELECT * FROM `t` WHERE toDate32(at) = today()'],
            'whereAny' => [fn (TestBuilder $q) => $q->from('t')->whereAny([$raw('a + 0'), 'b'], 3), 'SELECT * FROM `t` WHERE (a + 0 = 3 OR `b` = 3)'],
            'whereLike' => [fn (TestBuilder $q) => $q->from('t')->whereLike($raw('lower(s)'), 'x%'), "SELECT * FROM `t` WHERE lower(s) ILIKE 'x%'"],
            'whereEmpty' => [fn (TestBuilder $q) => $q->from('t')->whereEmpty($raw('arr')), 'SELECT * FROM `t` WHERE empty(arr)'],
            'a sub-query in a closure' => [
                fn (TestBuilder $q) => $q->from('t')->whereIn('id', fn (BaseBuilder $s) => $s->select($raw('max(id)'))->from('u')),
                'SELECT * FROM `t` WHERE `id` IN (SELECT max(id) FROM `u`)',
            ],
        ];
    }

    /**
     * A Laravel database expression, such as DB::raw(), is written as raw() is, wherever raw SQL is taken.
     *
     * @param Closure(TestBuilder): TestBuilder $query
     */
    #[DataProvider('laravelExpressionProvider')]
    public function test_a_laravel_expression_is_written_as_raw_sql(Closure $query, string $expectedSql): void
    {
        $this->assertEquals($expectedSql, $query($this->builderWithLaravelGrammar())->toSql());
    }
}
