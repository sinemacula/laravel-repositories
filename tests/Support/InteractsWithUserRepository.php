<?php

declare(strict_types = 1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Tests\Support\Repositories\TestUserRepository;

/**
 * Trait providing shared helpers for integration tests that use the test user
 * repository.
 *
 * Extracts common seeding and repository resolution logic shared across
 * multiple integration test classes.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait InteractsWithUserRepository
{
    /**
     * Seed baseline users for integration test scenarios.
     *
     * Seeds three users: Alice (active), Bob (inactive), Carol (active).
     *
     * @return void
     */
    private function seedUsers(): void
    {
        DB::table('test_users')->insert([
            ['id' => 1, 'name' => 'Alice', 'active' => true],
            ['id' => 2, 'name' => 'Bob', 'active' => false],
            ['id' => 3, 'name' => 'Carol', 'active' => true],
        ]);
    }

    /**
     * Resolve the repository under test.
     *
     * @return \Tests\Support\Repositories\TestUserRepository
     */
    private function repository(): TestUserRepository
    {
        static::assertNotNull($this->app);

        return $this->app->make(TestUserRepository::class);
    }
}
