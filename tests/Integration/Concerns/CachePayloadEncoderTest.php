<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\CachePayloadEncoder;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\Concerns\InteractsWithAuthors;
use Tests\Support\Enums\Status;
use Tests\Support\Models\Author;
use Tests\Support\Models\Book;

/**
 * Tests for converting repository read results into the stored payload.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachePayloadEncoder::class)]
final class CachePayloadEncoderTest extends IntegrationTestCase
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
     * Test that a payload holds nothing but arrays and scalars.
     *
     * @return void
     */
    public function testThePayloadHoldsNoObject(): void
    {
        $payload = CachePayloadEncoder::encode($this->authorGraph());

        self::assertNotNull($payload);
        self::assertSame($payload, unserialize(serialize($payload), ['allowed_classes' => false]));
    }

    /**
     * Test that scalars are stored as they are and values other than models are
     * stored by type.
     *
     * @return void
     */
    public function testStoresValuesOtherThanModelsByType(): void
    {
        $date = CarbonImmutable::parse('2026-03-09 12:34:56.789012', 'Europe/London');

        $payload = CachePayloadEncoder::encode([
            'scalars'    => [1, 1.5, 'one', false, null],
            'collection' => new Collection(['b' => 2, 'a' => 1]),
            'enum'       => Status::ACTIVE,
            'date'       => $date,
        ]);

        self::assertSame([
            'format' => CachePayloadEncoder::FORMAT,
            'value'  => [
                'type'  => 'array',
                'items' => [
                    'scalars'    => ['type' => 'array', 'items' => [1, 1.5, 'one', false, null]],
                    'collection' => ['type' => 'collection', 'class' => Collection::class, 'items' => ['b' => 2, 'a' => 1]],
                    'enum'       => ['type' => 'enum', 'class' => Status::class, 'case' => 'ACTIVE'],
                    'date'       => ['type' => 'date', 'class' => CarbonImmutable::class, 'time' => '2026-03-09 12:34:56.789012', 'timezone' => 'Europe/London'],
                ],
            ],
        ], $payload);
    }

    /**
     * Test that a model read straight from the database is stored with its raw
     * attributes and no state beyond its class defaults.
     *
     * @return void
     */
    public function testStoresAFreshlyReadModel(): void
    {
        $payload = CachePayloadEncoder::encode(Book::query()->findOrFail(3));

        self::assertSame([
            'format' => CachePayloadEncoder::FORMAT,
            'value'  => [
                'type'       => 'model',
                'id'         => 0,
                'class'      => Book::class,
                'connection' => 'testing',
                'exists'     => true,
                'attributes' => ['id' => 3, 'author_id' => 2, 'title' => 'Compilers'],
                'original'   => null,
                'state'      => [],
                'relations'  => [],
            ],
        ], $payload);
    }

    /**
     * Test that a model's original attributes are stored only when they differ
     * from its current ones.
     *
     * @return void
     */
    public function testStoresOriginalAttributesOnlyForAChangedModel(): void
    {
        $book = new Book(['title' => 'Draft']);

        $node = $this->modelNode($book);

        self::assertFalse($node['exists']);
        self::assertSame(['title' => 'Draft'], $node['attributes']);
        self::assertSame([], $node['original']);
    }

    /**
     * Test that only the state differing from a fresh instance is stored.
     *
     * @return void
     */
    public function testStoresOnlyStateThatDiffersFromAFreshInstance(): void
    {
        $author = Author::query()->findOrFail(1);

        $author->setHidden(['meta'])->setVisible(['id'])->setAppends([])->mergeCasts(['name' => 'string']);
        $author->setTable('writers');
        $author->timestamps          = true;
        $author->preventsLazyLoading = true;

        self::assertSame([
            'table'               => 'writers',
            'hidden'              => ['meta'],
            'visible'             => ['id'],
            'appends'             => [],
            'timestamps'          => true,
            'preventsLazyLoading' => true,
            'casts'               => ['name' => 'string'],
        ], $this->modelNode($author)['state']);
    }

    /**
     * Test that casts merged onto a model are stored without the casts its
     * class already declares.
     *
     * @return void
     */
    public function testStoresOnlyTheCastsMergedOntoAModel(): void
    {
        $author = Author::query()->findOrFail(1);

        $author->mergeCasts(['secret' => 'encrypted']);

        self::assertSame(['casts' => ['secret' => 'encrypted']], $this->modelNode($author)['state']);
    }

    /**
     * Test that a model reached a second time is stored as a reference to the
     * first.
     *
     * @return void
     */
    public function testStoresAModelReachedTwiceAsAReference(): void
    {
        $author = Author::query()->findOrFail(1);
        $book   = Book::query()->findOrFail(1);

        $book->setRelation('author', $author);

        $payload = CachePayloadEncoder::encode([$author, $book]);

        self::assertNotNull($payload);
        self::assertIsArray($payload['value']);

        /** @var array{items: array{0: array<string, mixed>, 1: array{id: int, relations: array<string, mixed>}}} $value */
        $value = $payload['value'];

        self::assertSame(0, $value['items'][0]['id']);
        self::assertSame(1, $value['items'][1]['id']);
        self::assertSame(['author' => ['type' => 'ref', 'id' => 0]], $value['items'][1]['relations']);
    }

    /**
     * Test that a pivot is stored with the relationship it belongs to.
     *
     * @return void
     */
    public function testStoresThePivotOfAManyToManyRelation(): void
    {
        $pivot = $this->authorGraph()->firstOrFail()->genres->firstOrFail()->getRelation('pivot');

        $pivotNode = $this->modelNode($pivot)['pivot'];

        self::assertIsArray($pivotNode);
        self::assertSame('author_id', $pivotNode['foreignKey']);
        self::assertSame('genre_id', $pivotNode['relatedKey']);
        self::assertNull($pivotNode['morphType']);
        self::assertNull($pivotNode['morphClass']);
        self::assertIsArray($pivotNode['parent']);
        self::assertSame(Author::class, $pivotNode['parent']['class']);
        self::assertIsArray($pivotNode['related']);
        self::assertSame('model', $pivotNode['related']['type']);
    }

    /**
     * Test that a polymorphic pivot is stored with its morph type and class.
     *
     * @return void
     */
    public function testStoresThePivotOfAPolymorphicRelation(): void
    {
        $pivot = $this->authorGraph()->get(1)?->labels->firstOrFail()->getRelation('pivot');

        $pivotNode = $this->modelNode($pivot)['pivot'];

        self::assertIsArray($pivotNode);
        self::assertSame('labelable_type', $pivotNode['morphType']);
        self::assertSame(Author::class, $pivotNode['morphClass']);
    }

    /**
     * Test that a model without a pivot is stored without a pivot section.
     *
     * @return void
     */
    public function testStoresNoPivotSectionForAModelThatIsNotAPivot(): void
    {
        self::assertArrayNotHasKey('pivot', $this->modelNode(Author::query()->findOrFail(1)));
    }

    /**
     * Test that a result holding an object that cannot be stored as arrays and
     * scalars is not encoded.
     *
     * @return void
     */
    public function testDoesNotEncodeAResultHoldingAnotherObject(): void
    {
        $book = Book::query()->findOrFail(1);

        $book->setRawAttributes(['id' => 1, 'title' => new \stdClass]);

        self::assertNull(CachePayloadEncoder::encode($book));
        self::assertNull(CachePayloadEncoder::encode(Author::query()->value('settings')));
        self::assertNull(CachePayloadEncoder::encode(new EloquentCollection([static fn (): int => 1])));
    }

    /**
     * Test that a model whose original attributes hold another object is not
     * encoded.
     *
     * @return void
     */
    public function testDoesNotEncodeAModelWhoseOriginalHoldsAnotherObject(): void
    {
        $book = Book::query()->findOrFail(1);

        $book->setRawAttributes(['id' => 1, 'title' => new \stdClass], true);
        $book->setRawAttributes(['id' => 1, 'title' => 'Notes']);

        self::assertNull(CachePayloadEncoder::encode($book));
    }

    /**
     * Test that the miss payload records that a read found nothing.
     *
     * @return void
     */
    public function testTheMissPayloadRecordsAMiss(): void
    {
        self::assertSame(['format' => CachePayloadEncoder::FORMAT, 'miss' => true], CachePayloadEncoder::miss());
    }

    /**
     * Encode a model and get its node.
     *
     * @param  mixed  $model
     * @return array<string, mixed>
     */
    private function modelNode(mixed $model): array
    {
        $payload = CachePayloadEncoder::encode($model);

        self::assertNotNull($payload);
        self::assertIsArray($payload['value']);

        /** @var array<string, mixed> */
        return $payload['value'];
    }
}
