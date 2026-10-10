<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use ClickHouseDB\Client;
use ClickHouseDB\Exception\QueryException as ClientQueryException;
use ClickHouseDB\Exception\UnsupportedValueType;
use ClickHouseDB\Query\Expression\Raw;
use ClickHouseDB\Statement;
use ClickHouseDB\Transport\CurlerRequest;
use ClickHouseDB\Transport\CurlerResponse;
use ClickHouseDB\Transport\CurlerRolling;
use Closure;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns as EloquentConcerns;
use Illuminate\Database\Eloquent\InvalidCastException;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression as LaravelExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use JsonSerializable;
use Orchestra\Testbench\TestCase;
use Oralunal\LaravelClickHouse\BaseModel;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\DateTimePrecision;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Enums\Format;
use Oralunal\LaravelClickHouse\ClickhouseBuilder\Query\Expression;
use Oralunal\LaravelClickHouse\ClientRequests;
use Oralunal\LaravelClickHouse\Concerns\BufferedInsertRegistry;
use Oralunal\LaravelClickHouse\Concerns\HasAttributes;
use Oralunal\LaravelClickHouse\Connection;
use Oralunal\LaravelClickHouse\Exceptions\QueryException;
use Oralunal\LaravelClickHouse\Grammar;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use ValueError;

/**
 * The attribute behaviour of BaseModel: reading and writing attributes, fill(),
 * accessors and mutators, $casts, $appends, $hidden, toArray(), isset(), dirty
 * tracking, date casts at the connection's datetime_precision, and the rows that
 * save() and create() send. Also the requests of the model's inserts and buffers,
 * the rows they refuse, optimize() and truncate(), and the queries it starts. No
 * ClickHouse server is needed:
 * AttributesCapturingModel records its inserts, and the recording models use a
 * connection whose client records its requests.
 */
class BaseModelAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow();
        AttributesCapturingModel::$captured = [];
        AttributesRecordingFormatModel::$format = null;
        AttributesRecordingModel::clearBuffer();
        AttributesRecordingJsonModel::clearBuffer();
        AttributesRecordingOtherConnectionModel::clearBuffer();
    }

    protected function tearDown(): void
    {
        AttributesRecordingModel::clearBuffer();
        AttributesRecordingJsonModel::clearBuffer();
        AttributesRecordingOtherConnectionModel::clearBuffer();
        AttributesRecordingModel::$recordingConnection = null;
        AttributesRecordingOtherConnectionModel::$otherConnection = null;

        parent::tearDown();
    }

    public function testBaseModelUsesNoEloquentConcernThatRequiresModel(): void
    {
        $traits = class_uses_recursive(BaseModel::class);

        $this->assertContains(HasAttributes::class, $traits);
        $this->assertNotContains(EloquentConcerns\HasAttributes::class, $traits, 'Larastan requires a class that uses it to extend Model.');
        $this->assertNotContains(EloquentConcerns\HasEvents::class, $traits, 'Larastan requires a class that uses it to extend Model.');
        $this->assertNotContains(EloquentConcerns\HasRelationships::class, $traits, 'Larastan requires a class that uses it to extend Model.');
    }

    public function testRelationshipAndTimestampMethodsAreGone(): void
    {
        foreach (['usesTimestamps', 'getRelationValue', 'relationResolver', 'relationLoaded', 'isRelation', 'relationsToArray'] as $method) {
            $this->assertFalse(method_exists(BaseModel::class, $method), $method);
        }

        $this->assertSame(['a' => 'int'], AttributesPlainModel::make()->mergeCasts(['a' => 'int'])->getCasts());
        $this->assertFalse(BaseModel::preventsAccessingMissingAttributes());
    }

    public function testOverridesOfTheRemovedMethodsAreNotCalled(): void
    {
        $model = new AttributesOldStubsModel();
        $model->created_at = Carbon::parse('2024-01-02 03:04:05.9', 'UTC');

        $this->assertSame([], $model->getDates(), 'getDates() does not ask usesTimestamps().');
        $this->assertInstanceOf(Carbon::class, $model->getAttributes()['created_at'], 'Without a cast, created_at is not a date column.');
        $this->assertNull($model->author, 'A key named like a method of the model does not read getRelationValue().');
        $this->assertNull($model->cached, 'A key that relationLoaded() accepts does not read getRelationValue().');
        $this->assertNull($model->virtual, 'A key that relationResolver() accepts does not read getRelationValue().');
    }

    public function testSetAndReadAnAttribute(): void
    {
        $model = new AttributesPlainModel();
        $model->col = 1;

        $this->assertSame(1, $model->col);
        $this->assertSame($model, $model->setAttribute('a', 'x'));
        $this->assertSame('x', $model->getAttribute('a'));
        $this->assertSame(['col' => 1, 'a' => 'x'], $model->getAttributes());
    }

    public function testMissingKeysReadAsNull(): void
    {
        $model = new AttributesPlainModel();

        $this->assertNull($model->missing);
        $this->assertNull($model->getAttribute(''));
        $this->assertNull($model->helperMethod, 'A key named like a method of the model is not a relationship.');
        $this->assertNull($model->getTable, 'A key named like a method of BaseModel.');
        $this->assertNull($model->where);
    }

    public function testFillStoresEveryKeyAsGiven(): void
    {
        $model = new AttributesPlainModel();

        $this->assertSame($model, $model->fill(['a' => 1, 'b' => 'two', 'c' => null]));
        $this->assertSame(['a' => 1, 'b' => 'two', 'c' => null], $model->getAttributes());
        $this->assertSame([0 => 'x'], (new AttributesPlainModel())->fill([0 => 'x'])->getAttributes());
        $this->assertSame(
            ['tick`name' => 1, 'ends\\' => 2, 'n.a' => [3]],
            AttributesPlainModel::make(['tick`name' => 1, 'ends\\' => 2, 'n.a' => [3]])->getAttributes()
        );
    }

    public function testSetRawAttributesSkipsCastsAndMutators(): void
    {
        $model = (new AttributesMutatorModel())->setRawAttributes(['name' => ' raw ', 'x' => 1]);

        $this->assertSame(['name' => ' raw ', 'x' => 1], $model->getAttributes());
    }

    public function testColumnsNamedLikeBaseModelProperties(): void
    {
        $model = AttributesPlainModel::make(['exists' => 5, 'table' => 't', 'casts' => 'c', 'attributes' => 'a', 'connection' => 'x']);

        $this->assertSame(['exists' => 5, 'table' => 't', 'casts' => 'c', 'attributes' => 'a', 'connection' => 'x'], $model->getAttributes());
        $this->assertFalse($model->exists, 'The public $exists property shadows the column.');
        $this->assertSame(['t', 'c', 'a'], [$model->table, $model->casts, $model->attributes]);
        $this->assertSame('attributes_plain_models', $model->getTable());
    }

    public function testAttributesToArrayAndToArray(): void
    {
        $model = AttributesPlainModel::make(['a' => 1, 'b' => [1, 2]]);

        $this->assertSame(['a' => 1, 'b' => [1, 2]], $model->attributesToArray());
        $this->assertSame(['a' => 1, 'b' => [1, 2]], $model->toArray());
    }

    public function testModelIsStillNotArrayableJsonSerializableOrArrayAccess(): void
    {
        $model = AttributesPlainModel::make(['a' => 1]);

        $this->assertNotInstanceOf(Arrayable::class, $model);
        $this->assertNotInstanceOf(JsonSerializable::class, $model);
        $this->assertNotInstanceOf(\ArrayAccess::class, $model);
        $this->assertSame('{"exists":false,"wasRecentlyCreated":false}', json_encode($model));
    }

    public function testIssetOnAttributesAndAccessors(): void
    {
        $this->assertTrue(isset(AttributesPlainModel::make(['a' => 1])->a));
        $this->assertTrue(isset(AttributesPlainModel::make(['a' => null])->a), 'An attribute that holds null is set.');
        $this->assertTrue(empty(AttributesPlainModel::make(['a' => '0'])->a));
        $this->assertTrue(isset((new AttributesMutatorModel())->full), 'A getFullAttribute() accessor.');
    }

    public function testIssetOnKeysWithoutAValue(): void
    {
        $this->assertFalse(isset((new AttributesPlainModel())->missing));
        $this->assertTrue(empty((new AttributesPlainModel())->missing));
        $this->assertFalse(isset((new AttributesCastModel())->c_int), 'A cast column without a value.');
        $this->assertTrue(isset((new AttributesMutatorModel())->slug), 'A slug(): Attribute accessor.');
    }

    public function testUnsetDoesNotRemoveTheAttribute(): void
    {
        $model = AttributesPlainModel::make(['a' => 1, 'b' => 2]);
        unset($model->a);

        $this->assertSame(['a' => 1, 'b' => 2], $model->getAttributes());
    }

    public function testCloneKeepsItsOwnAttributes(): void
    {
        $model = AttributesPlainModel::make(['a' => 1]);
        $clone = clone $model;
        $clone->a = 2;

        $this->assertSame([1, 2], [$model->a, $clone->a]);
    }

    public function testOldStyleAccessorsAndMutators(): void
    {
        $this->assertSame('BOB', self::raw(AttributesMutatorModel::class, ['name' => 'bob'])->name);
        $this->assertSame('full:bob', self::raw(AttributesMutatorModel::class, ['name' => 'bob'])->full);

        $model = new AttributesMutatorModel();
        $model->name = ' bob ';
        $this->assertSame(['name' => 'bob'], $model->getAttributes());
        $this->assertSame(['name' => 'x', 'title' => 'hello'], AttributesMutatorModel::make(['name' => '  x ', 'title' => 'HeLLo'])->getAttributes());
    }

    public function testAttributeAccessorsAndMutators(): void
    {
        $this->assertSame('Hello', self::raw(AttributesMutatorModel::class, ['title' => 'hello'])->title);
        $this->assertSame('slug-abc', self::raw(AttributesMutatorModel::class, ['title' => 'abc'])->slug);

        $model = new AttributesMutatorModel();
        $model->title = 'HeLLo';
        $model->slug = 'z';
        $model->shout = 'hey';
        $this->assertSame(['title' => 'hello', 'slug' => 'z', 'shout' => 'HEY'], $model->getAttributes());
        $this->assertSame('HEY', $model->shout);
        $this->assertSame(['name', 'full', 'title', 'slug'], $model->getMutatedAttributes());
    }

    public function testAppends(): void
    {
        $this->assertSame(
            ['name' => 'BOB', 'title' => 'Hello', 'other' => 1, 'full' => 'full:bob'],
            self::raw(AttributesMutatorModel::class, ['name' => 'bob', 'title' => 'hello', 'other' => 1])->attributesToArray()
        );
        $this->assertSame(['title' => 'abc', 'slug' => 'slug-abc'], self::raw(AttributesAppendsAttributeModel::class, ['title' => 'abc'])->toArray());
        $this->assertSame(
            ['title' => 'Abc', 'full' => 'full:', 'slug' => 'slug-abc'],
            self::raw(AttributesMutatorModel::class, ['title' => 'abc'])->append('slug')->attributesToArray()
        );

        $model = self::raw(AttributesMutatorModel::class, ['name' => 'bob']);
        $this->assertSame(['full'], $model->getAppends());
        $this->assertSame(['name' => 'BOB'], $model->setAppends([])->attributesToArray());
    }

    public function testAppendedAccessorsReceiveTheStoredValueOfTheirKey(): void
    {
        $model = self::raw(AttributesAppendsStoredValueModel::class, ['full' => 'F', 'slug' => 'S']);
        $this->assertSame(['full' => 'full:F', 'slug' => 'slug:S'], $model->attributesToArray(), 'Laravel 13.0 passed null.');
        $this->assertSame(['full' => 'full:F', 'slug' => 'slug:S'], $model->toArray());

        $this->assertSame(['other' => 1, 'full' => 'full:null', 'slug' => 'slug:null'], self::raw(AttributesAppendsStoredValueModel::class, ['other' => 1])->toArray());
    }

    public function testHiddenAndVisible(): void
    {
        $this->assertSame(['a' => 1], self::raw(AttributesHidingModel::class, ['a' => 1, 'secret' => 's'])->toArray());
        $this->assertSame(['a' => 1, 'secret' => 's'], self::raw(AttributesHidingModel::class, ['a' => 1, 'secret' => 's'])->makeVisible('secret')->toArray());
        $this->assertSame(['b' => 2], self::raw(AttributesPlainModel::class, ['a' => 1, 'b' => 2])->makeHidden('a')->toArray());
        $this->assertSame(['a' => 1], self::raw(AttributesVisibleModel::class, ['a' => 1, 'b' => 2])->toArray());
        $this->assertSame([[], []], [(new AttributesPlainModel())->getHidden(), (new AttributesPlainModel())->getVisible()]);
        $this->assertSame(['a' => 1, 'secret' => 's'], self::raw(AttributesHidingModel::class, ['a' => 1, 'secret' => 's'])->getAttributes());
    }

    /**
     * @return array<string, array{string, mixed, mixed, mixed, mixed, mixed}>
     */
    public static function primitiveCasts(): array
    {
        return [
            // key => [column, raw value, value read, value written, value stored, value in toArray()]
            'int' => ['c_int', '42', 42, '42', '42', 42],
            'integer' => ['c_integer', '7.9', 7, 7.9, 7.9, 7],
            'real' => ['c_real', '1.5', 1.5, '1.5', '1.5', 1.5],
            'float' => ['c_float', '2.25', 2.25, '2.25', '2.25', 2.25],
            'double' => ['c_double', '3.5', 3.5, 3, 3, 3.5],
            'decimal:2' => ['c_decimal', '1.555', '1.56', 1.555, 1.555, '1.56'],
            'string' => ['c_string', 42, '42', 42, 42, '42'],
            'bool' => ['c_bool', 1, true, true, true, true],
            'boolean' => ['c_boolean', '0', false, false, false, false],
            'array' => ['c_array', '{"a":1}', ['a' => 1], ['a' => 'é'], json_encode(['a' => 'é']), ['a' => 1]],
            'json' => ['c_json', '[1,2]', [1, 2], ['b' => 'é'], json_encode(['b' => 'é']), [1, 2]],
            'json:unicode' => ['c_json_unicode', '{"u":"é"}', ['u' => 'é'], ['u' => 'é'], '{"u":"é"}', ['u' => 'é']],
            'backed enum' => ['c_backed', 'active', AttributesStatus::Active, AttributesStatus::Inactive, 'inactive', 'active'],
            'unit enum' => ['c_unit', 'Small', AttributesSize::Small, AttributesSize::Large, 'Large', 'Small'],
            'custom' => ['c_custom', 'abc', 'ABC', 'XyZ', 'xyz', 'ABC'],
            'inbound' => ['c_inbound', 'abc', 'abc', 'v', 'in:v', 'abc'],
        ];
    }

    #[DataProvider('primitiveCasts')]
    public function testCast(string $column, mixed $raw, mixed $read, mixed $written, mixed $stored, mixed $inArray): void
    {
        $this->assertSame($read, self::raw(AttributesCastModel::class, [$column => $raw])->$column);
        $this->assertSame($inArray, self::raw(AttributesCastModel::class, [$column => $raw])->toArray()[$column]);
        $this->assertNull(self::raw(AttributesCastModel::class, [$column => null])->$column);

        $model = new AttributesCastModel();
        $model->$column = $written;
        $this->assertSame([$column => $stored], $model->getAttributes());
    }

    public function testObjectAndCollectionCasts(): void
    {
        $this->assertEquals((object) ['a' => 1], self::raw(AttributesCastModel::class, ['c_object' => '{"a":1}'])->c_object);
        $this->assertInstanceOf(stdClass::class, self::raw(AttributesCastModel::class, ['c_object' => '{"a":1}'])->toArray()['c_object']);
        $this->assertSame(['c_object' => '{"a":1}'], AttributesCastModel::make(['c_object' => ['a' => 1]])->getAttributes());

        $this->assertEquals(new Collection([1, 2]), self::raw(AttributesCastModel::class, ['c_collection' => '[1,2]'])->c_collection);
        $this->assertSame([1, 2], self::raw(AttributesCastModel::class, ['c_collection' => '[1,2]'])->toArray()['c_collection']);
        $this->assertSame(['c_collection' => '[1,2]'], AttributesCastModel::make(['c_collection' => [1, 2]])->getAttributes());
    }

    public function testClassCasts(): void
    {
        $arrayObject = self::raw(AttributesCastModel::class, ['c_array_object' => '{"a":1}'])->c_array_object;
        $this->assertInstanceOf(ArrayObject::class, $arrayObject);
        $this->assertSame(['a' => 1], $arrayObject->getArrayCopy());
        $this->assertSame(['c_array_object' => '{"a":1}'], AttributesCastModel::make(['c_array_object' => ['a' => 1]])->getAttributes());
        $this->assertSame(['a' => 1], self::raw(AttributesCastModel::class, ['c_array_object' => '{"a":1}'])->toArray()['c_array_object']);

        $model = self::raw(AttributesCastModel::class, ['c_array_object' => '{"a":1}']);
        $model->c_array_object['b'] = 2;
        $this->assertSame(['c_array_object' => '{"a":1,"b":2}'], $model->getAttributes(), 'An object changed in place reaches getAttributes().');

        $this->assertEquals(new Collection([1, 2]), self::raw(AttributesCastModel::class, ['c_as_collection' => '[1,2]'])->c_as_collection);
        $this->assertSame(['c_as_collection' => '[1,2]'], AttributesCastModel::make(['c_as_collection' => [1, 2]])->getAttributes());

        $this->assertEquals(new Stringable('abc'), self::raw(AttributesCastModel::class, ['c_stringable' => 'abc'])->c_stringable);
        $this->assertSame(['c_stringable' => 'xyz'], AttributesCastModel::make(['c_stringable' => 'xyz'])->getAttributes());

        $this->assertEquals(new AttributesValueObject('abc'), self::raw(AttributesCastModel::class, ['c_castable' => 'abc'])->c_castable);
        $this->assertSame(['c_castable' => 'v'], AttributesCastModel::make(['c_castable' => new AttributesValueObject('v')])->getAttributes());
    }

    public function testEnumCastRejectsAnotherEnum(): void
    {
        $model = new AttributesCastModel();

        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('is not of the expected enum type [' . AttributesStatus::class . ']');

        $model->c_backed = AttributesSize::Small;
    }

    public function testHashedAndEncryptedCasts(): void
    {
        $this->assertSame('plain-text', self::raw(AttributesCastModel::class, ['c_hashed' => 'plain-text'])->c_hashed);
        $this->assertTrue(Hash::check('secret', AttributesCastModel::make(['c_hashed' => 'secret'])->getAttributes()['c_hashed']));

        $this->assertSame('x', self::raw(AttributesCastModel::class, ['c_encrypted' => Crypt::encrypt('x', false)])->c_encrypted);
        $this->assertSame('x', Crypt::decrypt(AttributesCastModel::make(['c_encrypted' => 'x'])->getAttributes()['c_encrypted'], false));
        $this->assertSame(['a' => 1], self::raw(AttributesCastModel::class, ['c_encrypted_array' => Crypt::encrypt('{"a":1}', false)])->c_encrypted_array);
        $this->assertSame('{"a":1}', Crypt::decrypt(AttributesCastModel::make(['c_encrypted_array' => ['a' => 1]])->getAttributes()['c_encrypted_array'], false));
    }

    public function testJsonPathWrites(): void
    {
        $model = self::raw(AttributesCastModel::class, ['c_array' => '{"a":1}']);
        $model->{'c_array->x'} = 5;
        $this->assertSame(['c_array' => '{"a":1,"x":5}'], $model->getAttributes());

        $model = new AttributesPlainModel();
        $model->{'j->k'} = 1;
        $this->assertSame(['j' => '{"k":1}'], $model->getAttributes());
    }

    public function testDateCastsWithDateFormat(): void
    {
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');

        $model = new AttributesCastWithDateFormatModel();
        $model->c_date = $date;
        $model->c_datetime = $date;
        $model->c_immutable_datetime = $date;
        $this->assertSame(['c_date' => '2024-01-02 03:04:05', 'c_datetime' => '2024-01-02 03:04:05', 'c_immutable_datetime' => '2024-01-02 03:04:05'], $model->getAttributes());

        $read = self::raw(AttributesCastWithDateFormatModel::class, ['c_datetime' => '2024-01-02 03:04:05']);
        $this->assertEquals(Carbon::parse('2024-01-02 03:04:05', 'UTC'), $read->c_datetime);
        $this->assertSame('2024-01-02T03:04:05.000000Z', $read->toArray()['c_datetime']);
        $this->assertSame(1704164645, self::raw(AttributesCastWithDateFormatModel::class, ['c_timestamp' => '2024-01-02 03:04:05'])->c_timestamp);
        $this->assertSame('2024-01-02', self::raw(AttributesCastWithDateFormatModel::class, ['c_datetime_fmt' => '2024-01-02 03:04:05'])->toArray()['c_datetime_fmt']);
        $this->assertSame('Y-m-d H:i:s', (new AttributesCastWithDateFormatModel())->getDateFormat());
        $this->assertSame(['d' => '02/01/2024'], self::raw(AttributesSerializeDateModel::class, ['d' => '2024-01-02 03:04:05'])->toArray());
    }

    public function testDateCastsWithoutDateFormatUseTheDefaultFormat(): void
    {
        $model = new AttributesCastModel();
        $model->c_datetime = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');
        $model->c_date = '2024-01-02';

        $this->assertSame('Y-m-d H:i:s', $model->getDateFormat());
        $this->assertSame(['c_datetime' => '2024-01-02 03:04:05', 'c_date' => '2024-01-02 00:00:00'], $model->getAttributes());
        $this->assertEquals(Carbon::parse('2024-01-02 03:04:05', 'UTC'), self::raw(AttributesCastModel::class, ['c_datetime' => '2024-01-02 03:04:05'])->c_datetime);
        $this->assertSame('2024-01-02T03:04:05.000000Z', self::raw(AttributesCastModel::class, ['c_datetime' => '2024-01-02 03:04:05'])->toArray()['c_datetime']);
        $this->assertSame(['c_datetime' => '2024-01-02'], (new AttributesCastModel())->setDateFormat('Y-m-d')->fill(['c_datetime' => Carbon::parse('2024-01-02 03:04:05', 'UTC')])->getAttributes());
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    public static function dateTimePrecisionOptions(): array
    {
        return [
            // key => [datetime_precision of the clickhouse connection, a date with a fraction, a whole second]
            'left out' => [null, '2024-01-02 03:04:05', '2024-01-02 03:04:05'],
            'second' => ['second', '2024-01-02 03:04:05', '2024-01-02 03:04:05'],
            'microsecond' => ['microsecond', '2024-01-02 03:04:05.123456', '2024-01-02 03:04:05'],
            'microsecond in another letter case, with spaces' => [' MicroSecond ', '2024-01-02 03:04:05.123456', '2024-01-02 03:04:05'],
            'a DateTimePrecision instance' => [new DateTimePrecision(DateTimePrecision::MICROSECOND), '2024-01-02 03:04:05.123456', '2024-01-02 03:04:05'],
            'an invalid value, which the connection refuses when it is made' => ['millisecond', '2024-01-02 03:04:05', '2024-01-02 03:04:05'],
        ];
    }

    /**
     * Without $dateFormat, a date and time cast stores a date as the model's connection writes it in conditions and
     * inserts: with its microseconds while datetime_precision is 'microsecond', and a whole second without a
     * fraction, which a DateTime column refuses. The precision is read from the config without making the
     * connection, which would ping a node.
     */
    #[DataProvider('dateTimePrecisionOptions')]
    public function testDateCastsFollowTheDateTimePrecisionOfTheConnection(mixed $precision, string $fraction, string $whole): void
    {
        $this->app['config']->set('database.connections.clickhouse', ['driver' => 'clickhouse', 'datetime_precision' => $precision]);

        $model = AttributesCastModel::make([
            'c_datetime' => Carbon::parse('2024-01-02 03:04:05.123456', 'Europe/Istanbul'),
            'c_immutable_datetime' => Carbon::parse('2024-01-02 03:04:05', 'UTC'),
            'c_date' => Carbon::parse('2024-01-02 03:04:05.5', 'UTC'),
            'c_datetime_fmt' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'),
        ]);

        $this->assertSame($fraction, $model->getAttributes()['c_datetime'], 'In the date\'s own time zone.');
        $this->assertSame($whole, $model->getAttributes()['c_immutable_datetime']);
        $this->assertSame(str_replace('.123456', '.500000', $fraction), $model->getAttributes()['c_date']);
        $this->assertInstanceOf(Carbon::class, $model->getAttributes()['c_datetime_fmt'], 'A datetime:<format> cast keeps the date, which the insert writes.');
        $this->assertInstanceOf(Carbon::class, $model->c_datetime);
        $this->assertSame('Y-m-d H:i:s', $model->getDateFormat());
        $this->assertSame([], $this->app['db']->getConnections(), 'The connection is not made.');
    }

    /**
     * A connection that the database manager has made decides, rather than the config, which can have changed since.
     */
    public function testAMadeConnectionDecidesTheDateCastPrecision(): void
    {
        $this->app['config']->set('database.connections.clickhouse', ['driver' => 'clickhouse', 'datetime_precision' => 'second']);
        $this->makeConnection('clickhouse', $this->recordingConnection(['datetime_precision' => 'microsecond']));

        $model = AttributesCastModel::make(['c_datetime' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC')]);

        $this->assertSame(['c_datetime' => '2024-01-02 03:04:05.123456'], $model->getAttributes());
    }

    /**
     * $dateFormat, or a getDateFormat() that gives another format, decides the format whatever the precision.
     */
    public function testDateFormatAndAnotherGetDateFormatWinOverThePrecision(): void
    {
        $this->app['config']->set('database.connections.clickhouse', ['driver' => 'clickhouse', 'datetime_precision' => 'microsecond']);
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');

        $this->assertSame(['c_datetime' => '2024-01-02 03:04:05'], AttributesCastWithDateFormatModel::make(['c_datetime' => $date])->getAttributes());
        $this->assertSame(['d' => '2024-01-02'], AttributesDateFormatMethodModel::make(['d' => $date])->getAttributes());
        $this->assertSame(['c_datetime' => '2024-01-02 03:04:05.000000'], (new AttributesCastModel())->setDateFormat('Y-m-d H:i:s.u')->fill(['c_datetime' => $date->copy()->startOfSecond()])->getAttributes());
    }

    /**
     * The model's own connection decides, also a connection other than the default.
     */
    public function testDateCastsReadTheModelsOwnConnection(): void
    {
        $this->app['config']->set('database.connections.clickhouse', ['driver' => 'clickhouse', 'datetime_precision' => 'second']);
        $this->app['config']->set('database.connections.other', ['driver' => 'clickhouse', 'datetime_precision' => 'microsecond']);

        $model = AttributesOtherConnectionCastModel::make(['d' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC')]);

        $this->assertSame(['d' => '2024-01-02 03:04:05.123456'], $model->getAttributes());
    }

    public function testUnknownCastThrowsInvalidCastException(): void
    {
        $this->expectException(InvalidCastException::class);
        $this->expectExceptionMessage('Call to undefined cast [no_such_cast] on column [c_unknown] in model [' . AttributesCastModel::class . '].');

        self::raw(AttributesCastModel::class, ['c_unknown' => 'abc'])->c_unknown;
    }

    public function testCastsApi(): void
    {
        $model = new AttributesCastModel();
        $this->assertSame(['c_int' => 'int', 'c_integer' => 'integer'], array_slice($model->getCasts(), 0, 2));
        $this->assertSame([true, true, false, false], [$model->hasCast('c_int'), $model->hasCast('c_int', 'int'), $model->hasCast('c_int', ['string']), $model->hasCast('zz')]);

        $plain = (new AttributesPlainModel())->mergeCasts(['x' => 'int', 'y' => [AsCollection::class, 'x']]);
        $plain->setRawAttributes(['x' => '5']);
        $this->assertSame(5, $plain->x);
        $this->assertSame(['x' => 'int', 'y' => AsCollection::class . ':x'], $plain->getCasts());

        $this->assertSame([], (new AttributesCastsMethodModel())->getCasts(), 'The casts() method is not read.');
        $this->assertSame('1', self::raw(AttributesCastsMethodModel::class, ['flag' => '1'])->flag);
    }

    public function testOriginalAndDirtyTracking(): void
    {
        $model = AttributesPlainModel::make(['a' => 1]);
        $this->assertSame([true, ['a' => 1], []], [$model->isDirty(), $model->getDirty(), $model->getOriginal()]);

        $model->syncOriginal();
        $this->assertFalse($model->isDirty());

        $model->a = 2;
        $this->assertSame([true, false, 1, 1, true], [$model->isDirty('a'), $model->isDirty('b'), $model->getOriginal('a'), $model->getRawOriginal('a'), $model->isClean('b')]);
        $this->assertSame([false, []], [$model->wasChanged(), $model->getChanges()]);
        $this->assertSame(['a' => 2], $model->syncChanges()->getChanges());
        $this->assertTrue($model->wasChanged('a'));
        $this->assertSame(['a' => 1], $model->getPrevious());
        $this->assertSame(['a' => 1], $model->discardChanges()->getAttributes());

        $cast = self::raw(AttributesCastModel::class, ['c_int' => '5'])->syncOriginal();
        $this->assertSame(5, $cast->getOriginal('c_int'));
        $this->assertSame([['a' => 1], ['b' => 2]], [AttributesPlainModel::make(['a' => 1, 'b' => 2])->only(['a']), AttributesPlainModel::make(['a' => 1, 'b' => 2])->except(['a'])]);
    }

    public function testMissingAttributesThrowOnlyWhenTheModelAsksForIt(): void
    {
        $model = new AttributesStrictModel();
        $model->exists = true;
        $model->wasRecentlyCreated = true;
        $this->assertNull($model->missing);

        Model::preventAccessingMissingAttributes(true);
        try {
            $plain = new AttributesPlainModel();
            $plain->exists = true;
            $this->assertNull($plain->missing, 'Model::preventAccessingMissingAttributes() does not apply.');
        } finally {
            Model::preventAccessingMissingAttributes(false);
        }

        $model->wasRecentlyCreated = false;
        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('The attribute [missing] either does not exist or was not retrieved for model [' . AttributesStrictModel::class . '].');
        $model->missing;
    }

    public function testSaveSendsTheCastAttributes(): void
    {
        $model = new AttributesCapturingModel();
        foreach (self::capturingRow() as $key => $value) {
            $model->$key = $value;
        }

        $this->assertTrue($model->save());
        $this->assertSame([true, false, true, []], [$model->exists, $model->wasRecentlyCreated, $model->isDirty(), $model->getOriginal()]);
        $this->assertSame([false, [], []], [$model->wasChanged(), $model->getChanges(), $model->getPrevious()], 'save() records no changes.');
        $this->assertSame(
            [['flag' => true, 'flag2' => true, 'd' => '2024-01-02 03:04:05', 'arr' => '{"a":1}', 'status' => 'active', 'ao' => '{"x":1}']],
            AttributesCapturingModel::$captured[0]['raw']
        );
        $this->assertSame(
            [['flag' => 1, 'flag2' => true, 'd' => '2024-01-02 03:04:05', 'arr' => '{"a":1}', 'status' => 'active', 'ao' => '{"x":1}']],
            AttributesCapturingModel::$captured[0]['prepared']
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Clickhouse does not allow update rows');
        $model->save();
    }

    public function testCreateSendsTheCastAttributes(): void
    {
        $model = AttributesCapturingModel::create(self::capturingRow());

        $this->assertSame([AttributesCapturingModel::class, true, true], [$model::class, $model->exists, $model->wasRecentlyCreated]);
        $this->assertSame([true, [], false, []], [$model->isDirty(), $model->getOriginal(), $model->wasChanged(), $model->getChanges()], 'create() syncs neither the original attributes nor the changes.');
        $this->assertSame(
            [['flag' => 1, 'flag2' => true, 'd' => '2024-01-02 03:04:05', 'arr' => '{"a":1}', 'status' => 'active', 'ao' => '{"x":1}']],
            AttributesCapturingModel::$captured[0]['prepared']
        );
    }

    public function testObjectChangedInPlaceReachesSave(): void
    {
        $model = AttributesCapturingModel::make(self::capturingRow());
        $model->ao['y'] = 2;
        $model->save();

        $this->assertSame('{"x":1,"y":2}', AttributesCapturingModel::$captured[0]['prepared'][0]['ao']);
    }

    /**
     * Rows whose keys differ from the first row's are left as they are, so the insert refuses them; a missing key
     * used to get its position in the first row as its value. A cast column that a row lacks stays out of the row.
     */
    public function testPreparedRowsGetNoValueTheCallerDidNotGive(): void
    {
        $prepared = fn (array $rows): array => (fn (): array => static::prepareAssocRowsForInsert($rows))
            ->bindTo(null, AttributesCapturingModel::class)();

        $this->assertSame([['f_int' => 30, 's' => 'a'], ['f_int' => 31]], $prepared([['f_int' => 30, 's' => 'a'], ['f_int' => 31]]));
        $this->assertSame([['flag' => 1, 'f_int' => 1], ['flag' => 0, 'f_int' => 2]], $prepared([['flag' => true, 'f_int' => 1], ['f_int' => 2, 'flag' => false]]));
        $this->assertSame([['f_int' => 1]], $prepared([['f_int' => 1]]), 'The flag cast skips a row without flag.');
        $this->assertSame([['flag' => 0]], $prepared([['flag' => null]]), 'An explicit null is still cast.');
    }

    // The inserts, optimize() and truncate() of a model, through a connection whose client records its requests.

    public function testValuesInsertsWriteFloatsNanAndDatesAsTheConnectionDoes(): void
    {
        $connection = $this->recordingConnection(['datetime_precision' => 'microsecond']);

        AttributesRecordingModel::insertAssoc([[
            'id' => 1,
            'f' => 1 / 3,
            'n' => NAN,
            'arr' => [0.1 + 0.2, INF, [-INF]],
            'd' => Carbon::parse('2024-01-02 03:04:05.123456', 'Europe/Istanbul'),
            'whole' => Carbon::parse('2024-01-02 03:04:05', 'UTC'),
            'flag' => true,
            'b' => false,
            's' => "it's",
            'nul' => null,
        ]]);

        $this->assertSame(
            ['INSERT INTO `recorded_rows` (`id`,`f`,`n`,`arr`,`d`,`whole`,`flag`,`b`,`s`,`nul`)  VALUES  (1,0.3333333333333333,nan,'
                . "[0.30000000000000004,inf,[-inf]],'2024-01-02 03:04:05.123456','2024-01-02 03:04:05',1,'false','it\\'s',NULL)"],
            $this->sentSql($connection)
        );
    }

    public function testValuesInsertsWriteDatesWithSecondsByDefault(): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingModel::insertBulk([[1, Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'), 2.5]], ['id', 'd', 'f']);

        $this->assertSame(["INSERT INTO `recorded_rows` (`id`,`d`,`f`)  VALUES  (1,'2024-01-02 03:04:05',2.5)"], $this->sentSql($connection));
    }

    /**
     * The JSONEachRow encoder and the Values insert take their dates from the same grammar of the connection, so they
     * write the same text at both precisions.
     */
    #[DataProvider('bothPrecisions')]
    public function testJsonAndValuesInsertsWriteTheSameDates(string $precision, string $expected): void
    {
        $connection = $this->recordingConnection(['datetime_precision' => $precision]);
        $row = ['id' => 1, 'd' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'), 'a' => [Carbon::parse('2024-01-02 03:04:06', 'UTC')]];

        AttributesRecordingModel::insertAssoc([$row]);
        AttributesRecordingJsonModel::insertAssoc([$row]);

        $this->assertSame(
            "INSERT INTO `recorded_rows` (`id`,`d`,`a`)  VALUES  (1,'{$expected}',['2024-01-02 03:04:06'])",
            $this->sentSql($connection)[0]
        );
        $this->assertSame(
            '{"id":1,"d":"' . $expected . '","a":["2024-01-02 03:04:06"]}',
            $connection->curler->requests[1]->getDetails()['parameters']
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bothPrecisions(): array
    {
        return [
            'second' => ['second', '2024-01-02 03:04:05'],
            'microsecond' => ['microsecond', '2024-01-02 03:04:05.123456'],
        ];
    }

    public function testJsonInsertsSendTheHeadInTheUrlAndOneObjectPerRow(): void
    {
        $connection = $this->recordingConnection();

        $statement = AttributesRecordingJsonModel::insertAssoc([
            ['id' => 1, 'flag' => true, 'm' => ['x' => 1], 'f' => NAN],
            ['f' => 0.1 + 0.2, 'm' => new stdClass(), 'flag' => false, 'id' => 2],
        ]);

        $request = $connection->curler->requests[0];
        $this->assertSame('INSERT INTO `recorded_rows` (`id`, `flag`, `m`, `f`) FORMAT JSONEachRow', $this->urlQuery($request)['query']);
        $this->assertSame(
            '{"id":1,"flag":1,"m":{"x":1},"f":"nan"}' . "\n" . '{"id":2,"flag":0,"m":{},"f":0.30000000000000004}',
            $request->getDetails()['parameters']
        );
        $this->assertSame('1', $statement->summary('written_rows'));
    }

    public function testInsertBulkSendsJsonCompactEachRowWithOrWithoutColumns(): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingJsonModel::insertBulk([[1, true], [2, false]], ['id', 'flag']);
        AttributesRecordingJsonModel::prepareAndInsert([[3, 'x']]);

        $this->assertSame('INSERT INTO `recorded_rows` (`id`, `flag`) FORMAT JSONCompactEachRow', $this->urlQuery($connection->curler->requests[0])['query']);
        $this->assertSame("[1,1]\n[2,0]", $connection->curler->requests[0]->getDetails()['parameters']);
        $this->assertSame('INSERT INTO `recorded_rows` FORMAT JSONCompactEachRow', $this->urlQuery($connection->curler->requests[1])['query']);
        $this->assertSame('[3,"x"]', $connection->curler->requests[1]->getDetails()['parameters']);
    }

    /**
     * @return array<string, array{mixed, mixed, bool}>
     */
    public static function insertFormats(): array
    {
        return [
            // key => [$insertFormat of the model, insert_format of the connection, whether JSONEachRow is sent]
            'both left out' => [null, null, false],
            'the connection' => [null, 'jsoneachrow', true],
            'a blank model format uses the connection' => [' ', 'JSONEachRow', true],
            'the model over the connection' => ['Values', 'JSONEachRow', false],
            'the model in any letter case, with spaces' => [' jsonEachRow ', null, true],
            'a Format instance' => [new Format(Format::JSON_EACH_ROW), 'Values', true],
        ];
    }

    #[DataProvider('insertFormats')]
    public function testTheInsertFormatComesFromTheModelOrItsConnection(mixed $modelFormat, mixed $connectionFormat, bool $json): void
    {
        $connection = $this->recordingConnection(['insert_format' => $connectionFormat]);
        AttributesRecordingFormatModel::$format = $modelFormat;

        AttributesRecordingFormatModel::insertAssoc([['id' => 1]]);

        $this->assertSame(
            $json ? 'INSERT INTO `recorded_rows` (`id`) FORMAT JSONEachRow' : 'INSERT INTO `recorded_rows` (`id`)  VALUES  (1)',
            $json ? $this->urlQuery($connection->curler->requests[0])['query'] : $this->sentSql($connection)[0]
        );
    }

    public function testAnInvalidModelInsertFormatThrowsBeforeAnythingIsSent(): void
    {
        $connection = $this->recordingConnection();
        AttributesRecordingFormatModel::$format = 'CSV';

        try {
            AttributesRecordingFormatModel::insertAssoc([['id' => 1]]);
            $this->fail('The insert did not throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                "The \$insertFormat of the model [" . AttributesRecordingFormatModel::class . "] must be 'Values' or 'JSONEachRow', [CSV] given.",
                $exception->getMessage()
            );
        }

        $this->assertSame([], $connection->curler->requests);
    }

    /**
     * @return array<string, array{class-string<BaseModel>, class-string<\Throwable>, string}>
     */
    public static function rowsWithOtherKeys(): array
    {
        return [
            'Values' => [AttributesRecordingModel::class, ClientQueryException::class, 'Fields not match: f_int and f_int,s on element 1'],
            'JSONEachRow' => [AttributesRecordingJsonModel::class, QueryException::class, 'Cannot insert the rows as JSONEachRow: the row at index 1 lacks the keys [s].'],
        ];
    }

    /**
     * @param class-string<BaseModel> $model
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('rowsWithOtherKeys')]
    public function testRowsWithOtherKeysAreRefusedBeforeAnythingIsSent(string $model, string $exception, string $message): void
    {
        $connection = $this->recordingConnection();

        try {
            $model::insertAssoc([['f_int' => 30, 's' => 'a'], ['f_int' => 31]]);
            $this->fail('The insert did not throw.');
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown);
            $this->assertStringStartsWith($message, $thrown->getMessage());
        }

        $this->assertSame([], $connection->curler->requests);
    }

    public function testACastColumnThatARowLacksIsLeftOutOfTheInsert(): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingModel::insertAssoc([['id' => 1]]);
        AttributesRecordingModel::create(['id' => 2]);
        AttributesRecordingModel::insertBulk([[3]], ['id', 'flag']);

        $this->assertSame(
            [
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (1)',
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (2)',
                'INSERT INTO `recorded_rows` (`id`,`flag`)  VALUES  (3)',
            ],
            $this->sentSql($connection)
        );
    }

    /**
     * Each insert path of the Values and the JSONEachRow model, given no rows.
     *
     * @return array<string, array{class-string<AttributesRecordingModel>, Closure(class-string<AttributesRecordingModel>): mixed}>
     */
    public static function insertsWithoutRows(): array
    {
        return self::inBothFormats([
            'insertAssoc()' => fn (string $model) => $model::insertAssoc([]),
            'prepareAndInsertAssoc()' => fn (string $model) => $model::prepareAndInsertAssoc([]),
            'insertBulk()' => fn (string $model) => $model::insertBulk([]),
            'insertBulk() with columns' => fn (string $model) => $model::insertBulk([], ['id']),
            'prepareAndInsertBulk()' => fn (string $model) => $model::prepareAndInsertBulk([], ['id']),
            'prepareAndInsert()' => fn (string $model) => $model::prepareAndInsert([]),
        ]);
    }

    /**
     * No rows are refused before anything is sent or logged, in both formats, with the exception that smi2's
     * Client::insert() throws. The Values format used to send INSERT INTO `t` VALUES () for keyed rows.
     *
     * @param class-string<AttributesRecordingModel> $model
     * @param Closure(class-string<AttributesRecordingModel>): mixed $insert
     */
    #[DataProvider('insertsWithoutRows')]
    public function testNoRowsAreRefusedBeforeAnythingIsSent(string $model, Closure $insert): void
    {
        $connection = $this->recordingConnection();

        $this->assertRefusedAsEmpty(fn () => $insert($model));
        $log = $connection->pretend(fn () => $this->assertRefusedAsEmpty(fn () => $insert($model)));

        $this->assertSame([], $log);
        $this->assertSame([], $connection->curler->requests);
    }

    /**
     * Each insert path of the Values and the JSONEachRow model, given a row without values.
     *
     * @return array<string, array{class-string<AttributesRecordingModel>, Closure(class-string<AttributesRecordingModel>): mixed}>
     */
    public static function insertsOfARowWithoutValues(): array
    {
        return self::inBothFormats([
            'insertAssoc()' => fn (string $model) => $model::insertAssoc([[]]),
            'insertAssoc() with a later empty row' => fn (string $model) => $model::insertAssoc([['id' => 1], []]),
            'create()' => fn (string $model) => $model::create([]),
            'save()' => fn (string $model) => (new $model())->save(),
            'insertBulk()' => fn (string $model) => $model::insertBulk([[]]),
            'insertBulk() with a later empty row' => fn (string $model) => $model::insertBulk([[1], []], ['id']),
            'buffer()' => fn (string $model) => $model::buffer([[]]),
            'buffer() with a later empty row' => fn (string $model) => $model::buffer([['id' => 1], []]),
        ]);
    }

    /**
     * A row without values is refused before anything is sent or buffered, in both formats. The Values format used
     * to send VALUES (), which ClickHouse refuses, or, for one numeric column, stores as 0 instead of the default;
     * the JSONEachRow format sent {}, a row of column defaults.
     *
     * @param class-string<AttributesRecordingModel> $model
     * @param Closure(class-string<AttributesRecordingModel>): mixed $insert
     */
    #[DataProvider('insertsOfARowWithoutValues')]
    public function testARowWithoutValuesIsRefusedBeforeAnythingIsSent(string $model, Closure $insert): void
    {
        $connection = $this->recordingConnection();

        $this->assertRefusedAsEmpty(fn () => $insert($model));

        $this->assertSame([], $connection->curler->requests);
        $this->assertSame(0, $model::bufferCount());
    }

    /**
     * buffer() refuses a row whose keys differ from those of the first row of the call, or from those of the rows
     * already buffered, before anything is buffered, so the rows buffered before and after the call are flushed.
     * The buffer used to take the rows, and every later flush of the model failed.
     */
    public function testBufferRefusesRowsWithOtherKeysAndKeepsTheBufferFlushable(): void
    {
        $connection = $this->recordingConnection();
        $refused = fn (int $index, string $keys, string $reference, string $referenceKeys): string => 'Cannot buffer the rows'
            . ' of the model [' . AttributesRecordingModel::class . "]: the row at index {$index} has the keys [{$keys}], and"
            . " {$reference} [{$referenceKeys}]. Every buffered row of a model must have the same keys, in any order,"
            . ' because the buffer is sent as one insert. Nothing was buffered.';

        $this->assertBufferRefused(
            fn () => AttributesRecordingModel::buffer([['id' => 1, 's' => 'a'], ['id' => 2]]),
            $refused(1, 'id', 'the first row', 'id, s')
        );
        $this->assertSame(0, AttributesRecordingModel::bufferCount());

        AttributesRecordingModel::buffer(['id' => 3, 's' => 'c']);
        $this->assertBufferRefused(
            fn () => AttributesRecordingModel::buffer([['s' => 'd', 'id' => 4], ['id' => 5, 'x' => 'e']]),
            $refused(1, 'id, x', 'the rows already buffered', 'id, s')
        );
        $this->assertBufferRefused(
            fn () => AttributesRecordingModel::buffer(['id' => 6]),
            $refused(0, 'id', 'the rows already buffered', 'id, s')
        );
        AttributesRecordingModel::buffer([['s' => 'f', 'id' => 7]]);

        $this->assertSame(2, AttributesRecordingModel::bufferCount());
        AttributesRecordingModel::flushBuffer();

        $this->assertSame(["INSERT INTO `recorded_rows` (`id`,`s`)  VALUES  (3,'c'),  (7,'f')"], $this->sentSql($connection));
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
    }

    /**
     * While the connection pretends, buffer() refuses the same rows, and logs nothing for them.
     */
    public function testBufferRefusesRowsWithOtherKeysWhilePretending(): void
    {
        $connection = $this->recordingConnection();
        $this->makeConnection('clickhouse', $connection);

        $log = $connection->pretend(function (): void {
            try {
                AttributesRecordingJsonModel::buffer([['id' => 1, 's' => 'a'], ['s' => 'b']]);
                $this->fail('buffer() did not throw.');
            } catch (QueryException $exception) {
                $this->assertStringStartsWith(
                    'Cannot buffer the rows of the model [' . AttributesRecordingJsonModel::class . ']: the row at index 1 has the keys [s], and the first row [id, s].',
                    $exception->getMessage()
                );
            }
        });

        $this->assertSame([], $log);
        $this->assertSame([], $connection->curler->requests);
    }

    /**
     * Values inserts write each finite float as the shortest text that reads back as the same float, as smi2
     * writes it while the ini setting precision is -1, and restore the setting afterwards, also when a value
     * cannot be written.
     */
    public function testValuesInsertsWriteEveryFloatDigitAndRestoreThePrecisionSetting(): void
    {
        $connection = $this->recordingConnection();
        $precision = ini_get('precision');

        AttributesRecordingModel::insertBulk(
            [[1, 2.0, 1e25, -0.0, PHP_FLOAT_MAX, [0.1, 2.0, [1 / 3]], 0.1 + 0.2, 7]],
            ['id', 'whole', 'big', 'zero', 'max', 'arr', 'sum', 'int']
        );

        $this->assertSame(
            ['INSERT INTO `recorded_rows` (`id`,`whole`,`big`,`zero`,`max`,`arr`,`sum`,`int`)  VALUES  '
                . '(1,2,1.0E+25,-0,1.7976931348623157E+308,[0.1,2,[0.3333333333333333]],0.30000000000000004,7)'],
            $this->sentSql($connection)
        );
        $this->assertSame($precision, ini_get('precision'));

        try {
            AttributesRecordingModel::insertAssoc([['f' => 0.5, 'o' => new stdClass()]]);
            $this->fail('The insert did not throw.');
        } catch (UnsupportedValueType) {
        }

        $this->assertSame($precision, ini_get('precision'));
        $this->assertCount(1, $connection->curler->requests);
    }

    /**
     * A row of a Values insert is copied only when it holds a value that smi2 would write differently: NaN, INF,
     * raw SQL, or, at microsecond precision, a date with a fraction. Finite floats, and dates at second precision,
     * are left to smi2, so a batch of floats or dates takes no more memory than the SQL it sends.
     */
    public function testValuesRowsAreOnlyCopiedForValuesThatSmi2WouldWriteDifferently(): void
    {
        $prepare = fn (Grammar $grammar, array $row): ?array => (fn (): ?array => self::prepareValuesForValuesInsert(
            $grammar,
            $grammar->getDateTimePrecision() !== DateTimePrecision::SECOND,
            $row
        ))->bindTo(null, ClientRequests::class)();
        $second = new Grammar();
        $microsecond = (new Grammar())->setDateTimePrecision(DateTimePrecision::MICROSECOND);
        $whole = Carbon::parse('2024-01-02 03:04:05', 'UTC');
        $fraction = Carbon::parse('2024-01-02 03:04:05.5', 'UTC');

        $this->assertNull($prepare($second, ['f' => 1 / 3, 'd' => $fraction, 'a' => [0.1, [$whole, $fraction]], 's' => 'x', 'n' => null, 'r' => new Raw('now()')]));
        $this->assertNull($prepare($microsecond, ['f' => 1 / 3, 'd' => $whole, 'a' => [[$whole]]]));
        $this->assertSame(
            ['d' => '2024-01-02 03:04:05.500000', 'a' => [0.1, [$whole, '2024-01-02 03:04:05.500000']]],
            $prepare($microsecond, ['d' => $fraction, 'a' => [0.1, [$whole, $fraction]]])
        );

        $prepared = $prepare($second, ['n' => NAN, 'a' => [1.5, [-INF]], 'e' => new Expression('now()'), 'i' => INF]);
        $this->assertSame(
            ['n' => 'nan', 'a' => [1.5, ['-inf']], 'e' => 'now()', 'i' => 'inf'],
            array_map(fn (mixed $value): mixed => $this->rawSql($value), $prepared)
        );
    }

    /**
     * Values inserts write raw SQL, this package's Expression or a Laravel database expression such as DB::raw(),
     * as the SQL it holds, as smi2's Raw is written, and a Stringable value object whose value property is not
     * public as its string. smi2 read that property and failed with 'Cannot access protected property'.
     */
    public function testValuesInsertsWriteRawSqlAndStringableValueObjects(): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingModel::insertAssoc([[
            'id' => 1,
            'e' => new Expression("concat('a', 'b')"),
            'l' => new LaravelExpression('now()'),
            'r' => new Raw('today()'),
            'a' => [new Expression('1 + 1'), 2],
            'v' => new AttributesPrivateValue("it's"),
            'str' => Str::of('x'),
        ]]);

        $this->assertSame(
            ["INSERT INTO `recorded_rows` (`id`,`e`,`l`,`r`,`a`,`v`,`str`)  VALUES  (1,concat('a', 'b'),now(),today(),[1 + 1,2],'it\\'s','x')"],
            $this->sentSql($connection)
        );
    }

    /**
     * BaseModel::flushAllBuffers() flushes each model through its own connection, and buffer() only skips the
     * buffer of a model whose own connection pretends.
     */
    public function testEachModelBuffersAndFlushesOnItsOwnConnection(): void
    {
        $connection = $this->recordingConnection();
        $other = $this->otherRecordingConnection();
        $this->makeConnection('clickhouse', $connection);
        $this->makeConnection('recorded_other', $other);

        $log = $connection->pretend(function (): void {
            AttributesRecordingModel::buffer(['id' => 1]);
            AttributesRecordingOtherConnectionModel::buffer(['id' => 2]);
        });
        AttributesRecordingModel::buffer(['id' => 3]);

        $this->assertSame([AttributesRecordingOtherConnectionModel::class, AttributesRecordingModel::class], array_values(array_filter(
            BufferedInsertRegistry::all(),
            fn (string $class): bool => str_starts_with($class, 'Tests\Unit\AttributesRecording')
        )));

        BaseModel::flushAllBuffers();

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1)'], array_column($log, 'query'));
        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (3)'], $this->sentSql($connection));
        $this->assertSame(['INSERT INTO `recorded_other_rows` (`id`)  VALUES  (2)'], $this->sentSql($other));
        $this->assertSame(0, AttributesRecordingModel::bufferCount() + AttributesRecordingOtherConnectionModel::bufferCount());
    }

    /**
     * While the connection pretends, each write is logged once with the SQL it would send, and nothing is sent.
     */
    public function testPretendedWritesAreLoggedAndNotSent(): void
    {
        $connection = $this->recordingConnection();
        $saved = null;

        $log = $connection->pretend(function () use (&$saved): void {
            AttributesRecordingModel::insertAssoc([['id' => 1, 's' => "it's", 'f' => 0.1 + 0.2]]);
            AttributesRecordingModel::insertBulk([[2, true]], ['id', 'flag']);
            AttributesRecordingModel::create(['id' => 3]);
            $model = new AttributesRecordingModel();
            $model->id = 4;
            $saved = $model->save();
            AttributesRecordingModel::buffer([['id' => 5], ['id' => 6]]);
            AttributesRecordingModel::flushBuffer();
            AttributesRecordingJsonModel::insertAssoc([['id' => 7]]);
            AttributesRecordingJsonModel::insertBulk([[8]], ['id']);
            AttributesRecordingModel::optimize(true, '2024');
            AttributesRecordingModel::truncate();
        });

        $this->assertSame(
            [
                "INSERT INTO `recorded_rows` (`id`,`s`,`f`)  VALUES  (1,'it\\'s',0.30000000000000004)",
                'INSERT INTO `recorded_rows` (`id`,`flag`)  VALUES  (2,1)',
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (3)',
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (4)',
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (5),  (6)',
                'INSERT INTO `recorded_rows` (`id`) FORMAT JSONEachRow',
                'INSERT INTO `recorded_rows` (`id`) FORMAT JSONCompactEachRow',
                "OPTIMIZE TABLE recorded_sources PARTITION '2024' FINAL",
                'TRUNCATE TABLE recorded_sources',
            ],
            array_column($log, 'query')
        );
        $this->assertSame([], $connection->curler->requests);
        $this->assertTrue($saved);
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
    }

    /**
     * The log of a pretended Values insert is the SQL that smi2's Client::insert() sends for the same rows.
     */
    public function testAPretendedValuesInsertLogsTheSqlThatIsSentOtherwise(): void
    {
        $connection = $this->recordingConnection();
        $rows = [['id' => 1, 'tick`name' => 'a', 'ends\\' => [1.5, NAN], 'n.a' => null], ['id' => 2, 'tick`name' => "b'", 'ends\\' => [], 'n.a' => true]];

        AttributesRecordingModel::insertAssoc($rows);
        $log = $connection->pretend(fn () => AttributesRecordingModel::insertAssoc($rows));

        $this->assertSame($this->sentSql($connection), array_column($log, 'query'));
    }

    /**
     * Rows buffered while the model's connection pretends are logged as an insert at once and never buffered, so that a
     * flush after pretend() ends, such as the automatic one, cannot send them.
     */
    public function testRowsBufferedWhileTheConnectionPretendsAreLoggedAndNotKept(): void
    {
        $connection = $this->recordingConnection();
        $this->makeConnection('clickhouse', $connection);

        $log = $connection->pretend(fn () => AttributesRecordingModel::buffer([['id' => 1], ['id' => 2]]));
        BaseModel::flushAllBuffers();

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)'], array_column($log, 'query'));
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
        $this->assertNotContains(AttributesRecordingModel::class, BufferedInsertRegistry::all());
        $this->assertSame([], $connection->curler->requests);
    }

    /**
     * Rows buffered before pretend() stay buffered when flushBuffer() or flushAllBuffers() runs while the model's
     * connection pretends: the flush logs the insert and sends nothing, so that the first flush after pretend() ends
     * sends them, once.
     */
    public function testAFlushWhileTheConnectionPretendsKeepsTheRowsBufferedBeforeIt(): void
    {
        $connection = $this->recordingConnection();
        $this->makeConnection('clickhouse', $connection);
        AttributesRecordingModel::buffer([['id' => 1], ['id' => 2]]);
        AttributesRecordingJsonModel::buffer(['id' => 3]);
        $flushed = null;

        $log = $connection->pretend(function () use (&$flushed): void {
            $flushed = AttributesRecordingModel::flushBuffer();
            BaseModel::flushAllBuffers();
        });

        $this->assertSame(
            [
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)',
                'INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)',
                'INSERT INTO `recorded_rows` (`id`) FORMAT JSONEachRow',
            ],
            array_column($log, 'query')
        );
        $this->assertInstanceOf(Statement::class, $flushed);
        $this->assertFalse($flushed->isError());
        $this->assertSame([], $connection->curler->requests, 'nothing is sent while pretending');
        $this->assertSame([['id' => 1], ['id' => 2]], AttributesRecordingModel::getBufferedRows());
        $this->assertSame([['id' => 3]], AttributesRecordingJsonModel::getBufferedRows());
        $this->assertContains(AttributesRecordingModel::class, BufferedInsertRegistry::all());
        $this->assertContains(AttributesRecordingJsonModel::class, BufferedInsertRegistry::all());

        BaseModel::flushAllBuffers();
        BaseModel::flushAllBuffers();

        $this->assertSame(
            ['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)', 'INSERT INTO `recorded_rows` (`id`) FORMAT JSONEachRow'],
            $this->sentSql($connection)
        );
        $this->assertSame(0, AttributesRecordingModel::bufferCount() + AttributesRecordingJsonModel::bufferCount());
        $this->assertNotContains(AttributesRecordingModel::class, BufferedInsertRegistry::all());
        $this->assertNotContains(AttributesRecordingJsonModel::class, BufferedInsertRegistry::all());
    }

    /**
     * A model that overrides resolveConnection() flushes by the connection that it returns, which its inserts go
     * through, and not by the connection that its $connection names. Here the recording connection is not registered
     * in the database manager, so the default connection, which the model names, is not made and cannot pretend: a
     * flush while the returned connection pretends keeps the rows. It used to log the insert and drop them.
     */
    public function testAFlushWhileTheResolvedConnectionPretendsKeepsTheRows(): void
    {
        $connection = $this->recordingConnection();
        AttributesRecordingModel::buffer([['id' => 1], ['id' => 2]]);

        $log = $connection->pretend(fn () => AttributesRecordingModel::flushBuffer());

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)'], array_column($log, 'query'));
        $this->assertSame([], $connection->curler->requests);
        $this->assertSame([['id' => 1], ['id' => 2]], AttributesRecordingModel::getBufferedRows());
        $this->assertContains(AttributesRecordingModel::class, BufferedInsertRegistry::all());

        AttributesRecordingModel::flushBuffer();
        AttributesRecordingModel::flushBuffer();

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)'], $this->sentSql($connection));
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
    }

    /**
     * While only the connection that the model's $connection names pretends, a flush sends the rows through the
     * connection that resolveConnection() returns, which does not pretend, and empties the buffer. The rows used to
     * stay buffered after they were sent, so the next flush sent them a second time.
     */
    public function testAFlushWhileOnlyTheNamedConnectionPretendsSendsTheRowsOnce(): void
    {
        $connection = $this->recordingConnection();
        $named = $this->newRecordingConnection(['name' => 'clickhouse']);
        $this->makeConnection('clickhouse', $named);
        AttributesRecordingModel::buffer([['id' => 1], ['id' => 2]]);

        $log = $named->pretend(fn () => AttributesRecordingModel::flushBuffer());
        AttributesRecordingModel::flushBuffer();

        $this->assertSame([], $log);
        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)'], $this->sentSql($connection));
        $this->assertSame([], $named->curler->requests);
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
    }

    /**
     * buffer() also asks the connection that resolveConnection() returns. Rows buffered while it pretends are logged
     * at once and never buffered, also when the connection that the model names is not made; they used to be buffered
     * and sent by the first flush after pretend() ended. Rows buffered while only the named connection pretends are
     * buffered and sent once by the next flush; they used to be sent at once.
     */
    public function testBufferAsksTheConnectionThatTheModelResolves(): void
    {
        $connection = $this->recordingConnection();

        $log = $connection->pretend(fn () => AttributesRecordingModel::buffer([['id' => 1], ['id' => 2]]));
        AttributesRecordingModel::flushBuffer();

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (1),  (2)'], array_column($log, 'query'));
        $this->assertSame([], $connection->curler->requests);
        $this->assertSame(0, AttributesRecordingModel::bufferCount());

        $named = $this->newRecordingConnection(['name' => 'clickhouse']);
        $this->makeConnection('clickhouse', $named);

        $log = $named->pretend(fn () => AttributesRecordingModel::buffer(['id' => 3]));

        $this->assertSame([], $log);
        $this->assertSame([], $connection->curler->requests);
        $this->assertSame([['id' => 3]], AttributesRecordingModel::getBufferedRows());

        AttributesRecordingModel::flushBuffer();

        $this->assertSame(['INSERT INTO `recorded_rows` (`id`)  VALUES  (3)'], $this->sentSql($connection));
        $this->assertSame([], $named->curler->requests);
        $this->assertSame(0, AttributesRecordingModel::bufferCount());
    }

    /**
     * @return array<string, array{bool, mixed, string}>
     */
    public static function optimizeCalls(): array
    {
        return [
            'no partition' => [false, null, 'OPTIMIZE TABLE recorded_sources'],
            'final' => [true, null, 'OPTIMIZE TABLE recorded_sources FINAL'],
            'a string, escaped' => [true, "x' FINAL; DROP TABLE t; --", "OPTIMIZE TABLE recorded_sources PARTITION 'x\\' FINAL; DROP TABLE t; --' FINAL"],
            'a backslash' => [false, 'a\\b', "OPTIMIZE TABLE recorded_sources PARTITION 'a\\\\b'"],
            'an int' => [false, 202401, 'OPTIMIZE TABLE recorded_sources PARTITION 202401'],
            'a numeric string' => [false, '202401', "OPTIMIZE TABLE recorded_sources PARTITION '202401'"],
            'an empty string, which used to optimize every partition' => [false, '', "OPTIMIZE TABLE recorded_sources PARTITION ''"],
            "'0', which used to optimize every partition" => [true, '0', "OPTIMIZE TABLE recorded_sources PARTITION '0' FINAL"],
            'an Expression' => [true, new Expression("ID '202401'"), "OPTIMIZE TABLE recorded_sources PARTITION ID '202401' FINAL"],
            'a Laravel expression' => [false, new LaravelExpression("tuple(2024, 'a')"), "OPTIMIZE TABLE recorded_sources PARTITION tuple(2024, 'a')"],
        ];
    }

    #[DataProvider('optimizeCalls')]
    public function testOptimizeWritesItsPartitionAsDeleteWritesInPartition(bool $final, mixed $partition, string $sql): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingModel::optimize($final, $partition);

        $this->assertSame([$sql], $this->sentSql($connection));
    }

    public function testTruncateEmptiesTheSourcesTable(): void
    {
        $connection = $this->recordingConnection();

        AttributesRecordingModel::truncate();

        $this->assertSame(['TRUNCATE TABLE recorded_sources'], $this->sentSql($connection));
    }

    /**
     * query() starts a query as where() does, without a condition, and every model query follows the options of
     * the model's connection.
     */
    public function testQueryAndTheOtherModelQueriesFollowTheConnection(): void
    {
        $this->recordingConnection(['datetime_precision' => 'microsecond']);
        $date = Carbon::parse('2024-01-02 03:04:05.123456', 'UTC');

        $this->assertSame('SELECT * FROM `recorded_rows`', AttributesRecordingModel::query()->toSql());
        $this->assertSame(
            "SELECT * FROM `recorded_rows` WHERE `d` = '2024-01-02 03:04:05.123456'",
            AttributesRecordingModel::query()->where('d', $date)->toSql()
        );
        $this->assertSame(AttributesRecordingModel::where('d', $date)->toSql(), AttributesRecordingModel::query()->where('d', $date)->toSql());
        $this->assertSame(
            "SELECT `id` FROM `recorded_rows` WHERE `d` IN ('2024-01-02 03:04:05.123456', '2024-01-02 03:04:06')",
            AttributesRecordingModel::select(['id'])->whereIn('d', [$date, $date->copy()->addSecond()->startOfSecond()])->toSql()
        );
    }

    /**
     * A connection without a server whose client records each request and answers it with an empty HTTP 200 that
     * names one written row. The recording models use it.
     *
     * @param array<string, mixed> $config
     * @return AttributesRecordingConnection
     */
    private function recordingConnection(array $config = []): AttributesRecordingConnection
    {
        $connection = $this->newRecordingConnection($config + ['name' => 'clickhouse']);
        AttributesRecordingModel::$recordingConnection = $connection;

        return $connection;
    }

    /**
     * A second recording connection, named recorded_other, which AttributesRecordingOtherConnectionModel uses.
     *
     * @return AttributesRecordingConnection
     */
    private function otherRecordingConnection(): AttributesRecordingConnection
    {
        $connection = $this->newRecordingConnection(['name' => 'recorded_other']);
        AttributesRecordingOtherConnectionModel::$otherConnection = $connection;

        return $connection;
    }

    /**
     * @param array<string, mixed> $config
     * @return AttributesRecordingConnection
     */
    private function newRecordingConnection(array $config): AttributesRecordingConnection
    {
        $client = new Client(['host' => '127.0.0.1', 'port' => '1', 'username' => 'default', 'password' => '']);
        $client->database('db');
        $curler = new AttributesRecordingCurler();
        $client->transport()->setDirtyCurler($curler);

        $connection = new AttributesRecordingConnection(null, 'db', '', $config);
        $connection->recordingClient = $client;
        $connection->curler = $curler;

        return $connection;
    }

    /**
     * Assert that an insert throws smi2's QueryException for an empty row list, and nothing else.
     *
     * @param Closure(): mixed $insert
     * @return void
     */
    private function assertRefusedAsEmpty(Closure $insert): void
    {
        try {
            $insert();
            $this->fail('The insert did not throw.');
        } catch (ClientQueryException $exception) {
            $this->assertSame(ClientQueryException::class, $exception::class);
            $this->assertSame('Inserting empty values array is not supported in ClickHouse', $exception->getMessage());
        }
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
     * @param mixed $value
     * @return mixed The SQL of a smi2 Raw expression, or the value itself
     */
    private function rawSql(mixed $value): mixed
    {
        if ($value instanceof Raw) {
            return $value->getValue();
        }

        return is_array($value) ? array_map(fn (mixed $element): mixed => $this->rawSql($element), $value) : $value;
    }

    /**
     * Pair each insert path with the Values and the JSONEachRow recording model.
     *
     * @param array<string, Closure(class-string<AttributesRecordingModel>): mixed> $paths
     * @return array<string, array{class-string<AttributesRecordingModel>, Closure(class-string<AttributesRecordingModel>): mixed}>
     */
    private static function inBothFormats(array $paths): array
    {
        $cases = [];
        foreach (['Values' => AttributesRecordingModel::class, 'JSONEachRow' => AttributesRecordingJsonModel::class] as $format => $model) {
            foreach ($paths as $name => $insert) {
                $cases["{$format}: {$name}"] = [$model, $insert];
            }
        }

        return $cases;
    }

    /**
     * Put a connection into the database manager as if it had made it, without pinging a node.
     *
     * @param string $name
     * @param Connection $connection
     * @return void
     */
    private function makeConnection(string $name, Connection $connection): void
    {
        (function () use ($name, $connection): void {
            $this->connections[$name] = $connection;
        })->call($this->app['db']);
    }

    /**
     * @param AttributesRecordingConnection $connection
     * @return list<string> The SQL of each request the connection's client sent
     */
    private function sentSql(AttributesRecordingConnection $connection): array
    {
        return array_map(
            fn (CurlerRequest $request): string => (string) $request->getRequestExtendedInfo('sql'),
            $connection->curler->requests
        );
    }

    /**
     * @param CurlerRequest $request
     * @return array<string, string>
     */
    private function urlQuery(CurlerRequest $request): array
    {
        parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Make a model with raw attributes, without casts or mutators.
     *
     * @template TModel of BaseModel
     * @param class-string<TModel> $class
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    private static function raw(string $class, array $attributes): BaseModel
    {
        return (new $class())->setRawAttributes($attributes);
    }

    /**
     * @return array<string, mixed>
     */
    private static function capturingRow(): array
    {
        return [
            'flag' => true,
            'flag2' => true,
            'd' => Carbon::parse('2024-01-02 03:04:05.123456', 'UTC'),
            'arr' => ['a' => 1],
            'status' => AttributesStatus::Active,
            'ao' => ['x' => 1],
        ];
    }
}

enum AttributesStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum AttributesSize
{
    case Small;
    case Large;
}

final class AttributesValueObject
{
    public function __construct(public string $value)
    {
    }
}

final class AttributesUpperCast implements CastsAttributes
{
    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : strtoupper((string) $value);
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : strtolower((string) $value);
    }
}

final class AttributesInboundCast implements CastsInboundAttributes
{
    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        return 'in:' . $value;
    }
}

final class AttributesValueObjectCast implements Castable
{
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes {
            public function get($model, string $key, mixed $value, array $attributes): mixed
            {
                return $value === null ? null : new AttributesValueObject((string) $value);
            }

            public function set($model, string $key, mixed $value, array $attributes): mixed
            {
                return $value instanceof AttributesValueObject ? $value->value : $value;
            }
        };
    }
}

