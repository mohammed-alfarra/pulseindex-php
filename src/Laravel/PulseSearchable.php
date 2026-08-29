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

    public function getPulseEntityId(): int
    {
        return (int) $this->getKey();
    }

    public function pulseTenantId(): string
    {
        $configured = function_exists('config') ? config('pulseindex.tenant_id') : null;

        return (string) ($this->getAttribute('tenant_id') ?? $configured ?? '');
    }

    public function pulsePrice(): int
    {
        $data = $this->toPulseSearchableArray();

        return (int) ($data['price'] ?? $this->getAttribute('price') ?? 0);
    }

    public function pulseLocationPrefix(): int
    {
        $data = $this->toPulseSearchableArray();

        return (int) ($data['location_prefix']
            ?? $data['locationPrefix']
            ?? $this->getAttribute('location_prefix')
            ?? 0);
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
            'price',
            'location_prefix',
            'locationPrefix',
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
            price: $this->pulsePrice(),
            locationPrefix: $this->pulseLocationPrefix(),
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
