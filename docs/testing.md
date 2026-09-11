# Testing Guide

This guide covers testing patterns for downstream packages that extend `Repository` or implement `CriteriaInterface`.

## Setup

The package exports test utilities through the `SineMacula\Repositories\Testing` namespace. These utilities live in the
`testing/` directory and are not autoloaded in production.

Add the testing namespace to your downstream package's `composer.json`:

```json
{
    "autoload-dev": {
        "psr-4": {
            "SineMacula\\Repositories\\Testing\\": "vendor/sinemacula/laravel-repositories/testing/"
        }
    }
}
```

Run `composer dump-autoload` after adding the mapping.

### Available Utilities

| Utility              | Purpose                                                                                             |
|----------------------|-----------------------------------------------------------------------------------------------------|
| `CriteriaTestHelper` | Apply a criterion to a lightweight query builder without a database connection or service container |
| `InspectsRepository` | Trait providing state observation and mutation methods for repository test doubles                  |
| `CriterionStub`      | Configurable criterion stub for testing repository behavior without writing custom criteria         |

## Pattern 1: Testing a Criterion in Isolation

Test what a criterion does to a query without requiring a database connection, service container, or Orchestra
Testbench.

### When to Use

- Verifying that a criterion adds the expected WHERE clauses, joins, or ordering
- Checking the SQL or bindings produced by a criterion
- Fast unit tests that run without infrastructure

### How It Works

`CriteriaTestHelper::apply()` creates a minimal in-memory SQLite connection via Laravel's Capsule Manager, builds a
fresh Eloquent query builder for a given table name, and passes it to your criterion's `apply()` method. No service
container, no application bootstrap, no configured database.

### Example

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Criteria;

use PHPUnit\Framework\TestCase;
use SineMacula\Repositories\Testing\CriteriaTestHelper;
use App\Repositories\Criteria\ActiveUsersCriterion;

class ActiveUsersCriterionTest extends TestCase
{
    public function testAddsActiveWhereClause(): void
    {
        $builder = CriteriaTestHelper::apply(
            new ActiveUsersCriterion,
            'users'
        );

        // Inspect the generated SQL
        $sql = $builder->toSql();
        $this->assertStringContainsString('"active"', $sql);

        // Inspect the bindings
        $bindings = $builder->getBindings();
        $this->assertContains(1, $bindings);
    }

    public function testDoesNotModifyOtherClauses(): void
    {
        $builder = CriteriaTestHelper::apply(
            new ActiveUsersCriterion,
            'users'
        );

        // Verify the query only has the expected WHERE clause
        $wheres = $builder->getQuery()->wheres;
        $this->assertCount(1, $wheres);
        $this->assertSame('active', $wheres[0]['column']);
    }
}
```

### Limitations

The lightweight path covers query builder method calls (WHERE, ORDER BY, joins, eager-loading declarations, etc.) but
does not execute queries against a real database. For criteria that depend on database-specific behavior (JSON
operators,
full-text search, subqueries against real data), use integration tests with Orchestra Testbench.

## Pattern 2: Testing a Repository Subclass

Test a repository subclass that overrides `boot()` or adds custom query methods, using the `InspectsRepository` trait
for state observation.

### When to Use

- Verifying that `boot()` registers the expected persistent criteria or scopes
- Testing custom query methods on a repository subclass
- Observing criteria state (counts, flags) without building a custom shadow API

### How It Works

Add the `InspectsRepository` trait to your test double. The trait provides methods to observe the repository's criteria
counts, flag values, composing-scope count and model reference, and to mutate that state for edge-case testing. This
eliminates the need for custom public wrapper methods.

Observe the handle a composing call returned, not the one it was called on. `addScope()`, `withCriteria()`,
`useCriteria()`, `skipCriteria()` and `resetScopes()` return a copy, so the composition is on that copy. Scopes
registered with `pushScope()` are counted by `persistentScopesCount()`, and are also observable through the query they
produce.

### Example

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Repositories;

use Illuminate\Contracts\Database\Eloquent\Builder;
use SineMacula\Repositories\Repository;
use SineMacula\Repositories\Testing\Concerns\InspectsRepository;
use App\Models\User;

class TestableUserRepository extends Repository
{
    use InspectsRepository;

    public function model(): string
    {
        return User::class;
    }

    protected function boot(): void
    {
        $this->pushCriteria(new \App\Repositories\Criteria\ActiveUsersCriterion);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use Orchestra\Testbench\TestCase;
use Tests\Support\Repositories\TestableUserRepository;

class UserRepositoryTest extends TestCase
{
    public function testBootRegistersPersistentCriteria(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        // Observe state without custom wrapper methods
        $this->assertTrue($repository->isBooted());
        $this->assertSame(1, $repository->persistentCriteriaCount());
        $this->assertSame(0, $repository->transientCriteriaCount());
        $this->assertFalse($repository->isCriteriaDisabled());
    }

    public function testDisableCriteriaPreventsApplication(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);
        $repository->disableCriteria();

        $this->assertTrue($repository->isCriteriaDisabled());
        $this->assertFalse($repository->isCriteriaSkipped());
    }

    public function testScopeRegistration(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        $composed = $repository->addScope(fn ($query) => $query->orderBy('name'));

        // The scope travels with the copy, not the repository it came from
        $this->assertSame(1, $composed->scopesCount());
        $this->assertSame(0, $repository->scopesCount());
    }
}
```

### InspectsRepository Methods

#### State Observers