final class AttributesFakeStatement extends Statement
{
    public function __construct()
    {
    }

    public function isError(): bool
    {
        return false;
    }
}

class AttributesPlainModel extends BaseModel
{
    public function helperMethod(): string
    {
        return 'helper';
    }
}

/**
 * Overrides the methods that BaseModel had only for Eloquent's HasAttributes.
 */
class AttributesOldStubsModel extends BaseModel
{
    protected $dateFormat = 'Y-m-d H:i:s';

    public function usesTimestamps(): bool
    {
        return true;
    }

    public function getCreatedAtColumn(): string
    {
        return 'created_at';
    }

    public function getUpdatedAtColumn(): string
    {
        return 'updated_at';
    }

    public function author(): string
    {
        return 'author';
    }

    public function getRelationValue($key)
    {
        return 'relation:' . $key;
    }

    public function relationLoaded($key)
    {
        return $key === 'cached';
    }

    public function relationResolver($class, $key)
    {
        return $key === 'virtual' ? fn () => 'virtual' : null;
    }
}

class AttributesMutatorModel extends BaseModel
{
    protected $appends = ['full'];

    public function getNameAttribute($value)
    {
        return strtoupper((string) $value);
    }

    public function setNameAttribute($value)
    {
        $this->attributes['name'] = trim((string) $value);
    }

