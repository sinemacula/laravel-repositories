# Upgrade Guide

Consumer-visible changes between versions, newest first.

## From v3.2.2 to v3.2.3

### 1. Non-taggable stores invalidate with a generation token

On a non-taggable store, the `repository-cache-version:{table}` key holds a random generation token
that every write replaces, instead of an integer that every write incremented.

The file store increments with an unlocked read-modify-write, and seeding a missing counter was not atomic
either, so concurrent writes could roll the counter back to a value whose entries predate a write and serve them
again. A token never repeats, so neither concurrent writes nor an evicted key can revive an orphaned entry.

Per-query entries on non-taggable stores miss once after the upgrade. Code that read or
incremented the version key directly must stop; call `CacheStore::invalidateTable()` or `flushCache()` instead.

### 2. Cache keys follow global scopes and eager-load constraints

A cacheable read is keyed by the SQL Eloquent runs, global scopes and eager-load constraints
included, and a reference-mode repository keeps one snapshot per global-scope variant.

The key used to be taken before global scopes applied, and an eager-load constraint was identified by where it
was defined rather than by what it queried. Reads that ran different SQL (a `withTrashed()` read and a default one, two
tenants under a tenant scope, a constraint reading the authenticated user) shared one entry, so one request was served
another's rows until the TTL expired.

After upgrading:

- Every existing cache entry misses once after the upgrade, since the keys change.
- Global scopes and eager-load constraints run once more per read, on cache hits too. A scope or constraint with side
  effects, or one returning different SQL on each call (a timestamp, a random value), is not supported.
- A read eager loading a `MorphTo` relation or a relation class of your own, one with an `afterQuery()` callback other
  than the one `chaperone()` registers, one with a callable `withDefault()`, or one whose eager loads nest more than 16
  levels deep, runs uncached rather than risk serving another read's rows.

## From v3.0.x to v3.1.0

### 1. Composing a query returns a copy

Composing a query no longer mutates the repository. `addScope()`, `withCriteria()`, `useCriteria()`,
`skipCriteria()`, `resetScopes()` and `withoutCache()` return a copy carrying the composition, and the instance they
were called on is left untouched.

A composition used to live on the repository until a query consumed it. A scope method that threw after
composing left its constraints behind, and the next unrelated query inherited them. The package is not on the stack
when a caller's own scope method throws, so no cleanup inside it could catch that. Returning a copy makes the abandoned
composition garbage instead of state.

This affects any caller that composes in one statement and queries in another. The declarations a criterion
contributes (eager loads, field selection, relationship counts, metadata) are collected on the handle the query ran
through, so `getCollectedMetadata()` and its siblings must be read from the composed copy rather than from the
repository the copy came from.

**Before:**

```php
$repository->scopeByOrganization($organisation);

return $repository->findOrFail($id);
```

**After:**

```php
return $repository
    ->scopeByOrganization($organisation)
    ->findOrFail($id);
```

Or keep the composed handle when the composition is conditional:

```php
$scoped = $repository->withApiCriteria();

if ($caller->isExternal()) {
    $scoped = $scoped->scopeByOrganization($organisation);
}

return $scoped->paginate();
```

A repository scope method must return what it composed, and marking it pure extends the discarded-result check to its
own callers:

```php
/**
 * Compose the next query down to the live records.
 *
 * @return static
 *
 * @phpstan-pure
 */
public function scopeLive(): static
{
    return $this->addScope(static function (Builder $query): void {
        $query->whereNull('revoked_at');
    });
}
```

`addScope()`, `withCriteria()`, `useCriteria()`, `skipCriteria()`, `resetScopes()` and `withoutCache()` are marked
`@phpstan-pure`, so a discarded result is reported as `method.resultUnused` from PHPStan level 4 upward. Mark your own
scope methods `@phpstan-pure` to extend that check to their call sites.

Scopes that must apply to every query are configuration rather than composition. Register them with `pushScope()`,
which mutates the instance and is safe in `boot()`; `addScope()` in `boot()` only ever reached the first query an
instance built. `pushScope()` is protected, so only the repository itself can register one.

A repository in whole-table reference mode never serves a registered scope from its snapshot, so registering one keeps
every read on the real query pipeline rather than answering the constraint with unfiltered rows.

If your subclass defines `__clone()`, call `parent::__clone()` first. The base implementation drops the in-flight
builder and duplicates the criteria collections, and a copy that skips it shares both with the handle it came from.

## From v2.2.x to v3.0.0

### 1. Laravel 11 is no longer supported

v3.0.0 requires Laravel 12 or 13. Laravel 13 support is new, and Laravel 11 support is dropped.

Upgrade the application to Laravel 12 or later before requiring v3.

## From v2.1.x to v2.2.0

### 1. Repository::all() is a concrete method

`all()` is implemented directly on `Repository` as `all(mixed ...$arguments): mixed`, delegating to
the `get()` pipeline so criteria, scopes, and caching apply exactly as they would for `get()`.

This affects subclasses that declare their own `all()` with an incompatible signature. PHP checks signature
compatibility when the class loads, so a subclass `all()` that does not match `all(mixed ...$arguments): mixed` is a
fatal error.

**Before (v2.1):**

```php
final class UserRepository extends Repository
{
    public function all(array $columns = ['*']): Collection
    {
        // custom implementation
    }
}
```

**After (v2.2):**

```php
// Remove the override, or match the base signature and delegate:
final class UserRepository extends Repository
{
    public function all(mixed ...$arguments): mixed
    {
        return parent::all(...$arguments);
    }
}
```

