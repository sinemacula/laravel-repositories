# Laravel Repositories

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Build Status](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-repositories/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/quality-gates.yml)
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

To install the Laravel Repositories package, run the following command in your project directory:

```bash
composer require sinemacula/laravel-repositories
```

## Configuration

Publish the configuration file to customize the opt-in repository cache:

```bash
php artisan vendor:publish --provider="SineMacula\Repositories\RepositoryServiceProvider" --tag=config
```

Every option lives under `repositories.cache.*` and only affects repositories that opt into caching via the
`Cacheable` trait.

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

### Caching

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

#### Invalidation

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

#### Negative caching and the size guard

A read that returns nothing is cached as a miss marker under the shorter `negative_ttl` (10 seconds by default), so
repeated probes for a missing record do not hammer the database while a stale "not found" stays tightly bounded.
Results exceeding `max_rows` or `max_bytes` are still fetched and returned but never stored, preventing unbounded
cache growth.

#### Reference mode

For small, static tables read in full (countries, currencies, statuses), set `protected bool $cacheReferenceTable =
true` to opt into whole-table reference mode: the table is loaded once, cached as a single snapshot, memoised on the
repository instance, and indexed by primary key, so `get`, `all`, and `find` resolve without touching the database.
Other read verbs skip the cache entirely in this mode.

The snapshot always represents the unfiltered table, so reference reads only serve requests with no repository-level
composition pending: when criteria or scopes are active, `get`, `all`, and `find` execute a real (uncached) query so a
filtered read is never answered with the whole table. Eloquent global scopes (such as soft deletes) are part of the
snapshot query and always apply.

#### Cache configuration

Every option may be overridden per repository via a property:

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

#### Consistency notes

The per-query fingerprint folds in the connection name and database name, so multi-connection applications are
isolated automatically. Reference-mode snapshot keys, however, default to the table name alone: when two connections
expose the same table name (e.g. per-tenant databases), set a distinct `$cacheKeyPrefix` per repository so the
snapshots cannot collide.

Like any look-aside cache, a read that misses, queries the database, and stores its result can interleave with a
concurrent write on taggable stores and in reference mode: the freshly stored entry may predate the write, and is
served until the TTL expires or the next write flushes it. The staleness window is bounded and self-healing; on
non-taggable stores the generational version is captured before the database read, so a late store lands under the
old version and is never served.

#### Non-taggable store considerations

The generational version scheme depends on the version key outliving every entry it scopes, so a few things are
worth knowing before picking a non-taggable store:

- **Eviction risk.** Under memory pressure an LRU store (e.g. Redis or Memcached without tags) can evict the version
  key like any other entry, resetting the counter to zero and un-orphaning entries that were meant to stay
  invalidated. Use a non-evicting store (or a dedicated one) for the cache store when relying on this scheme.
- **Shared across connections.** The version key is scoped by table alone, not by connection: a write on one
  connection bumps the same counter another connection's per-query keys embed, so it invalidates that connection's
  cache too. This is a coherence trade-off rather than a correctness bug - orphaned entries still expire by TTL.
- **Database driver.** Each version bump is a single locking increment transaction against one row, plus an extra
  round trip the first time the key is seeded. Prefer a taggable store for tables with a high write volume.

### Testing Utilities

The package exports test utilities under the `SineMacula\Repositories\Testing` namespace for downstream packages that
extend `Repository` or implement `CriteriaInterface`. See [docs/testing.md](docs/testing.md) for the setup and the
documented testing patterns.

### Extension Points

Every protected member of the repository is classified as Stable, Transitional, or Internal, with stability
guarantees documented per member. The package adheres to [Semantic Versioning](https://semver.org/); changes to
stable extension points count as breaking. See [docs/extension-points.md](docs/extension-points.md) for the full
classification.

### Upgrading

See [UPGRADE.md](UPGRADE.md) for version-by-version migration guides, including breaking changes and the steps
required to move from 1.x to 2.x.

## Requirements

- PHP ^8.3
- Laravel 11+

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection mutation gate (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a list of notable changes, and [UPGRADE.md](UPGRADE.md) for version upgrade
guides.

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on branching, commits, code
quality, and pull requests.

## Security

If you discover a security vulnerability, please report it responsibly. See [SECURITY.md](SECURITY.md) for the
disclosure policy and contact details.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
