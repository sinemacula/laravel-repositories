<?php

declare(strict_types = 1);

namespace Tests\Support\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Collections\AuthorCollection;
use Tests\Support\Models\Author;

/**
 * Trait providing the author fixture graph and a cache store that refuses every
 * class, for tests of what the repository cache stores.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait InteractsWithAuthors
{
    /**
     * Create the author tables and seed two authors with books, genres and
     * labels.
     *
     * @return void
     */
    private function seedAuthors(): void
    {
        Schema::create('authors', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('status');
            $table->dateTime('born_at');
            $table->text('meta');
            $table->text('settings');
            $table->string('secret');
        });

        Schema::create('books', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id');
            $table->string('title');
        });

        Schema::create('genres', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('author_genre', static function (Blueprint $table): void {
            $table->foreignId('author_id');
            $table->foreignId('genre_id');
            $table->integer('position');
            $table->timestamps();
        });

        Schema::create('labels', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('labelables', static function (Blueprint $table): void {
            $table->foreignId('label_id');
            $table->morphs('labelable');
        });

        DB::table('authors')->insert([
            ['id' => 1, 'name' => 'Ada', 'status' => 'active', 'born_at' => '1815-12-10 08:30:00', 'meta' => '{"rank":1}', 'settings' => '{"theme":"dark"}', 'secret' => 'a'],
            ['id' => 2, 'name' => 'Grace', 'status' => 'inactive', 'born_at' => '1906-12-09 17:45:00', 'meta' => '{"rank":2}', 'settings' => '{"theme":"light"}', 'secret' => 'g'],
        ]);

        DB::table('books')->insert([
            ['id' => 1, 'author_id' => 1, 'title' => 'Notes'],
            ['id' => 2, 'author_id' => 1, 'title' => 'Sketch'],
            ['id' => 3, 'author_id' => 2, 'title' => 'Compilers'],
        ]);

        DB::table('genres')->insert([['id' => 1, 'name' => 'Maths'], ['id' => 2, 'name' => 'Computing']]);

        DB::table('author_genre')->insert([
            ['author_id' => 1, 'genre_id' => 1, 'position' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['author_id' => 1, 'genre_id' => 2, 'position' => 2, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
            ['author_id' => 2, 'genre_id' => 2, 'position' => 1, 'created_at' => '2026-01-03 00:00:00', 'updated_at' => '2026-01-04 00:00:00'],
        ]);

        DB::table('labels')->insert([['id' => 1, 'name' => 'Pioneer']]);
        DB::table('labelables')->insert([['label_id' => 1, 'labelable_type' => Author::class, 'labelable_id' => 2]]);
    }

    /**
     * Load every author with its whole relation graph, straight from the
     * database.
     *
     * @return \Tests\Support\Collections\AuthorCollection
     */
    private function authorGraph(): AuthorCollection
    {
        return Author::query()->with(['books', 'genres', 'labels'])->orderBy('id')->get();
    }

    /**
     * Point the repository cache at a store that serializes every value and, as
     * Laravel's serializable_classes setting does by default, unserializes no
     * class.
     *
     * @return void
     */
    private function useClassRefusingCacheStore(): void
    {
        Config::set('cache.serializable_classes', false);
        Config::set('cache.stores.class-refusing', ['driver' => 'array', 'serialize' => true]);
        Config::set('repositories.cache.store', 'class-refusing');
    }
}
