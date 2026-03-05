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
