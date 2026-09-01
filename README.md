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

## Drift detection — `pulse:reconcile`

The outbox covers everything that fires an Eloquent event. Changes that **don't** —
`Model::query()->update()/delete()`, raw SQL, another service on the DB, data from before the
SDK — still drift. `pulse:reconcile` diffs each searchable model against the engine per
tenant and enqueues outbox markers for the **exact** rows that differ (missing → re-push,
orphaned → delete). It never pushes directly.

```bash
php artisan pulse:reconcile                 # report only, exits non-zero on drift
php artisan pulse:reconcile --apply         # enqueue the corrective markers
php artisan pulse:reconcile --full --apply  # skip the cheap "grand totals match" gate
```

```php
$schedule->command('pulse:reconcile --apply')->dailyAt('03:30')->withoutOverlapping();
$schedule->command('pulse:reconcile --full --apply')->weekly()->withoutOverlapping();
// monitoring: run without --apply in CI/health and alert on a non-zero exit.
```

If a model overrides `shouldBePulseSearchable()` with per-row logic, also express it in
`pulseReconcileScope($query, $tenant)` — otherwise reconcile treats rows you exclude in PHP
but keep in the DB as orphans and enqueues deletes for them.

| env | default | purpose |
|---|---|---|
| `PULSEINDEX_RECONCILE_PAGE` | `50000` | entity ids fetched per engine page |
| `PULSEINDEX_RECONCILE_MAX_RECV` | `33554432` | gRPC receive cap for the id pages |
| `PULSEINDEX_RECONCILE_MAX_ORPHANS` / `_MAX_ORPHAN_RATIO` | `10000` / `0.25` | `--apply` refuses to delete more than this without `--force` |
| `PULSEINDEX_RECONCILE_PENDING_THRESHOLD` | `1000` | abort if the outbox is more backed-up than this (counts would be transient) |

`reconcile.tenants` in config pins the tenant list; empty = auto-derive from the
`tenant_id` column.

---

## Monitoring — `pulse:health`

Read-only probe (it reports, never fixes). Exits non-zero when a threshold trips, so a
cron / uptime check can alert.

```bash
php artisan pulse:health          # human summary + exit code
php artisan pulse:health --json   # { healthy, engine:{...}, outbox:{pending,failed,oldest_pending_seconds,latest_error}, checks:[...] }
```

```php
$schedule->command('pulse:health')->everyFiveMinutes();   // + alert on non-zero exit
```

| env | default | unhealthy when |
|---|---|---|
| `PULSEINDEX_HEALTH_MAX_FAILED` | `0` | outbox `failed_at` rows exceed this |
| `PULSEINDEX_HEALTH_MAX_PENDING` | `10000` | outbox backlog exceeds this |
| `PULSEINDEX_HEALTH_MAX_LAG` | `300` | oldest pending marker is older than this (seconds) |

Also unhealthy: engine unreachable, or the engine not serving.

### Readiness comes from the gRPC health service

Reachability and degraded recovery are read from `grpc.health.v1.Health`, which
the engine serves **without** its auth interceptor — no scope required, and no
credential sent.

This matters, and it is not an implementation detail. `GetRecoveryState`, the
obvious place to ask, requires the `admin` scope, and the engine refuses `admin`
to any key bound to a tenant — every key the dashboard issues. Before 2.0.0 both
`pulse:health` and `pulse:reconcile` asked it anyway, so a perfectly healthy
engine was reported unreachable for every customer.

`pulse:health` still reports `indexed_count` when the key is allowed to read it,
but only after reachability has been decided. A key that cannot read the number
no longer makes the engine look down.

The same signal is on the client directly:

```php
use Grpc\Health\V1\HealthCheckResponse\ServingStatus;

$client->health();                       // bool — reachable and serving
$client->servingStatus();                // int  — '' is the whole server
$client->servingStatus('pulseindex.engine.v1.SearchEngineService');
```

`health()` returns `false` rather than throwing, so unreachable and degraded
look alike; use `servingStatus()` to tell them apart.

## Keeping the proto in sync

The SDK vendors `proto/engine.proto`. Two guards cover different failures.

**`vendor/bin/phpunit --testsuite Unit`** pins the vendored schema — every RPC, message,
field number and type, and enum value — in `tests/Unit/ProtoSchemaTest.php`. Any change to
the proto fails the suite until the fixture is updated, so the change lands in the
pull-request diff instead of passing unseen. Seven meta-tests prove the guard bites rather
than merely existing.

It cannot tell you the vendored copy matches the engine. The fixture pins what this
repository believes; if the engine moves ahead and nobody syncs, the file and the fixture
stay stale and agree with each other.

**`composer check:proto`** closes that, in three layers:

1. the vendored proto still matches the stubs generated from it (`engine.proto.sha256`)
2. its schema matches the engine's, reported as semantic differences
3. textual-only drift (comments, ordering) — a warning, not an error

It finds the engine at `PULSEINDEX_PROTO` or a sibling checkout. With neither it exits 0
but prints `NOT VERIFIED` — never `ok` — because a guard that could not reach its source
must not look like a passing guard. `composer check:proto:ci` (`--require-engine`) makes
that state an error instead; CI uses it, so a missing or expired `ENGINE_REPO_TOKEN`
breaks the build rather than silently downgrading the check. A `PULSEINDEX_PROTO` that
points nowhere is always an error — there is no fallback that could hide it.

Regenerate with `composer build-proto` (which rewrites the hash and the stubs), update the
fixture, and commit, whenever the engine's contract changes.

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
