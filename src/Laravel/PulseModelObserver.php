<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Database\Eloquent\Model;
use PulseIndex\Laravel\Jobs\DrainOutboxJob;

/**
 * Records every Eloquent lifecycle change as a durable {@see Outbox} marker.
 *
 * The marker is written on the model's own connection, inside the same
 * transaction as the model write — so a change is never lost, even if the
 * engine is unreachable at the time. A worker drains markers with retry.
 */
final class PulseModelObserver
{
    public function created(Model $model): void
    {
        $this->enqueue($model, 'upsert');
    }

    public function updated(Model $model): void
    {
        $this->enqueue($model, 'upsert');
    }

    public function restored(Model $model): void
    {
        $this->enqueue($model, 'upsert');
    }

    public function deleted(Model $model): void
    {
        $this->enqueue($model, 'delete');
    }

    public function forceDeleted(Model $model): void
    {
        $this->enqueue($model, 'delete');
    }

    /**
     * @param 'upsert'|'delete' $operation
     */
    private function enqueue(Model $model, string $operation): void
    {
        if (!PulseSync::enabled()) {
            return;
        }

        Outbox::mark($model, $operation);

        if ((bool) config('pulseindex.outbox.dispatch', true)) {
            DrainOutboxJob::dispatch()->afterCommit();
        }
    }
}
