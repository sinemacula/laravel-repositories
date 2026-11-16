<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Scopes\TenantScope;

/**
 * Trait providing tenant-scoped, soft-deleting post fixtures and a way to
 * switch the current tenant, for tests of how global scopes reach the cache
 * key.
 *
 * Tenant one owns a live post, a trashed post and a comment on each tenant's
 * post; tenant two owns one live post.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait InteractsWithPosts
{
    /**
     * Create and seed the post, comment and tree node tables.
     *
     * @return void
     */
    private function seedPosts(): void
    {
        Schema::create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant');
            $table->string('title');
            $table->foreignId('book_id')->nullable();
            $table->nullableMorphs('subject');
            $table->softDeletes();
        });

        Schema::create('comments', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id');
            $table->foreignId('parent_id')->nullable();
            $table->string('tenant');
            $table->string('body');
        });

        Schema::create('tree_nodes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
        });

        DB::table('posts')->insert([
            ['id' => 1, 'tenant' => 'one', 'title' => 'live', 'deleted_at' => null, 'subject_type' => null, 'subject_id' => null],
            ['id' => 2, 'tenant' => 'one', 'title' => 'removed', 'deleted_at' => '2026-01-01 00:00:00', 'subject_type' => null, 'subject_id' => null],
            ['id' => 3, 'tenant' => 'two', 'title' => 'other', 'deleted_at' => null, 'subject_type' => null, 'subject_id' => null],
        ]);

        DB::table('comments')->insert([
            ['id' => 1, 'post_id' => 1, 'parent_id' => null, 'tenant' => 'one', 'body' => 'mine'],
            ['id' => 2, 'post_id' => 1, 'parent_id' => null, 'tenant' => 'two', 'body' => 'theirs'],
        ]);

        DB::table('tree_nodes')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'root'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'leaf'],
        ]);

        $this->actAsTenant('one');
    }

    /**
     * Make the given tenant the current one.
     *
     * @param  string  $tenant
     * @return void
     */
    private function actAsTenant(string $tenant): void
    {
        static::assertNotNull($this->app);

        $this->app->instance(TenantScope::BINDING, $tenant);
    }
}
