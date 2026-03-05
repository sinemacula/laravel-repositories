# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-02-28

### Added

- `ManagesCriteria` trait extracted from `Repository` class to encapsulate all criteria lifecycle logic.
- `RepositoryInterface` now declares eight methods: `model()`, `makeModel()`, `resetModel()`, `getModel()`,
  `addScope()`, `resetScopes()`, `query()`, `newQuery()`.
- `$forceUseCriteria` protected property on `Repository` for transient criteria force-enable behavior.
- `query()` and `newQuery()` public methods on `Repository` as explicit query builder entrypoints.
- `prepareQueryBuilder()` protected method for internal query composition orchestration.
- `isModelInstance()` protected method for model type checking.
- `#[\Override]` attributes on all interface implementation methods (requires PHP 8.3).
- PHPStan level 8 static analysis with strict rules.
- Comprehensive test suite with integration tests using Orchestra Testbench.
- Supplementary criteria capability contracts: `DeclaresEagerLoading`, `DeclaresFieldSelection`,
  `DeclaresRelationshipCounts`, and `ContributesMetadata`. These are opt-in interfaces that criteria can implement
  alongside `CriteriaInterface` to declare data requirements without reaching outside the contract.
- Capability collection in the criteria application lifecycle: the repository detects supplementary contracts at
  criteria application time and collects declarations, accessible via `getCollectedEagerLoads()`,
  `getCollectedFields()`, `getCollectedCounts()`, and `getCollectedMetadata()`.
- Lifecycle phase documentation ("at rest" and "query composition") in the `Repository` class docblock and
  `EXTENSION-POINTS.md`.
- Criteria flag precedence rules documented in the `ManagesCriteria` trait docblock and `EXTENSION-POINTS.md`, including
  the complete 16-state truth table.
- Criteria application ordering rules documented: transient criteria first (insertion order), then persistent criteria
  (insertion order).
- Extension point classification for all protected members: 2 Stable (`boot()`, `$app`), 1 Transitional (`$model`),
  10 Internal. Documented in `EXTENSION-POINTS.md`.
- `CHANGELOG.md` with retroactive entries for all versions.
- `UPGRADING.md` with v1-to-v2 migration guide covering 7 breaking change categories.
- Versioning policy in `README.md` defining what constitutes a breaking change, deprecation policy, and stability
  exclusions.
- Comprehensive criteria flag state tests covering 14 of 16 flag combinations (remaining 2 are documented as equivalent
  to tested states).
- Exported test utilities in `testing/` directory under the `SineMacula\Repositories\Testing` namespace:
  `InspectsRepository` trait for repository state observability, `CriteriaTestHelper` for lightweight criteria
  verification without database connections, and `CriterionStub` for configurable test criteria.
- `TESTING.md` with three documented testing patterns: criterion isolation testing, repository subclass testing, and
  criteria-scope interaction testing.

### Changed

- **Breaking:** `RepositoryInterface` expanded from an empty marker interface to declaring 8 methods. Classes
  implementing `RepositoryInterface` directly (without extending `Repository`) must now implement all 8 methods.
- **Breaking:** `useCriteria()` now sets `$forceUseCriteria = true` in addition to `$skipCriteria = false`. Previously
  it only unset skip; now it actively overrides `disableCriteria()` for the next query.
- **Breaking:** `removeCriteria()` now removes matching criteria from both persistent and transient collections.
  Previously it only operated on the persistent collection.
- **Breaking:** `getModel()` now always returns a `Model` instance, calling `makeModel()` if the internal state holds a
  `Builder`. Previously it returned `$this->model` directly, which could be a `Builder` mid-lifecycle.
- **Breaking:** `__callStatic()` now resolves the repository from the Laravel container via
  `Container::getInstance()->make()` instead of `new static()`. Throws `RepositoryException` if the container is not an
  `Application` instance.
- **Breaking:** `$model` property type changed from `Builder|Model` to `Builder|Model|null` with a default of `null`.
- Criteria now always receive a `Builder` input (never a raw `Model`) during the repository's query composition phase.
  Criteria that previously performed defensive `instanceof Model ? $model->newQuery() : $model` conversion continue to
  work — the `Model` branch simply never executes. Criteria that assumed `Builder` input already require no changes.
- Repository normalizes `$model` to a `Builder` at the start of `prepareQueryBuilder()` before applying criteria and
  scopes. This eliminates `instanceof` discrimination checks in `applyCriteria()` and `applyScopes()`.
- Minimum PHP version remains ^8.3.
- Removed `minimum-stability: dev` and `prefer-stable: true` from `composer.json`.

### Deprecated

- `PresentableInterface` is deprecated and will be removed in v3.0.0. The interface is unused within the package and has
  no documented purpose. If you implement this interface, please open an issue to discuss your use case.

### Removed

- Direct instantiation via `new static()` in `__callStatic()` (replaced by container resolution).

## [1.0.4] - 2026-02-19

### Fixed

- Race condition fix in `__call` method using model booted check.

## [1.0.3] - 2026-02-19

### Fixed

- Race condition in `__call` method during query forwarding.

## [1.0.2] - 2025-08-15

### Added

- `PresentableInterface` contract for presenter class resolution.
- Qlty integration for code quality checks.

## [1.0.1] - 2025-04-23

### Changed

- Updated Laravel dependency compatibility.

## [1.0.0] - 2024-08-06

### Added

- Initial release.
- `Repository` abstract base class with Eloquent model binding and criteria-driven query composition.
- `CriteriaInterface` for implementing query modification criteria.
- `RepositoryInterface` as a marker interface for repository implementations.
- `RepositoryCriteriaInterface` defining the criteria lifecycle API.
- `RepositoryException` for repository-specific error handling.
- Persistent and transient criteria collections with enable/disable/skip/reset controls.
- Magic method forwarding (`__call`, `__callStatic`) for model-like ergonomics.

[Unreleased]: https://github.com/sinemacula/laravel-repositories/compare/v2.0.0...HEAD

[2.0.0]: https://github.com/sinemacula/laravel-repositories/compare/v1.0.4...v2.0.0

[1.0.4]: https://github.com/sinemacula/laravel-repositories/compare/v1.0.3...v1.0.4

[1.0.3]: https://github.com/sinemacula/laravel-repositories/compare/v1.0.2...v1.0.3

[1.0.2]: https://github.com/sinemacula/laravel-repositories/compare/v1.0.1...v1.0.2

[1.0.1]: https://github.com/sinemacula/laravel-repositories/compare/v1.0.0...v1.0.1

[1.0.0]: https://github.com/sinemacula/laravel-repositories/releases/tag/v1.0.0
