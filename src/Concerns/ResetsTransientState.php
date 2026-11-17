<?php

declare(strict_types = 1);

namespace SineMacula\Repositories\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Clears a failed call's transient state so the next query starts clean.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
trait ResetsTransientState
{
    /**
     * Reset transient state after a failed call.
     *
     * A failure here is logged rather than thrown so it cannot mask the
     * original exception; the next query rebuilds the model.
     *
     * @return void
     */
    protected function resetAfterFailure(): void
    {
        $this->resetCriteriaState();
        $this->clearComposingScopes();

        try {
            $this->resetModel();
        } catch (\Throwable $exception) {

            Log::error('Model re-resolution failed during failure cleanup', ['exception' => $exception]);

            $this->model = null;
        }
    }
}
