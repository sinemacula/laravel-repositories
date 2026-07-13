# Upgrade Guide

This document provides migration guidance for breaking changes between major versions.

## From v2.1.x to v2.2.0

v2.2.0 adds two consumer-visible changes to the base `Repository` and to `Cacheable`'s reference mode.

### 1. Repository::all() is now a concrete method

**What changed:** `all()` is now implemented directly on `Repository` as `all(mixed ...$arguments): mixed`, delegating
to the `get()` pipeline so criteria, scopes, and caching apply exactly as they would for `get()`.

**Who is affected:** Subclasses that declare their own `all()` method with an incompatible signature. PHP enforces
method signature compatibility at class-load time, so a subclass `all()` that does not match
`all(mixed ...$arguments): mixed` now fatals when the class is loaded.

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
// Remove the override entirely - the base class now provides it - or match
// the base signature and delegate:
final class UserRepository extends Repository
{
    public function all(mixed ...$arguments): mixed
    {
        return parent::all(...$arguments);
    }
}
```

**Action:** Remove any subclass `all()` override, or re-sign it to match `all(mixed ...$arguments): mixed`.

### 2. Reference-mode reads now honour active criteria and scopes

**What changed:** A repository with `protected bool $cacheReferenceTable = true` previously always served the
unfiltered whole-table snapshot from `get()`, `all()`, and `find()`, even when criteria or scopes were active. Reference
mode now executes a real, uncached query instead of the snapshot whenever criteria or scopes are pending.

**Why:** The old behaviour silently discarded active criteria and scopes on reference-mode repositories, returning more
data than the caller asked for.

**Who is affected:** Consumers combining `cacheReferenceTable = true` with `pushCriteria()`, `withCriteria()`, or
`addScope()` and relying on the previous (incorrect) unfiltered result.

**Action:** No change is required for typical usage - this delivers the already-documented reference-mode contract. To
keep serving the unfiltered snapshot regardless of active criteria, call `skipCriteria()` explicitly before the read.

## From v1.x to v2.0

v2.0.0 introduced seven categories of breaking change. This section covers each one with the change rationale and
before/after code examples.

### 1. RepositoryInterface expanded from empty marker to 8 methods

**What changed:** `RepositoryInterface` was an empty marker interface in v1. In v2, it declares eight methods:
`model()`, `makeModel()`, `resetModel()`, `getModel()`, `addScope()`, `resetScopes()`, `query()`, `newQuery()`.

**Why:** The empty interface provided no contract guarantees. Populating it ensures that any code type-hinting
`RepositoryInterface` can rely on these methods being available.

**Who is affected:** Only code that implements `RepositoryInterface` directly without extending `Repository`. If you
extend `Repository` (the typical pattern), `Repository` provides all implementations and no changes are needed.

**Before (v1):**

```php
// This worked in v1 because the interface was empty
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

**What changed:** A new `protected bool $forceUseCriteria = false` property was added to support the updated
`useCriteria()` behavior.

**Who is affected:** Subclasses that define a property named `$forceUseCriteria` will experience a collision.

**Action:** Rename any conflicting property in your subclass.

### 3. useCriteria() behavioral change

**What changed:** In v1, `useCriteria()` only set `$skipCriteria = false` (undoing a `skipCriteria()` call). In v2, it
also sets `$forceUseCriteria = true`, which actively overrides `disableCriteria()` for the next query.

**Why:** This makes `useCriteria()` a true "force criteria on for this query" method, even when criteria are globally
disabled.

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

**Action:** If you relied on `useCriteria()` not overriding `disableCriteria()`, use `skipCriteria()` / removing the
`skipCriteria()` call instead, or do not call `useCriteria()` after `disableCriteria()`.

### 4. removeCriteria() now affects both collections

**What changed:** In v1, `removeCriteria()` only removed criteria from the persistent collection. In v2, it removes
matching criteria from both persistent and transient collections.

**Why:** Removing a criterion should remove it everywhere, not leave a copy in the transient collection.

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

**Action:** If you relied on `removeCriteria()` leaving transient criteria intact, adjust your criteria management
logic.

### 5. getModel() always returns a Model

**What changed:** In v1, `getModel()` returned `$this->model` directly, which could be a `Builder` instance if called
during query composition. In v2, `getModel()` checks whether the internal state is a `Model` and calls `makeModel()` if
it is not, ensuring it always returns a `Model`.

**Why:** `getModel()` should return a model, not a query builder. The v1 behavior leaked internal lifecycle state.

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

**Action:** If you relied on `getModel()` returning a `Builder` mid-lifecycle to inspect query state, use `query()`
instead to get the builder.

### 6. __callStatic() requires Laravel container

**What changed:** In v1, `__callStatic()` used `new static()` to create the repository. In v2, it resolves the
repository from the Laravel container via `Container::getInstance()->make(static::class)` and throws
`RepositoryException` if the container is not an `Application` instance.

**Why:** Container resolution ensures the repository receives its dependencies correctly and respects any bindings or
decorators registered in the container.

**Before (v1):**

```php
// Worked anywhere, even without Laravel booted
$user = UserRepository::find(1);
```

**After (v2):**

```php
// Requires the Laravel container to be available
// In tests: ensure the application is bootstrapped (e.g., via Orchestra Testbench)
$user = UserRepository::find(1);

// Without Laravel container: throws RepositoryException
```

**Action:** Ensure the Laravel application is bootstrapped before using static method calls. In tests, use Orchestra
Testbench or resolve the repository from the container directly.

### 7. $model property type includes null

**What changed:** The `$model` property type changed from `Builder|Model` to `Builder|Model|null`, with a default value
of `null`. The model is now resolved during construction rather than being assumed non-null from the start.

**Who is affected:** Subclasses with type assertions or PHPStan narrowing that assume `$model` is never null.

**Action:** Add null checks where you access `$this->model` in subclass code, or rely on `getModel()` which handles the
null case.
