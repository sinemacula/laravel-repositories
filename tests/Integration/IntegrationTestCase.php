<?php

declare(strict_types = 1);

namespace Tests\Integration;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Base class for integration tests.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Set up the integration schema.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    /**
     * Configure the test environment.
     *
     * @param  mixed  $app
     * @return void
     */
    #[\Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        if (!$app instanceof Application) {
            return;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * Create the database schema used by integration tests.
     *
     * @return void
     */
    private function createSchema(): void
    {
        Schema::dropIfExists('test_users');

        Schema::create('test_users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
    }
}
