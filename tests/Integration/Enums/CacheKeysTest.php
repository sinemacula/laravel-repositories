<?php

declare(strict_types = 1);

namespace Tests\Integration\Enums;

use Illuminate\Support\Facades\Config;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SineMacula\Repositories\Enums\CacheKeys;

/**
 * Tests for the CacheKeys enum.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CacheKeys::class)]
final class CacheKeysTest extends TestCase
{
    /**
     * Provide all CacheKeys cases with their expected values.
     *
     * @return iterable<string, array{\SineMacula\Repositories\Enums\CacheKeys, string}>
     */
    public static function caseProvider(): iterable
    {
        yield 'REPOSITORY_CACHE' => [CacheKeys::REPOSITORY_CACHE, 'repository-cache:%s'];
        yield 'REPOSITORY_CACHE_META' => [CacheKeys::REPOSITORY_CACHE_META, 'repository-cache-meta:%s'];
        yield 'REPOSITORY_QUERY_CACHE' => [CacheKeys::REPOSITORY_QUERY_CACHE, 'repository-query:%s:%s'];
        yield 'REPOSITORY_CACHE_VERSION' => [CacheKeys::REPOSITORY_CACHE_VERSION, 'repository-cache-version:%s'];
    }

    /**
     * Test that resolveKey returns a prefixed key with no replacements.
     *
     * @param  \SineMacula\Repositories\Enums\CacheKeys  $case
     * @param  string  $expectedValue
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testResolveKeyWithEmptyReplacementsReturnsPrefixedKey(CacheKeys $case, string $expectedValue): void
    {
        Config::set('repositories.cache.prefix', 'test-prefix');

        $result = $case->resolveKey();

        self::assertStringStartsWith('test-prefix:', $result);
        self::assertSame('test-prefix:' . $expectedValue, $result);
    }

    /**
     * Test that resolveKey uses the default prefix when no config is set.
     *
     * @return void
     */
    public function testResolveKeyUsesDefaultPrefixWhenConfigMissing(): void
    {
        $result = CacheKeys::REPOSITORY_CACHE->resolveKey(['tags']);

        self::assertStringStartsWith('repositories:', $result);
        self::assertSame('repositories:repository-cache:tags', $result);
    }

    /**
     * Provide keys with placeholders, their replacements and resolved values.
     *
     * @return iterable<string, array{\SineMacula\Repositories\Enums\CacheKeys, array<int, string>, string}>
     */
    public static function replacementProvider(): iterable
    {
        yield 'single' => [CacheKeys::REPOSITORY_CACHE_META, ['tags'], 'app:repository-cache-meta:tags'];
        yield 'multiple' => [CacheKeys::REPOSITORY_QUERY_CACHE, ['tags', 'abc123'], 'app:repository-query:tags:abc123'];
    }

    /**
     * Test that resolveKey replaces each placeholder.
     *
     * @param  \SineMacula\Repositories\Enums\CacheKeys  $case
     * @param  array<int, string>  $replacements
     * @param  string  $expected
     * @return void
     */
    #[DataProvider('replacementProvider')]
    public function testResolveKeyReplacesPlaceholders(CacheKeys $case, array $replacements, string $expected): void
    {
        Config::set('repositories.cache.prefix', 'app');

        self::assertSame($expected, $case->resolveKey($replacements));
    }

    /**
     * Test that each case has the expected backing value.
     *
     * @param  \SineMacula\Repositories\Enums\CacheKeys  $case
     * @param  string  $expectedValue
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testCaseHasExpectedValue(CacheKeys $case, string $expectedValue): void
    {
        self::assertSame($expectedValue, $case->value);
    }

    /**
     * Test that the expected number of cases exist.
     *
     * @return void
     */
    public function testExpectedCaseCount(): void
    {
        self::assertCount(4, CacheKeys::cases());
    }
}
