<h1 align="center">Laravel Repositories</h1>

<div align="center">

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![PHP Version](https://img.shields.io/packagist/dependency-v/sinemacula/laravel-repositories/php.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Laravel Version](https://img.shields.io/packagist/dependency-v/sinemacula/laravel-repositories/laravel/framework.svg?label=laravel)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Build Status](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml)
[![Quality Gates](https://github.com/sinemacula/laravel-repositories/actions/workflows/quality-gates.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/quality-gates.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![License](https://img.shields.io/github/license/sinemacula/laravel-repositories.svg)](LICENSE)

</div>

A repository layer for Eloquent models with criteria-driven query composition and an opt-in per-query cache. It keeps
the parts of [andersao/l5-repository](https://github.com/andersao/l5-repository) that still earn their place in a modern
Laravel application and drops the rest.

Repositories are resolved from the container and expose `query()` / `newQuery()` plus model-style forwarding such as
`UserRepository::find($id)`. Criteria come in two lifetimes, persistent (`pushCriteria()`) and next-query-only
(`withCriteria()`), with `enableCriteria()`, `disableCriteria()`, `useCriteria()`, `skipCriteria()` and
`resetCriteria()` controlling which apply. A criterion can also implement `DeclaresEagerLoading`,
`DeclaresFieldSelection`, `DeclaresRelationshipCounts` or `ContributesMetadata`; the repository collects those
declarations as it applies criteria and exposes them through `getCollectedEagerLoads()`, `getCollectedFields()`,
`getCollectedCounts()` and `getCollectedMetadata()` on the handle the query ran through.

## Installation

Install the package with Composer:

```bash
composer require sinemacula/laravel-repositories
```

## Configuration

Publish the configuration file to tune the repository cache:

```bash
php artisan vendor:publish --provider="SineMacula\Repositories\RepositoryServiceProvider" --tag=config
```

Every option lives under `repositories.cache.*` and only affects repositories that opt into caching via the
`Cacheable` trait.

## Declaring the model

A repository names the model it targets, either with an attribute or by implementing `model()`:

```php
use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Repository;

/** @extends \SineMacula\Repositories\Repository<\App\Models\User> */
#[Model(User::class)]
final class UserRepository extends Repository {}
```

The attribute is looked for on the repository and then on each of its ancestors, so an abstract base can declare
the model a family of repositories shares. Implementing `model()` replaces the lookup, so the two can never
disagree, and a repository that does neither fails when it is constructed rather than when it is first queried.

Write the `@extends` annotation as well: it tells static analysis which model the repository returns, and the attribute,
read at runtime, cannot narrow the generic.

## Usage

```php
// Explicit query entrypoint
$users = $userRepository->query()->where('active', true)->get();

// Model-style forwarding
$user = UserRepository::find($id);
```

### Composition Returns a Copy

Composing a query never mutates the repository. `addScope()`, `withCriteria()`, `useCriteria()`, `skipCriteria()`,
`resetScopes()` and `withoutCache()` each return a copy carrying the composition, so the returned value is the only
thing that has it:

```php
$live = $credentials->scopeLive();       // $credentials is unchanged
$live->get();                            // the copy carries the scope
```

If a scope method throws after composing, the partial composition is garbage rather than state, so nothing reaches the
next query made through the repository. Discarding the return value discards the composition, so these methods are
marked pure and static analysis reports a discarded result as an error. A scope method of your own that forwards to
`addScope()` is only covered by that check if it is marked `@phpstan-pure` too.

Configuration does mutate. `pushScope()` and `pushCriteria()` register constraints that apply to every query for the
life of the instance, and are what `boot()` should use. `pushCriteria()` is public; `pushScope()` is protected, so only
the repository itself can register a scope.

### Container Lifecycle

A repository instance carries its registered configuration, so bind repositories as transient or scoped bindings
(`bind` or `scoped`) rather than `singleton` when that configuration is request-specific.

### Caching

Caching is opt-in: add the `Cacheable` trait to a repository and its read verbs (`get`, `all`, `find`, `first`,
`firstWhere`, `firstOrFail`, `findOrFail`, `sole`, `value`, `pluck`) are served from a cache keyed by a fingerprint of
the executed query. A cache hit executes zero database queries. The fingerprint is the SQL Eloquent runs: the root
query with its global scopes applied, plus each eager-loaded relation's query with its constraint run and the related
model's global scopes applied. A filtered or by-id read never collides with the full-table collection, and reads that
differ only by a global scope (`withTrashed()`, a tenant scope) or by what a constraint reads when it runs (such as the
authenticated user) are cached per value.

Global scopes and eager-load constraints therefore run once more per read, on cache hits too, and must be
deterministic. A read whose result is shaped outside SQL runs uncached: one eager loading a `MorphTo` relation or a
relation class other than the framework's own, one with an `afterQuery()` callback (other than the one `chaperone()`
registers), one with a callable `withDefault()`, or one whose eager loads nest more than 16 levels deep (a model whose
`$with` loads itself). Eager-loaded rows are cached with their parent and invalidated by writes to the parent's table
only.

```php
use SineMacula\Repositories\Attributes\Model;
use SineMacula\Repositories\Concerns\Cacheable;
use SineMacula\Repositories\Repository;

#[Model(User::class)]
final class UserRepository extends Repository
{
    use Cacheable;
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
- **Non-taggable stores** (e.g. file, database): every key embeds a generation token for the table, and a write
  replaces it with a fresh random one in a single write. Invalidation is O(1), and since a token never repeats,
  concurrent writes cannot make an orphaned entry reachable again; orphaned entries expire by TTL. Setting
  `registry_enabled` to `false` disables the replacement, leaving TTL expiry as the only invalidation.

A cache-store failure during the post-write flush is logged and swallowed: the write has already committed, so the
safe degraded state is stale-until-TTL rather than surfacing an error the caller could retry into a duplicate write.

Writes performed outside the repository (directly on the model, query builder, or database) are invisible to the
cache and are served stale until the TTL expires or `flushCache()` is called.

#### Negative caching and the size guard

A read that returns `null` is cached as a miss marker under the shorter `negative_ttl` (10 seconds by default), so
repeated probes for a missing record do not hammer the database while a stale "not found" stays tightly bounded.
Results exceeding `max_rows` or `max_bytes` are still fetched and returned but never stored. `max_bytes` is weighed
against the serialized payload the store holds. Set either to `null` to disable that bound.

#### What the cache stores

Every entry is stored as arrays and scalars, never as objects, so the cache works under Laravel's default
`cache.serializable_classes => false` without allowing any model or package class. A model is stored as its raw
attributes, its eager-loaded relations (pivots included), and any hidden, visible, appended, cast, or table state set
on the instance, and is rebuilt on read without firing model events. Collections keep their class and keys, and the
enum and date values `value` and `pluck` return keep their type. A result holding any other object, such as a value
cast to an `ArrayObject`, is still returned but never cached. An entry that does not read back in the expected shape,
such as one an earlier version stored as objects, is treated as a miss, then recomputed and overwritten.

#### Reference mode

For small, static tables read in full (countries, currencies, statuses), set `protected bool $cacheReferenceTable =
true` to opt into whole-table reference mode: the table is loaded once, cached as a single snapshot, memoised on a
collaborator every copy of the repository shares, and indexed by primary key, so `get`, `all`, and `find` resolve
without touching the database. A `get` or `find` that selects only some columns executes a real query, since the
snapshot holds every column. Other read verbs skip the cache entirely in this mode.

The snapshot always represents the unfiltered table, so reference reads only serve requests with no repository-level
composition pending: when criteria or scopes are active, `get`, `all`, and `find` execute a real (uncached) query so a
filtered read is never answered with the whole table. A scope registered with `pushScope()` counts as active for this
purpose, because the snapshot is built straight from the model and would otherwise answer a registered constraint with
unfiltered rows.

Eloquent global scopes (such as soft deletes or a tenant scope) are part of the snapshot query and always apply. Each
variant they produce is held as its own snapshot, keyed by the fingerprint of the snapshot query, and a write
invalidates every variant at once on any store, whatever `registry_enabled` is set to.

#### Cache configuration

Every option except `prefix` may be overridden per repository via a property:

| Config key         | Env variable                        | Property                | Default        |
|--------------------|-------------------------------------|-------------------------|----------------|
| `prefix`           | `REPOSITORY_CACHE_PREFIX`           | -                       | `repositories` |
| `store`            | `REPOSITORY_CACHE_STORE`            | `$cacheStoreName`       | app default    |
| `ttl`              | `REPOSITORY_CACHE_TTL`              | `$cacheTtl`             | `3600`         |
| `reference_ttl`    | `REPOSITORY_CACHE_REFERENCE_TTL`    | `$cacheReferenceTtl`    | `3600`         |
| `negative_ttl`     | `REPOSITORY_CACHE_NEGATIVE_TTL`     | `$cacheNegativeTtl`     | `10`           |
| `max_rows`         | `REPOSITORY_CACHE_MAX_ROWS`         | `$cacheMaxRows`         | `1000`         |
| `max_bytes`        | `REPOSITORY_CACHE_MAX_BYTES`        | `$cacheMaxBytes`        | `262144`       |
| `registry_enabled` | `REPOSITORY_CACHE_REGISTRY_ENABLED` | `$cacheRegistryEnabled` | `true`         |

`prefix` namespaces every key the package writes. Within it, a repository's entries are keyed and invalidated under
`$cacheKeyPrefix`, which defaults to the model's table name; two per-query repositories on the same store that share a
`$cacheKeyPrefix` share invalidation. `protected bool $cacheReferenceTable` (default `false`) switches a repository to
reference mode.

Using a dedicated cache store (`REPOSITORY_CACHE_STORE`) is recommended so application-wide flushes of the default
store never evict repository caches, and vice versa.

#### Consistency notes

The per-query fingerprint and the reference-mode snapshot key both fold in the connection name and database name, so
multi-connection applications are isolated automatically.

Like any look-aside cache, a read that misses, queries the database, and stores its result can interleave with a
concurrent write on taggable stores and in reference mode: the freshly stored entry may predate the write, and is served
until the TTL expires or the next write flushes it. The staleness window is bounded and self-healing. A reference-mode
repository also keeps serving the snapshot it memoised until it is discarded, whatever another instance writes. On
non-taggable stores a repository captures the generation token before its first database read and keeps it for its
lifetime, so a late store lands under the old token and is never served to a repository resolved after the write; a
repository that captured the token before the write keeps serving its own entries until it is discarded.

#### Non-taggable store considerations

- **Eviction.** A store that evicts under memory pressure can evict the token like any other entry. A missing token
  is replaced with a fresh one, so eviction costs a round of cache misses but never revives invalidated entries.
- **Shared across connections.** The token is scoped by `$cacheKeyPrefix` (the table name by default), not by
  connection: a write on one connection replaces the token another connection's per-query keys embed, so it
  invalidates that connection's cache too. Over-invalidation only; orphaned entries still expire by TTL.
- **Failed writes.** If the store cannot write a new token, the repository that wrote keeps a local one and logs the
  failure; other processes keep serving their entries until the TTL expires.

### Testing Utilities

The package ships test utilities under the `SineMacula\Repositories\Testing` namespace for packages that extend
`Repository` or implement `CriteriaInterface`. See [docs/testing.md](docs/testing.md) for setup and patterns.

### Extension Points

Every protected member of the repository is classified as Stable, Transitional, or Internal. The package follows
[Semantic Versioning](https://semver.org/), and a change to a Stable extension point counts as breaking. See
[docs/extension-points.md](docs/extension-points.md) for the classification.

### Upgrading

See [UPGRADE.md](UPGRADE.md) for version-by-version upgrade steps.

## Requirements

- PHP ^8.3
- Laravel 12+

## Testing

```bash
composer test                # PHPUnit suite in parallel via Paratest
composer test:coverage       # suite with Clover coverage output
composer test:mutation       # Infection gate on the diff against origin/master (min MSI 90)
composer test:mutation:full  # full mutation suite without thresholds
composer check               # static analysis and lint via qlty
composer format              # format via qlty
composer smells              # duplication / complexity smells via qlty
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Security

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
