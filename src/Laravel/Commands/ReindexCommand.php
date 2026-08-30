<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Commands;

use Illuminate\Console\Command;
use PulseIndex\AdminHttpClient;
use PulseIndex\ClientInterface;
use PulseIndex\Entity;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Laravel\PulseSync;
use Throwable;

/**
 * Bootstrap or rebuild the PulseIndex index from the primary database.
 *
 * The engine is a push-sink with no backfill of its own; this is the only path
 * that (re)populates it from existing rows. Also the operator tool for clearing
 * the engine's `needs_full_reindex` flag after a total snapshot loss.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'pulse:reindex
        {model? : One model FQCN. Omit to rebuild every pulseindex.searchable_models.}
        {--recovery : Require the engine to be in degraded recovery, then mark it complete. No model argument.}
        {--chunk=1000 : Rows per batchIndex call.}';

    protected $description = 'Rebuild the PulseIndex index from the primary database (bootstrap or recovery).';

    public function handle(ClientInterface $client, AdminHttpClient $admin): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $recovery = (bool) $this->option('recovery');
        $modelArg = $this->argument('model');

        if ($recovery && $modelArg !== null) {
            $this->error('--recovery rebuilds the whole index; run `pulse:reindex --recovery` with no model argument.');

            return self::FAILURE;
        }

        $targets = $this->resolveTargets($modelArg);
        if ($targets === null) {
            return self::FAILURE;
        }

        if ($recovery) {
            $state = $client->getRecoveryState();
            if (!$state->needsFullReindex) {
                $this->error('Engine is not in degraded recovery (needs_full_reindex is false). '
                    . 'Run without --recovery for a normal rebuild.');

                return self::FAILURE;
            }
            $this->warn('Engine is in degraded recovery — rebuilding every configured model, then clearing the flag.');
        }

        $this->warn('pulse:reindex is NOT atomic with concurrent writes: the observer is disabled for the run, '
            . 'so rows changed mid-scan may be pushed stale. Prefer a quiet window until the outbox lands.');

        try {
            $totals = PulseSync::withoutSyncing(fn (): array => $this->reindexAll($targets, $chunk, $client));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Indexed %s entities across %s model(s) in %s batch(es); skipped %s not-searchable rows.',
            number_format($totals['indexed']),
            count($targets),
            number_format($totals['chunks']),
            number_format($totals['skipped']),
        ));

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
     * @return list<class-string>|null null on a resolution error (already reported)
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
     * @return array{indexed: int, skipped: int, chunks: int}
     */
    private function reindexAll(array $targets, int $chunk, ClientInterface $client): array
    {
        $indexed = 0;
        $skipped = 0;
        $chunks = 0;

        foreach ($targets as $class) {
            $total = (int) $class::query()->count();
            $this->line("→ {$class} ({$total} rows)");
            $bar = $this->output->createProgressBar($total);

            /** @var list<Entity> $buffer */
            $buffer = [];
            $chunkIndex = 0;
            $firstId = null;
            $lastId = null;

            $flush = function () use (&$buffer, &$chunks, &$chunkIndex, &$firstId, &$lastId, $class, $client): void {
                if ($buffer === []) {
                    return;
                }
                $chunkIndex++;
                try {
                    $client->batchIndex($buffer);
                } catch (Throwable $e) {
                    throw new PulseIndexException(
                        "{$class} failed at chunk {$chunkIndex} (ids {$firstId}..{$lastId}): {$e->getMessage()}",
                        0,
                        $e,
                    );
                }
                $chunks++;
                $buffer = [];
                $firstId = null;
            };

            foreach ($class::query()->lazyById($chunk) as $model) {
                $bar->advance();

                if (method_exists($model, 'shouldBePulseSearchable') && !$model->shouldBePulseSearchable()) {
                    $skipped++;
                    continue;
                }

                $firstId ??= $model->getKey();
                $lastId = $model->getKey();
                $buffer[] = $model->toPulseEntity();
                $indexed++;

                if (count($buffer) >= $chunk) {
                    $flush();
                }
            }

            $flush();
            $bar->finish();
            $this->newLine();
        }

        return ['indexed' => $indexed, 'skipped' => $skipped, 'chunks' => $chunks];
    }
}
