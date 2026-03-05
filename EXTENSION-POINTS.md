# Extension Points

This document classifies every protected member accessible to Repository subclasses and describes the stability
guarantees for each.

## Classification Legend

| Classification   | Meaning                                                                        | Stability Guarantee                                                                                                                      |
|------------------|--------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| **Stable**       | Intended for subclass use. Documented contract.                                | Will not change without a deprecation notice in a prior release. At least one minor or major release between deprecation and removal.    |
| **Transitional** | Currently available but planned for change. Use is discouraged for new code.   | May change in a future minor or major release. Changes will be documented in the CHANGELOG but may not receive a full deprecation cycle. |
| **Internal**     | Accessible due to PHP visibility rules but not part of the committed contract. | May change without notice in any release. Do not rely on these members in downstream code.                                               |

## Protected Methods

### boot() — Stable

**Purpose:** Hook for subclass initialization. Called once at the end of the constructor, after all internal state has
been initialized.

**When to use:** Override this method to register persistent criteria, configure initial scopes, or set up any
subclass-specific state during repository construction.

**Behavioral guarantees:**

- Called exactly once per repository instance, at the end of the constructor.
- When `boot()` is invoked, the following state is guaranteed to be initialized:
  - `$persistentCriteria` and `$transientCriteria` are empty Collections.
  - `$disableCriteria`, `$skipCriteria`, and `$forceUseCriteria` are `false`.
  - `$scopes` is an empty array.
  - `$model` holds a resolved Model instance (via `makeModel()`).
  - `$app` holds the Application instance.
- It is safe to call `pushCriteria()`, `addScope()`, and `getModel()` during `boot()`.

**Example:**

```php
protected function boot(): void
{
    $this->pushCriteria(new ActiveRecordsCriterion);
}
```

### prepareQueryBuilder() — Internal

**Purpose:** Orchestrates criteria and scope application, then ensures `$model` is a Builder. Called internally by
`query()` and `__call()`.

**Why Internal:** This method coordinates multiple internal concerns (criteria application, scope application, model-to-
builder conversion). Its behavior and signature may change as the lifecycle is clarified in future versions. Subclasses
should use `query()` to obtain a prepared builder.

### applyScopes() — Internal

**Purpose:** Iterates over registered scopes and applies each to the current model/builder. Called internally by
`prepareQueryBuilder()`.

**Why Internal:** Scope application is an internal step in the query composition pipeline. The public API for scopes
is `addScope()` and `resetScopes()`.

### resetAndReturn() — Internal

**Purpose:** Resets transient state (transient criteria, scopes, model) after a forwarded method call completes. Called
internally by `__call()`.

**Why Internal:** This method is a cleanup step in the magic method forwarding pipeline. Its behavior is tied to
internal state management and may change without notice.

### applyCriteria() — Internal

**Purpose:** Applies persistent and transient criteria to the model/builder according to the four control flags.
Defined in the `ManagesCriteria` trait (which is itself `@internal`).

**Why Internal:** Criteria application logic is the core of the criteria lifecycle state machine. The public API for
criteria management is `pushCriteria()`, `withCriteria()`, `enableCriteria()`, `disableCriteria()`, `useCriteria()`,
`skipCriteria()`, and `resetCriteria()`.

## Protected Properties

### $app — Stable

**Type:** `Illuminate\Contracts\Foundation\Application` (readonly)

**Purpose:** The Laravel application/container instance, injected via the constructor.

**When to use:** Access this property when your subclass needs to resolve dependencies from the container during its
lifecycle (e.g., in `boot()` or in custom query methods).

**Behavioral guarantees:**

- Always holds a valid `Application` instance after construction.
- Readonly — cannot be reassigned after construction.
- Will not be removed, renamed, or retyped without a deprecation cycle.

### $model — Transitional

**Type:** `Builder|Model|null`

**Purpose:** Holds the current model instance ("at rest") or query builder ("during query composition"). Changes type
during a single operation according to the repository's lifecycle phases.

**Why Transitional:** The dual-role nature of this property (holding both a Model and a Builder at different lifecycle
points) is identified as a design issue. Future versions may separate these concerns into distinct properties or
introduce a clearer lifecycle contract. Do not rely on the specific type of `$model` at any given point.

**Lifecycle phases:**

| Phase                 | $model Type | Entry Point                                              | Exit Point                                             |
|-----------------------|-------------|----------------------------------------------------------|--------------------------------------------------------|
| **At rest**           | `Model`     | After construction; after `resetModel()` completes       | When `prepareQueryBuilder()` begins                    |
| **Query composition** | `Builder`   | When `prepareQueryBuilder()` normalizes Model to Builder | When `query()` or `__call()` resets via `resetModel()` |

During query composition, all criteria and scopes receive a `Builder` — no defensive `Model`-to-`Builder` conversion
is needed in criteria implementations. The normalization happens once at the start of `prepareQueryBuilder()` before any
criteria or scopes are applied.

### $persistentCriteria — Internal

**Type:** `Collection`

**Purpose:** Stores criteria that persist across queries until explicitly removed.

**Why Internal:** Managed entirely through the public API (`pushCriteria()`, `removeCriteria()`, `getCriteria()`,
`resetCriteria()`). Direct property access is unnecessary and bypasses the criteria lifecycle.

### $transientCriteria — Internal

**Type:** `Collection`

**Purpose:** Stores criteria that apply to the next query only, then are cleared.

**Why Internal:** Managed through `withCriteria()` and automatically cleared after each query. Direct access bypasses
the intended one-shot lifecycle.

### $disableCriteria — Internal

