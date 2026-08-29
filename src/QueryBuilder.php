<?php

declare(strict_types=1);

namespace PulseIndex;

use PulseIndex\Engine\V1\FilterPredicate;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Engine\V1\RangePredicate;
use PulseIndex\Engine\V1\SearchQueryRequest;
use PulseIndex\Geo\GeoHash;

/**
 * Fluent builder for PulseIndex Search RPCs.
 */
final class QueryBuilder
{
    private string $tenantId = '';

    private int $locationPrefix = 0;

    private int $limit = 0;

    private int $offset = 0;

    /** @var list<array{op: int, attribute: string}> */
    private array $filters = [];

    /** @var list<array{field: string, min: int, max: int}> */
    private array $ranges = [];

    public function __construct(
        private readonly ?ClientInterface $client = null,
    ) {
    }

    public function tenant(string $tenantId): self
    {
        $clone = clone $this;
        $clone->tenantId = $tenantId;

        return $clone;
    }

    public function location(int $locationPrefix): self
    {
        $clone = clone $this;
        $clone->locationPrefix = $locationPrefix;

        return $clone;
    }

    public function must(string $attribute): self
    {
        return $this->filter(Operation::MUST, $attribute);
    }

    public function should(string $attribute): self
    {
        return $this->filter(Operation::SHOULD, $attribute);
    }

    public function mustNot(string $attribute): self
    {
        return $this->filter(Operation::MUST_NOT, $attribute);
    }

    /**
     * Exact GeoHash cell match: MUST `geo:{precision}:{hash}` bitwise tag.
     */
    public function whereGeoHash(string $geohash): self
    {
        return $this->must(GeoHash::tag($geohash));
    }

    /**
     * Alias of {@see whereGeoHash()}.
     */
    public function inGeoHash(string $geohash): self
    {
        return $this->whereGeoHash($geohash);
    }

    /**
     * Radius coverage as SHOULD `geo:{precision}:{hash}` tags for cells that
     * intersect the circle (engine ORs the SHOULD group, then ANDs it in).
     *
     * When $precision is omitted, {@see GeoHash::optimalPrecisionForRadius()} is used.
     */
    public function withinRadius(float $lat, float $lon, float $radiusKm, ?int $precision = null): self
    {
        $clone = clone $this;
        foreach (GeoHash::getCoveringHashes($lat, $lon, $radiusKm, $precision) as $hash) {
            $clone->filters[] = [
                'op' => Operation::SHOULD,
                'attribute' => GeoHash::tag($hash),
            ];
        }

        return $clone;
    }

    public function range(string $field, int $min, int $max): self
    {
        $clone = clone $this;
        $clone->ranges[] = [
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ];

        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = max(0, $limit);

        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = max(0, $offset);

        return $clone;
    }

    public function toRequest(): SearchQueryRequest
    {
        $request = new SearchQueryRequest();
        $request->setTenantId($this->tenantId);
        $request->setLocationPrefix($this->locationPrefix);
        $request->setLimit($this->limit);
        $request->setOffset($this->offset);

        $predicates = [];
        foreach ($this->filters as $filter) {
            $predicate = new FilterPredicate();
            $predicate->setOp($filter['op']);
            $predicate->setAttribute($filter['attribute']);
            $predicates[] = $predicate;
        }
        $request->setFilters($predicates);

        $rangeMessages = [];
        foreach ($this->ranges as $range) {
            $predicate = new RangePredicate();
            $predicate->setField($range['field']);
            $predicate->setMinVal($range['min']);
            $predicate->setMaxVal($range['max']);
            $rangeMessages[] = $predicate;
        }
        $request->setRanges($rangeMessages);

        return $request;
    }

    /**
     * Execute via the bound Client when constructed from Client::query().
     */
    public function execute(): SearchResult
    {
        if ($this->client === null) {
            throw new Exception\PulseIndexException(
                'QueryBuilder has no Client; pass the builder to Client::search() or create it via Client::query().'
            );
        }

        return $this->client->search($this);
    }

    /**
     * @return array{
     *     tenant_id: string,
     *     location_prefix: int,
     *     limit: int,
     *     offset: int,
     *     filters: list<array{op: int, attribute: string}>,
     *     ranges: list<array{field: string, min: int, max: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'location_prefix' => $this->locationPrefix,
            'limit' => $this->limit,
            'offset' => $this->offset,
            'filters' => $this->filters,
            'ranges' => $this->ranges,
        ];
    }

    private function filter(int $op, string $attribute): self
    {
        $clone = clone $this;
        $clone->filters[] = [
            'op' => $op,
            'attribute' => $attribute,
        ];

        return $clone;
    }
}
