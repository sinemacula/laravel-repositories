<?php

declare(strict_types = 1);

namespace SineMacula\Repositories;

use Illuminate\Support\ServiceProvider;

/**
 * Merges the package configuration and offers it for publishing.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return; // @codeCoverageIgnore
        }

        if (!function_exists('config_path')) {
            return; // @codeCoverageIgnore
        }

        $this->publishes([
            __DIR__ . '/../config/repositories.php' => config_path('repositories.php'),
        ], 'config');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/repositories.php',
            'repositories',
        );
    }
}
