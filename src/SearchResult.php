<?php

declare(strict_types=1);

namespace PulseIndex;

final class SearchResult
{
    /**
     * @param list<int> $matchedEntityIds
     */
    public function __construct(
        public readonly array $matchedEntityIds,
        public readonly int $totalMatches,
        public readonly int $executionTimeUs,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->matchedEntityIds === [];
    }

    public function count(): int
    {
        return count($this->matchedEntityIds);
    }
}
