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
PULSEINDEX_TIMEOUT=5
PULSEINDEX_FALLBACK_ENABLED=true
```

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

## PHP client

```php
use PulseIndex\Client;
use PulseIndex\Entity;

$client = Client::create('localhost:50051', 'your-api-key');

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
