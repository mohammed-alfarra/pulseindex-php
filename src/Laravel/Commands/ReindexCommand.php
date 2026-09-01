<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Commands;

use Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Illuminate\Console\Command;
use PulseIndex\AdminHttpClient;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Laravel\Outbox;
use PulseIndex\Laravel\OutboxWorker;

/**
 * Bootstrap or rebuild the PulseIndex index from the primary database.
 *
 * The engine is a push-sink with no backfill of its own; this is the only path
 * that (re)populates it from existing rows, and the operator tool for clearing
 * the engine's `needs_full_reindex` flag after a total snapshot loss.
 *
 * It enqueues a durable outbox marker per row and drains through the same worker
 * as live changes, so it is safe to run while the application keeps writing —
 * concurrent changes are coalesced, never lost.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'pulse:reindex
        {model? : One model FQCN. Omit to rebuild every pulseindex.searchable_models.}
        {--recovery : Require the engine to be in degraded recovery, then mark it complete. No model argument.}
        {--async : Enqueue only; let pulse:outbox:work drain (not allowed with --recovery).}
        {--chunk=1000 : Rows per DB read / drain batch.}';

    protected $description = 'Rebuild the PulseIndex index from the primary database (bootstrap or recovery).';

    public function handle(ClientInterface $client, AdminHttpClient $admin, OutboxWorker $worker): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $recovery = (bool) $this->option('recovery');
        $async = (bool) $this->option('async');
        $modelArg = $this->argument('model');

        if ($recovery && $modelArg !== null) {
            $this->error('--recovery rebuilds the whole index; run `pulse:reindex --recovery` with no model argument.');

            return self::FAILURE;
        }
        if ($recovery && $async) {
            $this->error('--async cannot be combined with --recovery (recovery must confirm the drain finished).');

            return self::FAILURE;
        }

        $targets = $this->resolveTargets($modelArg);
        if ($targets === null) {
            return self::FAILURE;
        }

        if ($recovery) {
            // A service that is serving does not need a recovery rebuild, and
            // running one anyway would re-push everything for nothing.
            if ($client->servingStatus() === ServingStatus::SERVING) {
                $this->error('The service is serving normally. '
                    . 'Run without --recovery for a normal rebuild.');

                return self::FAILURE;
            }
            $this->warn('The service is not serving — rebuilding every configured model.');
        }

        $enqueued = $this->enqueue($targets, $chunk);
        $this->info(sprintf('Enqueued %s marker(s) across %s model(s).', number_format($enqueued), count($targets)));

        if ($async) {
            $this->info('Run `php artisan pulse:outbox:work` to drain.');

            return self::SUCCESS;
        }

        if (!$this->drain($worker, $chunk)) {
            $this->error('Outbox did not drain cleanly (failed rows present). Fix the cause and re-run; the flag was NOT cleared.');

            return self::FAILURE;
        }

        if ($recovery) {
            try {
                $admin->markReindexComplete();
            } catch (PulseIndexException $e) {
                $this->error('Indexing succeeded but the engine did not clear needs_full_reindex: ' . $e->getMessage());

                return self::FAILURE;
            }
            $this->info('Engine degraded-recovery flag cleared; /ready and Search are restored.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<class-string>|null
     */
    private function resolveTargets(?string $modelArg): ?array
    {
        $classes = $modelArg !== null
            ? [$modelArg]
            : array_values((array) config('pulseindex.searchable_models', []));

        if ($classes === []) {
            $this->error($modelArg === null
                ? 'No model given and pulseindex.searchable_models is empty.'
                : 'No model given.');

            return null;
        }

        foreach ($classes as $class) {
            if (!class_exists($class) || !method_exists($class, 'toPulseEntity')) {
                $this->error("{$class} does not exist or does not use the PulseSearchable trait.");

                return null;
            }
        }

        /** @var list<class-string> $classes */
        return $classes;
    }

    /**
     * @param list<class-string> $targets
     */
    private function enqueue(array $targets, int $chunk): int
    {
        $total = 0;
        foreach ($targets as $class) {
            $rows = (int) $class::query()->count();
            $this->line("→ {$class} ({$rows} rows)");
            $bar = $this->output->createProgressBar($rows);

            $class::query()->chunkById($chunk, function ($models) use (&$total, $bar): void {
                $searchable = $models->filter(
                    static fn ($m): bool => !method_exists($m, 'shouldBePulseSearchable') || $m->shouldBePulseSearchable(),
                );
                $total += Outbox::enqueueMany($searchable);
                $bar->advance($models->count());
            });

            $bar->finish();
            $this->newLine();
        }

        return $total;
    }

    private function drain(OutboxWorker $worker, int $chunk): bool
    {
        $connections = $this->connections();
        $maxAttempts = (int) config('pulseindex.outbox.max_attempts', 12);

        $bar = $this->output->createProgressBar(Outbox::pending($connections));
        $stalledPasses = 0;
        $stalled = false;

        while (true) {
            $before = Outbox::pending($connections);
            if ($before === 0) {
                break;
            }

            foreach ($connections as $connection) {
                $worker->drain($connection, $chunk, $maxAttempts);
            }

            $after = Outbox::pending($connections);
            $bar->advance(max(0, $before - $after));

            if ($after >= $before) {
                if (++$stalledPasses >= 10) {
                    $stalled = true;
                    break;
                }
                sleep(2);
            } else {
                $stalledPasses = 0;
            }
        }

        $bar->finish();
        $this->newLine();

        if ($stalled) {
            $this->error('Outbox stalled — remaining markers are leased or backing off. Retry, or run pulse:outbox:work.');

            return false;
        }

        return Outbox::failed($connections) === 0;
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
