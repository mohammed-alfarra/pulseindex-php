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
}
