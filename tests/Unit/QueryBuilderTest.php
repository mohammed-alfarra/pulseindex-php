<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Entity;
use PulseIndex\Geo\GeoHash;
use PulseIndex\QueryBuilder;

final class QueryBuilderTest extends TestCase
{

    /**
     * The builder used to default to 0, which the engine read as "no ceiling"
     * and answered with every matching id the tenant had. Nobody meant to ask
     * for that, and the cost landed on the service rather than on the caller
     * who forgot to say.
     */
    public function test_a_query_carries_a_page_size_without_being_told(): void
    {
        self::assertSame(
            QueryBuilder::DEFAULT_LIMIT,
            (new QueryBuilder())->toArray()['limit'],
        );
    }

    /**
     * Zero is still expressible, and now means what it says on the engine:
     * the total number of matches, and no ids to carry back.
     */
    public function test_zero_is_kept_for_callers_who_only_want_the_count(): void
    {
        self::assertSame(0, (new QueryBuilder())->limit(0)->toArray()['limit']);
    }
    public function testFluentFiltersAreImmutableAndOrdered(): void
    {
        $base = new QueryBuilder();
        $built = $base
            ->tenant('acme')
            ->must('feature:pool')
            ->should('amenity:parking')
            ->mustNot('feature:shared')
            ->range('price', 100, 500)
            ->location(42)
            ->limit(25)
            ->offset(10);

        self::assertSame([], $base->toArray()['filters']);
        self::assertSame('acme', $built->toArray()['tenant_id']);
        self::assertSame(42, $built->toArray()['location_prefix']);
        self::assertSame(25, $built->toArray()['limit']);
        self::assertSame(10, $built->toArray()['offset']);
        self::assertSame([
            ['op' => Operation::MUST, 'attribute' => 'feature:pool'],
            ['op' => Operation::SHOULD, 'attribute' => 'amenity:parking'],
            ['op' => Operation::MUST_NOT, 'attribute' => 'feature:shared'],
        ], $built->toArray()['filters']);
        self::assertSame([
            ['field' => 'price', 'min' => 100, 'max' => 500],
        ], $built->toArray()['ranges']);
    }

    public function testToRequestMapsProtoMessages(): void
    {
        $request = (new QueryBuilder())
            ->tenant('default')
            ->must('feature:pool')
            ->range('price', 50, 150)
            ->limit(5)
            ->toRequest();

        self::assertSame('default', $request->getTenantId());
        self::assertSame(5, $request->getLimit());
        self::assertCount(1, $request->getFilters());
        self::assertSame(Operation::MUST, $request->getFilters()[0]->getOp());
        self::assertSame('feature:pool', $request->getFilters()[0]->getAttribute());
        self::assertCount(1, $request->getRanges());
        self::assertSame('price', $request->getRanges()[0]->getField());
        self::assertSame(50, $request->getRanges()[0]->getMinVal());
        self::assertSame(150, $request->getRanges()[0]->getMaxVal());
    }

    public function testEntityFromArrayAcceptsSnakeAndCamelKeys(): void
    {
        $entity = Entity::fromArray([
            'entity_id' => 99,
            'categories' => ['feature:pool'],
            'price' => 1200,
            'locationPrefix' => 7,
            'tenantId' => 't1',
        ]);

        self::assertSame(99, $entity->entityId);
        self::assertSame(['feature:pool'], $entity->categories);
        self::assertSame(1200, $entity->price);
        self::assertSame(7, $entity->locationPrefix);
        self::assertSame('t1', $entity->tenantId);
    }

    public function testWhereGeoHashAddsMustGeoTag(): void
    {
        $request = (new QueryBuilder())
            ->whereGeoHash('ezs42')
            ->toRequest();

        self::assertCount(1, $request->getFilters());
        self::assertSame(Operation::MUST, $request->getFilters()[0]->getOp());
        self::assertSame('geo:5:ezs42', $request->getFilters()[0]->getAttribute());
    }

    public function testInGeoHashIsAliasOfWhereGeoHash(): void
    {
        $viaWhere = (new QueryBuilder())->whereGeoHash('geo:5:ezs42')->toArray();
        $viaIn = (new QueryBuilder())->inGeoHash('ezs42')->toArray();

        self::assertSame($viaWhere['filters'], $viaIn['filters']);
        self::assertSame(Operation::MUST, $viaIn['filters'][0]['op']);
        self::assertSame('geo:5:ezs42', $viaIn['filters'][0]['attribute']);
    }

    public function testWithinRadiusAddsShouldGeoTagsForCoveringHashes(): void
    {
        $lat = 42.6;
        $lon = -5.6;
        $radiusKm = 4.9;
        $covering = GeoHash::getCoveringHashes($lat, $lon, $radiusKm);

        $base = new QueryBuilder();
        $built = $base->withinRadius($lat, $lon, $radiusKm);
        $request = $built->toRequest();

        self::assertSame([], $base->toArray()['filters']);
        self::assertCount(count($covering), $request->getFilters());
        self::assertSame(5, GeoHash::optimalPrecisionForRadius($radiusKm));

        $attributes = [];
        foreach ($request->getFilters() as $filter) {
            self::assertSame(Operation::SHOULD, $filter->getOp());
            $attributes[] = $filter->getAttribute();
        }

        self::assertSame(array_map(GeoHash::tag(...), $covering), $attributes);
        self::assertSame('geo:5:ezs42', $attributes[0]);
        foreach ($attributes as $attribute) {
            self::assertMatchesRegularExpression('/^geo:5:[0-9bcdefghjkmnpqrstuvwxyz]+$/', $attribute);
        }
    }

    public function testWithinRadiusHonorsExplicitPrecision(): void
    {
        $lat = 42.6;
        $lon = -5.6;
        $covering = GeoHash::getCoveringHashes($lat, $lon, 1.0, 6);
        $request = (new QueryBuilder())->withinRadius($lat, $lon, 1.0, 6)->toRequest();

        self::assertCount(count($covering), $request->getFilters());
        self::assertSame('geo:6:' . $covering[0], $request->getFilters()[0]->getAttribute());
        self::assertSame(Operation::SHOULD, $request->getFilters()[0]->getOp());
    }
}
