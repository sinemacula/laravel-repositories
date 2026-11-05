<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Concerns\CacheMiss;
use SineMacula\Repositories\Concerns\CachePayloadDecoder;
use SineMacula\Repositories\Concerns\CachePayloadEncoder;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Collections\AuthorCollection;
use Tests\Support\Collections\KeyedCollection;
use Tests\Support\Concerns\InteractsWithAuthors;
use Tests\Support\Enums\Status;
use Tests\Support\Models\Author;
use Tests\Support\Models\Genre;
use Tests\Support\Models\Label;

/**
 * Tests for rebuilding repository read results from the stored payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachePayloadDecoder::class)]
final class CachePayloadDecoderTest extends IntegrationTestCase
{
    use InteractsWithAuthors;

    /**
     * Set up the test environment.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedAuthors();
    }

    /**
     * Test that an eager-loaded graph read back with every class refused equals
     * the graph the database returned.
     *
     * @return void
     */
    public function testRebuildsTheRelationGraphEqualToTheOriginal(): void
    {
        $graph   = $this->authorGraph();
        $decoded = $this->roundTrip($graph);

        self::assertInstanceOf(AuthorCollection::class, $decoded);
        self::assertEquals($graph, $decoded);
        self::assertSame($graph->toArray(), $decoded->toArray());
    }

    /**
     * Test that a model reached twice is rebuilt once, so a chaperoned relation
     * points back at the instance that holds it.
     *
     * @return void
     */
    public function testReferencesResolveToTheSameInstance(): void
    {
        $decoded = $this->decodedGraph();
        $author  = $decoded->firstOrFail();
        $book    = $author->books->firstOrFail();

        self::assertSame($author, $book->getRelation('author'));
    }

    /**
     * Test that a pivot is rebuilt with its keys, table, timestamps and the
     * models of the relationship it belongs to.
     *
     * @return void
     */
    public function testRebuildsThePivotOfAManyToManyRelation(): void
    {
        $genre = $this->decodedGraph()->firstOrFail()->genres->firstOrFail();
        $pivot = $genre->getRelation('pivot');

        self::assertInstanceOf(Pivot::class, $pivot);
        self::assertSame('author_id', $pivot->getForeignKey());
        self::assertSame('genre_id', $pivot->getRelatedKey());
        self::assertSame('author_genre', $pivot->getTable());
        self::assertTrue($pivot->timestamps);
        self::assertTrue($pivot->exists);
        self::assertInstanceOf(Author::class, $pivot->pivotParent);
        self::assertInstanceOf(Genre::class, $pivot->pivotRelated);
        self::assertSame(1, $pivot->getAttribute('position'));
    }

    /**
     * Test that a polymorphic pivot is rebuilt with its morph type and class.
     *
     * @return void
     */
    public function testRebuildsThePivotOfAPolymorphicRelation(): void
    {
        $graph   = $this->authorGraph();
        $decoded = $this->roundTrip($graph);

        self::assertInstanceOf(AuthorCollection::class, $decoded);

        $original = $graph->get(1)?->labels->firstOrFail()->getRelation('pivot');
        $rebuilt  = $decoded->get(1)?->labels->firstOrFail()->getRelation('pivot');

        self::assertInstanceOf(MorphPivot::class, $rebuilt);
        self::assertInstanceOf(Label::class, $rebuilt->pivotRelated);
        self::assertSame('labelable_type', $rebuilt->getMorphType());
        self::assertEquals($original, $rebuilt);
    }

    /**
     * Test that state a model carries beyond its class defaults is restored.
     *
     * @return void
     */
    public function testRestoresStateThatDiffersFromAFreshInstance(): void
    {
        $author = Author::query()->findOrFail(1);

        $author->makeVisible('secret')->makeHidden('meta')->setAppends([])->mergeCasts(['name' => 'string']);
        $author->setTable('writers');
        $author->setVisible(['id', 'name']);
        $author->preventsLazyLoading = true;
        $author->timestamps          = true;

        $decoded = $this->roundTrip($author);

        self::assertInstanceOf(Author::class, $decoded);
        self::assertSame(['id', 'name'], $decoded->getVisible());
        self::assertSame($author->getHidden(), $decoded->getHidden());
        self::assertSame([], $decoded->getAppends());
        self::assertSame('string', $decoded->getCasts()['name']);
        self::assertSame('writers', $decoded->getTable());
        self::assertTrue($decoded->preventsLazyLoading);
        self::assertTrue($decoded->timestamps);
        self::assertEquals($author, $decoded);
    }

    /**
     * Test that a model changed since it was read keeps both its changes and
     * its original attributes.
     *
     * @return void
     */
    public function testRestoresTheOriginalAttributesOfAChangedModel(): void
    {
        $author = Author::query()->findOrFail(1);

        $author->setAttribute('name', 'Augusta');

        $decoded = $this->roundTrip($author);

        self::assertInstanceOf(Author::class, $decoded);
        self::assertSame('Augusta', $decoded->getAttribute('name'));
        self::assertSame('Ada', $decoded->getOriginal('name'));
        self::assertTrue($decoded->isDirty('name'));
    }

    /**
     * Test that rebuilding a model fires no model event, as reading it back
     * from the cache is not a fresh retrieval.
     *
     * @return void
     */
    public function testRebuildingAModelFiresNoRetrievedEvent(): void
    {
        $payload = CachePayloadEncoder::encode(Author::query()->findOrFail(1));
        $fired   = 0;

        Author::retrieved(static function () use (&$fired): void {
            $fired++;
        });

        self::assertInstanceOf(Author::class, CachePayloadDecoder::decode($payload));
        self::assertSame(0, $fired);
    }

    /**
     * Test that a rebuilt model collection turns on relationship autoloading
     * when the application enables it, as a freshly read one does.
     *
     * @return void
     */
    public function testRebuiltCollectionsAutoloadRelationsWhenEnabled(): void
    {
        $payload = CachePayloadEncoder::encode($this->authorGraph());

        $disabled = CachePayloadDecoder::decode($payload);

        Model::automaticallyEagerLoadRelationships();

        try {
            $enabled = CachePayloadDecoder::decode($payload);
        } finally {
            Model::automaticallyEagerLoadRelationships(false);
        }

        self::assertInstanceOf(AuthorCollection::class, $disabled);
        self::assertInstanceOf(AuthorCollection::class, $enabled);
        self::assertFalse($disabled->firstOrFail()->hasRelationAutoloadCallback());
        self::assertTrue($enabled->firstOrFail()->hasRelationAutoloadCallback());
    }

    /**
     * Test that values other than models read back equal and of the same type.
     *
     * @return void
     */
    public function testRebuildsValuesOtherThanModels(): void
    {
        $value = [
            'scalars'    => [1, 1.5, 'one', true, null],
            'collection' => new Collection(['b' => Status::INACTIVE, 'a' => Status::ACTIVE]),
            'carbon'     => Carbon::parse('2026-03-09 12:34:56.789012', 'Europe/London'),
            'immutable'  => CarbonImmutable::parse('2026-07-01 08:00:00', '+02:00'),
            'native'     => new \DateTimeImmutable('2026-01-01 00:00:00.5', new \DateTimeZone('UTC')),
            'models'     => new EloquentCollection,
        ];

        $decoded = $this->roundTrip($value);

        self::assertEquals($value, $decoded);
        self::assertIsArray($decoded);
        self::assertInstanceOf(Collection::class, $decoded['collection']);
        self::assertInstanceOf(Carbon::class, $decoded['carbon']);
        self::assertSame(Status::INACTIVE, $decoded['collection']['b']);
        self::assertSame(['b', 'a'], $decoded['collection']->keys()->all());
        self::assertInstanceOf(CarbonImmutable::class, $decoded['immutable']);
        self::assertSame('+02:00', $decoded['immutable']->getTimezone()->getName());
        self::assertSame('2026-03-09 12:34:56.789012', $decoded['carbon']->format('Y-m-d H:i:s.u'));
    }

    /**
     * Test that a date reads back as the same instant even at a wall time the
     * timezone repeats, and before the epoch.
     *
     * @return void
     */
    public function testRebuildsADateAsTheSameInstant(): void
    {
        $repeated = Carbon::parse('2026-11-01T06:30:00.25Z')->setTimezone('America/New_York');
        $historic = new \DateTime('1815-12-10 08:30:00.5', new \DateTimeZone('Europe/London'));

        $decoded = $this->roundTrip([$repeated, $historic]);

        self::assertIsArray($decoded);
        self::assertInstanceOf(Carbon::class, $decoded[0]);
        self::assertInstanceOf(\DateTime::class, $decoded[1]);
        self::assertSame($repeated->format('Y-m-d H:i:s.u P e'), $decoded[0]->format('Y-m-d H:i:s.u P e'));
        self::assertSame($historic->format('Y-m-d H:i:s.u P e'), $decoded[1]->format('Y-m-d H:i:s.u P e'));
    }

    /**
     * Test that the instance state a model can carry beyond what the other
     * tests set is restored.
     *
     * @return void
     */
    public function testRestoresKeyPagingAndPersistenceState(): void
    {
        $author = Author::query()->findOrFail(1);

        $author->fillable(['name'])->guard(['secret'])->setDateFormat('U')->setKeyName('name')->setKeyType('string');
        $author->setIncrementing(false)->setPerPage(5)->setTouchedRelations(['books']);

        $decoded = $this->roundTrip($author);

        self::assertInstanceOf(Author::class, $decoded);
        self::assertSame(['name'], $decoded->getFillable());
        self::assertSame(['secret'], $decoded->getGuarded());
        self::assertSame('U', $decoded->getDateFormat());
        self::assertSame('name', $decoded->getKeyName());
        self::assertSame('string', $decoded->getKeyType());
        self::assertFalse($decoded->getIncrementing());
        self::assertSame(5, $decoded->getPerPage());
        self::assertSame(['books'], $decoded->getTouchedRelations());
        self::assertEquals($author, $decoded);
    }

    /**
     * Test that a stored value that contains itself, as a reference in the
     * serialized form can make it, is rejected rather than read without end.
     *
     * @return void
     */
    public function testRejectsAValueThatContainsItself(): void
    {
        $node = ['type' => 'array', 'items' => []];

        $node['items'][0] = &$node;

        $stored = unserialize(serialize(self::payload($node)), ['allowed_classes' => false]);

        self::assertNull(CachePayloadDecoder::decode($stored));
    }

    /**
     * Test that a value nested as deep as a payload may be written reads back.
     *
     * @return void
     */
    public function testDecodesAValueNestedToTheDeepestLevel(): void
    {
        self::assertIsArray(CachePayloadDecoder::decode(self::payload(self::nested(CachePayloadEncoder::MAX_DEPTH))));
    }

    /**
     * Test that a payload recording a read found nothing decodes to the miss
     * marker.
     *
     * @return void
     */
    public function testDecodesAMissToTheMissMarker(): void
    {
        self::assertInstanceOf(CacheMiss::class, CachePayloadDecoder::decode(CachePayloadEncoder::miss()));
    }

    /**
     * Test that the readable nodes the rejection cases start from decode, so
     * each case is rejected for the one field it breaks.
     *
     * @return void
     */
    public function testDecodesTheNodesTheRejectionCasesStartFrom(): void
    {
        $morph = CachePayloadDecoder::decode(self::pivotPayload(MorphPivot::class, ['morphType' => 'type', 'morphClass' => Author::class]));

        self::assertInstanceOf(Genre::class, CachePayloadDecoder::decode(self::payload(self::modelNode())));
        self::assertInstanceOf(Pivot::class, CachePayloadDecoder::decode(self::pivotPayload(Pivot::class, [])));
        self::assertInstanceOf(MorphPivot::class, $morph);
        self::assertSame('type', $morph->getMorphType());
        self::assertInstanceOf(Pivot::class, CachePayloadDecoder::decode(self::pivotPayload(Pivot::class, ['morphType' => 'type', 'morphClass' => Author::class])));
        self::assertSame(1, CachePayloadDecoder::decode(self::payload(['type' => 'array', 'items' => [1]]))[0] ?? null);
    }

    /**
     * Provide payloads that do not read back whole.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function unreadablePayloads(): iterable
    {
        $model = self::modelNode();

        yield 'an incomplete object' => [unserialize(serialize(new EloquentCollection), ['allowed_classes' => false])];
        yield 'a scalar' => ['entry'];
        yield 'no format' => [['value' => 1]];
        yield 'another format' => [['format' => CachePayloadEncoder::FORMAT + 1, 'value' => 1]];
        yield 'a miss that is not true' => [['format' => CachePayloadEncoder::FORMAT, 'miss' => 1]];
        yield 'no value' => [['format' => CachePayloadEncoder::FORMAT]];
        yield 'an object value' => [self::payload(new \stdClass)];
        yield 'an untyped array' => [self::payload(['items' => []])];
        yield 'an unknown type' => [self::payload(['type' => 'resource'])];
        yield 'array items that are not an array' => [self::payload(['type' => 'array', 'items' => 'a'])];
        yield 'an array item that is unreadable' => [self::payload(['type' => 'array', 'items' => [['type' => 'resource']]])];
        yield 'a collection class that is not a collection' => [self::payload(['type' => 'collection', 'class' => \stdClass::class, 'items' => []])];
        yield 'a collection class that does not exist' => [self::payload(['type' => 'collection', 'class' => 'Missing\Collection', 'items' => []])];
        yield 'a collection class that is not a string' => [self::payload(['type' => 'collection', 'class' => 1, 'items' => []])];
        yield 'a collection without a class' => [self::payload(['type' => 'collection', 'items' => []])];
        yield 'a model class that is not a model' => [self::payload(['class' => Collection::class] + $model)];
        yield 'a model class that cannot be built' => [self::payload(['class' => Model::class] + $model)];
        yield 'a collection class that cannot be built without arguments' => [self::payload(['type' => 'collection', 'class' => KeyedCollection::class, 'items' => []])];
        yield 'a model without an id' => [self::payload(array_diff_key($model, ['id' => true]))];
        yield 'a model id that is not an integer' => [self::payload(['id' => '0'] + $model)];
        yield 'two models with one id' => [self::payload(['type' => 'array', 'items' => [$model, $model]])];
        yield 'an existence flag that is not a boolean' => [self::payload(['exists' => 1] + $model)];
        yield 'a connection that is not a string' => [self::payload(['connection' => 1] + $model)];
        yield 'attributes that are not an array' => [self::payload(['attributes' => 'a'] + $model)];
        yield 'an attribute that is not a scalar' => [self::payload(['attributes' => ['name' => ['a']]] + $model)];
        yield 'an attribute without a name' => [self::payload(['attributes' => ['a']] + $model)];
        yield 'original attributes that are unreadable' => [self::payload(['original' => ['name' => ['a']]] + $model)];
        yield 'a model without original attributes' => [self::payload(array_diff_key($model, ['original' => true]))];
        yield 'state that is not an array' => [self::payload(['state' => 'a'] + $model)];
        yield 'unknown state' => [self::payload(['state' => ['with' => []]] + $model)];
        yield 'state under a numeric key' => [self::payload(['state' => [[]]] + $model)];
        yield 'fillable attributes that are not strings' => [self::payload(['state' => ['fillable' => [1]]] + $model)];
        yield 'guarded attributes that are not strings' => [self::payload(['state' => ['guarded' => [1]]] + $model)];
        yield 'a date format that is not a string' => [self::payload(['state' => ['dateFormat' => 1]] + $model)];
        yield 'a key name that is not a string' => [self::payload(['state' => ['keyName' => 1]] + $model)];
        yield 'a key type that is not a string' => [self::payload(['state' => ['keyType' => 1]] + $model)];
        yield 'an incrementing flag that is not a boolean' => [self::payload(['state' => ['incrementing' => 1]] + $model)];
        yield 'a page size that is not an integer' => [self::payload(['state' => ['perPage' => '15']] + $model)];
        yield 'touched relations that are not strings' => [self::payload(['state' => ['touches' => [1]]] + $model)];
        yield 'a table that is not a string' => [self::payload(['state' => ['table' => 1]] + $model)];
        yield 'hidden attributes that are not strings' => [self::payload(['state' => ['hidden' => [1]]] + $model)];
        yield 'visible attributes that are not strings' => [self::payload(['state' => ['visible' => [1]]] + $model)];
        yield 'appended attributes that are not strings' => [self::payload(['state' => ['appends' => [1]]] + $model)];
        yield 'casts that are not strings' => [self::payload(['state' => ['casts' => ['name' => 1]]] + $model)];
        yield 'a timestamps flag that is not a boolean' => [self::payload(['state' => ['timestamps' => 1]] + $model)];
        yield 'a lazy loading flag that is not a boolean' => [self::payload(['state' => ['preventsLazyLoading' => 1]] + $model)];
        yield 'relations that are not an array' => [self::payload(['relations' => 'a'] + $model)];
        yield 'a relation that is unreadable' => [self::payload(['relations' => ['books' => ['type' => 'resource']]] + $model)];
        yield 'a pivot section on a model that is not a pivot' => [self::payload(['pivot' => self::pivotNode()] + $model)];
        yield 'a pivot without its pivot section' => [self::payload(['class' => Pivot::class] + $model)];
        yield 'a pivot section that is not an array' => [self::payload(['class' => Pivot::class, 'pivot' => 'a'] + $model)];
        yield 'a pivot parent that is not a model' => [self::pivotPayload(Pivot::class, ['parent' => 'a'])];
        yield 'a pivot without a parent' => [self::pivotPayload(Pivot::class, ['parent' => null])];
        yield 'a pivot related model that is not a model' => [self::pivotPayload(Pivot::class, ['related' => 'a'])];
        yield 'a pivot foreign key that is not a string' => [self::pivotPayload(Pivot::class, ['foreignKey' => 1])];
        yield 'a pivot related key that is not a string' => [self::pivotPayload(Pivot::class, ['relatedKey' => 1])];
        yield 'a pivot morph type that is not a string' => [self::pivotPayload(MorphPivot::class, ['morphType' => 1])];
        yield 'a pivot morph class that is not a string' => [self::pivotPayload(MorphPivot::class, ['morphType' => 'type', 'morphClass' => 1])];
        yield 'a pivot without a morph class' => [self::pivotPayload(MorphPivot::class, ['morphType' => 'type'], ['morphClass'])];
        yield 'a reference to no model' => [self::payload(['type' => 'ref', 'id' => 0])];
        yield 'a reference without an id' => [self::payload(['type' => 'ref'])];
        yield 'a reference id that is not an integer' => [self::payload(['type' => 'array', 'items' => [$model, ['type' => 'ref', 'id' => '0']]])];
        yield 'an enum class that is not an enum' => [self::payload(['type' => 'enum', 'class' => \stdClass::class, 'case' => 'ACTIVE'])];
        yield 'an enum case that no longer exists' => [self::payload(['type' => 'enum', 'class' => Status::class, 'case' => 'ARCHIVED'])];
        yield 'an enum case that is not a string' => [self::payload(['type' => 'enum', 'class' => Status::class, 'case' => 1])];
        yield 'a date class that is not a date' => [self::payload(['type' => 'date', 'class' => \stdClass::class, 'time' => '0 0', 'timezone' => 'UTC'])];
        yield 'a date class that is only an interface' => [self::payload(['type' => 'date', 'class' => \DateTimeInterface::class, 'time' => '0 0', 'timezone' => 'UTC'])];
        yield 'a date class that is not a string' => [self::payload(['type' => 'date', 'class' => 1, 'time' => '0 0', 'timezone' => 'UTC'])];
        yield 'a date time that cannot be read' => [self::payload(['type' => 'date', 'class' => \DateTimeImmutable::class, 'time' => 'not a time', 'timezone' => 'UTC'])];
        yield 'a date time that Carbon cannot read' => [self::payload(['type' => 'date', 'class' => Carbon::class, 'time' => 'not a time', 'timezone' => 'UTC'])];
        yield 'a date timezone that cannot be read' => [self::payload(['type' => 'date', 'class' => \DateTime::class, 'time' => '0 0', 'timezone' => 'Nowhere/Else'])];
        yield 'a date time that is not a string' => [self::payload(['type' => 'date', 'class' => \DateTime::class, 'time' => 1, 'timezone' => 'UTC'])];
        yield 'a value nested deeper than any payload is written' => [self::payload(self::nested(CachePayloadEncoder::MAX_DEPTH + 1))];
    }

    /**
     * Test that a payload that does not read back whole is rejected.
     *
     * @param  mixed  $payload
     * @return void
     */
    #[DataProvider('unreadablePayloads')]
    public function testRejectsAPayloadThatDoesNotReadBackWhole(mixed $payload): void
    {
        self::assertNull(CachePayloadDecoder::decode($payload));
    }

    /**
     * Wrap a node in a payload of the current format.
     *
     * @param  mixed  $node
     * @return array{format: int, value: mixed}
     */
    private static function payload(mixed $node): array
    {
        return ['format' => CachePayloadEncoder::FORMAT, 'value' => $node];
    }

    /**
     * Wrap a readable pivot node in a payload, overriding or dropping fields of
     * its pivot section.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Relations\Pivot>  $class
     * @param  array<string, mixed>  $pivot
     * @param  array<int, string>  $without
     * @return array{format: int, value: mixed}
     */
    private static function pivotPayload(string $class, array $pivot, array $without = []): array
    {
        $section = array_diff_key($pivot + self::pivotNode(), array_flip($without));

        return self::payload(['class' => $class, 'pivot' => $section] + self::modelNode());
    }

    /**
     * Get an array node nested the given number of levels deep.
     *
     * @param  int  $levels
     * @return array<string, mixed>
     */
    private static function nested(int $levels): array
    {
        $node = ['type' => 'array', 'items' => []];

        for ($level = 1; $level < $levels; $level++) {
            $node = ['type' => 'array', 'items' => [$node]];
        }

        return $node;
    }

    /**
     * Get a readable model node.
     *
     * @return array<string, mixed>
     */
    private static function modelNode(): array
    {
        return [
            'type'       => 'model',
            'id'         => 0,
            'class'      => Genre::class,
            'connection' => null,
            'exists'     => true,
            'attributes' => ['id' => 1, 'name' => 'Maths'],
            'original'   => null,
            'state'      => [],
            'relations'  => [],
        ];
    }

    /**
     * Get a readable pivot section.
     *
     * @return array<string, mixed>
     */
    private static function pivotNode(): array
    {
        return [
            'parent'     => ['id' => 1, 'class' => Author::class] + self::modelNode(),
            'related'    => null,
            'foreignKey' => 'author_id',
            'relatedKey' => 'genre_id',
            'morphType'  => null,
            'morphClass' => null,
        ];
    }

    /**
     * Encode a value, store it as a class-refusing store would and decode it.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function roundTrip(mixed $value): mixed
    {
        $payload = CachePayloadEncoder::encode($value);

        self::assertNotNull($payload);

        $stored = unserialize(serialize($payload), ['allowed_classes' => false]);

        self::assertSame($payload, $stored);

        return CachePayloadDecoder::decode($stored);
    }

    /**
     * Get the author graph after a round trip through the stored form.
     *
     * @return \Tests\Support\Collections\AuthorCollection
     */
    private function decodedGraph(): AuthorCollection
    {
        $decoded = $this->roundTrip($this->authorGraph());

        self::assertInstanceOf(AuthorCollection::class, $decoded);

        return $decoded;
    }
}
