<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Keeps PulseIndex in sync with Eloquent lifecycle events.
 */
final class PulseModelObserver
{
    public function created(Model $model): void
    {
        $this->safely($model, static fn () => $model->pulseIndex());
    }

    public function updated(Model $model): void
    {
        $this->safely($model, static fn () => $model->pulseIndex());
    }

    public function deleted(Model $model): void
    {
        $this->safely($model, static fn () => $model->pulseUnindex());
    }

    public function restored(Model $model): void
    {
        $this->safely($model, static fn () => $model->pulseIndex());
    }

    public function forceDeleted(Model $model): void
    {
        $this->safely($model, static fn () => $model->pulseUnindex());
    }

    private function safely(Model $model, callable $action): void
    {
        if (!PulseSync::enabled()) {
            return;
        }

        try {
            $action();
        } catch (\Throwable $e) {
            Log::warning('PulseIndex model sync failed.', [
                'model' => $model::class,
                'id' => $model->getKey(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
