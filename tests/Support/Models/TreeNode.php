<?php

declare(strict_types = 1);

namespace Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fixture tree node model that always eager loads its children, so every node
 * loads the whole subtree beneath it.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class TreeNode extends Model
{
    /** @var bool Indicates whether the model tracks timestamps */
    public $timestamps = false;

    /** @var string|null The backing table for tree nodes */
    protected $table = 'tree_nodes';

    /** @var array<string> The guarded attributes */
    protected $guarded = [];

    /** @var list<string> The relations to eager load on every query */
    protected $with = ['children'];

    /**
     * Get the children of the node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Tests\Support\Models\TreeNode, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
