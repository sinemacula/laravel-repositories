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

- **Repository Base Class**: A container-resolved repository abstraction with explicit model validation and lifecycle
  reset behavior.
- **Criteria Lifecycle Controls**: Persistent and one-shot criteria pipelines with runtime enable, disable, skip, and
  reset controls.
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

## Contributing

Contributions are welcome via GitHub pull requests.

## Security

If you discover a security issue, please contact Sine Macula directly rather than opening a public issue.

## License

Licensed under the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
