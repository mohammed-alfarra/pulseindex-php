<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Entity;
use PulseIndex\Geo\GeoHash;

/**
 * Opt-in Eloquent integration: observers sync the index, `pulseSearch()` hydrates results.
 *
 * Override {@see toPulseSearchableArray()} / {@see pulseCategories()} to control tags.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait PulseSearchable
{
    public static function bootPulseSearchable(): void
    {
        static::observe(PulseModelObserver::class);
    }

    public static function pulseSearch(?ClientInterface $client = null): PulseQueryBuilder
    {
        return new PulseQueryBuilder(static::class, $client);
    }

    public static function withoutSyncingToPulse(callable $callback): mixed
    {
        return PulseSync::withoutSyncing($callback);
    }

    public function pulseIndex(): bool
    {
        if (!$this->shouldBePulseSearchable()) {
            return $this->pulseUnindex();
        }

        return $this->pulseClient()->index($this->toPulseEntity());
    }

    public function pulseUnindex(): bool
    {
        return $this->pulseClient()->deleteEntity(
            $this->getPulseEntityId(),
            $this->pulseTenantId(),
        );
    }

    public function shouldBePulseSearchable(): bool
    {
        return true;
    }

    /**
     * Query used by `pulse:reconcile` to enumerate the rows that *should* be in
     * the engine for a tenant. Default: filter by the tenant column when present.
     *
     * If you override {@see shouldBePulseSearchable()} with per-row logic, mirror
     * it here as a SQL constraint — otherwise reconcile treats rows you exclude
     * in PHP but keep in the DB as engine orphans and enqueues deletes for them.
     *
     * @param \Illuminate\Contracts\Database\Eloquent\Builder $query
     * @return \Illuminate\Contracts\Database\Eloquent\Builder
     */
    public function pulseReconcileScope($query, string $tenant)
    {
        $column = function_exists('config')
            ? (string) config('pulseindex.reconcile.tenant_column', 'tenant_id')
            : 'tenant_id';

        if ($column !== '' && $this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), $column)) {
            $query->where($column, $tenant);
        }

        return $query;
    }

    public function getPulseEntityId(): int
    {
        return (int) $this->getKey();
    }

    public function pulseTenantId(): string
    {
        $configured = function_exists('config') ? config('pulseindex.tenant_id') : null;

        return (string) ($this->getAttribute('tenant_id') ?? $configured ?? '');
    }

    /**
     * The model's numeric fields, under your own names.
     *
     * Return whatever your records actually hold:
     * `['price_cents' => 45000, 'bedrooms' => 3]`. This replaced `pulsePrice()`
     * and `pulseLocationPrefix()`, which named two fields on your behalf and
     * gave you room for no others.
     *
     * @return array<string, int>
     */
    /**
     * The model's positions, under your own names.
     *
     * Return `['points' => ['where' => ['lat' => ..., 'lon' => ...]]]` from
     * `toPulseSearchableArray()`. The latitude and longitude the geohash tags
     * are built from are left alone: those narrow which parts of the index are
     * opened, and this is what lets the engine measure the real distance.
     *
     * @return array<string, array{lat: float, lon: float}>
     */
    public function pulsePoints(): array
    {
        $data = $this->toPulseSearchableArray();

        /** @var array<string, array<string, float|string>> $points */
        $points = $data['points'] ?? [];

        $out = [];
        foreach ($points as $name => $point) {
            $lat = $point['lat'] ?? $point['latitude'] ?? null;
            $lon = $point['lon'] ?? $point['lng'] ?? $point['longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lon)) {
                $out[(string) $name] = ['lat' => (float) $lat, 'lon' => (float) $lon];
            }
        }

        return $out;
    }

    public function pulseNumbers(): array
    {
        $data = $this->toPulseSearchableArray();

        /** @var array<string, int|float|string> $numbers */
        $numbers = $data['numbers'] ?? [];

        $out = [];
        foreach ($numbers as $name => $value) {
            if (is_numeric($value)) {
                $out[(string) $name] = (int) $value;
            }
        }

        return $out;
    }

    public function pulseLatitude(): ?float
    {
        $data = $this->toPulseSearchableArray();
        $lat = $data['latitude'] ?? $data['lat'] ?? $this->getAttribute($this->pulseLatitudeColumn());

        return $lat === null || $lat === '' ? null : (float) $lat;
    }

    public function pulseLongitude(): ?float
    {
        $data = $this->toPulseSearchableArray();
        $lon = $data['longitude']
            ?? $data['lng']
            ?? $data['lon']
            ?? $this->getAttribute($this->pulseLongitudeColumn());

        return $lon === null || $lon === '' ? null : (float) $lon;
    }

    /**
     * Which database column, if any, answers each attribute namespace.
     *
     * Only consulted when the engine is unreachable and the Eloquent fallback
     * is switched on. The SDK used to guess this by splitting a tag on its
     * colon and treating the left side as a column name, so `feature:pool`
     * became `where('feature', '=', 'pool')` — a column most models do not
     * have. That either threw a SQL error or matched nothing, and the caller
     * was told only by a line in the log.
     *
     * Declare what your schema can actually answer:
     *
     *     public function pulseFallbackMap(): array
     *     {
     *         return ['status' => 'status', 'city' => 'city_name'];
     *     }
     *
     * A query touching an attribute that is not listed here will not fall
     * back. It raises instead, because a search that quietly answers a
     * different question is worse than one that fails.
     *
     * @return array<string, string> attribute namespace => column name
     */
    public function pulseFallbackMap(): array
    {
        return [];
    }

    public function pulseLatitudeColumn(): string
    {
        return 'latitude';
    }

    public function pulseLongitudeColumn(): string
    {
        return 'longitude';
    }

    /**
     * Fields that become PulseIndex categories / range / geo inputs.
     *
     * @return array<string, mixed>
     */
    public function toPulseSearchableArray(): array
    {
        return $this->attributesToArray();
    }

    /**
     * @return list<string>
     */
    public function pulseCategories(): array
    {
        $data = $this->toPulseSearchableArray();
        $skip = array_flip([
            $this->getKeyName(),
            'id',
            'numbers',
            'points',
            'tenant_id',
            'tenantId',
            'latitude',
            'longitude',
            'lat',
            'lng',
            'lon',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);

        $categories = [];

        foreach (['categories', 'tags'] as $listKey) {
            if (!isset($data[$listKey]) || !is_array($data[$listKey])) {
                continue;
            }
            foreach ($data[$listKey] as $item) {
                if (is_scalar($item) && $item !== '') {
                    $categories[] = (string) $item;
                }
            }
        }

        foreach ($data as $key => $value) {
            if (isset($skip[$key]) || $key === 'categories' || $key === 'tags') {
                continue;
            }

            if (is_bool($value)) {
                if ($value) {
                    $categories[] = (string) $key;
                }
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_scalar($item) && $item !== '') {
                        $categories[] = $key . ':' . $item;
                    }
                }
                continue;
            }

            if (is_scalar($value) && $value !== '' && $value !== null) {
                $categories[] = $key . ':' . $value;
            }
        }

        $lat = $this->pulseLatitude();
        $lon = $this->pulseLongitude();
        if ($lat !== null && $lon !== null) {
            $categories = array_merge($categories, GeoHash::encodeMultiTags($lat, $lon));
        }

        return array_values(array_unique($categories));
    }

    public function toPulseEntity(): Entity
    {
        return new Entity(
            entityId: $this->getPulseEntityId(),
            categories: $this->pulseCategories(),
            numbers: $this->pulseNumbers(),
            points: $this->pulsePoints(),
            tenantId: $this->pulseTenantId(),
        );
    }

    protected function pulseClient(): ClientInterface
    {
        if (function_exists('app')) {
            if (app()->bound(ClientInterface::class)) {
                return app(ClientInterface::class);
            }
            if (app()->bound(Client::class)) {
                return app(Client::class);
            }
        }

        return new Client();
    }
}
