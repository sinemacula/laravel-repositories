<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Concerns\CacheSizeGuard;

/**
 * Tests for the CacheSizeGuard collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheSizeGuard::class)]
final class CacheSizeGuardTest extends TestCase
{
    /**
     * Provide results the guard allows, with its row and byte ceilings.
     *
     * @return iterable<string, array{int|null, int|null, \Illuminate\Support\Collection<int, string>|string, int}>
     */
    public static function allowedResults(): iterable
    {
        yield 'within both ceilings' => [1000, 262144, collect(['a', 'b']), 2];
        yield 'no row ceiling' => [null, 262144, collect(['a', 'b', 'c']), 100000];
        yield 'no byte ceiling' => [1000, null, str_repeat('x', 100000), 1];
        yield 'rows at the ceiling' => [5, 262144, collect(['a']), 5];
        yield 'bytes at the ceiling' => [1000, strlen(serialize(collect(['a', 'b']))), collect(['a', 'b']), 2];
    }

    /**
     * Test that a result within the ceilings is allowed.
     *
     * @param  int|null  $maxRows
     * @param  int|null  $maxBytes
     * @param  \Illuminate\Support\Collection<int, string>|string  $result
     * @param  int  $rows
     * @return void
     */
    #[DataProvider('allowedResults')]
    public function testAllowsResultWithinCeilings(?int $maxRows, ?int $maxBytes, Collection|string $result, int $rows): void
    {
        self::assertTrue((new CacheSizeGuard($maxRows, $maxBytes))->allows($result, $rows));
    }

    /**
     * Provide results the guard rejects, with its row and byte ceilings.
     *
     * @return iterable<string, array{int, int, \Illuminate\Support\Collection<int, string>|string, int}>
     */
    public static function rejectedResults(): iterable
    {
        yield 'rows over the ceiling' => [2, 262144, collect(['a', 'b', 'c']), 3];
        yield 'bytes over the ceiling' => [1000, 8, str_repeat('x', 256), 1];
    }

    /**
     * Test that a result exceeding either ceiling is rejected.
     *
     * @param  int  $maxRows
     * @param  int  $maxBytes
     * @param  \Illuminate\Support\Collection<int, string>|string  $result
     * @param  int  $rows
     * @return void
     */
    #[DataProvider('rejectedResults')]
    public function testRejectsResultExceedingACeiling(int $maxRows, int $maxBytes, Collection|string $result, int $rows): void
    {
        self::assertFalse((new CacheSizeGuard($maxRows, $maxBytes))->allows($result, $rows));
    }
}
