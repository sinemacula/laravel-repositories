# Laravel Repositories

[![Latest Stable Version](https://img.shields.io/packagist/v/sinemacula/laravel-repositories.svg)](https://packagist.org/packages/sinemacula/laravel-repositories)
[![Build Status](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml/badge.svg?branch=master)](https://github.com/sinemacula/laravel-repositories/actions/workflows/tests.yml)
[![StyleCI](https://github.styleci.io/repos/787662248/shield?style=flat&branch=master)](https://github.styleci.io/repos/787662248)
[![Maintainability](https://api.codeclimate.com/v1/badges/d7efec236c6db6d92f2d/maintainability)](https://codeclimate.com/github/sinemacula/laravel-repositories/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/d7efec236c6db6d92f2d/test_coverage)](https://codeclimate.com/github/sinemacula/laravel-repositories/test_coverage)
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

## Configuration

After installation, publish the package configuration to customize it according to your needs:

```bash
php artisan vendor:publish --provider="SineMacula\Repositories\RepositoryServiceProvider"
```

This command publishes the package configuration file to your application's config directory.

## Usage

Coming soon...

## Contributing

Contributions are welcome and will be fully credited. We accept contributions via pull requests on GitHub.

## Security

If you discover any security related issues, please email instead of using the issue tracker.

## License

The Laravel Repositories repository is open-sourced software licensed under
the [Apache License, Version 2.0](https://www.apache.org/licenses/LICENSE-2.0).