    public function getFullAttribute()
    {
        return 'full:' . ($this->attributes['name'] ?? '');
    }

    protected function title(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => ucfirst((string) $value),
            set: fn ($value) => strtolower((string) $value),
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(get: fn ($value, array $attributes) => 'slug-' . ($attributes['title'] ?? ''));
    }

    protected function shout(): Attribute
    {
        return Attribute::make(set: fn ($value) => strtoupper((string) $value));
    }
}

class AttributesAppendsAttributeModel extends BaseModel
{
    protected $appends = ['slug'];

    protected function slug(): Attribute
    {
        return Attribute::make(get: fn ($value, array $attributes) => 'slug-' . ($attributes['title'] ?? ''));
    }
}

class AttributesAppendsStoredValueModel extends BaseModel
{
    protected $appends = ['full', 'slug'];

    public function getFullAttribute($value)
    {
        return 'full:' . ($value ?? 'null');
    }

    protected function slug(): Attribute
    {
        return Attribute::make(get: fn ($value) => 'slug:' . ($value ?? 'null'));
    }
}

class AttributesHidingModel extends BaseModel
{
    protected $hidden = ['secret'];
}

class AttributesVisibleModel extends BaseModel
{
    protected $visible = ['a'];
}

class AttributesCastModel extends BaseModel
{
    protected $casts = [
        'c_int' => 'int',
        'c_integer' => 'integer',
        'c_real' => 'real',
        'c_float' => 'float',
        'c_double' => 'double',
        'c_decimal' => 'decimal:2',
        'c_string' => 'string',
        'c_bool' => 'bool',
        'c_boolean' => 'boolean',
        'c_object' => 'object',
        'c_array' => 'array',
        'c_json' => 'json',
        'c_json_unicode' => 'json:unicode',
        'c_collection' => 'collection',
        'c_date' => 'date',
        'c_datetime' => 'datetime',
        'c_datetime_fmt' => 'datetime:Y-m-d',
        'c_immutable_datetime' => 'immutable_datetime',
        'c_timestamp' => 'timestamp',
        'c_hashed' => 'hashed',
        'c_encrypted' => 'encrypted',
        'c_encrypted_array' => 'encrypted:array',
        'c_backed' => AttributesStatus::class,
        'c_unit' => AttributesSize::class,
        'c_array_object' => AsArrayObject::class,
        'c_as_collection' => AsCollection::class,
        'c_stringable' => AsStringable::class,
        'c_custom' => AttributesUpperCast::class,
        'c_inbound' => AttributesInboundCast::class,
        'c_castable' => AttributesValueObjectCast::class,
        'c_unknown' => 'no_such_cast',
    ];
}

