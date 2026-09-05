<?php

declare(strict_types=1);

namespace PulseIndex;

use PulseIndex\Engine\V1\FilterPredicate;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Engine\V1\RangePredicate;
use PulseIndex\Engine\V1\SearchQueryRequest;
use PulseIndex\Engine\V1\SortSpec;
use PulseIndex\Geo\GeoHash;

/**
 * Fluent builder for PulseIndex Search RPCs.
 */
final class QueryBuilder
{
    /**
     * A page, for callers who never say otherwise.
     *
     * The default used to be 0, which the engine read as "no ceiling" and
     * answered with every matching id the tenant had. That is a request nobody
     * meant to make, and the cost of it landed on the service rather than on
     * the caller who forgot the limit.
     */
    public const DEFAULT_LIMIT = 100;

    private string $tenantId = '';

    private int $locationPrefix = 0;

    private int $limit = self::DEFAULT_LIMIT;

    private int $offset = 0;

    /** @var list<array{op: int, attribute: string, group: int}> */
    private array $filters = [];

    /** @var list<array{field: string, min: int, max: int}> */
    private array $ranges = [];

    /** @var array{field: string, descending: bool}|null */
    private ?array $sort = null;

    /**
     * The next disjunction number to hand out.
     *
     * Group 0 is the default and belongs to plain should() calls. Anything
     * that builds a disjunction of its own - a radius, which becomes one
     * SHOULD per covering cell - takes a number from here, so it cannot merge
     * with a disjunction the caller wrote.
     */
    private int $nextGroup = 1;

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

    /**
     * At least one of these has to match.
     *
     * Pass a $group to keep a disjunction separate from another one. Members
     * of a group are OR'd together and the groups are AND'd with each other,
     * so two groups ask for a red or blue shirt in small or medium. Left
     * unset it is 0, which is one disjunction - what every query did before
     * groups existed.
     */
    public function should(string $attribute, int $group = 0): self
    {
        return $this->filter(Operation::SHOULD, $attribute, $group);
    }

    public function mustNot(string $attribute): self
    {
        return $this->filter(Operation::MUST_NOT, $attribute);
    }

    /**
     * Exact GeoHash cell match: MUST `geo:{precision}:{hash}` tag.
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
     * intersect the circle.
     *
     * The cells go into a disjunction of their own. They are one geographic
     * constraint spelled as "any of these", and before groups existed they
     * shared the single OR with everything else the caller had asked for - so
     * "within 5 km and (red or blue)" was answered as "within 5 km or red or
     * blue", quietly, with a plausible page of results. Each further radius
     * takes another group, so two circles are AND'd.
     *
     * When $precision is omitted, {@see GeoHash::optimalPrecisionForRadius()} is used.
     */
    public function withinRadius(float $lat, float $lon, float $radiusKm, ?int $precision = null): self
    {
        $clone = clone $this;
        $group = $clone->nextGroup;
        $clone->nextGroup++;
        foreach (GeoHash::getCoveringHashes($lat, $lon, $radiusKm, $precision) as $hash) {
            $clone->filters[] = [
                'op' => Operation::SHOULD,
                'attribute' => GeoHash::tag($hash),
                'group' => $group,
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

    /**
     * How many ids to return. Zero asks the engine for the total number of
     * matches and no ids at all, which is the cheap way to count.
     */
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

    /**
     * Order the page by a numeric field, smallest first.
     *
     * An ordered search cannot stop as soon as the page is full - the cheapest
     * remaining row may be anywhere in the tenant - so it costs more than the
     * same filter unordered. offset + limit is capped at 100,000.
     */
    public function sortAsc(string $field): self
    {
        return $this->sortBy($field, false);
    }

    /** Order the page by a numeric field, largest first. */
    public function sortDesc(string $field): self
    {
        return $this->sortBy($field, true);
    }

    /**
     * Order the page by a numeric field. Rows carrying no value for it sort
     * last in both directions; they still count towards totalMatches, they
     * simply have nothing to be ordered by.
     */
    public function sortBy(string $field, bool $descending = false): self
    {
        if (trim($field) === '') {
            throw new Exception\PulseIndexException('Sort field must not be empty.');
        }

        $clone = clone $this;
        $clone->sort = ['field' => $field, 'descending' => $descending];

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
            $predicate->setGroup($filter['group']);
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

        if ($this->sort !== null) {
            $sort = new SortSpec();
            $sort->setField($this->sort['field']);
            $sort->setDescending($this->sort['descending']);
            $request->setSort($sort);
        }

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
     *     filters: list<array{op: int, attribute: string, group: int}>,
     *     ranges: list<array{field: string, min: int, max: int}>,
     *     sort: array{field: string, descending: bool}|null
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
            'sort' => $this->sort,
        ];
    }

    private function filter(int $op, string $attribute, int $group = 0): self
    {
        $group = max(0, $group);

        $clone = clone $this;
        $clone->filters[] = [
            'op' => $op,
            'attribute' => $attribute,
            'group' => $group,
        ];
        // A caller naming their own group must not have it handed out again to
        // a radius later in the same chain.
        if ($group >= $clone->nextGroup) {
            $clone->nextGroup = $group + 1;
        }

        return $clone;
    }
}
