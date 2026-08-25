<?php

declare(strict_types=1);

namespace PulseIndex\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use PulseIndex\Client;
use PulseIndex\Entity;
use PulseIndex\QueryBuilder;
use PulseIndex\SearchResult;

/**
 * @method static QueryBuilder query()
 * @method static bool indexEntity(int $entityId, array $categories = [], int $price = 0, int $locationPrefix = 0, string $tenantId = '')
 * @method static bool index(Entity $entity)
 * @method static int batchIndex(array $entities)
 * @method static bool deleteEntity(int $entityId, string $tenantId = '')
 * @method static SearchResult search(QueryBuilder $query)
 *
 * @see Client
 */
final class PulseIndex extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
