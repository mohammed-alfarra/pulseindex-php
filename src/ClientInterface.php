<?php

declare(strict_types=1);

namespace PulseIndex;

interface ClientInterface
{
    public function query(): QueryBuilder;

    /**
     * @param list<string> $categories
     */
    public function indexEntity(
        int $entityId,
        array $categories = [],
        int $price = 0,
        int $locationPrefix = 0,
        string $tenantId = '',
    ): bool;

    public function index(Entity $entity): bool;

    /**
     * @param list<Entity|array<string, mixed>> $entities
     */
    public function batchIndex(array $entities): int;

    public function deleteEntity(int $entityId, string $tenantId = ''): bool;

    /**
     * Delete many entities in one call, up to 10,000 ids per page.
     *
     * Returns the number of rows that actually changed, which is lower than
     * count($entityIds) when some were unknown or already deleted.
     *
     * @param list<int> $entityIds
     */
    public function batchDelete(array $entityIds, string $tenantId = ''): int;

    public function search(QueryBuilder $query): SearchResult;

    /**
     * Serving status from `grpc.health.v1.Health`. Needs no particular scope,
     * so it works with any key.
     *
     * @return int one of \Grpc\Health\V1\HealthCheckResponse\ServingStatus
     */
    public function servingStatus(string $service = ''): int;

    /** True only when the engine is reachable and reports SERVING. */
    public function health(): bool;
}
