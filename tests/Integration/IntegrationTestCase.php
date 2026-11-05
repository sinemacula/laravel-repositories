<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use SineMacula\Repositories\RepositoryServiceProvider;

/**
 * Base class for integration tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var string|null The per-test isolated file-cache directory, if one was provisioned. */
    private ?string $fileCachePath = null;

    /** @var array<int, string> The cache keys written with a value that does not read back without its classes. */
    private array $unreadableCacheWrites = [];

    /**
     * Set up the integration schema.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    /**
     * Fail the test when it wrote a cache entry that would not read back with
     * every class refused, as Laravel's serializable_classes setting refuses
     * them by default.
     *
     * @return void
     */
    #[\Override]
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        if ($this->unreadableCacheWrites === []) {
            return;
        }

        static::fail('These cache entries do not read back with serializable classes disabled: ' . implode(', ', array_unique($this->unreadableCacheWrites)));
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        if ($this->fileCachePath !== null) {
            (new Filesystem)->deleteDirectory($this->fileCachePath);
        }

        parent::tearDown();
    }

    /**
     * Get the package providers.
     *
     * @param  mixed  $app
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [
            RepositoryServiceProvider::class,
        ];
    }

    /**
     * Configure the test environment.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        if (!$app instanceof Application) {
            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);

        // Isolate the file cache per test so parallel test runs (each mutant is
        // a separate process during mutation testing) never collide on a shared
        // cache directory, which otherwise makes TTL/expiry-sensitive cache
        // assertions flap and the mutation score non-deterministic.
        $this->fileCachePath = sys_get_temp_dir() . '/laravel-repositories-test-cache-' . getmypid() . '-' . uniqid('', true);
        $app['config']->set('cache.stores.file.path', $this->fileCachePath);

        $app['events']->listen(KeyWritten::class, $this->recordUnreadableCacheWrite(...));
    }

    /**
     * Record a cache write whose value does not read back unchanged once
     * serialized and unserialized with every class refused.
     *
     * The write is recorded before the value is serialized, so a value that
     * cannot be serialized at all stays recorded when serializing it throws.
     *
     * @param  \Illuminate\Cache\Events\KeyWritten  $event
     * @return void
     */
    private function recordUnreadableCacheWrite(KeyWritten $event): void
    {
        $this->unreadableCacheWrites[] = $event->key;

        if (unserialize(serialize($event->value), ['allowed_classes' => false]) !== $event->value) {
            return;
        }

        array_pop($this->unreadableCacheWrites);
    }

    /**
     * Create the database schema used by integration tests.
     *
     * @return void
     */
    private function createSchema(): void
    {
        Schema::dropIfExists('test_users');

        Schema::create('test_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });

        Schema::dropIfExists('tags');

        Schema::create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }
}
