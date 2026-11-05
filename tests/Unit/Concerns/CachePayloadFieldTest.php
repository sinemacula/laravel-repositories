<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Concerns\CachePayloadField;

/**
 * Tests for reading the typed fields of a stored payload node.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CachePayloadField::class)]
final class CachePayloadFieldTest extends TestCase
{
    /** @var string The reason a class of the wrong type is rejected. */
    private const string WRONG_CLASS = 'A stored class is missing or of the wrong type';

    /** @var array<string, array<string, int|string|null>|bool|float|int|list<int|string>|string|null> A node holding a field of every type. */
    private const array NODE = [
        'scalar'     => 1.5,
        'class'      => Collection::class,
        'attributes' => ['id' => 1, 'name' => 'Ada', 'deleted_at' => null],
        'strings'    => ['a', 'b'],
        'empty'      => [],
        'string'     => 'text',
        'nothing'    => null,
        'flag'       => false,
        'count'      => 0,
    ];

    /**
     * Test that each field is read as the type it holds.
     *
     * @return void
     */
    public function testReadsEachFieldAsItsType(): void
    {
        self::assertSame(1.5, CachePayloadField::scalar(self::NODE['scalar']));
        self::assertNull(CachePayloadField::scalar(null));
        self::assertSame(Collection::class, CachePayloadField::classOf(self::NODE, Collection::class));
        self::assertSame(['id' => 1, 'name' => 'Ada', 'deleted_at' => null], CachePayloadField::attributes(self::NODE, 'attributes'));
        self::assertSame([], CachePayloadField::attributes(self::NODE, 'empty'));
        self::assertSame(['a', 'b'], CachePayloadField::strings(self::NODE, 'strings'));
        self::assertSame([], CachePayloadField::list(self::NODE, 'empty'));
        self::assertSame('text', CachePayloadField::string(self::NODE, 'string'));
        self::assertSame('text', CachePayloadField::nullableString(self::NODE, 'string'));
        self::assertNull(CachePayloadField::nullableString(self::NODE, 'nothing'));
        self::assertFalse(CachePayloadField::boolean(self::NODE, 'flag'));
        self::assertSame(0, CachePayloadField::integer(self::NODE, 'count'));
        self::assertNull(CachePayloadField::field(self::NODE, 'nothing'));
    }

    /**
     * Provide reads of a field that is missing or of another type, with the
     * reason each is rejected.
     *
     * @return iterable<string, array{\Closure(): (array<array-key, scalar|null>|scalar|null), string}>
     */
    public static function unreadableFields(): iterable
    {
        yield 'a missing field' => [static fn (): string => CachePayloadField::string(self::NODE, 'missing'), 'A stored value is missing a field'];
        yield 'an array that is a scalar' => [static fn (): array => CachePayloadField::list(self::NODE, 'string'), 'A stored value is not an array'];
        yield 'a string that is an integer' => [static fn (): string => CachePayloadField::string(self::NODE, 'count'), 'A stored value is not a string'];
        yield 'a nullable string that is an integer' => [static fn (): ?string => CachePayloadField::nullableString(self::NODE, 'count'), 'A stored value is not a string'];
        yield 'a boolean that is an integer' => [static fn (): bool => CachePayloadField::boolean(self::NODE, 'count'), 'A stored value is not a boolean'];
        yield 'an integer that is a boolean' => [static fn (): int => CachePayloadField::integer(self::NODE, 'flag'), 'A stored value is not an integer'];
        yield 'strings that hold another type' => [static fn (): array => CachePayloadField::strings(self::NODE, 'attributes'), 'A stored list holds a value other than a string'];
        yield 'unnamed attributes' => [static fn (): array => CachePayloadField::attributes(self::NODE, 'strings'), 'A stored attribute is not a named scalar'];
        yield 'attributes of arrays' => [static fn (): array => CachePayloadField::attributes(['a' => ['name' => ['a']]], 'a'), 'A stored attribute is not a named scalar'];
        yield 'a class of another type' => [static fn (): string => CachePayloadField::classOf(self::NODE, \DateTimeInterface::class), self::WRONG_CLASS];
        yield 'a class that is not a string' => [static fn (): string => CachePayloadField::classOf(['class' => 1], Collection::class), self::WRONG_CLASS];
        yield 'a scalar that is an array' => [static fn (): bool|float|int|string|null => CachePayloadField::scalar([]), 'A stored value is neither a scalar nor an array'];
    }

    /**
     * Test that a field missing or of another type is rejected.
     *
     * @param  \Closure(): (array<array-key, scalar|null>|scalar|null)  $read
     * @param  string  $message
     * @return void
     */
    #[DataProvider('unreadableFields')]
    public function testRejectsAFieldMissingOrOfAnotherType(\Closure $read, string $message): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $read();
    }
}
