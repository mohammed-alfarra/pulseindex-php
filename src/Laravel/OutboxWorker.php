<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PulseIndex\ClientInterface;
use PulseIndex\Entity;
use Throwable;

/**
 * Drains {@see Outbox} markers into the engine.
 *
 * Leased claim → RPC (no locks / txn held) → optimistic finalize keyed on
 * `revision`, so a change that lands while an RPC is in flight is corrected on
 * the next pass, never lost.
 */
final class OutboxWorker
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    /**
     * One pass over one connection.
     *
     * @return array{claimed:int,indexed:int,deleted:int,requeued:int,failed:int}
     */
    public function drain(?string $connection, int $batch, int $maxAttempts): array
    {
        $db = DB::connection($connection);
        $table = Outbox::table();
        $lease = max(1, (int) config('pulseindex.outbox.lease_seconds', 300));

        $claimed = $this->claim($db, $table, $batch, $lease);
        $result = ['claimed' => $claimed->count(), 'indexed' => 0, 'deleted' => 0, 'requeued' => 0, 'failed' => 0];
        if ($claimed->isEmpty()) {
            return $result;
        }

        [$upserts, $deletes] = $this->bucket($claimed);
        $revisionOf = $claimed->mapWithKeys(fn ($r) => [$r->id => (int) $r->revision])->all();

        foreach ($upserts as $items) {
            $entities = array_map(static fn (array $i): Entity => $i['entity'], $items);
            $ids = array_map(static fn (array $i): int => $i['id'], $items);
            try {
                $this->client->batchIndex($entities);
                foreach ($ids as $id) {
                    $this->finalizeOk($db, $table, $id, $revisionOf[$id]) ? $result['indexed']++ : $result['requeued']++;
                }
            } catch (Throwable $e) {
                foreach ($ids as $id) {
                    $result['failed'] += $this->finalizeErr($db, $table, $id, $e->getMessage(), $maxAttempts);
                }
            }
        }

        foreach ($deletes as $d) {
            try {
                $this->client->deleteEntity($d['entity_id'], $d['tenant_id']);
                $this->finalizeOk($db, $table, $d['id'], $revisionOf[$d['id']]) ? $result['deleted']++ : $result['requeued']++;
            } catch (Throwable $e) {
                $result['failed'] += $this->finalizeErr($db, $table, $d['id'], $e->getMessage(), $maxAttempts);
            }
        }

        return $result;
    }

    private function claim(object $db, string $table, int $batch, int $lease): Collection
    {
        return $db->transaction(function () use ($db, $table, $batch, $lease): Collection {
            $query = $db->table($table)
                ->where('available_at', '<=', now())
                ->whereNull('failed_at')
                ->orderBy('available_at')
                ->limit($batch);

            if (in_array($db->getDriverName(), ['pgsql', 'mysql'], true)) {
                $query->lock('for update skip locked');
            }

            $rows = $query->get();
            if ($rows->isNotEmpty()) {
                $db->table($table)
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update(['available_at' => now()->addSeconds($lease)]);
            }

            return $rows;
        });
    }

    /**
     * @return array{0: array<string, list<array{entity: Entity, id: int}>>, 1: list<array{entity_id: int, tenant_id: string, id: int}>}
     */
    private function bucket(Collection $claimed): array
    {
        $models = [];
        foreach ($claimed->groupBy('model_type') as $type => $rows) {
            if (!is_string($type) || !class_exists($type) || !method_exists($type, 'toPulseEntity')) {
                continue;
            }
            $keyName = $type::query()->getModel()->getKeyName();
            $found = $type::query()->whereIn($keyName, $rows->pluck('model_key')->all())->get()->keyBy($keyName);
            foreach ($rows as $r) {
                $models["{$type}#{$r->model_key}"] = $found->get($r->model_key);
            }
        }

        $upserts = [];
        $deletes = [];
        foreach ($claimed as $r) {
            $model = $models["{$r->model_type}#{$r->model_key}"] ?? null;
            $searchable = $model !== null
                && (!method_exists($model, 'shouldBePulseSearchable') || $model->shouldBePulseSearchable());

            if ($searchable) {
                $entity = $model->toPulseEntity();
                $upserts[$entity->tenantId][] = ['entity' => $entity, 'id' => (int) $r->id];
            } else {
                $deletes[] = [
                    'entity_id' => (int) $r->entity_id,
                    'tenant_id' => (string) $r->tenant_id,
                    'id' => (int) $r->id,
                ];
            }
        }

        return [$upserts, $deletes];
    }

    private function finalizeOk(object $db, string $table, int $id, int $revision): bool
    {
        $deleted = $db->table($table)->where('id', $id)->where('revision', $revision)->delete();
        if ($deleted === 0) {
            // A concurrent change bumped `revision` while the RPC was in flight —
            // keep the marker and re-queue so the newer state is pushed.
            $db->table($table)->where('id', $id)->update(['available_at' => now()]);

            return false;
        }

        return true;
    }

    private function finalizeErr(object $db, string $table, int $id, string $message, int $maxAttempts): int
    {
        $row = $db->table($table)->where('id', $id)->first();
        if ($row === null) {
            return 0;
        }

        $attempts = (int) $row->attempts + 1;
        $backoff = min((2 ** min($attempts, 12)) * 10, 3600);
        $update = [
            'attempts' => $attempts,
            'last_error' => mb_substr($message, 0, 2000),
            'available_at' => now()->addSeconds($backoff),
        ];
        if ($attempts >= $maxAttempts) {
            $update['failed_at'] = now();
        }
        $db->table($table)->where('id', $id)->update($update);

        return 1;
    }
}
