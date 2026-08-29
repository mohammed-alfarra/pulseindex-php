<?php

declare(strict_types=1);

namespace PulseIndex\Geo;

use InvalidArgumentException;

/**
 * Lightweight GeoHash encoder/decoder for bitwise categorical geo tags.
 *
 * Index entities with {@see encodeMultiTags()} (e.g. `geo:5:ezs42`, `geo:6:ezs42e`)
 * and query via {@see \PulseIndex\QueryBuilder::whereGeoHash()} or
 * {@see \PulseIndex\QueryBuilder::withinRadius()}.
 *
 * Cell size at the equator (approx.): precision 6 ≈ 1.2×0.6km, precision 5 ≈ 4.9×4.9km,
 * precision 4 ≈ 39×19km.
 */
final class GeoHash
{
    public const TAG_PREFIX = 'geo:';

    public const MIN_PRECISION = 1;

    public const MAX_PRECISION = 12;

    /** Index both of these so radius queries can pick a matching granularity. */
    public const INDEX_PRECISIONS = [5, 6];

    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    private const EARTH_RADIUS_KM = 6371.0;

    private const MAX_COVERING_CELLS = 64;

    /**
     * Neighbor charset keyed by direction then even/odd hash length (0 = even).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NEIGHBORS = [
        'n' => ['p0r21436x8zb9dcf5h7kjnmqesgutwvy', 'bc01fg45238967deuvhjyznpkmstqrwx'],
        's' => ['14365h7k9dcfesgujnmqp0r2twvyx8zb', '238967debc01fg45kmstqrwxuvhjyznp'],
        'e' => ['bc01fg45238967deuvhjyznpkmstqrwx', 'p0r21436x8zb9dcf5h7kjnmqesgutwvy'],
        'w' => ['238967debc01fg45kmstqrwxuvhjyznp', '14365h7k9dcfesgujnmqp0r2twvyx8zb'],
    ];

    /**
     * Border charset keyed by direction then even/odd hash length (0 = even).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const BORDERS = [
        'n' => ['prxz', 'bcfguvyz'],
        's' => ['028b', '0145hjnp'],
        'e' => ['bcfguvyz', 'prxz'],
        'w' => ['0145hjnp', '028b'],
    ];

    /**
     * Encode coordinates to a GeoHash of the given precision (1–12).
     */
    public static function encode(float $lat, float $lon, int $precision = 6): string
    {
        self::assertLatitude($lat);
        self::assertLongitude($lon);
        self::assertPrecision($precision);

        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $hash = '';
        $bit = 0;
        $ch = 0;
        $even = true;

        while (strlen($hash) < $precision) {
            if ($even) {
                $mid = ($lonMin + $lonMax) / 2.0;
                if ($lon >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $lonMin = $mid;
                } else {
                    $lonMax = $mid;
                }
            } else {
                $mid = ($latMin + $latMax) / 2.0;
                if ($lat >= $mid) {
                    $ch |= 1 << (4 - $bit);
                    $latMin = $mid;
                } else {
                    $latMax = $mid;
                }
            }

            $even = !$even;

            if ($bit < 4) {
                $bit++;
            } else {
                $hash .= self::BASE32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }

        return $hash;
    }

    /**
     * Decode a GeoHash to the centre of its cell.
     *
     * @return array{lat: float, lon: float}
     */
    public static function decode(string $hash): array
    {
        $bounds = self::decodeBounds($hash);

        return [
            'lat' => ($bounds['latMin'] + $bounds['latMax']) / 2.0,
            'lon' => ($bounds['lonMin'] + $bounds['lonMax']) / 2.0,
        ];
    }

    /**
     * Decode a GeoHash to its bounding box.
     *
     * @return array{latMin: float, latMax: float, lonMin: float, lonMax: float}
     */
    public static function decodeBounds(string $hash): array
    {
        $hash = self::normalizeHash($hash);

        $latMin = -90.0;
        $latMax = 90.0;
        $lonMin = -180.0;
        $lonMax = 180.0;
        $even = true;

        $length = strlen($hash);
        for ($i = 0; $i < $length; $i++) {
            $cd = strpos(self::BASE32, $hash[$i]);
            if ($cd === false) {
                throw new InvalidArgumentException(sprintf('Invalid GeoHash character "%s".', $hash[$i]));
            }

            for ($mask = 16; $mask > 0; $mask >>= 1) {
                if ($even) {
                    $mid = ($lonMin + $lonMax) / 2.0;
                    if (($cd & $mask) !== 0) {
                        $lonMin = $mid;
                    } else {
                        $lonMax = $mid;
                    }
                } else {
                    $mid = ($latMin + $latMax) / 2.0;
                    if (($cd & $mask) !== 0) {
                        $latMin = $mid;
                    } else {
                        $latMax = $mid;
                    }
                }
                $even = !$even;
            }
        }

        return [
            'latMin' => $latMin,
            'latMax' => $latMax,
            'lonMin' => $lonMin,
            'lonMax' => $lonMax,
        ];
    }

    /**
     * Adjacent hash in a cardinal direction: n, s, e, or w.
     */
    public static function neighbor(string $hash, string $direction): string
    {
        $direction = strtolower($direction);
        if (!isset(self::NEIGHBORS[$direction])) {
            throw new InvalidArgumentException('Direction must be one of: n, s, e, w.');
        }

        return self::adjacent(self::normalizeHash($hash), $direction);
    }

    /**
     * Eight cells surrounding $hash (N, NE, E, SE, S, SW, W, NW).
     *
     * @return list<string>
     */
    public static function neighbors(string $hash): array
    {
        $hash = self::normalizeHash($hash);
        $north = self::adjacent($hash, 'n');
        $south = self::adjacent($hash, 's');
        $east = self::adjacent($hash, 'e');
        $west = self::adjacent($hash, 'w');

        return [
            $north,
            self::adjacent($north, 'e'),
            $east,
            self::adjacent($south, 'e'),
            $south,
            self::adjacent($south, 'w'),
            $west,
            self::adjacent($north, 'w'),
        ];
    }

    /**
     * Precision that tightly covers $radiusKm without oversized cells.
     *
     * - ≤ 1.5 km → 6 (~1.2×0.6 km)
     * - ≤ 8.0 km → 5 (~4.9×4.9 km)
     * - > 8.0 km → 4 (~39×19 km)
     */
    public static function optimalPrecisionForRadius(float $radiusKm): int
    {
        if ($radiusKm < 0.0) {
            throw new InvalidArgumentException('Radius must be non-negative.');
        }

        if ($radiusKm <= 1.5) {
            return 6;
        }

        if ($radiusKm <= 8.0) {
            return 5;
        }

        return 4;
    }

    /**
     * @see optimalPrecisionForRadius()
     */
    public static function precisionForRadius(float $radiusKm): int
    {
        return self::optimalPrecisionForRadius($radiusKm);
    }

    /**
     * GeoHashes at $precision whose cells intersect the search circle.
     *
     * Walks the centre cell and its neighbors (expanding only through intersecting
     * cells) so the covering tightly bounds the radius instead of always emitting
     * a 3×3 neighborhood.
     *
     * @return list<string>
     */
    public static function getCoveringHashes(float $lat, float $lon, float $radiusKm, ?int $precision = null): array
    {
        if ($radiusKm < 0.0) {
            throw new InvalidArgumentException('Radius must be non-negative.');
        }

        $precision ??= self::optimalPrecisionForRadius($radiusKm);
        self::assertPrecision($precision);

        $center = self::encode($lat, $lon, $precision);
        $covering = [];
        $visited = [];
        $queue = [$center];

        while ($queue !== []) {
            $hash = array_shift($queue);
            if (isset($visited[$hash])) {
                continue;
            }
            $visited[$hash] = true;

            if (!self::cellIntersectsCircle($hash, $lat, $lon, $radiusKm)) {
                continue;
            }

            $covering[] = $hash;
            if (count($covering) >= self::MAX_COVERING_CELLS) {
                break;
            }

            foreach (self::neighbors($hash) as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $covering;
    }

    /**
     * Bitwise categorical tag `geo:{precision}:{hash}`. Idempotent if already prefixed.
     */
    public static function tag(string $geohash): string
    {
        $hash = self::normalizeHash($geohash);

        return self::TAG_PREFIX . strlen($hash) . ':' . $hash;
    }

    /**
     * Encode coordinates and return the namespaced categorical tag used at index and query time.
     */
    public static function encodeTag(float $lat, float $lon, int $precision = 6): string
    {
        return self::tag(self::encode($lat, $lon, $precision));
    }

    /**
     * Dual-granularity index tags (precision 5 and 6) so radius queries can match
     * without colliding with other `geo:` namespaces.
     *
     * @return list<string>
     */
    public static function encodeMultiTags(float $lat, float $lon): array
    {
        $tags = [];
        foreach (self::INDEX_PRECISIONS as $precision) {
            $tags[] = self::encodeTag($lat, $lon, $precision);
        }

        return $tags;
    }

    private static function cellIntersectsCircle(string $hash, float $lat, float $lon, float $radiusKm): bool
    {
        $bounds = self::decodeBounds($hash);
        $closestLat = min(max($lat, $bounds['latMin']), $bounds['latMax']);
        $closestLon = min(max($lon, $bounds['lonMin']), $bounds['lonMax']);

        return self::haversineKm($lat, $lon, $closestLat, $closestLon) <= $radiusKm;
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2.0) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2.0) ** 2;

        return 2.0 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    private static function adjacent(string $hash, string $direction): string
    {
        if ($hash === '') {
            throw new InvalidArgumentException('GeoHash must not be empty.');
        }

        $lastChar = $hash[strlen($hash) - 1];
        $type = strlen($hash) % 2;
        $parent = substr($hash, 0, -1);

        if ($parent !== '' && str_contains(self::BORDERS[$direction][$type], $lastChar)) {
            $parent = self::adjacent($parent, $direction);
        }

        $index = strpos(self::NEIGHBORS[$direction][$type], $lastChar);
        if ($index === false) {
            throw new InvalidArgumentException(sprintf('Invalid GeoHash character "%s".', $lastChar));
        }

        return $parent . self::BASE32[$index];
    }

    private static function normalizeHash(string $hash): string
    {
        $normalized = strtolower(trim($hash));
        if (str_starts_with($normalized, self::TAG_PREFIX)) {
            $normalized = substr($normalized, strlen(self::TAG_PREFIX));
        }

        if (preg_match('/^([1-9]|1[0-2]):([' . self::BASE32 . ']+)$/', $normalized, $matches) === 1) {
            $normalized = $matches[2];
        }

        if ($normalized === '') {
            throw new InvalidArgumentException('GeoHash must not be empty.');
        }

        if (strspn($normalized, self::BASE32) !== strlen($normalized)) {
            throw new InvalidArgumentException(sprintf('Invalid GeoHash "%s".', $normalized));
        }

        return $normalized;
    }

    private static function assertLatitude(float $lat): void
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new InvalidArgumentException('Latitude must be between -90 and 90.');
        }
    }

    private static function assertLongitude(float $lon): void
    {
        if ($lon < -180.0 || $lon > 180.0) {
            throw new InvalidArgumentException('Longitude must be between -180 and 180.');
        }
    }

    private static function assertPrecision(int $precision): void
    {
        if ($precision < self::MIN_PRECISION || $precision > self::MAX_PRECISION) {
            throw new InvalidArgumentException(sprintf(
                'GeoHash precision must be between %d and %d.',
                self::MIN_PRECISION,
                self::MAX_PRECISION
            ));
        }
    }
}
