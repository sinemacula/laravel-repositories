# Extension Points

Every protected member a `Repository` subclass can reach, and the stability guarantee each carries.

## Classification Legend

| Classification   | Meaning                                                                        | Stability Guarantee                                                                                                                      |
|------------------|--------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| **Stable**       | Intended for subclass use. Documented contract.                                | Will not change without a deprecation notice in a prior release. At least one minor or major release between deprecation and removal.    |
| **Transitional** | Currently available but planned for change. Use is discouraged for new code.   | May change in a future minor or major release. Changes will be documented in the CHANGELOG but may not receive a full deprecation cycle. |
| **Internal**     | Accessible due to PHP visibility rules but not part of the committed contract. | May change without notice in any release. Do not rely on these members in downstream code.                                               |

## Protected Methods

### boot() - Stable

**Purpose:** Hook for subclass initialization. Called once by the constructor, after the base state is initialized and
before concern hooks run.

**When to use:** Override this method to register persistent criteria, configure initial scopes, or set up any
subclass-specific state during repository construction.

**Behavioral guarantees:**

- Called exactly once per constructed instance; a composed copy does not call it again.
- When `boot()` is invoked, the following state is guaranteed to be initialized:
  - `$persistentCriteria` and `$transientCriteria` are empty Collections.
  - `$disableCriteria`, `$skipCriteria`, and `$forceUseCriteria` are `false`.
  - `$scopes` and `$persistentScopes` are empty arrays.
  - `$model` holds a resolved Model instance (via `makeModel()`).
  - `$app` holds the Application instance.
- It is safe to call `pushCriteria()`, `pushScope()`, and `getModel()` during `boot()`.
- Register scopes with `pushScope()`, not `addScope()`. `addScope()` returns a copy carrying the scope, and `boot()`
  has no way to return a replacement instance, so a scope added there would be discarded with the copy.

**Example:**

```php
protected function boot(): void
{
    $this->pushCriteria(new ActiveRecordsCriterion);
}
```

### pushScope() - Stable

**Purpose:** Registers a scope that applies to every query for the life of the instance. The scope counterpart of
`pushCriteria()`.

**Signature:** `protected function pushScope(\Closure $scope): static`

**Contract:** A registered scope is never consumed. It survives every query and every `resetScopes()`, and is applied
before the scopes composing the current query, so a per-query scope can still override an ordering or limit it set.

**Reference-mode note:** A repository in whole-table reference mode serves reads from a snapshot built straight from the
model, which no scope reaches. Registering a scope therefore keeps every read on the real query pipeline, so the
constraint is never answered with unfiltered rows.

**Contrast with `addScope()`:** `addScope()` composes the next query only and returns a copy rather than mutating the
repository, which is why it cannot be used from `boot()`.

```php
protected function boot(): void
{
    $this->pushScope(static fn (Builder $query) => $query->whereNull('archived_at'));
}
```

### model() - Stable

**Purpose:** Names the Eloquent model the repository targets.

**Signature:** `public function model(): string`

**Contract:** The default implementation returns the class named by the `Model` attribute, looked for on the
repository and then on each of its ancestors, and throws a `RepositoryException` when neither the attribute nor an
override is present. Overriding the method replaces the lookup entirely, so the two cannot disagree.

```php
use SineMacula\Repositories\Attributes\Model;

#[Model(User::class)]
final class UserRepository extends Repository {}
```

A declaration carrying no arguments is walked past rather than read, because a generated test double copies the
attributes of the class it stands in for by name alone.

### prepareQueryBuilder() - Internal

**Purpose:** Ensures `$model` is a Builder, then applies criteria and scopes to it. Called internally by `query()` and
`__call()`.

**Why Internal:** It coordinates model-to-builder conversion, criteria application and scope application, and may
change as that lifecycle evolves. Use `query()` to obtain a prepared builder.

### applyScopes() - Internal

**Purpose:** Applies the registered and composing scopes to the current builder. Called internally by
`prepareQueryBuilder()`.

**Why Internal:** Scope application is an internal step in the query composition pipeline. Scopes are composed publicly
with `addScope()` and `resetScopes()`, and registered from inside the subclass with `pushScope()`.

### resetAndReturn() - Internal

