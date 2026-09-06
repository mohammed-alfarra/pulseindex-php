<?php

declare(strict_types=1);

namespace PulseIndex;

final class SearchResult
{
    /**
     * @param list<int> $matchedEntityIds
     * @param bool $totalIsExact Whether $totalMatches is the real number of
     *        matches or the early-exit count from a paged search. A paged
     *        search stops as soon as the page is full, which is what makes it
     *        cost microseconds — and leaves the total far below the truth.
     *        Measured on a million entities: a query with 166,325 matches
     *        reported 10,866 when asked for a page of 100.
     */
    public function __construct(
        public readonly array $matchedEntityIds,
        public readonly int $totalMatches,
        public readonly int $executionTimeUs,
        public readonly bool $totalIsExact = false,
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

    /**
     * The total, but only when it can be relied on.
     *
     * Returns null rather than a number that looks right and is not, so
     * "page 1 of N" cannot be built out of an early-exit count by accident.
     * Use {@see \PulseIndex\Client::searchWithTotal()} to get one.
     */
    public function exactTotal(): ?int
    {
        return $this->totalIsExact ? $this->totalMatches : null;
    }
}
