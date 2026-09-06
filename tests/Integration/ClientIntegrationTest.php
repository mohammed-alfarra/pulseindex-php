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

    public function testBatchDeleteClearsTheTenantAndReportsRowsChanged(): void
    {
        $ids = range(2001, 2050);
        $entities = [];
        foreach ($ids as $id) {
            $entities[] = new Entity(
                entityId: $id,
                categories: ['feature:clearme'],
                price: 100,
                tenantId: $this->tenant,
            );
        }

        self::assertSame(50, $this->client->batchIndex($entities));

        self::assertSame(
            50,
            $this->client->batchDelete($ids, $this->tenant),
            'every row that was live is reported',
        );

        $after = $this->client->search(
            $this->client->query()
                ->tenant($this->tenant)
                ->must('feature:clearme')
                ->limit(50)
        );
        self::assertSame([], $after->matchedEntityIds);

        // A retry of a page that already applied is not an error, and reports
        // the smaller number rather than failing on ids that are already gone.
        self::assertSame(0, $this->client->batchDelete($ids, $this->tenant));

        // Ids that were never indexed are skipped the same way.
        self::assertSame(0, $this->client->batchDelete([9_000_001, 9_000_002], $this->tenant));
    }

    /**
     * A paged search stops as soon as the page is full, so its total is
     * whatever it had counted when it stopped. Measured on the production
     * million: a query with 166,325 matches reported 10,866 for a page of 100.
     * Anything printing "page 1 of N" from that is wrong by an order of
     * magnitude and looks entirely fine.
     */
    public function testPagedTotalIsMarkedInexactAndSearchWithTotalFixesIt(): void
    {
        $entities = [];
        foreach (range(3001, 3600) as $id) {
            $entities[] = new Entity(
                entityId: $id,
                categories: ['bulk:yes'],
                price: 100,
                tenantId: $this->tenant,
            );
        }
        self::assertSame(600, $this->client->batchIndex($entities));

        $paged = $this->client->search(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(10)
        );
        self::assertCount(10, $paged->matchedEntityIds);
        self::assertFalse($paged->totalIsExact, 'a paged total must not claim to be exact');
        self::assertNull($paged->exactTotal(), 'an inexact total must not be handed out as a number');

        $counted = $this->client->search(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(0)
        );
        self::assertTrue($counted->totalIsExact);
        self::assertSame(600, $counted->totalMatches);

        $both = $this->client->searchWithTotal(
            $this->client->query()->tenant($this->tenant)->must('bulk:yes')->limit(10)
        );
        self::assertCount(10, $both->matchedEntityIds, 'still one page of ids');
        self::assertTrue($both->totalIsExact);
        self::assertSame(600, $both->exactTotal(), 'and the real total beside it');
    }

    /**
     * The operator RPCs stay out of the customer client.
     *
     * This used to call getRecoveryState() and assert on it. That method was
     * removed when CreateSnapshot, GetRecoveryState and SetCdcOffset were
     * trimmed from the vendored proto — no customer key can call them — and the
     * test has been failing ever since, which is how a suite teaches people to
     * stop reading it.
     *
     * Turned around: it now asserts the trim stays trimmed, so the day someone
     * regenerates the stubs without the plugin and publishes all three again,
     * something says so.
     */
    public function testTheClientDoesNotExposeOperatorRpcs(): void
    {
        foreach (['getRecoveryState', 'createSnapshot', 'setCdcOffset'] as $method) {
            self::assertFalse(
                method_exists($this->client, $method),
                sprintf('%s is an operator RPC and must not be on the customer client', $method),
            );
        }

        $stub = new \ReflectionClass(\PulseIndex\Engine\V1\SearchEngineServiceClient::class);
        $rpcs = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $stub->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === $stub->getName()
                    && $m->getName() !== '__construct',
            ),
        );
        sort($rpcs);

        self::assertSame(
            ['BatchDeleteEntities', 'BatchIndexEntities', 'DeleteEntity', 'IndexEntity', 'Search'],
            $rpcs,
            'the generated stub gained or lost an RPC',
        );
    }
}
