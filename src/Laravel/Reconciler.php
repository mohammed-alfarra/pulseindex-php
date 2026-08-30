<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PulseIndex\ClientInterface;
use PulseIndex\QueryBuilder;

/**
 * Diffs the primary DB against the engine and enqueues outbox markers for the
 * exact rows that drifted. Makes no direct RPC writes — the worker does the push.
 */
final class Reconciler
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * @param class-string $modelClass
     */
    public function plan(string $modelClass, string $tenant): ReconcileDiff
    {
        $model = $modelClass::query()->getModel();
        $keyName = $model->getKeyName();

        $dbIds = $modelClass::query()
            ->tap(fn ($q) => $model->pulseReconcileScope($q, $tenant))
            ->pluck($keyName);
        $dbSet = [];
        foreach ($dbIds as $id) {
            $dbSet[(string) $id] = true;
        }

        $engineSet = [];
        foreach ($this->engineIds($tenant) as $id) {
            $engineSet[(string) $id] = true;
        }

        $missing = [];
        foreach (array_keys($dbSet) as $id) {
            if (!isset($engineSet[$id])) {
                $missing[] = is_numeric($id) ? (int) $id : $id;
            }
        }

        $orphans = [];
        foreach (array_keys($engineSet) as $id) {
            if (!isset($dbSet[$id])) {
                $orphans[] = (int) $id;
            }
        }

        return new ReconcileDiff(
            model: $modelClass,
            tenant: $tenant,
            dbCount: count($dbSet),
            engineCount: count($engineSet),
            missingIds: $missing,
            orphanIds: $orphans,
        );
    }

    /**
     * @return array{enqueued_upserts: int, enqueued_deletes: int}
     */
    public function apply(ReconcileDiff $diff): array
    {
        $upserts = 0;
        foreach (array_chunk($diff->missingIds, 1000) as $chunk) {
            $models = $diff->model::query()->whereIn(
                $diff->model::query()->getModel()->getKeyName(),
                $chunk,
            )->get();
            $upserts += Outbox::enqueueMany($models, 'upsert');
        }

        $deletes = 0;
        if ($diff->orphanIds !== []) {
            $deletes = $this->enqueueOrphanDeletes($diff);
        }

        return ['enqueued_upserts' => $upserts, 'enqueued_deletes' => $deletes];
    }

    private function enqueueOrphanDeletes(ReconcileDiff $diff): int
    {
        $now = Carbon::now();
        $connection = $diff->model::query()->getModel()->getConnectionName();
        $table = Outbox::table();
        $written = 0;

        foreach (array_chunk($diff->orphanIds, 1000) as $chunk) {
            $rows = array_map(static fn (int $id): array => [
                'model_type' => $diff->model,
                'model_key' => (string) $id,
                'entity_id' => $id,
                'tenant_id' => $diff->tenant,
                'operation' => 'delete',
                'revision' => 0,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::connection($connection)->table($table)->insertOrIgnore($rows);
            $written += count($rows);
        }

        return $written;
    }

    /**
     * Every live entity id the engine holds for `$tenant`, paged.
     *
     * @return Generator<int, int>
     */
    public function engineIds(string $tenant): Generator
    {
        $page = max(1, (int) config('pulseindex.reconcile.page_size', 50_000));
        $offset = 0;

        while (true) {
            $result = $this->client->search(
                (new QueryBuilder())->tenant($tenant)->limit($page)->offset($offset),
            );

            $ids = $result->matchedEntityIds;
            foreach ($ids as $id) {
                yield (int) $id;
            }

            if (count($ids) < $page) {
                return;
            }
            $offset += $page;
        }
    }

    /**
     * @param class-string $modelClass
     * @return list<string>
     */
    public function tenantsFor(string $modelClass): array
    {
        $configured = array_values(array_filter(array_map(
            'strval',
            (array) config('pulseindex.reconcile.tenants', []),
        )));
        if ($configured !== []) {
            return $configured;
        }

        $model = $modelClass::query()->getModel();
        $column = (string) config('pulseindex.reconcile.tenant_column', 'tenant_id');
        $default = (string) config('pulseindex.tenant_id', '');

        $tenants = [$default];
        if ($column !== '' && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column)) {
            foreach ($modelClass::query()->distinct()->pluck($column) as $t) {
                $tenants[] = (string) ($t ?? $default);
            }
        }

        return array_values(array_unique($tenants));
    }
}