| Method                      | Returns                | Description                                           |
|-----------------------------|------------------------|-------------------------------------------------------|
| `persistentCriteriaCount()` | `int`                  | Number of persistent criteria registered              |
| `transientCriteriaCount()`  | `int`                  | Number of transient criteria registered               |
| `scopesCount()`             | `int`                  | Number of scopes composing the next query             |
| `persistentScopesCount()`   | `int`                  | Number of scopes registered for the instance          |
| `isCriteriaDisabled()`      | `bool`                 | Whether persistent criteria are disabled              |
| `isCriteriaSkipped()`       | `bool`                 | Whether the next query will skip all criteria         |
| `isForceUsingCriteria()`    | `bool`                 | Whether criteria are force-enabled for the next query |
| `currentModel()`            | `Builder\|Model\|null` | The current internal model/builder reference          |
| `isBooted()`                | `bool`                 | Whether a model or builder reference is held          |

#### State Mutators

| Method                               | Description                                 |
|--------------------------------------|---------------------------------------------|
| `forceModel($model)`                 | Override the internal model reference       |
| `forcePersistentCriteria($criteria)` | Override the persistent criteria collection |
| `forceTransientCriteria($criteria)`  | Override the transient criteria collection  |

#### Protected Method Wrappers

| Method                          | Description                                  |
|---------------------------------|----------------------------------------------|
| `invokeApplyScopes()`           | Call the protected `applyScopes()` method    |
| `invokeResetAndReturn($result)` | Call the protected `resetAndReturn()` method |

### Asserting a Composition Did Not Leak

Composition returns a copy, so a test for a scope method should assert both halves of the contract: the returned handle
carries the composition, and the handle it was composed from does not. The second assertion is the one that catches a
scope method written to mutate.

```php
public function testScopeEmailVerifiedLeavesTheRepositoryUntouched(): void
{
    $repository = $this->app->make(TestableUserRepository::class);

    $composed = $repository->scopeEmailVerified();

    $this->assertSame(1, $composed->scopesCount());
    $this->assertSame(0, $repository->scopesCount());
}
```

To assert the same thing for a method that fails part-way, catch the failure and then query through the original
handle. Nothing the aborted call composed should appear:

```php
try {
    $repository->scopeEmailVerified()->scopeByTokenThatThrows('token');
} catch (\RuntimeException) {
    // the composition is abandoned
}

$this->assertSame(0, $repository->scopesCount());
$this->assertStringNotContainsString('email_verified_at', $repository->query()->toSql());
```

## Pattern 3: Testing Criteria and Scope Interaction

Test how criteria and scopes interact within a repository, verifying application order and combined query effects.

### When to Use

- Verifying that transient criteria apply before persistent criteria
- Testing that scopes and criteria compose correctly
- Using criterion stubs to observe application order without writing custom criteria

### How It Works

Use `CriterionStub` as a lightweight, configurable criterion that tracks whether and how many times it was applied.
Combine with the `InspectsRepository` trait to observe the full lifecycle.

### Example

```php
<?php

declare(strict_types=1);

namespace Tests\Integration\Repositories;

use Orchestra\Testbench\TestCase;
use SineMacula\Repositories\Testing\CriterionStub;
use Tests\Support\Repositories\TestableUserRepository;

class CriteriaScopeInteractionTest extends TestCase
{
    public function testTransientCriteriaApplyBeforePersistent(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        $order = [];

        $persistent = new CriterionStub(function ($builder) use (&$order) {
            $order[] = 'persistent';
            return $builder;
        });

        $transient = new CriterionStub(function ($builder) use (&$order) {
            $order[] = 'transient';
            return $builder;
        });

        // pushCriteria() is configuration and mutates; withCriteria() composes a copy that inherits it
        $repository->pushCriteria($persistent);

        $repository->withCriteria($transient)->query();

        $this->assertSame(['transient', 'persistent'], $order);
        $this->assertTrue($persistent->wasApplied());
        $this->assertTrue($transient->wasApplied());
    }

    public function testScopesApplyAfterCriteria(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        $order = [];

        $criterion = new CriterionStub(function ($builder) use (&$order) {
            $order[] = 'criterion';
            return $builder;
        });

        $repository->pushCriteria($criterion);

        $repository->addScope(function ($builder) use (&$order): void {
            $order[] = 'scope';
        })->query();

        $repository->query();

        $this->assertSame(['criterion', 'scope'], $order);
    }

    public function testSkippedCriteriaAreNotApplied(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        $stub = new CriterionStub;

        $repository->pushCriteria($stub);

        // skipCriteria() composes a copy, so the query has to be built from it
        $repository->skipCriteria()->query();

        $this->assertFalse($stub->wasApplied());
        $this->assertSame(0, $stub->applyCount());
    }

    public function testCriterionStubApplyCount(): void
    {
        $repository = $this->app->make(TestableUserRepository::class);

        $stub = new CriterionStub;
        $repository->pushCriteria($stub);

        // First query
        $repository->query();
        $this->assertSame(1, $stub->applyCount());

        // Second query - a registered criterion is never consumed
        $repository->query();
        $this->assertSame(2, $stub->applyCount());
    }
}
```

### CriterionStub API

| Member                            | Type        | Description                                                                                                                   |
|-----------------------------------|-------------|-------------------------------------------------------------------------------------------------------------------------------|
| `__construct(?Closure $callback)` | Constructor | Optional callback receives the Builder and must return a Builder. If omitted, the stub passes the builder through unmodified. |
| `applyCount()`                    | `int`       | Number of times `apply()` has been called                                                                                     |
| `wasApplied()`                    | `bool`      | Returns `true` if `apply()` was called at least once                                                                          |
