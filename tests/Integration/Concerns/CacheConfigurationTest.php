<?php

declare(strict_types = 1);

namespace Tests\Integration\Concerns;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\Concerns\CacheConfiguration;
use Tests\Integration\IntegrationTestCase;

/**
 * Tests for the CacheConfiguration collaborator.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheConfiguration::class)]
final class CacheConfigurationTest extends IntegrationTestCase
{
    /**
     * Test that every overridable property takes precedence over conflicting
     * package configuration.
     *
     * @return void
     */
    public function testPropertyOverridesTakePrecedenceOverConfiguration(): void
    {
        Config::set('repositories.cache.store', 'secondary');
        Config::set('repositories.cache.ttl', 999);
        Config::set('repositories.cache.reference_ttl', 999);
        Config::set('repositories.cache.negative_ttl', 999);
        Config::set('repositories.cache.registry_enabled', true);

        $configuration = CacheConfiguration::resolveFor([
            'cacheStoreName'       => 'primary',
            'cacheKeyPrefix'       => 'custom-prefix',
            'cacheReferenceTable'  => true,
            'cacheTtl'             => 120,
            'cacheReferenceTtl'    => 240,
            'cacheNegativeTtl'     => 30,
            'cacheMaxRows'         => 50,
            'cacheMaxBytes'        => 2048,
            'cacheRegistryEnabled' => false,
        ], 'tags');

        self::assertSame('primary', $configuration->storeName);
        self::assertSame('custom-prefix', $configuration->prefix);
        self::assertTrue($configuration->referenceMode);
        self::assertSame(240, $configuration->referenceTtl);
        self::assertSame(120, $configuration->storeOptions->ttl);
        self::assertSame(30, $configuration->storeOptions->negativeTtl);
        self::assertFalse($configuration->storeOptions->registryEnabled);
        self::assertTrue($configuration->storeOptions->sizeGuard->allows(['a'], 50));
        self::assertFalse($configuration->storeOptions->sizeGuard->allows(['a'], 51));
    }

    /**
     * Test that package configuration is used for every value the repository
     * does not override.
     *
     * @return void
     */
    public function testConfigurationIsUsedWhenPropertiesAreAbsent(): void
    {
        Config::set('repositories.cache.store', 'secondary');
        Config::set('repositories.cache.ttl', '120');
        Config::set('repositories.cache.reference_ttl', '240');
        Config::set('repositories.cache.max_rows', '50');
        Config::set('repositories.cache.max_bytes', '2048');

        $configuration = CacheConfiguration::resolveFor([], 'tags');

        self::assertSame('secondary', $configuration->storeName);
        self::assertSame('tags', $configuration->prefix);
        self::assertFalse($configuration->referenceMode);
        self::assertSame(240, $configuration->referenceTtl);
        self::assertSame(120, $configuration->storeOptions->ttl);
        self::assertTrue($configuration->storeOptions->sizeGuard->allows(['a'], 50));
        self::assertFalse($configuration->storeOptions->sizeGuard->allows(['a'], 51));
    }

    /**
     * Test that a non-numeric configuration value falls back to the packaged
     * default rather than propagating an invalid type.
     *
     * @return void
     */
    public function testNonNumericConfigurationFallsBackToPackagedDefaults(): void
    {
        Config::set('repositories.cache.ttl', 'not-numeric');
        Config::set('repositories.cache.reference_ttl', 'not-numeric');
        Config::set('repositories.cache.negative_ttl', 'not-numeric');

        $configuration = CacheConfiguration::resolveFor([], 'tags');

        self::assertSame(3600, $configuration->storeOptions->ttl);
        self::assertSame(3600, $configuration->referenceTtl);
        self::assertSame(10, $configuration->storeOptions->negativeTtl);
    }

    /**
     * Test that every tuning value falls back to its packaged default when the
     * configuration keys are absent entirely.
     *
     * @return void
     */
    public function testAbsentConfigurationFallsBackToPackagedDefaults(): void
    {
        Config::set('repositories.cache', []);

        $configuration = CacheConfiguration::resolveFor([], 'tags');

        self::assertSame(3600, $configuration->storeOptions->ttl);
        self::assertSame(3600, $configuration->referenceTtl);
        self::assertSame(10, $configuration->storeOptions->negativeTtl);
        self::assertTrue($configuration->storeOptions->registryEnabled);
        self::assertTrue($configuration->storeOptions->sizeGuard->allows(['a'], 1000));
        self::assertFalse($configuration->storeOptions->sizeGuard->allows(['a'], 1001));
    }

    /**
     * Test that a numeric string configuration value is cast to an integer.
     *
     * @return void
     */
    public function testNumericStringConfigurationIsCastToInteger(): void
    {
        Config::set('repositories.cache.ttl', '120');
        Config::set('repositories.cache.reference_ttl', '240');
        Config::set('repositories.cache.max_rows', '50');
        Config::set('repositories.cache.max_bytes', '2048');

        $configuration = CacheConfiguration::resolveFor([], 'tags');

        self::assertSame(120, $configuration->storeOptions->ttl);
        self::assertSame(240, $configuration->referenceTtl);
        self::assertTrue($configuration->storeOptions->sizeGuard->allows(['a'], 50));
        self::assertFalse($configuration->storeOptions->sizeGuard->allows(['a'], 51));
    }

    /**
     * Test that the store name falls back to the array driver when neither an
     * overridable property nor configuration resolves to a string.
     *
     * @return void
     */
    public function testStoreNameFallsBackToArrayDriverForNonStringConfiguration(): void
    {
        Config::set('repositories.cache.store', ['unexpected' => 'shape']);
        Config::set('cache.default', ['unexpected' => 'shape']);

        $configuration = CacheConfiguration::resolveFor([], 'tags');

        self::assertSame('array', $configuration->storeName);
    }
}