class AttributesCastWithDateFormatModel extends AttributesCastModel
{
    protected $dateFormat = 'Y-m-d H:i:s';
}

class AttributesCastsMethodModel extends BaseModel
{
    protected function casts(): array
    {
        return ['flag' => 'boolean'];
    }
}

class AttributesSerializeDateModel extends BaseModel
{
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $casts = ['d' => 'datetime'];

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('d/m/Y');
    }
}

class AttributesStrictModel extends BaseModel
{
    public static function preventsAccessingMissingAttributes()
    {
        return true;
    }
}

class AttributesCapturingModel extends BaseModel
{
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $casts = [
        'flag' => 'boolean',
        'flag2' => 'bool',
        'd' => 'datetime',
        'arr' => 'array',
        'status' => AttributesStatus::class,
        'ao' => AsArrayObject::class,
    ];

    /**
     * @var list<array{raw: array<int, array<string, mixed>>, prepared: array<int, array<string, mixed>>}>
     */
    public static array $captured = [];

    public static function insertAssoc(array $rows): Statement
    {
        static::$captured[] = ['raw' => $rows, 'prepared' => static::prepareAssocRowsForInsert($rows)];

        return new AttributesFakeStatement();
    }
}

/**
 * Overrides getDateFormat() to give another format, without setting $dateFormat.
 */
