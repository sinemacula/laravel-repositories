<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture tag model for repository cache behavior checks.
 *
 * @method static static create(array<string, string> $attributes = [])
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class Tag extends Model
{
    /** @var string|null The backing table for tags */
    protected $table = 'tags';

    /** @var list<string> The fillable attributes */
    protected $fillable = ['name'];

    /**
     * The tags this one is related to.
     *
     * Never queried: the fixtures exist so a fingerprint has real eager loads
     * to key on.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<self, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'tag_relations', 'tag_id', 'related_tag_id');
    }

    /**
     * The tags nested beneath this one.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The alternative names this tag answers to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\TagAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class, 'parent_id');
    }
}
