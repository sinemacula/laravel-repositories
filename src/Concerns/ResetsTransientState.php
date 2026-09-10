<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Transient-state cleanup helper for the failure path of the query composition
 * pipeline.
 *
 * A failed call must leave the next query composing from a clean builder rather
 * than one carrying the failed call's transient criteria, scopes, one-shot
 * criteria flags, collected declarations, or model state.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ResetsTransientState
{
    /**
     * Reset transient state after a failed call so the next query starts from a
     * clean builder instead of re-applying criteria and scopes onto the dirty
     * one.
     *
     * A model re-resolution failure here is deliberately not rethrown: the
     * original exception is the one propagating to the caller, and nulling the
     * model guarantees the next prepareQueryBuilder() rebuilds it (surfacing
     * any resolution failure at that point). Every throwable is caught, not
     * just a resolution failure, because one escaping from here would both mask
     * the original exception and leave the dirty builder in place. The failure
     * is logged so it does not vanish silently.
     *
     * @return void
     */
    protected function resetAfterFailure(): void
    {
        $this->resetCriteriaState();
        $this->resetScopes();

        try {
            $this->resetModel();
        } catch (\Throwable $exception) {

            Log::error('Model re-resolution failed during failure cleanup', ['exception' => $exception]);

            $this->model = null;
        }
    }
}
