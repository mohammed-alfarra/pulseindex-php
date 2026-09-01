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
         * True when the tenant's index must be rebuilt from the primary store
         * before it can serve queries. While set, `Search` returns UNAVAILABLE.
         * `lastCdcOffset` is a bookmark, never a resume cursor in this state.
         */
        public readonly bool $needsFullReindex,
    ) {
    }
}
