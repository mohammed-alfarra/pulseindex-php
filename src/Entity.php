<?php

declare(strict_types=1);

namespace PulseIndex;

final class Entity
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $entityId,
        public readonly array $categories = [],
        public readonly int $price = 0,
        public readonly int $locationPrefix = 0,
        public readonly string $tenantId = '',
    ) {
    }

    /**
     * @param array{
     *     entity_id?: int|string,
     *     entityId?: int|string,
     *     categories?: list<string>,
     *     price?: int,
     *     location_prefix?: int|string,
     *     locationPrefix?: int|string,
     *     tenant_id?: string,
     *     tenantId?: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            entityId: (int) ($data['entity_id'] ?? $data['entityId'] ?? 0),
            categories: array_values($data['categories'] ?? []),
            price: (int) ($data['price'] ?? 0),
            locationPrefix: (int) ($data['location_prefix'] ?? $data['locationPrefix'] ?? 0),
            tenantId: (string) ($data['tenant_id'] ?? $data['tenantId'] ?? ''),
        );
    }
}
