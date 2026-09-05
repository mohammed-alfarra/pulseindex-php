<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Exception\PulseIndexFallbackUnavailable;
use PulseIndex\QueryBuilder;
use PulseIndex\SearchResult;

/**
 * Fluent Eloquent hydrator over PulseIndex Search RPCs.
 *
 * @template TModel of Model
 */
final class PulseQueryBuilder
{
    private ?string $tenantId = null;

    private ?int $locationPrefix = null;

    /**
     * A page unless the caller asks for another size.
     *
     * Search results are paginated everywhere else in the world, and the
     * alternative here was worse than a default: the engine reads a limit of
     * zero as a request for the total alone, so `get()` would have come back
     * empty rather than complete.
     */
    private int $limit = QueryBuilder::DEFAULT_LIMIT;

    private int $offset = 0;

    /** @var list<string> */
    private array $must = [];

    /**
     * One entry per whereIn call, each a disjunction of its own.
     *
     * This used to be a flat list of tokens. Every whereIn poured into it, so
     * two of them merged: whereIn('color', ['red','blue'])->whereIn('size',
     * ['s','m']) asked for red or blue or small or medium, and returned a
     * plausible page that answered a different question.
     *
     * @var list<list<string>>
     */
    private array $should = [];

    /** @var list<array{field: string, min: int, max: int}> */
    private array $ranges = [];

    /** @var array{lat: float, lon: float, radiusKm: float, precision: ?int}|null */
    private ?array $geo = null;

    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $eloquentWheres = [];

    /** @var list<array{field: string, values: list<mixed>}> */
    private array $eloquentWhereIns = [];

    /**
     * @param class-string<TModel> $modelClass
     */
    public function __construct(
        private readonly string $modelClass,
        private ?ClientInterface $client = null,
    ) {
    }

    public function tenant(string $tenantId): self
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function location(int $locationPrefix): self
    {
        $this->locationPrefix = $locationPrefix;

        return $this;
    }

