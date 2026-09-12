<?php

declare(strict_types=1);

namespace PulseIndex;

final class Entity
{
    /**
     * @param list<string>       $categories
     * @param array<string, array{lat: float, lon: float}> $points Positions
     *                                    under your own names. Degrees go on
     *                                    the wire and the engine packs them:
     *                                    a representation split between this
     *                                    package and the engine, with nothing
     *                                    comparing the two, is how the geo
     *                                    defects in 4.0.0 happened.
     * @param array<string, int> $numbers Numeric fields under your own names.
     *                                    Any name, any integer, any number of
     *                                    them. This replaced a single `price`
     *                                    and a `locationPrefix` that the SDK
     *                                    named on your behalf.
     */
    public function __construct(
        public readonly int $entityId,
        public readonly array $categories = [],
        public readonly array $numbers = [],
        public readonly array $points = [],
        public readonly string $tenantId = '',
    ) {
    }

    /**
     * @param array{
     *     entity_id?: int|string,
     *     entityId?: int|string,
     *     categories?: list<string>,
     *     numbers?: array<string, int|float|string>,
     *     points?: array<string, array{lat: float|string, lon: float|string}>,
     *     tenant_id?: string,
     *     tenantId?: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        self::assertNoUnknownKeys($data);

        return new self(
            entityId: (int) ($data['entity_id'] ?? $data['entityId'] ?? 0),
            categories: array_values($data['categories'] ?? []),
            numbers: self::normaliseNumbers($data['numbers'] ?? []),
            points: self::normalisePoints($data['points'] ?? []),
            tenantId: (string) ($data['tenant_id'] ?? $data['tenantId'] ?? ''),
        );
    }

    /**
     * Every key this accepts, so a typo is a refusal rather than a silence.
     *
     * @var list<string>
     */
    private const KNOWN_KEYS = [
        'entity_id', 'entityId',
        'categories',
        'numbers',
        'points',
        'tenant_id', 'tenantId',
    ];

    /**
     * Where a key that used to mean something now goes.
     *
     * @var array<string, string>
     */
    private const MOVED_KEYS = [
        'price' => "numbers, under your own name: ['numbers' => ['price' => 45000]]",
        'location_prefix' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'locationPrefix' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'latitude' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'longitude' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'lat' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'lon' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'lng' => "points, as degrees: ['points' => ['where' => ['lat' => .., 'lon' => ..]]]",
        'tags' => 'categories',
    ];

    /**
     * A key this does not read is a mistake, not a no-op.
     *
     * This is a DTO, not the attribute flattener the TypeScript `index()` call
     * is - there is no rule here that turns an unrecognised key into a tag, so
     * one could only ever be dropped. It was dropped silently until now, and
     * `'price' => 45000` is exactly the shape that costs: it was read in 4.x,
     * it is not read in 5.x, and the only sign was a search refusing the field
     * later on. Measured on the real demo, that cost half an hour to attribute
     * to the seeder rather than to the model.
     *
     * @param array<string, mixed> $data
     */
    private static function assertNoUnknownKeys(array $data): void
    {
        $unknown = array_diff(array_keys($data), self::KNOWN_KEYS);
        if ($unknown === []) {
            return;
        }

        $lines = [];
        foreach ($unknown as $key) {
            $key = (string) $key;
            $lines[] = isset(self::MOVED_KEYS[$key])
                ? sprintf('%s belongs in %s', $key, self::MOVED_KEYS[$key])
                : sprintf('%s is not a field an entity has', $key);
        }

        throw new \InvalidArgumentException(sprintf(
            'Entity::fromArray was given %s it does not read, so they would have been '
            . 'dropped: %s. It reads %s. Anything else about a record is a category '
            . 'token and belongs in categories.',
            count($unknown) === 1 ? 'a key' : 'keys',
            implode('; ', $lines),
            implode(', ', self::KNOWN_KEYS),
        ));
    }

    /**
     * One record's numeric fields, as the engine stores them.
     *
     * The engine's column is a 64-bit integer. A fraction is refused rather
     * than truncated: 4.3 stored as 4 is a lie that nothing downstream can
     * detect, and the SDK used to make it silently.
     *
     * @param  array<string, int|float|string> $numbers
     * @return array<string, int>
     */
    /**
     * One record's positions, in degrees.
     *
     * @param  array<string, array<string, float|string>> $points
     * @return array<string, array{lat: float, lon: float}>
     */
    private static function normalisePoints(array $points): array
    {
        $out = [];
        foreach ($points as $name => $point) {
            $name = (string) $name;
            if (trim($name) === '') {
                throw new \InvalidArgumentException('A position field name must not be empty.');
            }
            $lat = $point['lat'] ?? $point['latitude'] ?? null;
            $lon = $point['lon'] ?? $point['lng'] ?? $point['longitude'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lon)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s must be an array with numeric lat and lon.',
                    $name
                ));
            }
            $lat = (float) $lat;
            $lon = (float) $lon;
            if ($lat < -90.0 || $lat > 90.0) {
                throw new \InvalidArgumentException(sprintf('%s lat is %s, outside -90..90.', $name, $lat));
            }
            if ($lon < -180.0 || $lon > 180.0) {
                throw new \InvalidArgumentException(sprintf('%s lon is %s, outside -180..180.', $name, $lon));
            }
            $out[$name] = ['lat' => $lat, 'lon' => $lon];
        }

        return $out;
    }

    private static function normaliseNumbers(array $numbers): array
    {
        $out = [];
        foreach ($numbers as $name => $value) {
            $name = (string) $name;
            if (trim($name) === '') {
                throw new \InvalidArgumentException('A numeric field name must not be empty.');
            }
            if (!is_numeric($value)) {
                throw new \InvalidArgumentException(sprintf('%s must be a number.', $name));
            }
            if ((float) $value !== floor((float) $value)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s is %s, and the engine stores whole numbers. Scale it to an integer and '
                    . 'keep the scale on your side - a price in cents, a rating out of 100.',
                    $name,
                    (string) $value
                ));
            }
            $out[$name] = (int) $value;
        }

        return $out;
    }
}
