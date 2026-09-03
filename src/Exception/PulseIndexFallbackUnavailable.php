<?php

declare(strict_types=1);

namespace PulseIndex\Exception;

/**
 * The engine could not be reached and the Eloquent fallback cannot stand in
 * for it without changing the answer.
 *
 * Attribute filters are tags, not columns. Where a model has not said which
 * column answers which namespace — see `pulseFallbackMap()` — the fallback has
 * nothing to translate the query into, and running it anyway would return rows
 * for a different question with nothing to mark them as such.
 */
final class PulseIndexFallbackUnavailable extends PulseIndexException
{
    /**
     * @param list<string> $fields
     */
    public static function forFields(array $fields, string $modelClass): self
    {
        $list = implode(', ', $fields);

        return new self(
            "PulseIndex is unreachable and the Eloquent fallback cannot answer this query: "
            ."{$modelClass} has no pulseFallbackMap() entry for [{$list}]. "
            ."Declare the columns that answer those attributes, or handle the connection failure."
        );
    }
}
