<?php

declare(strict_types = 1);

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Concerns\CacheSizeGuard;
use SineMacula\Repositories\Concerns\CacheStoreOptions;

/**
 * Tests for the CacheStoreOptions value bundle.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheStoreOptions::class)]
final class CacheStoreOptionsTest extends TestCase
{
    /**
     * Test that the constructor assigns each option to its matching
     * readonly property, unchanged.
     *
     * @return void
     */
    public function testConstructorAssignsEachOptionToItsProperty(): void
    {
        $sizeGuard = new CacheSizeGuard(1000, 262144);

        $options = new CacheStoreOptions(3600, $sizeGuard, true, 10);

        self::assertSame(3600, $options->ttl);
        self::assertSame($sizeGuard, $options->sizeGuard);
        self::assertTrue($options->registryEnabled);
        self::assertSame(10, $options->negativeTtl);
    }

    /**
     * Test that a disabled registry is preserved as false rather than coerced,
     * pinning both boolean states through the constructor.
     *
     * @return void
     */
    public function testConstructorPreservesADisabledRegistryFlag(): void
    {
        $options = new CacheStoreOptions(60, new CacheSizeGuard(null, null), false, 5);

        self::assertFalse($options->registryEnabled);
    }
}
