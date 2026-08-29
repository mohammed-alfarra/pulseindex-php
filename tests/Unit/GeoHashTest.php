<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PulseIndex\Geo\GeoHash;

final class GeoHashTest extends TestCase
{
    public function testEncodesWellKnownCoordinates(): void
    {
        self::assertSame('ezs42', GeoHash::encode(42.6, -5.6, 5));
        self::assertSame('u4pruydqqvj', GeoHash::encode(57.64911, 10.40744, 11));
        self::assertSame('9q8yyk', GeoHash::encode(37.7749, -122.4194, 6));
    }

    public function testDecodeReturnsCellCentreWithinPrecision(): void
    {
        $decoded = GeoHash::decode('ezs42');

        self::assertEqualsWithDelta(42.6, $decoded['lat'], 0.05);
        self::assertEqualsWithDelta(-5.6, $decoded['lon'], 0.05);
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $lat = 24.7136;
        $lon = 46.6753;
        $hash = GeoHash::encode($lat, $lon, 8);
        $decoded = GeoHash::decode($hash);

        self::assertEqualsWithDelta($lat, $decoded['lat'], 0.001);
        self::assertEqualsWithDelta($lon, $decoded['lon'], 0.001);
    }

    public function testNeighborsOfEzS42(): void
    {
        self::assertSame([
            'ezs48',
            'ezs49',
            'ezs43',
            'ezs41',
            'ezs40',
            'ezefp',
            'ezefr',
            'ezefx',
        ], GeoHash::neighbors('ezs42'));
    }

    public function testNeighborCardinalDirections(): void
    {
        self::assertSame('ezs48', GeoHash::neighbor('ezs42', 'n'));
        self::assertSame('ezs43', GeoHash::neighbor('ezs42', 'e'));
        self::assertSame('ezs40', GeoHash::neighbor('ezs42', 's'));
        self::assertSame('ezefr', GeoHash::neighbor('ezs42', 'w'));
    }

    public function testOptimalPrecisionForRadius(): void
    {
        self::assertSame(6, GeoHash::optimalPrecisionForRadius(0.0));
        self::assertSame(6, GeoHash::optimalPrecisionForRadius(1.0));
        self::assertSame(6, GeoHash::optimalPrecisionForRadius(1.5));
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(1.51));
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(4.9));
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(8.0));
        self::assertSame(4, GeoHash::optimalPrecisionForRadius(8.01));
        self::assertSame(4, GeoHash::optimalPrecisionForRadius(40.0));
        self::assertSame(GeoHash::optimalPrecisionForRadius(4.9), GeoHash::precisionForRadius(4.9));
    }

    public function testGetCoveringHashesUsesOptimalPrecisionAndKeepsCentre(): void
    {
        $hashes = GeoHash::getCoveringHashes(42.6, -5.6, 4.9);

        self::assertSame('ezs42', $hashes[0]);
        self::assertGreaterThanOrEqual(1, count($hashes));
        self::assertSame($hashes, array_values(array_unique($hashes)));
        foreach ($hashes as $hash) {
            self::assertSame(5, strlen($hash));
        }
    }

    public function testGetCoveringHashesUsesPrecision6ForOneKilometreRadius(): void
    {
        $hashes = GeoHash::getCoveringHashes(37.7749, -122.4194, 1.2);

        self::assertSame('9q8yyk', $hashes[0]);
        self::assertGreaterThanOrEqual(1, count($hashes));
        foreach ($hashes as $hash) {
            self::assertSame(6, strlen($hash));
        }
    }

    public function testGetCoveringHashesDropsNeighborsThatMissTheCircle(): void
    {
        $bounds = GeoHash::decodeBounds('ezs42');
        $lat = ($bounds['latMin'] + $bounds['latMax']) / 2.0;
        $lon = ($bounds['lonMin'] + $bounds['lonMax']) / 2.0;

        $hashes = GeoHash::getCoveringHashes($lat, $lon, 0.05, 5);

        self::assertSame(['ezs42'], $hashes);
        self::assertNotContains('ezs48', $hashes);
    }

    public function testGetCoveringHashesHonorsExplicitPrecision(): void
    {
        $hashes = GeoHash::getCoveringHashes(42.6, -5.6, 1.0, 5);

        self::assertSame('ezs42', $hashes[0]);
        foreach ($hashes as $hash) {
            self::assertSame(5, strlen($hash));
        }
    }

    public function testTagIsNamespacedByPrecisionAndIdempotent(): void
    {
        self::assertSame('geo:5:ezs42', GeoHash::tag('ezs42'));
        self::assertSame('geo:5:ezs42', GeoHash::tag('geo:ezs42'));
        self::assertSame('geo:5:ezs42', GeoHash::tag('geo:5:ezs42'));
        self::assertSame('geo:5:ezs42', GeoHash::encodeTag(42.6, -5.6, 5));
        self::assertSame('geo:6:' . GeoHash::encode(42.6, -5.6, 6), GeoHash::encodeTag(42.6, -5.6, 6));
    }

    public function testEncodeMultiTagsReturnsPrecision5And6(): void
    {
        $lat = 42.6;
        $lon = -5.6;
        $tags = GeoHash::encodeMultiTags($lat, $lon);

        self::assertSame([
            'geo:5:' . GeoHash::encode($lat, $lon, 5),
            'geo:6:' . GeoHash::encode($lat, $lon, 6),
        ], $tags);
        self::assertSame(['geo:5:ezs42', 'geo:6:' . GeoHash::encode($lat, $lon, 6)], $tags);
        self::assertStringStartsWith('geo:6:', $tags[1]);
        self::assertSame(6, strlen(substr($tags[1], strlen('geo:6:'))));
    }

    public function testRejectsInvalidInputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoHash::encode(91.0, 0.0, 5);
    }
}