    /**
     * MUST attribute filter.
     *
     * `where('feature:pool')` uses the token as-is.
     * `where('status', 'open')` becomes MUST `status:open` (and Eloquent `status = open` on fallback).
     */
    public function where(string $field, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 1) {
            $this->must[] = $field;
            $this->eloquentWheres[] = $this->fallbackFromToken($field);

            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->must[] = $this->tokenize($field, $value);
        $this->eloquentWheres[] = [
            'field' => $field,
            'operator' => (string) $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * One disjunction per call: the values are OR'd with each other, and the
     * call is AND'd with every other filter, including other whereIn calls.
     *
     * @param list<mixed> $values
     */
    public function whereIn(string $field, array $values): self
    {
        $values = array_values($values);
        $group = [];
        foreach ($values as $value) {
            $group[] = $this->tokenize($field, $value);
        }
        if ($group !== []) {
            $this->should[] = $group;
        }

        $this->eloquentWhereIns[] = [
            'field' => $field,
            'values' => $values,
        ];

        return $this;
    }

    public function whereRange(string $field, int $min, int $max): self
    {
        $this->ranges[] = [
            'field' => $field,
            'min' => $min,
            'max' => $max,
        ];

        return $this;
    }

    public function whereGeoRadius(float $lat, float $lon, float $radiusKm, ?int $precision = null): self
    {
        $this->geo = [
            'lat' => $lat,
            'lon' => $lon,
            'radiusKm' => $radiusKm,
            'precision' => $precision,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    public function toPulseQuery(?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $query = new QueryBuilder($this->resolveClient());

        $tenant = $this->tenantId ?? $this->newModel()->pulseTenantId();
        if ($tenant !== '') {
            $query = $query->tenant($tenant);
        }

        if ($this->locationPrefix !== null) {
            $query = $query->location($this->locationPrefix);
        }

        foreach ($this->must as $attribute) {
            $query = $query->must($attribute);
        }

        // Group 0 is left for a caller writing should() on the builder
        // directly, so these start at 1.
        foreach ($this->should as $index => $group) {
            foreach ($group as $attribute) {
                $query = $query->should($attribute, $index + 1);
            }
        }

        foreach ($this->ranges as $range) {
            $query = $query->range($range['field'], $range['min'], $range['max']);
        }

        if ($this->geo !== null) {
            $query = $query->withinRadius(
                $this->geo['lat'],
                $this->geo['lon'],
                $this->geo['radiusKm'],
                $this->geo['precision'],
            );
        }

        $limit ??= $this->limit;
        $offset ??= $this->offset;

        if ($limit > 0) {
            $query = $query->limit($limit);
        }
        if ($offset > 0) {
            $query = $query->offset($offset);
        }

        return $query;
    }

    /**
     * @return EloquentCollection<int, TModel>
     */
    public function get(): EloquentCollection
    {
        try {
            $result = $this->searchPulse($this->limit, $this->offset);
        } catch (PulseIndexException $e) {
            return $this->fallbackOrThrow($e, fn () => $this->fallbackQuery()->get());
        }

        return $this->hydrate($result->matchedEntityIds);
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 15, ?int $page = null): LengthAwarePaginator
    {
        $page ??= Paginator::resolveCurrentPage();
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        try {
            $result = $this->searchPulse($perPage, $offset);
        } catch (PulseIndexException $e) {
            return $this->fallbackOrThrow(
                $e,
                fn () => $this->fallbackQuery()->paginate($perPage, ['*'], 'page', $page),
            );
        }

        $models = $this->hydrate($result->matchedEntityIds);

        return new LengthAwarePaginator(
            $models,
            $result->totalMatches,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ],
        );
    }

    /**
     * @return TModel
     */
    private function newModel(): Model
    {
        return new $this->modelClass();
    }

    private function resolveClient(): ClientInterface
    {
        if ($this->client instanceof ClientInterface) {
            return $this->client;
        }

        if (function_exists('app')) {
            if (app()->bound(ClientInterface::class)) {
                return $this->client = app(ClientInterface::class);
            }
            if (app()->bound(Client::class)) {
                return $this->client = app(Client::class);
            }
        }

        throw new PulseIndexException('PulseIndex Client is not bound in the container.');
    }

    private function searchPulse(int $limit, int $offset): SearchResult
    {
        $query = $this->toPulseQuery($limit, $offset);

        return $this->resolveClient()->search($query);
    }

    /**
     * @param list<int> $ids
     * @return EloquentCollection<int, TModel>
     */
    private function hydrate(array $ids): EloquentCollection
    {
        $model = $this->newModel();
        $collection = $model->newCollection();

        if ($ids === []) {
            return $collection;
        }

        $keyName = $model->getKeyName();
        /** @var EloquentCollection<int, TModel> $fetched */
        $fetched = $model->newQuery()->whereIn($keyName, $ids)->get()->keyBy($keyName);

        foreach ($ids as $id) {
            $match = $fetched->get($id) ?? $fetched->get((string) $id);
            if ($match !== null) {
                $collection->push($match);
            }
        }

        return $collection;
    }

    private function fallbackQuery(): Builder
    {
        $model = $this->newModel();
        $query = $model->newQuery();

        // Every field here is present in the map: fallbackOrThrow refuses the
        // query otherwise, so this never guesses at a column name.
        $map = $this->fallbackMap();

        foreach ($this->eloquentWheres as $where) {
            $query->where($map[$where['field']], $where['operator'], $where['value']);
        }

        foreach ($this->eloquentWhereIns as $whereIn) {
            $query->whereIn($map[$whereIn['field']], $whereIn['values']);
        }

        foreach ($this->ranges as $range) {
            $query->whereBetween($map[$range['field']], [$range['min'], $range['max']]);
        }

        if ($this->geo !== null) {
            $this->applyGeoFallback($query, $model);
        }

        if ($this->limit > 0) {
            $query->limit($this->limit);
        }
        if ($this->offset > 0) {
            $query->offset($this->offset);
        }

        return $query;
    }

    private function applyGeoFallback(Builder $query, Model $model): void
    {
        if ($this->geo === null || !method_exists($model, 'pulseLatitudeColumn')) {
            return;
        }

        $lat = $this->geo['lat'];
        $lon = $this->geo['lon'];
        $radiusKm = $this->geo['radiusKm'];
        $latDelta = $radiusKm / 111.32;
        $cosLat = cos(deg2rad($lat));
        $lonDelta = abs($cosLat) < 1e-6 ? 180.0 : $radiusKm / (111.32 * abs($cosLat));

        $query->whereBetween($model->pulseLatitudeColumn(), [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween($model->pulseLongitudeColumn(), [$lon - $lonDelta, $lon + $lonDelta]);
    }

    /**
     * @template T
     * @param callable(): T $fallback
     * @return T
     */
    private function fallbackOrThrow(PulseIndexException $e, callable $fallback): mixed
    {
        if (!$this->fallbackEnabled()) {
            throw $e;
        }

        // Attribute filters are tags. Unless the model has said which column
        // answers which namespace, there is nothing to translate them into,
        // and a query built from a guess answers a different question while
        // looking like an answer to this one.
        $untranslatable = $this->untranslatableFields();
        if ($untranslatable !== []) {
            throw PulseIndexFallbackUnavailable::forFields($untranslatable, $this->modelClass);
        }

        // Logged here rather than on the way in: this used to say it was
        // falling back before deciding whether it would.
        Log::warning('PulseIndex connection failed; falling back to Eloquent.', [
            'model' => $this->modelClass,
            'message' => $e->getMessage(),
        ]);

        return $fallback();
    }

    /**
     * Attribute namespaces in this query that the model cannot answer from a
     * column.
     *
     * @return list<string>
     */
    private function untranslatableFields(): array
    {
        $map = $this->fallbackMap();
        $missing = [];

        foreach ($this->eloquentWheres as $where) {
            if (!array_key_exists($where['field'], $map)) {
                $missing[] = $where['field'];
            }
        }
        foreach ($this->eloquentWhereIns as $whereIn) {
            if (!array_key_exists($whereIn['field'], $map)) {
                $missing[] = $whereIn['field'];
            }
        }
        foreach ($this->ranges as $range) {
            if (!array_key_exists($range['field'], $map)) {
                $missing[] = $range['field'];
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return array<string, string>
     */
    private function fallbackMap(): array
    {
        $model = $this->newModel();

        return method_exists($model, 'pulseFallbackMap') ? $model->pulseFallbackMap() : [];
    }

    /**
     * Off unless asked for.
     *
     * On by default, an unreachable engine silently became a different query
     * against the database — same shape, different answer, and only a log line
     * to say so. Opting in is how a caller says they would rather have
     * approximate rows than an error.
     */
    private function fallbackEnabled(): bool
    {
        if (function_exists('config')) {
            return (bool) config('pulseindex.fallback_enabled', false);
        }

        return false;
    }

    private function tokenize(string $field, mixed $value): string
    {
        $string = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        if (str_contains($string, ':')) {
            return $string;
        }

        return $field . ':' . $string;
    }

    /**
     * @return array{field: string, operator: string, value: mixed}
     */
    private function fallbackFromToken(string $token): array
    {
        $pos = strpos($token, ':');
        if ($pos === false) {
            return [
                'field' => $token,
                'operator' => '=',
                'value' => true,
            ];
        }

        return [
            'field' => substr($token, 0, $pos),
            'operator' => '=',
            'value' => substr($token, $pos + 1),
        ];
    }
}
