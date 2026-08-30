# PulseIndex PHP / Laravel SDK

Official PHP and Laravel client for **PulseIndex** — the Ultra-Fast, Low-Memory Sidecar Retrieval Core.

[![Version](https://img.shields.io/badge/version-v1.0.0-blue.svg)](https://packagist.org/packages/pulseindex/pulseindex-php)
[![Tests](https://img.shields.io/badge/tests-31%20passed-brightgreen.svg)]()

## Key Features

- **Microsecond-Class Retrieval:** Queries executed over gRPC returning ordered matching candidate IDs in tens of microseconds.
- **Rank-Preserving Eloquent Hydration:** Hydrates Eloquent models using `whereIn('id', $ids)` while strictly maintaining the score and rank order returned by PulseIndex.
- **Automatic Event Sync:** Observer hooks (`created`, `updated`, `deleted`, `restored`, `forceDeleted`) keep PulseIndex in sync automatically.
- **Graceful Fallback:** If the PulseIndex engine is unreachable, automatically logs a warning and falls back to native Eloquent DB logic without breaking user experience.

---

## Installation

Install the package via Composer:

```bash
composer require pulseindex/pulseindex-php
```

Requires PHP 8.2+ and `ext-grpc`. Laravel 11 / 12 auto-discovers `PulseIndexServiceProvider`.

Publish the config:

```bash
php artisan vendor:publish --tag=pulseindex-config
```

```env
PULSEINDEX_HOST=localhost:50051
PULSEINDEX_API_KEY=
PULSEINDEX_SSL=false
PULSEINDEX_TIMEOUT=5
PULSEINDEX_FALLBACK_ENABLED=true
```

Production customer gRPC must set `PULSEINDEX_SSL=true` (or `ssl: true` on `Client::create`). The default is plaintext for local development. Do not treat CIDR isolation as encryption.

---

## Laravel usage

```php
use Illuminate\Database\Eloquent\Model;
use PulseIndex\Laravel\PulseSearchable;

class Property extends Model
{
    use PulseSearchable;

    public function toPulseSearchableArray(): array
    {
        return [
            'categories' => $this->tags,
            'status' => $this->status,
            'price' => $this->price,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}

$hits = Property::pulseSearch()
    ->where('feature:pool')
    ->whereIn('amenity', ['parking', 'gym'])
    ->whereRange('price', 1000, 5000)
    ->whereGeoRadius(24.7136, 46.6753, 5)
    ->limit(20)
    ->get();

$page = Property::pulseSearch()->paginate(15);
```

`get()` / `paginate()` search PulseIndex over gRPC, then hydrate with `Model::whereIn('id', $pulseIds)` in rank order. If the connection fails, a warning is logged and Eloquent is used when `fallback_enabled` is `true`.

---

## Sync: durable outbox

Every `PulseSearchable` model change (create / update / delete / restore) writes a **marker
row** to `pulseindex_outbox` — on the model's own connection, inside the same transaction —
and a worker drains markers into the engine with retry. A brief engine outage never loses a
change; repeated edits to one row coalesce and the worker pushes its **current** state.

Publish + run the migration on every connection that has searchable models:

```bash
php artisan vendor:publish --tag=pulseindex-migrations
php artisan migrate
```

Drain the outbox — schedule the safety-net pass and/or run a daemon:

```php
// app/Console/Kernel.php  (or routes/console.php on Laravel 11+)
$schedule->command('pulse:outbox:work --once')->everyMinute()->withoutOverlapping();
```

```bash
# long-running worker (Supervisor / Horizon), like queue:work
php artisan pulse:outbox:work
```

When `outbox.dispatch` is `true` (default) each change also dispatches a `DrainOutboxJob`
for low latency; the scheduled `--once` pass covers a dead queue or a failed job.

| env | default | purpose |
|---|---|---|
| `PULSEINDEX_OUTBOX_CONNECTIONS` | `DB_CONNECTION` | comma list of connections the worker drains |
| `PULSEINDEX_OUTBOX_DISPATCH` | `true` | also queue a drain job on each change |
| `PULSEINDEX_OUTBOX_QUEUE` | `default` | queue for `DrainOutboxJob` |
| `PULSEINDEX_OUTBOX_LEASE` | `300` | seconds a claimed marker is hidden from other workers |
| `PULSEINDEX_OUTBOX_MAX_ATTEMPTS` | `12` | after this many failed pushes a marker is parked (`failed_at`) |

---

## Bootstrapping & recovery — `pulse:reindex`

The observer only syncs *future* saves, so existing rows need one rebuild. `pulse:reindex`
enqueues a marker per row and drains through the same worker — **safe to run while the app
keeps writing**; concurrent changes coalesce, nothing is lost.

```bash
# Initial import of one model
php artisan pulse:reindex "App\Models\Property"

# Rebuild every configured model
php artisan pulse:reindex

# After a needs_full_reindex alert (total snapshot loss on the engine):
#   rebuilds every model, drains, then POSTs /recovery/reindex-complete
php artisan pulse:reindex --recovery

# enqueue only; let pulse:outbox:work drain
php artisan pulse:reindex --async
```

| key | env | purpose |
|---|---|---|
| `searchable_models` | — | FQCNs for no-arg / `--recovery` mode |
| `admin_url` | `PULSEINDEX_ADMIN_URL` | engine admin base URL; derived from `host` + `admin_port` if unset |
| `admin_port` | `PULSEINDEX_ADMIN_PORT` | default `8081` |
| `internal_token` | `PULSEINDEX_ENGINE_INTERNAL_TOKEN` | required for `--recovery` |

`--recovery` aborts if the engine reports `needs_full_reindex == false`, or if any marker
lands in `failed_at` — **without** clearing the flag. See
[`pulseindex-engine/docs/ingestion-recovery.md`](../pulseindex-engine/docs/ingestion-recovery.md).

---

## PHP client

```php
use PulseIndex\Client;
use PulseIndex\Entity;

$client = Client::create('localhost:50051', 'your-api-key');
// Production (TLS terminated at the engine edge, or native TLS):
// $client = Client::create('engine.example.com:443', getenv('PULSEINDEX_API_KEY'), true);

$client->index(new Entity(
    entityId: 1001,
    categories: ['feature:pool', 'amenity:parking'],
    price: 1500,
    tenantId: 'acme',
));

$result = $client->search(
    $client->query()
        ->tenant('acme')
        ->must('feature:pool')
        ->range('price', 1000, 5000)
        ->withinRadius(24.7136, 46.6753, 5)
        ->limit(50)
);

$ids = $result->matchedEntityIds;
```
