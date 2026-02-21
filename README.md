# Laravel Repositories

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Build Status](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml)
[![Maintainability](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/maintainability.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Code Coverage](https://qlty.sh/gh/sinemacula/projects/laravel-repositories/coverage.svg)](https://qlty.sh/gh/sinemacula/projects/laravel-repositories)
[![Total Downloads](https://img.shields.io/packagist/dt/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)

This Laravel package offers a streamlined repository pattern implementation with criteria-based query filtering,
optimized for elegant and efficient manipulation of Eloquent models. It simplifies the robust capabilities of the
original l5-repositories, focusing on the features most essential and frequently used, given that the l5-repositories
project is no longer maintained.

A big thanks to the creators of [andersao/l5-repository](https://github.com/andersao/l5-repository) for their pioneering
work, which heavily inspired this project. Our package aims to continue in that spirit, tailored for today's Laravel
applications.

## Features

- **Clean Model Architecture**: Implements the data repository pattern to abstract data logic away from the models,
  ensuring that your models stay clean and focused solely on their intended functionalities.
- **Flexible Data Retrieval**: Utilizes a robust system of criteria and scopes that allow for precise and flexible
  retrieval of data, enabling developers to easily implement complex query logic without cluttering the model layer.
- **Criteria-Based Filtering**: Offers the ability to dynamically add, remove, or modify query criteria on-the-fly,
  providing powerful and reusable components for custom query construction.

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

Repositories carry transient criteria and scope state during a query pipeline.
Register repositories as transient/scoped bindings (`bind` or `scoped`) rather
than `singleton` to avoid state leakage across requests.

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
