<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Entity;
use PulseIndex\QueryBuilder;

final class QueryBuilderTest extends TestCase
{
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
}