**Purpose:** Resets per-query state (transient criteria, composing scopes, model) after a forwarded method call
completes. Called internally by `__call()`.

**Why Internal:** It is a cleanup step in the method forwarding pipeline, tied to internal state management.

### applyCriteria() - Internal

**Purpose:** Applies transient and persistent criteria to the builder according to the three criteria flags. Defined in
the internal `ManagesCriteria` trait.

**Why Internal:** It is the core of the criteria state machine. The public API for criteria management is
`pushCriteria()`, `withCriteria()`, `enableCriteria()`, `disableCriteria()`, `useCriteria()`, `skipCriteria()`, and
`resetCriteria()`.

## Protected Properties

### $app - Stable

**Type:** `Illuminate\Contracts\Foundation\Application` (readonly)

**Purpose:** The Laravel application/container instance, injected via the constructor.

**When to use:** Access this property when your subclass needs to resolve dependencies from the container during its
lifecycle (e.g., in `boot()` or in custom query methods).

**Behavioral guarantees:**

- Always holds a valid `Application` instance after construction.
- Readonly - cannot be reassigned after construction.
- Will not be removed, renamed, or retyped without a deprecation cycle.

### $model - Transitional

**Type:** `Builder|Model|null`

**Purpose:** Holds the current model instance at rest, or the query builder during query composition.

**Why Transitional:** Holding both a Model and a Builder at different points is a known design issue that a future
version may split into separate properties. Do not rely on the type of `$model` at any given point.

**Lifecycle phases:**

| Phase                 | $model Type | Entry Point                                              | Exit Point                                                    |
|-----------------------|-------------|----------------------------------------------------------|---------------------------------------------------------------|
| **At rest**           | `Model`     | After construction; after `resetModel()` completes       | When `prepareQueryBuilder()` begins                           |
| **Query composition** | `Builder`   | When `prepareQueryBuilder()` normalizes Model to Builder | When `query()` or `__call()` resets via `resetModel()`        |
| **Composed copy**     | `null`      | When a composing call clones the instance                | When `prepareQueryBuilder()` or `getModel()` resolves a model |

`prepareQueryBuilder()` normalizes `$model` to a `Builder` before applying any criteria or scopes, so criteria never
need a `Model`-to-`Builder` conversion of their own.

### $persistentCriteria - Internal

**Type:** `Collection`

**Purpose:** Stores criteria that persist across queries until explicitly removed.

**Why Internal:** Managed through `pushCriteria()`, `removeCriteria()`, `getCriteria()` and `resetCriteria()`.

### $transientCriteria - Internal

**Type:** `Collection`

**Purpose:** Stores criteria that apply to the next query only, then are cleared.

**Why Internal:** Managed through `withCriteria()` and cleared after each query.

### $disableCriteria - Internal

**Type:** `bool`

**Purpose:** When `true`, persistent criteria are not applied (transient criteria are still applied).

**Why Internal:** Managed through `enableCriteria()` and `disableCriteria()`.

### $skipCriteria - Internal

**Type:** `bool`

**Purpose:** When `true`, all criteria (persistent and transient) are skipped for the next query. Resets to `false`
after each query.

**Why Internal:** Managed through `skipCriteria()`.

### $forceUseCriteria - Internal

**Type:** `bool`

**Purpose:** When `true`, persistent criteria are applied even if `$disableCriteria` is `true`. Overrides
`$disableCriteria` for one query.

**Why Internal:** Managed through `useCriteria()`.

### $scopes - Internal

**Type:** `array`

**Purpose:** Stores the scopes composing the next query.

**Why Internal:** Managed through `addScope()` and `resetScopes()`, both of which return a copy rather than mutating
the instance.

### $persistentScopes - Internal

**Type:** `array`

**Purpose:** Stores scopes registered via `pushScope()`. Applied to every query for the life of the instance, before the
scopes composing the current query, and never consumed by a query or cleared by `resetScopes()`.

**Why Internal:** Managed through `pushScope()`.

## Criteria Flag Precedence

Three flags decide which criteria apply:

1. **`$skipCriteria` overrides all other flags.** When true, no criteria (persistent or transient) are applied,
   regardless of `$disableCriteria` or `$forceUseCriteria` state. Both `$skipCriteria` and `$forceUseCriteria` are
   reset after the query, and transient criteria are cleared.

