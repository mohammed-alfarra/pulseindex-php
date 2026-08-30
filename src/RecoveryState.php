<?php

declare(strict_types=1);

namespace PulseIndex;

/**
 * Snapshot / recovery state reported by the engine (`GetRecoveryState`).
 */
final class RecoveryState
{
    public function __construct(
        public readonly int $lastCdcOffset,
        public readonly int $indexedCount,
        public readonly int $chunkCount,
        public readonly int $mutationsSinceSnapshot,
        /**
         * True when the engine lost every snapshot generation at cold boot: the
         * index is empty, `Search` returns UNAVAILABLE, and the ingestion pipeline
         * must re-push every live entity, then POST /recovery/reindex-complete on
         * the admin port. `lastCdcOffset` is a bookmark, never a recovery cursor.
         */
        public readonly bool $needsFullReindex,
    ) {
    }
}
