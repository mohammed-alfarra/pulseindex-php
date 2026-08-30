<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Durable dirty-marker store for model → PulseIndex sync.
 *
 * One row per (model_type, model_key): rapid repeated changes coalesce, and the
 * worker pushes the model's *current* state — never a replay of history.
 */
final class Outbox
{
    public static function table(): string
    {
        return (string) config('pulseindex.outbox.table', 'pulseindex_outbox');
    }

    /**
     * Record a pending change. Written on the model's own connection so it shares
     * the model write's transaction (atomic — no orphan markers on rollback).
     *
     * @param 'upsert'|'delete' $operation
     */
    public static function mark(Model $model, string $operation): void
    {
        if (!PulseSync::enabled() || !method_exists($model, 'toPulseEntity')) {
            return;
        }

        $now = Carbon::now();
        $modelType = $model->getMorphClass();
        $modelKey = (string) $model->getKey();

        $db = DB::connection($model->getConnectionName());
        $table = self::table();

        $refresh = [
            'entity_id' => (int) $model->getPulseEntityId(),
            'tenant_id' => (string) $model->pulseTenantId(),
            'operation' => $operation,
            'attempts' => 0,
            'failed_at' => null,
            'available_at' => $now,
            'updated_at' => $now,
        ];

        $existing = $db->table($table)
            ->where('model_type', $modelType)
            ->where('model_key', $modelKey)
            ->first();

        if ($existing !== null) {
            $db->table($table)->where('id', $existing->id)->update(
                $refresh + ['revision' => DB::raw('revision + 1')],
            );

            return;
        }

        try {
            $db->table($table)->insert($refresh + [
                'model_type' => $modelType,
                'model_key' => $modelKey,
                'revision' => 0,
                'created_at' => $now,
            ]);
        } catch (QueryException $e) {
            // Lost a concurrent first-insert race — fall back to the update path.
            if (!self::isUniqueViolation($e)) {
                throw $e;
            }
            $db->table($table)
                ->where('model_type', $modelType)
                ->where('model_key', $modelKey)
                ->update($refresh + ['revision' => DB::raw('revision + 1')]);
        }
    }

    /**
     * Bulk-enqueue markers for a `pulse:reindex` run. Existing markers are left
     * as-is (already pending); the worker will push their current state anyway.
     *
     * @param iterable<Model> $models
     */
    public static function enqueueMany(iterable $models, string $operation = 'upsert'): int
    {
        $now = Carbon::now();
        $count = 0;
        $buffers = [];

        $flush = function (string $connection) use (&$buffers): void {
            if (empty($buffers[$connection])) {
                return;
            }
            DB::connection($connection === '' ? null : $connection)
                ->table(self::table())
                ->insertOrIgnore($buffers[$connection]);
            $buffers[$connection] = [];
        };

        foreach ($models as $model) {
            if (!method_exists($model, 'toPulseEntity')) {
                continue;
            }
            $connection = (string) ($model->getConnectionName() ?? '');
            $buffers[$connection][] = [
                'model_type' => $model->getMorphClass(),
                'model_key' => (string) $model->getKey(),
                'entity_id' => (int) $model->getPulseEntityId(),
                'tenant_id' => (string) $model->pulseTenantId(),
                'operation' => $operation,
                'revision' => 0,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $count++;
            if (count($buffers[$connection]) >= 1000) {
                $flush($connection);
            }
        }

        foreach (array_keys($buffers) as $connection) {
            $flush($connection);
        }

        return $count;
    }

    /**
     * @param list<string|null> $connections
     */
    public static function pending(array $connections): int
    {
        return self::countAcross($connections, false);
    }

    /**
     * @param list<string|null> $connections
     */
    public static function failed(array $connections): int
    {
        return self::countAcross($connections, true);
    }

    /**
     * @param list<string|null> $connections
     */
    private static function countAcross(array $connections, bool $failed): int
    {
        $total = 0;
        foreach ($connections === [] ? [null] : $connections as $connection) {
            $query = DB::connection($connection)->table(self::table());
            $query = $failed ? $query->whereNotNull('failed_at') : $query->whereNull('failed_at');
            $total += (int) $query->count();
        }

        return $total;
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'unique');
    }
}