class AttributesDateFormatMethodModel extends BaseModel
{
    protected $casts = ['d' => 'datetime'];

    public function getDateFormat()
    {
        return 'Y-m-d';
    }
}

/**
 * A model of another connection, with a date and time cast.
 */
class AttributesOtherConnectionCastModel extends BaseModel
{
    protected $connection = 'other';

    protected $casts = ['d' => 'datetime'];
}

/**
 * A curler that records each request and answers it with an empty HTTP 200 that names one written row.
 */
final class AttributesRecordingCurler extends CurlerRolling
{
    /**
     * @var list<CurlerRequest>
     */
    public array $requests = [];

    public function __construct()
    {
    }

    public function execOne(CurlerRequest $request, bool $auto_close = false): int
    {
        $this->requests[] = $request;
        $response = new CurlerResponse();
        $response->_info = ['http_code' => 200, 'content_type' => 'text/plain; charset=UTF-8'];
        $response->_headers = ['X-ClickHouse-Summary' => '{"read_rows":"0","written_rows":"1"}'];
        $response->_body = '';
        $request->setResponse($response);

        return 200;
    }
}

/**
 * A connection without a server, whose active node is a client that records its requests.
 */
final class AttributesRecordingConnection extends Connection
{
    public Client $recordingClient;

    public AttributesRecordingCurler $curler;

