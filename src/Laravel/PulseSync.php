<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

/**
 * Process-wide toggle for Eloquent → PulseIndex observers.
 */
final class PulseSync
{
    private static int $disabledDepth = 0;

    public static function withoutSyncing(callable $callback): mixed
    {
        self::$disabledDepth++;

        try {
            return $callback();
        } finally {
            self::$disabledDepth--;
        }
    }

    public static function enabled(): bool
    {
        return self::$disabledDepth === 0;
    }
}
