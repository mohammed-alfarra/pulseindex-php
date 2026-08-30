<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Commands;

use Illuminate\Console\Command;
use PulseIndex\Laravel\OutboxWorker;

/**
 * Drains the PulseIndex outbox. Run `--once` on the scheduler
 * (`->everyMinute()->withoutOverlapping()`) and/or as a long-running daemon
 * under Supervisor.
 */
final class OutboxWorkCommand extends Command
{
    protected $signature = 'pulse:outbox:work
        {--connection=* : Connections to drain (default: pulseindex.outbox.connections)}
        {--once : One pass then exit}
        {--batch=1000 : Rows per claim}
        {--sleep=1 : Seconds to idle when there is nothing to do (daemon mode)}
        {--max-attempts= : Override pulseindex.outbox.max_attempts}';

    protected $description = 'Drain the PulseIndex outbox into the engine, with retry.';

    public function handle(OutboxWorker $worker): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $sleep = max(0, (int) $this->option('sleep'));
        $maxAttempts = (int) ($this->option('max-attempts') ?: config('pulseindex.outbox.max_attempts', 12));

        $connections = $this->option('connection') !== []
            ? $this->option('connection')
            : (array_values((array) config('pulseindex.outbox.connections', [])) ?: [null]);

        $stopping = false;
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$stopping): void {
                $stopping = true;
            });
        }

        do {
            $movedAny = false;
            foreach ($connections as $connection) {
                $r = $worker->drain($connection === '' ? null : $connection, $batch, $maxAttempts);
                if ($r['claimed'] > 0) {
                    $movedAny = true;
                    $this->line(sprintf(
                        '[%s] claimed=%d indexed=%d deleted=%d requeued=%d failed=%d',
                        $connection ?: 'default',
                        $r['claimed'], $r['indexed'], $r['deleted'], $r['requeued'], $r['failed'],
                    ));
                }
            }

            if ($this->option('once') || $stopping) {
                break;
            }
            if (!$movedAny && $sleep > 0) {
                sleep($sleep);
            }
        } while (!$stopping);

        return self::SUCCESS;
    }
}