    public function getClient(): Client
    {
        return $this->recordingClient;
    }
}

/**
 * A model that inserts through the recording connection, into recorded_rows, and writes its mutations, optimize()
 * and truncate() to recorded_sources.
 */
class AttributesRecordingModel extends BaseModel
{
    public static ?AttributesRecordingConnection $recordingConnection = null;

    protected $table = 'recorded_rows';

    protected $tableSources = 'recorded_sources';

    protected $casts = ['flag' => 'boolean'];

    public function getThisClient(): Client
    {
        return $this->resolveConnection()->getClient();
    }

    public function resolveConnection(): Connection
    {
        return static::$recordingConnection;
    }
}

/**
 * The recording model with JSONEachRow inserts.
 */
class AttributesRecordingJsonModel extends AttributesRecordingModel
{
    protected $insertFormat = 'JSONEachRow';
}

/**
 * The recording model with the insert format that a test sets.
 */
class AttributesRecordingFormatModel extends AttributesRecordingModel
{
    public static mixed $format = null;

    public function __construct()
    {
        $this->insertFormat = static::$format;
    }
}

/**
 * A model that inserts through the second recording connection, named recorded_other, into recorded_other_rows.
 */
class AttributesRecordingOtherConnectionModel extends BaseModel
{
    public static ?AttributesRecordingConnection $otherConnection = null;

    protected $connection = 'recorded_other';

    protected $table = 'recorded_other_rows';

    public function getThisClient(): Client
    {
        return $this->resolveConnection()->getClient();
    }

    public function resolveConnection(): Connection
    {
        return static::$otherConnection;
    }
}

/**
 * A value object whose string is its private $value, as many value objects are written.
 */
final class AttributesPrivateValue implements \Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
