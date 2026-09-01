<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Commands;

use Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Illuminate\Console\Command;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\Outbox;
use PulseIndex\Laravel\ReconcileDiff;
use PulseIndex\Laravel\Reconciler;

/**
 * Defense-in-depth: diff the primary DB against the engine and enqueue outbox
 * markers for the exact rows that drifted (bulk updates, raw SQL, pre-install
 * data, parked markers). Never pushes directly — the worker does.
 *
 * Dry-run by default; `--apply` enqueues. Non-zero exit on unreconciled drift so
 * CI / monitoring can alert.
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'pulse:reconcile
        {model?* : Model FQCNs. Default: pulseindex.searchable_models.}
        {--tenant=* : Tenants. Default: discovered per model.}
        {--full : Deprecated; the sweep always runs and this flag does nothing.}
        {--apply : Enqueue markers (default: report only).}
        {--force : Apply past the orphan-count brake.}';

    protected $description = 'Reconcile the primary DB against the PulseIndex engine.';

    public function handle(ClientInterface $client, Reconciler $reconciler): int
    {
        $models = $this->models();
        if ($models === null) {
            return self::FAILURE;
        }

        // Readiness comes from the health service, which needs no scope.
        if ($client->servingStatus() !== ServingStatus::SERVING) {
            $this->error('Engine is not serving (degraded recovery). Run pulse:reindex --recovery first.');

            return self::FAILURE;
        }

        $connections = array_values((array) config('pulseindex.outbox.connections', [])) ?: [null];
        $pending = Outbox::pending($connections);
        $threshold = (int) config('pulseindex.reconcile.pending_threshold', 1000);
        if ($pending > $threshold) {
            $this->error("Outbox has {$pending} pending markers (> {$threshold}); counts are transient. Drain first.");

            return self::FAILURE;
        }

        /** @var list<array{0: class-string, 1: string}> $targets */
        $targets = [];
        foreach ($models as $model) {
            foreach ($this->tenants($reconciler, $model) as $tenant) {
                $targets[] = [$model, $tenant];
            }
        }

        // The shortcut that compared grand totals before sweeping is gone: it
        // needed an index-wide count that only an operator key can read. Every
        // run now walks the models, which is slower and gives the same answer.

        /** @var list<ReconcileDiff> $diffs */
        $diffs = [];
        foreach ($targets as [$model, $tenant]) {
            $diffs[] = $reconciler->plan($model, $tenant);
        }
        $this->render($diffs);

        $drifted = array_values(array_filter($diffs, static fn (ReconcileDiff $d): bool => !$d->inSync()));
        if ($drifted === []) {
            $this->info('In sync.');

            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->warn('Drift found. Re-run with --apply to enqueue markers.');

            return self::FAILURE;
        }

        $maxOrphans = (int) config('pulseindex.reconcile.max_orphans', 10_000);
        $ratio = (float) config('pulseindex.reconcile.max_orphan_ratio', 0.25);
        $upserts = 0;
        $deletes = 0;
        foreach ($drifted as $diff) {
            $brake = max($maxOrphans, (int) ($ratio * $diff->engineCount));
            if (count($diff->orphanIds) > $brake && !$this->option('force')) {
                $this->error(sprintf(
                    '%s / %s: %d orphans exceeds the brake (%d). Check pulseReconcileScope / --tenant, or pass --force.',
                    class_basename($diff->model), $diff->tenant, count($diff->orphanIds), $brake,
                ));

                return self::FAILURE;
            }

            $r = $reconciler->apply($diff);
            $upserts += $r['enqueued_upserts'];
            $deletes += $r['enqueued_deletes'];
        }

        $this->info(sprintf('Enqueued %d upsert + %d delete marker(s). Run pulse:outbox:work to drain.', $upserts, $deletes));

        return self::SUCCESS;
    }

    /**
     * @return list<class-string>|null
     */
    private function models(): ?array
    {
        $arg = (array) $this->argument('model');
        $classes = $arg !== [] ? $arg : array_values((array) config('pulseindex.searchable_models', []));

        if ($classes === []) {
            $this->error('No models given and pulseindex.searchable_models is empty.');

            return null;
        }
        foreach ($classes as $class) {
            if (!class_exists($class) || !method_exists($class, 'pulseReconcileScope')) {
                $this->error("{$class} does not exist or does not use the PulseSearchable trait.");

                return null;
            }
        }

        /** @var list<class-string> $classes */
        return $classes;
    }

    /**
     * @param class-string $model
     * @return list<string>
     */
    private function tenants(Reconciler $reconciler, string $model): array
    {
        $opt = array_values(array_filter(array_map('strval', (array) $this->option('tenant'))));

        return $opt !== [] ? $opt : $reconciler->tenantsFor($model);
    }


    /**
     * @param list<ReconcileDiff> $diffs
     */
    private function render(array $diffs): void
    {
        $this->table(
            ['model', 'tenant', 'db', 'engine', 'missing', 'orphans'],
            array_map(static fn (ReconcileDiff $d): array => [
                class_basename($d->model), $d->tenant, $d->dbCount, $d->engineCount,
                count($d->missingIds), count($d->orphanIds),
            ], $diffs),
        );
    }
}