Remove any subclass `all()` override, or re-sign it to match `all(mixed ...$arguments): mixed`.

### 2. Reference-mode reads honour active criteria and scopes

A repository with `protected bool $cacheReferenceTable = true` used to serve the unfiltered snapshot
from `get()`, `all()`, and `find()` even when criteria or scopes were active. It now executes a real, uncached query
whenever criteria or scopes are pending.

The snapshot discarded active criteria and scopes, returning more data than the caller asked for.

This affects consumers combining `cacheReferenceTable = true` with `pushCriteria()`, `withCriteria()`, or
`addScope()` and relying on the unfiltered result.

Typical usage needs no change. To serve the snapshot despite active criteria, skip them for the read with
`skipCriteria()`; skipping criteria does not lift scopes.

## From v1.x to v2.0

v2.0.0 made seven breaking changes. The samples show the v2 idiom, with composition written as its own statement; from
v3.1 the composition methods return a copy, so read them alongside that section before copying any of them.

### 1. RepositoryInterface expanded from empty marker to 8 methods

`RepositoryInterface` was an empty marker interface in v1. In v2, it declares eight methods:
`model()`, `makeModel()`, `resetModel()`, `getModel()`, `addScope()`, `resetScopes()`, `query()`, `newQuery()`.

The empty interface guaranteed nothing. Code type-hinting `RepositoryInterface` can now rely on these methods.

This affects only code that implements `RepositoryInterface` directly without extending `Repository`. If you
extend `Repository` (the typical pattern), `Repository` provides all implementations and no changes are needed.

**Before (v1):**

```php
// The interface was empty, so this compiled
class CustomStore implements RepositoryInterface
{
    // No methods required
}
```

**After (v2):**

```php
// Must now implement all 8 methods, or extend Repository instead
class CustomStore extends Repository
{
    public function model(): string
    {
        return MyModel::class;
    }
}
```

### 2. New protected property: $forceUseCriteria

A `protected bool $forceUseCriteria = false` property backs the new `useCriteria()` behavior.

This affects subclasses that already define a property named `$forceUseCriteria`.

Rename any conflicting property in your subclass.

### 3. useCriteria() behavioral change

In v1, `useCriteria()` only set `$skipCriteria = false` (undoing a `skipCriteria()` call). In v2, it
also sets `$forceUseCriteria = true`, which overrides `disableCriteria()` for the next query.

`useCriteria()` should force criteria on for the next query even when they are disabled.

**Before (v1):**

```php
$repository->disableCriteria();
$repository->useCriteria();
// v1: criteria remain DISABLED (useCriteria only unsets skipCriteria)
$results = $repository->query()->get();
```

**After (v2):**

```php
$repository->disableCriteria();
$repository->useCriteria();
// v2: criteria are FORCE-ENABLED for this query
$results = $repository->query()->get();
```

If you used `useCriteria()` only to undo `skipCriteria()`, drop the `skipCriteria()` call instead, and do not call
`useCriteria()` after `disableCriteria()` unless you mean to force criteria on.

### 4. removeCriteria() now affects both collections

In v1, `removeCriteria()` only removed criteria from the persistent collection. In v2, it removes
matching criteria from both persistent and transient collections.

Removing a criterion should not leave a copy in the transient collection.

**Before (v1):**

```php
$repository->pushCriteria(new MyCriterion);
$repository->withCriteria(new MyCriterion);
$repository->removeCriteria(MyCriterion::class);
// v1: removed from persistent only; transient copy still applies
```

**After (v2):**

```php
$repository->pushCriteria(new MyCriterion);
$repository->withCriteria(new MyCriterion);
$repository->removeCriteria(MyCriterion::class);
// v2: removed from BOTH persistent and transient
```

If you relied on `removeCriteria()` leaving transient criteria intact, adjust your criteria management
logic.

### 5. getModel() always returns a Model

In v1, `getModel()` returned `$this->model` directly, which could be a `Builder` during query
composition. In v2, it calls `makeModel()` when the internal state is not a `Model`, so it always returns a `Model`.

The v1 behavior leaked internal lifecycle state.

**Before (v1):**

```php
// During query composition, this could return a Builder
$model = $repository->getModel();
// $model might be Builder|Model depending on timing
```

**After (v2):**

```php
// Always returns a Model, regardless of internal lifecycle state
$model = $repository->getModel();
// $model is always Model
```

If you relied on `getModel()` returning a `Builder` mid-lifecycle to inspect query state, use `query()`
instead to get the builder.

### 6. __callStatic() requires Laravel container

In v1, `__callStatic()` used `new static()` to create the repository. In v2, it resolves the
repository with `Container::getInstance()->make(static::class)` and throws `RepositoryException` if the container is
not an `Application` instance.

Container resolution injects the repository's dependencies and respects any bindings or decorators.

**Before (v1):**

```php
// Worked anywhere, even without Laravel booted
$user = UserRepository::find(1);
```

**After (v2):**

```php
// Requires a booted application (in tests, e.g. Orchestra Testbench)
$user = UserRepository::find(1);

// Without one: throws RepositoryException
```

Ensure the Laravel application is bootstrapped before using static method calls. In tests, use Orchestra
Testbench or resolve the repository from the container directly.

### 7. $model property type includes null

The `$model` property type changed from `Builder|Model` to `Builder|Model|null`, with a default of
`null`. The model is resolved during construction rather than assumed non-null from the start.

This affects subclasses with type assertions or PHPStan narrowing that assume `$model` is never null.

Add null checks where you access `$this->model` in subclass code, or rely on `getModel()` which handles the
null case.
