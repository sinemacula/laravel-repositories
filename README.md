# Laravel Repositories

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Build Status](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)

This Laravel package provides a streamlined repository pattern layer for Eloquent models with criteria-driven query
composition. It keeps the parts of l5-repository that are most useful in modern Laravel applications while removing
unnecessary abstraction and maintenance overhead.

A big thanks to the creators of [andersao/l5-repository](https://github.com/andersao/l5-repository) for the original
foundation this package builds on.

## Features

- **Repository Base Class**: A container-resolved repository abstraction with explicit model validation, lifecycle reset
  behavior, and a `boot()` extension point for subclass initialization.
- **Criteria Lifecycle Controls**: Persistent and one-shot criteria pipelines with runtime enable, disable, skip, and
  reset controls.
- **Supplementary Capability Contracts**: Opt-in interfaces that criteria can implement to declare eager-loading
  (`DeclaresEagerLoading`), field selection (`DeclaresFieldSelection`), relationship counts
  (`DeclaresRelationshipCounts`), and metadata (`ContributesMetadata`) alongside query modification. The repository
  collects these declarations at criteria application time and exposes them via dedicated accessors.
- **Scoped Query Mutation**: Per-query scope registration for concise query customization without polluting models.
- **Model-Like Ergonomics**: Explicit query entrypoints (`query()` / `newQuery()`) plus magic forwarding for
  model-style usage such as `Repository::find($id)`.
- **Opt-In Per-Query Caching**: A transparent caching layer (`Cacheable`) that serves repeated reads from a
  per-query cache and invalidates per table on writes, with whole-table reference mode, negative caching, and a
  size guard. Repositories that never use the trait pay nothing.

## Installation

To install the Laravel API Repositories package, run the following command in your project directory:

```bash
composer require sinemacula/laravel-repositories
```

## Usage

```php
// Explicit query entrypoint
$users = $userRepository->query()->where('active', true)->get();

// Magic forwarding remains available for model-like usage
$user = UserRepository::find($id);
```

### Container Lifecycle

Repositories carry transient criteria and scope state while a query pipeline is being built. Register repositories as
transient or scoped bindings (`bind` or `scoped`) rather than `singleton` to avoid state leakage across requests.

## Caching

Caching is fully opt-in: add the `Cacheable` trait to a repository and its read verbs (`get`, `all`, `find`, `first`,
`firstWhere`, `firstOrFail`, `findOrFail`, `sole`, `value`, `pluck`) are served from a cache keyed by a fingerprint of
the executed query. A cache hit executes zero database queries. Because the fingerprint folds in the compiled SQL, the
bindings, the read verb, its arguments, and the registered eager loads, a filtered or by-id read never collides with
the full-table collection.

```php
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;

final class UserRepository extends Repository
{
    use Cacheable;

    public function model(): string
    {
        return User::class;
    }
}

$users = $repository->get();                 // First read: one query, result cached
$users = $repository->get();                 // Repeat read: zero queries
$fresh = $repository->withoutCache()->get(); // Bypass the cache for one read
$repository->flushCache();                   // Drop every cached entry for the table
$status = $repository->getCacheStatus();     // isPopulated() / getAge() / getLastInvalidatedAt()
```

### Invalidation

Write verbs forwarded through the repository (`create`, `update`, `delete`, `firstOrCreate`, `updateOrCreate`,
`upsert`, `increment`, `decrement`, `restore`, and friends) invalidate every cached entry for the repository's table
after the write executes. How the invalidation happens depends on the backing store:

- **Taggable stores** (e.g. Redis): every entry is tagged with its table, and a write flushes the tag.
- **Non-taggable stores** (e.g. file, database): every key embeds a generational table version, and a write bumps the
  version with a single atomic increment. Invalidation is O(1) and race-free; orphaned old-version entries simply
  expire by TTL. Setting `registry_enabled` to `false` disables the version bump, degrading invalidation to TTL
  expiry only.

A cache-store failure during the post-write flush is logged and swallowed: the write has already committed, so the
safe degraded state is stale-until-TTL rather than surfacing an error the caller could retry into a duplicate write.

Writes performed outside the repository (directly on the model, query builder, or database) are invisible to the
cache and are served stale until the TTL expires or `flushCache()` is called.

### Negative caching and the size guard

A read that returns nothing is cached as a miss marker under the shorter `negative_ttl` (10 seconds by default), so
repeated probes for a missing record do not hammer the database while a stale "not found" stays tightly bounded.
Results exceeding `max_rows` or `max_bytes` are still fetched and returned but never stored, preventing unbounded
cache growth.

### Reference mode

For small, static tables read in full (countries, currencies, statuses), set `protected bool $cacheReferenceTable =
true` to opt into whole-table reference mode: the table is loaded once, cached as a single snapshot, memoised on the
repository instance, and indexed by primary key, so `get`, `all`, and `find` resolve without touching the database.
Other read verbs skip the cache entirely in this mode.

### Configuration

Publish the config with `php artisan vendor:publish --provider="SineMacula\Repositories\RepositoryServiceProvider"
--tag=config`. Every option lives under `repositories.cache.*` and may be overridden per repository via a property:

| Config key         | Env variable                       | Property                | Default        |
|--------------------|------------------------------------|-------------------------|----------------|
| `prefix`           | `REPOSITORY_CACHE_PREFIX`          | `$cacheKeyPrefix`       | table name     |
| `store`            | `REPOSITORY_CACHE_STORE`           | `$cacheStoreName`       | app default    |
| `ttl`              | `REPOSITORY_CACHE_TTL`             | `$cacheTtl`             | `3600`         |
| `reference_ttl`    | `REPOSITORY_CACHE_REFERENCE_TTL`   | `$cacheReferenceTtl`    | `3600`         |
| `negative_ttl`     | `REPOSITORY_CACHE_NEGATIVE_TTL`    | `$cacheNegativeTtl`     | `10`           |
| `max_rows`         | `REPOSITORY_CACHE_MAX_ROWS`        | `$cacheMaxRows`         | `1000`         |
| `max_bytes`        | `REPOSITORY_CACHE_MAX_BYTES`       | `$cacheMaxBytes`        | `262144`       |
| `registry_enabled` | `REPOSITORY_CACHE_REGISTRY_ENABLED`| `$cacheRegistryEnabled` | `true`         |

Using a dedicated cache store (`REPOSITORY_CACHE_STORE`) is recommended so application-wide flushes of the default
store never evict repository caches, and vice versa.

## Testing

```bash
composer test
composer test-coverage
composer check
```

## Versioning Policy

This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**What constitutes a breaking change:**

- Adding, removing, or changing method signatures on any interface (`CriteriaInterface`, `RepositoryInterface`,
  `RepositoryCriteriaInterface`, `ContributesMetadata`, `DeclaresEagerLoading`, `DeclaresFieldSelection`,
  `DeclaresRelationshipCounts`)
- Changing the observable behavior of public methods in ways that violate documented contracts
- Changing protected members that are classified as [stable extension points](UPGRADING.md)

**Deprecation policy:**

- Features planned for removal will be marked with `@deprecated` annotations that include a description, the
  recommended replacement, and the version in which the feature will be removed.
- Deprecated features will survive at least one minor or major release cycle before removal.
- PHPStan (with `phpstan-deprecation-rules`) will surface deprecation warnings in your IDE and CI pipeline.

**What is NOT covered by stability guarantees:**

- Members marked `@internal` (including the `ManagesCriteria` trait)
- Protected properties and methods not classified as stable extension points
- Undocumented behavioral details (e.g., internal criteria application ordering)

See [CHANGELOG.md](CHANGELOG.md) for a history of changes, [UPGRADING.md](UPGRADING.md) for migration guidance
between major versions, and [EXTENSION-POINTS.md](EXTENSION-POINTS.md) for the extension point classification and
lifecycle documentation.

## Contributing

Contributions are welcome via GitHub pull requests.

## Security

If you discover a security issue, please contact Sine Macula directly rather than opening a public issue.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
