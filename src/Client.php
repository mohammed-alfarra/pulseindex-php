<?php

declare(strict_types=1);

namespace PulseIndex;

use Grpc\ChannelCredentials;
use PulseIndex\Engine\V1\BatchIndexEntitiesRequest;
use PulseIndex\Engine\V1\DeleteEntityRequest;
use PulseIndex\Engine\V1\IndexEntityRequest;
use PulseIndex\Engine\V1\SearchEngineServiceClient;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Exception\PulseIndexException;

/**
 * Fluent PHP wrapper around the PulseIndex gRPC SearchEngineService.
 */
final class Client
{
    private SearchEngineServiceClient $stub;

    /** @var array<string, array<int, string>> */
    private array $metadata;

    /**
     * @param array{
     *     host?: string,
     *     api_key?: string|null,
     *     ssl?: bool,
     *     timeout_us?: int,
     *     stub?: SearchEngineServiceClient|null
     * } $config
     */
    public function __construct(array $config = [])
    {
        $host = $config['host'] ?? getenv('PULSEINDEX_HOST') ?: 'localhost:50051';
        $apiKey = $config['api_key'] ?? (getenv('PULSEINDEX_API_KEY') ?: null);
        $ssl = (bool) ($config['ssl'] ?? false);
        $timeoutUs = (int) ($config['timeout_us'] ?? 5_000_000);

        $this->metadata = [];
        if (is_string($apiKey) && $apiKey !== '') {
            $this->metadata['x-api-key'] = [$apiKey];
        }

        if (isset($config['stub']) && $config['stub'] instanceof SearchEngineServiceClient) {
            $this->stub = $config['stub'];

            return;
        }

        if (!class_exists(SearchEngineServiceClient::class)) {
            throw new PulseIndexException(
                'SearchEngineServiceClient not found. Run `composer build-proto` first.'
            );
        }

        $credentials = $ssl
            ? ChannelCredentials::createSsl()
            : ChannelCredentials::createInsecure();

        $this->stub = new SearchEngineServiceClient($host, [
            'credentials' => $credentials,
            'timeout' => $timeoutUs,
        ]);
    }

    public static function create(string $host, ?string $apiKey = null, bool $ssl = false): self
    {
        return new self([
            'host' => $host,
            'api_key' => $apiKey,
            'ssl' => $ssl,
        ]);
    }

    public function query(): QueryBuilder
    {
        return new QueryBuilder($this);
    }

    /**
     * @param list<string> $categories
     */
    public function indexEntity(
        int $entityId,
        array $categories = [],
        int $price = 0,
        int $locationPrefix = 0,
        string $tenantId = '',
    ): bool {
        $request = new IndexEntityRequest();
        $request->setEntityId($entityId);
        $request->setCategories(array_values($categories));
        $request->setPrice($price);
        $request->setLocationPrefix($locationPrefix);
        $request->setTenantId($tenantId);

        /** @var \PulseIndex\Engine\V1\IndexEntityResponse $response */
        $response = $this->unary($this->stub->IndexEntity($request, $this->metadata));

        return (bool) $response->getSuccess();
    }

    public function index(Entity $entity): bool
    {
        return $this->indexEntity(
            $entity->entityId,
            $entity->categories,
            $entity->price,
            $entity->locationPrefix,
            $entity->tenantId,
        );
    }

    /**
     * @param list<Entity|array<string, mixed>> $entities
     */
    public function batchIndex(array $entities): int
    {
        $messages = [];
        foreach ($entities as $entity) {
            if (is_array($entity)) {
                $entity = Entity::fromArray($entity);
            }
            if (!$entity instanceof Entity) {
                throw new PulseIndexException('batchIndex expects Entity instances or associative arrays.');
            }

            $request = new IndexEntityRequest();
            $request->setEntityId($entity->entityId);
            $request->setCategories($entity->categories);
            $request->setPrice($entity->price);
            $request->setLocationPrefix($entity->locationPrefix);
            $request->setTenantId($entity->tenantId);
            $messages[] = $request;
        }

        $batch = new BatchIndexEntitiesRequest();
        $batch->setEntities($messages);

        /** @var \PulseIndex\Engine\V1\BatchIndexEntitiesResponse $response */
        $response = $this->unary($this->stub->BatchIndexEntities($batch, $this->metadata));

        return (int) $response->getIndexedCount();
    }

    public function deleteEntity(int $entityId, string $tenantId = ''): bool
    {
        $request = new DeleteEntityRequest();
        $request->setEntityId($entityId);
        $request->setTenantId($tenantId);

        /** @var \PulseIndex\Engine\V1\DeleteEntityResponse $response */
        $response = $this->unary($this->stub->DeleteEntity($request, $this->metadata));

        return (bool) $response->getSuccess();
    }

    public function search(QueryBuilder $query): SearchResult
    {
        /** @var \PulseIndex\Engine\V1\SearchQueryResponse $response */
        $response = $this->unary($this->stub->Search($query->toRequest(), $this->metadata));

        $ids = [];
        foreach ($response->getMatchedEntityIds() as $id) {
            $ids[] = (int) $id;
        }

        return new SearchResult(
            matchedEntityIds: $ids,
            totalMatches: (int) $response->getTotalMatches(),
            executionTimeUs: (int) $response->getExecutionTimeUs(),
        );
    }

    /**
     * @param mixed $call Result of a *_simpleRequest unary call (array{0: Message, 1: object}|object)
     */
    private function unary(mixed $call): object
    {
        if (is_array($call) && isset($call[0], $call[1])) {
            [$response, $status] = $call;
        } elseif (is_object($call) && method_exists($call, 'wait')) {
            [$response, $status] = $call->wait();
        } else {
            throw new PulseIndexException('Unexpected gRPC call return type.');
        }

        $code = is_object($status) ? (int) ($status->code ?? 0) : 0;
        $details = is_object($status) ? (string) ($status->details ?? '') : '';

        if ($code !== 0) {
            throw new GrpcException(
                message: $details !== '' ? $details : "gRPC call failed with status {$code}",
                grpcStatusCode: $code,
                grpcDetails: $details,
            );
        }

        if (!is_object($response)) {
            throw new PulseIndexException('Empty gRPC response.');
        }

        return $response;
    }
}
