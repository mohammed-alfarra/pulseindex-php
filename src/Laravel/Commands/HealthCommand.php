<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Commands;

use Illuminate\Console\Command;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\Outbox;
use Throwable;

/**
 * Read-only health probe for monitoring: engine reachability + degraded state,
 * and outbox backlog / failures / lag. Exits non-zero when a threshold trips.
 * It reports — it never fixes.
 */
final class HealthCommand extends Command
{
    protected $signature = 'pulse:health {--json : Machine-readable output}';

    protected $description = 'Report PulseIndex connector health (engine + outbox).';

    public function handle(ClientInterface $client): int
    {
        $connections = array_values((array) config('pulseindex.outbox.connections', [])) ?: [null];

        $reachable = true;
        $needsFullReindex = false;
        $indexedCount = null;
        try {
            $state = $client->getRecoveryState();
            $needsFullReindex = $state->needsFullReindex;
            $indexedCount = $state->indexedCount;
        } catch (Throwable $e) {
            $reachable = false;
            $engineError = $e->getMessage();
        }

        $pending = Outbox::pending($connections);
        $failed = Outbox::failed($connections);
        $lag = Outbox::oldestPendingSeconds($connections);
        $latestError = Outbox::latestError($connections);

        $maxPending = (int) config('pulseindex.health.max_pending', 10_000);
        $maxFailed = (int) config('pulseindex.health.max_failed', 0);
        $maxLag = (int) config('pulseindex.health.max_lag_seconds', 300);

        $checks = [
            $this->check('engine_reachable', $reachable, $reachable ? null : ($engineError ?? 'unreachable')),
            $this->check('engine_not_degraded', !$needsFullReindex, $needsFullReindex ? 'needs_full_reindex' : null),
            $this->check('outbox_no_failed', $failed <= $maxFailed, "failed={$failed} (> {$maxFailed})"),
            $this->check('outbox_backlog', $pending <= $maxPending, "pending={$pending} (> {$maxPending})"),
            $this->check('outbox_lag', $lag === null || $lag <= $maxLag, "lag={$lag}s (> {$maxLag})"),
        ];
        $healthy = !in_array(false, array_column($checks, 'ok'), true);

        $payload = [
            'healthy' => $healthy,
            'engine' => [
                'reachable' => $reachable,
                'needs_full_reindex' => $needsFullReindex,
                'indexed_count' => $indexedCount,
            ],
            'outbox' => [
                'pending' => $pending,
                'failed' => $failed,
                'oldest_pending_seconds' => $lag,
                'latest_error' => $latestError,
            ],
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->line($healthy ? '<info>PulseIndex: healthy</info>' : '<error>PulseIndex: UNHEALTHY</error>');
        foreach ($checks as $c) {
            $mark = $c['ok'] ? '<info>✓</info>' : '<error>✗</error>';
            $this->line("  {$mark} {$c['name']}" . ($c['ok'] || $c['detail'] === null ? '' : " — {$c['detail']}"));
        }
        $this->line(sprintf(
            '  engine: reachable=%s degraded=%s indexed=%s | outbox: pending=%d failed=%d lag=%s',
            $reachable ? 'yes' : 'no',
            $needsFullReindex ? 'yes' : 'no',
            $indexedCount ?? '?',
            $pending, $failed, $lag === null ? '-' : "{$lag}s",
        ));
        if ($latestError !== null) {
            $this->line('  latest outbox error: ' . $latestError);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{name: string, ok: bool, detail: string|null}
     */
    private function check(string $name, bool $ok, ?string $failDetail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $ok ? null : $failDetail];
    }
}
