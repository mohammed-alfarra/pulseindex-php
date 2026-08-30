<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use PulseIndex\Laravel\OutboxWorker;

/**
 * Low-latency drain of the PulseIndex outbox. The scheduled `pulse:outbox:work`
 * command is the always-on safety net regardless of whether this job runs.
 */
final class DrainOutboxJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly int $batch = 1000)
    {
        $this->onQueue((string) config('pulseindex.outbox.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('pulseindex-outbox'))->dontRelease()->expireAfter(600)];
    }

    public function handle(OutboxWorker $worker): void
    {
        $maxAttempts = (int) config('pulseindex.outbox.max_attempts', 12);

        foreach ($this->connections() as $connection) {
            do {
                $moved = $worker->drain($connection, $this->batch, $maxAttempts);
            } while (($moved['indexed'] + $moved['deleted'] + $moved['requeued'] + $moved['failed']) > 0
                && $moved['claimed'] >= $this->batch);
        }
    }

    /**
     * @return list<string|null>
     */
    private function connections(): array
    {
        $configured = array_values((array) config('pulseindex.outbox.connections', []));

        return $configured === [] ? [null] : $configured;
    }
}
