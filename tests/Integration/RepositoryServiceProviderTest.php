<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use SineMacula\Repositories\RepositoryServiceProvider;

/**
 * Tests for the repository service provider.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(RepositoryServiceProvider::class)]
final class RepositoryServiceProviderTest extends IntegrationTestCase
{
    /**
     * Test that the package configuration is merged with its defaults.
     *
     * @return void
     */
    public function testPackageConfigurationIsMergedWithDefaults(): void
    {
        self::assertSame('repositories', Config::get('repositories.cache.prefix'));
        self::assertSame(3600, Config::get('repositories.cache.ttl'));
        self::assertNull(Config::get('repositories.cache.store'));
        self::assertSame(1000, Config::get('repositories.cache.max_rows'));
        self::assertSame(262144, Config::get('repositories.cache.max_bytes'));
        self::assertSame(3600, Config::get('repositories.cache.reference_ttl'));
        self::assertSame(10, Config::get('repositories.cache.negative_ttl'));
        self::assertTrue((bool) Config::get('repositories.cache.registry_enabled'));
    }

    /**
     * Test that the package configuration is published under the config tag.
     *
     * @return void
     */
    public function testPackageConfigurationIsPublishable(): void
    {
        $paths = ServiceProvider::pathsToPublish(RepositoryServiceProvider::class, 'config');

        self::assertCount(1, $paths);
        self::assertFileExists((string) array_key_first($paths));
        self::assertStringEndsWith('config/repositories.php', (string) array_key_first($paths));
        self::assertStringEndsWith('config/repositories.php', (string) reset($paths));
    }
}