**Type:** `bool`

**Purpose:** When `true`, persistent criteria are not applied (transient criteria are still applied).

**Why Internal:** Managed through `enableCriteria()` and `disableCriteria()`. Direct manipulation risks desynchronizing
the flag state machine.

### $skipCriteria — Internal

**Type:** `bool`

**Purpose:** When `true`, all criteria (persistent and transient) are skipped for the next query. Resets to `false`
after each query.

**Why Internal:** Managed through `skipCriteria()`. This is a transient flag that resets automatically.

### $forceUseCriteria — Internal

**Type:** `bool`

**Purpose:** When `true`, persistent criteria are applied even if `$disableCriteria` is `true`. Overrides
`$disableCriteria` for one query.

**Why Internal:** Managed through `useCriteria()`. This is a transient flag that resets automatically.

### $scopes — Internal

**Type:** `array`

**Purpose:** Stores registered query scopes (closures) that are applied during query composition.

**Why Internal:** Managed through `addScope()` and `resetScopes()`. Direct array manipulation bypasses the public API.

## Criteria Flag Precedence

The criteria state machine uses four binary flags that interact according to these precedence rules:

1. **`$skipCriteria` overrides all other flags.** When true, no criteria (persistent or transient) are applied,
   regardless of `$disableCriteria` or `$forceUseCriteria` state. Both `$skipCriteria` and `$forceUseCriteria` are
   reset after the query, and transient criteria are cleared.

2. **`$forceUseCriteria` overrides `$disableCriteria` for persistent criteria.** When both are true, persistent criteria
   ARE applied. This allows `useCriteria()` and `withCriteria()` to temporarily re-enable criteria application even when
   criteria are globally disabled.

3. **`$disableCriteria` does NOT gate transient criteria.** When true (and `$skipCriteria` is false), only persistent
   criteria are suppressed. Transient criteria are still applied. Use `skipCriteria()` to suppress all criteria
   including transient.

See the `ManagesCriteria` trait docblock for the complete 16-state truth table.

## Criteria Application Ordering

When criteria are applied, they execute in a fixed two-phase order:

1. **Transient criteria first** — Criteria registered via `withCriteria()` are applied in insertion order, then cleared.
2. **Persistent criteria second** — Criteria registered via `pushCriteria()` are applied in insertion order. They remain
   registered for future queries.

Within each phase, criteria execute in the order they were added. There is no priority or dependency mechanism;
insertion order is the sole determinant.

Supplementary capability declarations (eager-loading, field selection, counts, metadata) are collected from each
criterion immediately after its `apply()` method executes, in the same ordering as above. Collected declarations are
accessible via `getCollectedEagerLoads()`, `getCollectedFields()`, `getCollectedCounts()`, and
`getCollectedMetadata()`.

## Supplementary Criteria Capabilities

Criteria can opt into declaring additional capabilities by implementing supplementary contracts alongside
`CriteriaInterface`. These contracts are purely additive — existing criteria that only implement `CriteriaInterface`
are unaffected.

| Contract                     | Purpose                                 | Collection Method          |
|------------------------------|-----------------------------------------|----------------------------|
| `DeclaresEagerLoading`       | Declare relationships to eager-load     | `getCollectedEagerLoads()` |
| `DeclaresFieldSelection`     | Declare fields to select                | `getCollectedFields()`     |
| `DeclaresRelationshipCounts` | Declare relationship count aggregations | `getCollectedCounts()`     |
| `ContributesMetadata`        | Attach key-value metadata               | `getCollectedMetadata()`   |

The repository detects these contracts at criteria application time via `instanceof` checks and collects the
declarations. Downstream code (e.g., repository subclasses) can retrieve the collected declarations and apply them
to the query as appropriate for their context.

## Constructor Boot Sequence

The constructor performs the following steps in order. Each step's post-conditions are guaranteed when the next step
begins:

| Step | Action                                  | Post-condition                                                                                                                           |
|------|-----------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| 1    | `$app` assigned (constructor promotion) | `$app` holds the Application instance                                                                                                    |
| 2    | `$persistentCriteria = new Collection`  | Empty persistent criteria collection exists                                                                                              |
| 3    | `$transientCriteria = new Collection`   | Empty transient criteria collection exists                                                                                               |
| 4    | `resetCriteria()`                       | All criteria flags reset to defaults (`disableCriteria=false`, `skipCriteria=false`, `forceUseCriteria=false`); both collections cleared |
| 5    | `resetScopes()`                         | `$scopes` is an empty array                                                                                                              |
| 6    | `makeModel()`                           | `$model` holds a resolved Model instance; RepositoryException thrown if model class is invalid                                           |
| 7    | `boot()`                                | Subclass initialization hook. All state from steps 1-6 is available.                                                                     |

Subclasses overriding `boot()` can safely assume all state from steps 1-6 is initialized. It is safe to call
`pushCriteria()`, `addScope()`, `getModel()`, and any public method during `boot()`.

## Versioning Commitment

Changes to **Stable** members follow the deprecation policy documented in
the [Versioning Policy](README.md#versioning-policy):

- Stable members will not change behavior or signature without a deprecation notice in a prior release.
- The minimum deprecation window is one minor or major release cycle.
- Deprecated members will include documentation of the migration path in [UPGRADING.md](UPGRADING.md).

**Transitional** members may change in any minor or major release. Changes will be documented in
[CHANGELOG.md](CHANGELOG.md) with migration guidance where applicable.

**Internal** members carry no stability commitment. They may change in any release, including patch releases, without
prior notice. Use the public API methods instead.
