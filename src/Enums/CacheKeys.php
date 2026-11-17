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
    // A reference-mode snapshot
    case REPOSITORY_CACHE = 'repository-cache:%s';

    // A repository's cache status metadata
    case REPOSITORY_CACHE_META = 'repository-cache-meta:%s';

    // A per-query cached result, by table and query fingerprint
    case REPOSITORY_QUERY_CACHE = 'repository-query:%s:%s';

    // A table's generation token
    case REPOSITORY_CACHE_VERSION = 'repository-cache-version:%s';

    /**
     * Resolve the prefixed cache key with its placeholders replaced.
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
