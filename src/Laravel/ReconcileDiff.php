<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

/**
 * Result of comparing one model's rows against the engine for one tenant.
 */
final class ReconcileDiff
{
    /**
     * @param class-string $model
     * @param list<int|string> $missingIds  in the DB, absent from the engine → re-push
     * @param list<int>        $orphanIds   in the engine, absent from the DB → delete
     */
    public function __construct(
        public readonly string $model,
        public readonly string $tenant,
        public readonly int $dbCount,
        public readonly int $engineCount,
        public readonly array $missingIds,
        public readonly array $orphanIds,
    ) {
    }

    public function inSync(): bool
    {
        return $this->missingIds === [] && $this->orphanIds === [];
    }
}
