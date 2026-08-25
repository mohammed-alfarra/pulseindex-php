<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PulseIndex\Client;
use PulseIndex\Entity;
use PulseIndex\Exception\GrpcException;

/**
 * Requires a local PulseIndex engine on PULSEINDEX_HOST (default localhost:50051).
 *
 * docker compose up -d pulseindex-engine
 * docker compose run --rm php composer test:integration
 */
final class ClientIntegrationTest extends TestCase
{
    private Client $client;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('grpc')) {
            self::markTestSkipped('ext-grpc is not loaded');
        }

        $host = getenv('PULSEINDEX_HOST') ?: 'localhost:50051';
        $apiKey = getenv('PULSEINDEX_API_KEY') ?: 'dev-key';

        $this->client = Client::create($host, $apiKey);
        $this->tenant = 'php-sdk-' . bin2hex(random_bytes(4));

        try {
            $this->client->indexEntity(
                entityId: 1,
                categories: ['feature:warmup'],
                tenantId: $this->tenant,
            );
        } catch (GrpcException $e) {
            self::markTestSkipped('PulseIndex gRPC server unreachable: ' . $e->getMessage());
        } catch (\Throwable $e) {
            self::markTestSkipped('PulseIndex gRPC server unreachable: ' . $e->getMessage());
        }
    }

    public function testIndexSearchAndDeleteRoundTrip(): void
    {
        $indexed = $this->client->batchIndex([
            new Entity(
                entityId: 1001,
                categories: ['feature:pool', 'amenity:parking'],
                price: 1500,
                tenantId: $this->tenant,
            ),
            new Entity(
                entityId: 1002,
                categories: ['feature:garden'],
                price: 900,
                tenantId: $this->tenant,
            ),
            [
                'entity_id' => 1003,
                'categories' => ['feature:pool'],
                'price' => 2000,
                'tenant_id' => $this->tenant,
            ],
        ]);

        self::assertSame(3, $indexed);

        $result = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:pool')
                ->should('amenity:parking')
                ->mustNot('feature:garden')
                ->range('price', 1000, 1800)
                ->limit(50)
        );

        self::assertContains(1001, $result->matchedEntityIds);
        self::assertNotContains(1002, $result->matchedEntityIds);
        self::assertGreaterThan(0, $result->totalMatches);

        self::assertTrue($this->client->deleteEntity(1001, $this->tenant));

        $afterDelete = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:pool')
                ->limit(50)
        );

        self::assertNotContains(1001, $afterDelete->matchedEntityIds);
        self::assertContains(1003, $afterDelete->matchedEntityIds);
    }
}
