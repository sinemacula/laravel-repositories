<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Log;
use SineMacula\Repositories\Exceptions\RepositoryException;

/**
 * Transient-state cleanup helper for the failure path of the magic method
 * forwarding pipeline.
 *
 * A failed forwarded call must leave the next query composing from a clean
 * builder rather than one carrying the failed call's transient criteria,
 * scopes, or model state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ResetsTransientState
{
    /**
     * Reset transient state after a failed forwarded call so the next query
     * starts from a clean builder instead of re-applying criteria and scopes
     * onto the dirty one.
     *
     * A model re-resolution failure here is deliberately not rethrown: the
     * original exception is the one propagating to the caller, and nulling the
     * model guarantees the next prepareQueryBuilder() rebuilds it (surfacing
     * any resolution failure at that point). The re-resolution failure is
     * logged so it does not vanish silently.
     *
     * @return void
     */
    protected function resetAfterFailure(): void
    {
        $this->resetTransientCriteria();
        $this->resetScopes();

        try {
            $this->resetModel();
        } catch (BindingResolutionException|RepositoryException $exception) {

            Log::error('Model re-resolution failed during failure cleanup', ['exception' => $exception]);

            $this->model = null;
        }
    }
}
