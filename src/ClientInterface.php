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

    public function search(QueryBuilder $query): SearchResult;

    public function getRecoveryState(): RecoveryState;

    /**
     * Serving status from `grpc.health.v1.Health`, which the engine serves
     * without its auth interceptor. Unlike {@see getRecoveryState()}, this
     * needs no scope — that RPC requires `admin`, which the engine refuses to
     * every tenant-bound key.
     *
     * @return int one of \Grpc\Health\V1\HealthCheckResponse\ServingStatus
     */
    public function servingStatus(string $service = ''): int;

    /** True only when the engine is reachable and reports SERVING. */
    public function health(): bool;
}
