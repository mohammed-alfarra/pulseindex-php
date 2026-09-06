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

    /**
     * Only ever a precision entities actually carry.
     *
     * This used to return 4 above 8 km, and nothing is indexed at 4, so every
     * radius over 8 km matched nothing whatsoever. Against a real engine with
     * entities tagged by encodeMultiTags, 15 km returned 0 of 386 and 50 km
     * returned 0 of 4,282 — an empty page, with no error to explain it.
     */
    public function testOptimalPrecisionIsAlwaysOneTheIndexCarries(): void
    {
        foreach ([0.0, 0.5, 1.0, 1.5, 2.0, 5.0, 8.0, 8.01, 10.0, 15.0, 25.0, 40.0, 50.0] as $radius) {
            self::assertContains(
                GeoHash::optimalPrecisionForRadius($radius, 24.7136, 46.6753),
                GeoHash::INDEX_PRECISIONS,
                sprintf('a %s km radius chose a precision nothing is indexed at', $radius),
            );
        }
    }

    public function testFinerPrecisionIsPreferredWhileItFitsTheBudget(): void
    {
        $lat = 24.7136;
        $lon = 46.6753;

        // Small circles need the fine cell: the coarse one wastes 6.91x the
        // area at 2 km and 2.76x at 5 km, well past what is acceptable.
        self::assertSame(6, GeoHash::optimalPrecisionForRadius(0.5, $lat, $lon));
        // 5 km takes the coarse cell: 2.76x wasted against 10 cells, where the
        // fine one costs 140 to reach 1.21x. A pre-filter is worth 2.76x.
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(5.0, $lat, $lon));
        // Large ones do not. At 15 km the coarse cell is already within 1.44x,
        // and the fine one would cost 1,120 cells instead of 47 to reach 1.07x.
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(15.0, $lat, $lon));
        self::assertSame(5, GeoHash::optimalPrecisionForRadius(50.0, $lat, $lon));

        self::assertSame(
            GeoHash::optimalPrecisionForRadius(4.9, $lat, $lon),
            GeoHash::precisionForRadius(4.9, $lat, $lon),
        );
    }

    /**
     * A radius too large to cover is refused, not half-covered. The old cap
     * stopped the walk at 64 cells and returned them: a 50 km search came back
     * with cells covering 18% of its own circle and said nothing about it.
     */
    public function testARadiusTooLargeToCoverIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs more than \\d+ geohash cells/');

        GeoHash::optimalPrecisionForRadius(400.0, 24.7136, 46.6753);
    }

    /**
     * The covering has to actually contain the circle. Sixteen bearings around
     * the rim, every one inside a returned cell — this is what a truncated
     * covering fails.
     */
    public function testTheCoveringContainsTheWholeCircle(): void
    {
        $places = [
            [24.7136, 46.6753],     // Riyadh
            [51.5074, -0.1278],     // London, across the prime meridian
            [-33.8688, 151.2093],   // Sydney
            [0.0, 179.99],          // hard against the antimeridian, east side
            [0.0, -179.99],         // and the west side
            [-16.5, 179.9],         // Fiji, a real place that sits on it
            [71.0, 25.8],           // North Cape, where cells are narrow
        ];
        foreach ($places as [$lat, $lon]) {
            foreach ([0.5, 2.0, 5.0, 15.0, 40.0] as $radius) {
                $cells = GeoHash::getCoveringHashes($lat, $lon, $radius);
                $bounds = array_map(static fn (string $h): array => GeoHash::decodeBounds($h), $cells);

                for ($bearing = 0; $bearing < 360; $bearing += 22.5) {
                    [$plat, $plon] = self::destination($lat, $lon, $radius * 0.999, $bearing);
                    $inside = false;
                    foreach ($bounds as $b) {
                        if ($plat < $b['latMin'] || $plat > $b['latMax']) {
                            continue;
                        }
                        // Longitude compared in a frame anchored at the cell's
                        // west edge, so the +/-180 seam is not a discontinuity.
                        // Comparing raw degrees made this assertion lie at the
                        // antimeridian in both directions.
                        $span = $b['lonMax'] - $b['lonMin'];
                        $off = fmod(($plon - $b['lonMin']) + 180.0, 360.0);
                        if ($off < 0.0) {
                            $off += 360.0;
                        }
                        $off -= 180.0;
                        if ($off >= -1e-9 && $off <= $span + 1e-9) {
                            $inside = true;
                            break;
                        }
                    }
                    self::assertTrue($inside, sprintf(
                        'a point on the %s km rim at bearing %s from (%s, %s) fell outside every returned cell',
                        $radius, $bearing, $lat, $lon,
                    ));
                }
            }
        }
    }

    /**
     * The over-inclusion a covering costs, bounded. Cells are rectangles and
     * the query is a circle, so some excess is unavoidable — 6x is not.
     * Measured before this change: 2 km returned 5.43x the true count against a
     * real engine, and 5 km returned 3.03x.
     */
    public function testCoveredAreaStaysCloseToTheCircle(): void
    {
        $lat = 24.7136;
        $lon = 46.6753;

        foreach ([0.5, 2.0, 5.0, 10.0, 15.0, 25.0, 50.0] as $radius) {
            $covered = 0.0;
            foreach (GeoHash::getCoveringHashes($lat, $lon, $radius) as $hash) {
                $b = GeoHash::decodeBounds($hash);
                $covered += (6371.0 * deg2rad($b['latMax'] - $b['latMin']))
                    * (6371.0 * cos(deg2rad(($b['latMax'] + $b['latMin']) / 2)) * deg2rad($b['lonMax'] - $b['lonMin']));
            }
            $ratio = $covered / (M_PI * $radius ** 2);
            // The bound is the threshold the chooser works to, plus the slack a
            // circle smaller than one cell cannot avoid. Before this file was
            // corrected, 15 km covered 5.89x.
            self::assertLessThan(
                $radius < 1.0 ? 5.0 : GeoHash::ACCEPTABLE_COVER_RATIO + 0.01,
                $ratio,
                sprintf('a %s km radius covers %.2fx the area it asked for', $radius, $ratio),
            );
        }
    }

    public function testAPrecisionNothingIsIndexedAtIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not indexed/');

        GeoHash::getCoveringHashes(24.7136, 46.6753, 15.0, 4);
    }

    /**
     * The same vectors the JS SDK asserts against.
     *
     * Two implementations of one contract, and nothing checked they agreed. A
     * customer moving between the SDKs would have got different result sets for
     * the same call and had no way to tell which was right.
     */
    public function testCoveringMatchesTheSharedVectors(): void
    {
        $vectors = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/geo-covering-vectors.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertNotEmpty($vectors);

        foreach ($vectors as $v) {
            $cells = GeoHash::getCoveringHashes($v['lat'], $v['lon'], $v['radiusKm']);
            $label = sprintf('%s at %s km', $v['name'], $v['radiusKm']);

            self::assertCount($v['count'], $cells, $label);
            self::assertSame($v['precision'], strlen($cells[0]), $label);
            self::assertSame($v['first'], $cells[0], $label);
            self::assertSame($v['last'], $cells[count($cells) - 1], $label);
        }
    }

    /**
     * The coarser cell is chosen whenever it is accurate enough, because the
     * finer one is not free.
     *
     * An earlier rule took the finest precision that merely fit the budget. At
     * 15 km that is precision 6: 1,120 cells for 1.07x the circle, against
     * precision 5's 47 cells for 1.44x. Twenty-four times the predicates to
     * shave a quarter off an excess that was already small.
     */
    public function testTheCoarserCellIsUsedWhenItIsAccurateEnough(): void
    {
        $fifteen = GeoHash::getCoveringHashes(24.7136, 46.6753, 15.0);
        self::assertLessThan(
            200,
            count($fifteen),
            'a 15 km covering should cost tens of cells, not over a thousand',
        );

        // And the fine cell is still chosen where the coarse one is loose.
        $two = GeoHash::getCoveringHashes(24.7136, 46.6753, 2.0);
        self::assertSame(6, strlen($two[0]));
    }

    /**
     * The nearest point in a cell, going the short way round the globe.
     *
     * A plain clamp is wrong at the antimeridian because -180 and +180 are the
     * same meridian. Measured before this: a query at lon 179.99 against the
     * cell spanning -180 to -179.989 clamped to the far edge and measured
     * 2.334 km, when the true nearest point is -180.0 at 1.112 km. The cell was
     * dropped from a 2 km radius it sits well inside, and five of sixteen
     * points on that circle's rim fell outside the covering.
     */
    public function testACellAcrossTheAntimeridianIsNotDropped(): void
    {
        $cells = GeoHash::getCoveringHashes(0.0, 179.99, 2.0);

        $west = array_filter($cells, static fn (string $h): bool => GeoHash::decodeBounds($h)['lonMin'] < 0.0);

        self::assertNotEmpty($west, 'the covering never crossed the line');
        self::assertContains('800000', $cells, 'the cell immediately across the line is missing');
    }

    public function testTheSameCoveringIsFoundFromEitherSideOfTheLine(): void
    {
        // 179.999 and -179.999 are 222 metres apart. Their 5 km coverings
        // should be nearly the same set, not two disjoint halves.
        $east = GeoHash::getCoveringHashes(0.0, 179.999, 5.0);
        $west = GeoHash::getCoveringHashes(0.0, -179.999, 5.0);

        $shared = count(array_intersect($east, $west));
        self::assertGreaterThan(
            count($east) * 0.8,
            $shared,
            'coverings a few hundred metres apart barely overlapped, so one of them did not wrap',
        );
    }

    /**
     * Cells keep their width in degrees and narrow in kilometres toward the
     * poles, so the same radius costs more cells the further north it is asked.
     * The refusal has to say that, or an operator reads "too many cells" and
     * goes looking at the radius.
     */
    public function testTheRefusalNamesTheLatitudeThatCausedIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/latitude 89\\.9/');

        GeoHash::getCoveringHashes(89.9, 0.0, 50.0);
    }

    /**
     * The budget was first set at one latitude and refused a 50 km search
     * anywhere above 60 degrees — Oslo, Stockholm, Helsinki, Saint Petersburg.
     */
    public function testFiftyKilometresWorksWhereEuropeansLive(): void
    {
        foreach ([[59.9139, 10.7522], [59.3293, 18.0686], [60.1699, 24.9384], [69.6492, 18.9553]] as [$lat, $lon]) {
            $cells = GeoHash::getCoveringHashes($lat, $lon, 50.0);
            self::assertNotEmpty($cells);
            self::assertLessThanOrEqual(GeoHash::COVERING_CELL_BUDGET, count($cells));
        }
    }

    /** Great-circle destination, for placing points exactly on the rim. */
    private static function destination(float $lat, float $lon, float $km, float $bearing): array
    {
        $R = 6371.0;
        $d = $km / $R;
        $b = deg2rad($bearing);
        $la = deg2rad($lat);
        $lo = deg2rad($lon);
        $la2 = asin(sin($la) * cos($d) + cos($la) * sin($d) * cos($b));
        $lo2 = $lo + atan2(sin($b) * sin($d) * cos($la), cos($d) - sin($la) * sin($la2));

        return [rad2deg($la2), rad2deg($lo2)];
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
