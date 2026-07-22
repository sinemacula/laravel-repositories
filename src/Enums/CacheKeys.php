<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Enums;

use Illuminate\Support\Facades\Config;

/**
 * Define the keys used for the repository cache.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
enum CacheKeys: string
{
    // Store the cached collection data for a repository (reference mode)
    case REPOSITORY_CACHE = 'repository-cache:%s';

    // Store the cache metadata for a repository
    case REPOSITORY_CACHE_META = 'repository-cache-meta:%s';

    // Store a per-query cached result for a repository (table, query hash)
    case REPOSITORY_QUERY_CACHE = 'repository-query:%s:%s';

    // Store the generational version that scopes a repository table's per-query
    // keys
    case REPOSITORY_CACHE_VERSION = 'repository-cache-version:%s';

    /**
     * Resolves the cache key with the necessary prefix and replaces any
     * placeholders.
     *
     * @param  array<int, string>  $replacements
     * @return string
     */
    public function resolveKey(array $replacements = []): string
    {
        $prefix = Config::get('repositories.cache.prefix', 'repositories');
        $prefix = is_string($prefix) ? $prefix : 'repositories';

        $key = $prefix . ':' . $this->value;

        if (!empty($replacements)) {
            $key = vsprintf($key, $replacements);
        }

        return $key;
    }
}