2. **`$forceUseCriteria` overrides `$disableCriteria` for persistent criteria.** When both are true, persistent criteria
   ARE applied. This allows `useCriteria()` and `withCriteria()` to temporarily re-enable criteria application even when
   criteria are globally disabled.

3. **`$disableCriteria` does NOT gate transient criteria.** When true (and `$skipCriteria` is false), only persistent
   criteria are suppressed. Transient criteria are still applied. Use `skipCriteria()` to suppress all criteria
   including transient.

## Criteria Application Ordering

When criteria are applied, they execute in a fixed two-phase order:

1. **Transient criteria first** - Criteria registered via `withCriteria()` are applied in insertion order, then cleared.
2. **Persistent criteria second** - Criteria registered via `pushCriteria()` are applied in insertion order. They remain
   registered for future queries.

There is no priority or dependency mechanism; insertion order is the sole determinant.

Supplementary capability declarations (eager-loading, field selection, counts, metadata) are collected from each
criterion immediately after its `apply()` method executes, in the same ordering as above. Collected declarations are
accessible via `getCollectedEagerLoads()`, `getCollectedFields()`, `getCollectedCounts()`, and
`getCollectedMetadata()`.

## Supplementary Criteria Capabilities

A criterion can declare more than its query by implementing these contracts alongside `CriteriaInterface`:

| Contract                     | Purpose                                 | Collection Method          |
|------------------------------|-----------------------------------------|----------------------------|
| `DeclaresEagerLoading`       | Declare relationships to eager-load     | `getCollectedEagerLoads()` |
| `DeclaresFieldSelection`     | Declare fields to select                | `getCollectedFields()`     |
| `DeclaresRelationshipCounts` | Declare relationship count aggregations | `getCollectedCounts()`     |
| `ContributesMetadata`        | Attach key-value metadata               | `getCollectedMetadata()`   |

The repository collects the declarations as it applies criteria but does not act on them; a subclass or caller reads
them back and applies them as its context requires.

## Constructor Boot Sequence

The constructor runs these steps in order, and each step's post-condition holds when the next begins:

| Step | Action                                  | Post-condition                                                                                                                           |
|------|-----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| 1    | `$app` assigned (constructor promotion) | `$app` holds the Application instance                                                                                                    |
| 2    | `$persistentCriteria = new Collection`  | Empty persistent criteria collection exists                                                                                              |
| 3    | `$transientCriteria = new Collection`   | Empty transient criteria collection exists                                                                                               |
| 4    | `resetCriteria()`                       | Both criteria collections cleared; flags at declared defaults (`disableCriteria=false`, `skipCriteria=false`, `forceUseCriteria=false`)  |
| 5    | (scope defaults)                        | `$scopes` is an empty array, as is `$persistentScopes`                                                                                   |
| 6    | `makeModel()`                           | `$model` holds a resolved Model instance; RepositoryException thrown if model class is invalid                                           |
| 7    | `boot()`                                | Subclass initialization hook. All state from steps 1-6 is available.                                                                     |
| 8    | `bootConcerns()`                        | Each trait's `boot{TraitName}()` hook has run, such as `bootCacheable()`                                                                 |

Subclasses overriding `boot()` can safely assume all state from steps 1-6 is initialized. It is safe to call
`pushCriteria()`, `pushScope()`, `getModel()`, and the other base repository methods during `boot()`. Methods provided
by bootable concerns (such as the cache operations added by `Cacheable`) are not yet available: concern collaborators
initialise after `boot()`, via `bootConcerns()`.

## Versioning Commitment

Changes to **Stable** members follow [Semantic Versioning](https://semver.org/) and this deprecation policy:

- Stable members will not change behavior or signature without a deprecation notice in a prior release.
- The minimum deprecation window is one minor or major release cycle.
- Deprecated members will include documentation of the migration path in [UPGRADE.md](../UPGRADE.md).

**Transitional** members may change in any minor or major release. Changes will be documented in
[CHANGELOG.md](../CHANGELOG.md) with migration guidance where applicable.

**Internal** members carry no stability commitment. They may change in any release, including patch releases, without
prior notice. Use the public API methods instead.
