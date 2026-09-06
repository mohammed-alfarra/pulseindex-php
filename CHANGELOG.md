# Changelog

## 4.0.0

**A major, not a minor.** The previous draft of these notes said 3.2.0. Checking
what actually breaks says otherwise, so the number says otherwise too.

### Breaking

1. **`withinRadius` returns different results.** It has to: above 8 km it was
   returning **nothing at all**, and below that it over-matched by up to 5.4x.
   Measured against a real engine with 20,000 points:

   | radius | true | before | after |
   |--------|-----:|-------:|------:|
   | 2 km   | 7    | 38     | 9     |
   | 5 km   | 36   | 109    | 42    |
   | 15 km  | 386  | **0**  | 518   |
   | 50 km  | 4,282| **0**  | 4,800 |

2. **`getCoveringHashes()` refuses a precision nothing is indexed at.** Passing
   4 used to return cells that matched no entity; it now throws.

3. **A radius too large for the indexed precisions is refused**, naming the
   latitude. Cells narrow toward the poles, so 50 km is available to about 82
   degrees and 15 km to about 89. Previously such a request came back
   silently covering a fraction of its own circle.

4. **`ClientInterface` gained two methods.** Anything implementing it directly
   must add `batchDelete()` and `searchWithTotal()`. Mocks and the shipped
   `Client` are unaffected.

### The published package could not create its own table

`.gitattributes` carried `/database export-ignore`, and the only thing under
`database/` is the outbox migration the service provider loads. Every install
from v3.1.0 onward therefore shipped a provider whose `loadMigrationsFrom()`
pointed at a directory that was not there: `php artisan migrate` reported
nothing to run, and the first model sync failed with *relation
pulseindex_outbox does not exist*. A package that installs cleanly and cannot
work.

### Every health check threw

`Grpc\Health\V1\HealthCheckResponse` calls `\GPBMetadata\Health::initOnce()`
when it is constructed, and that class was deleted by a build rather than by a
decision: `compile-proto.sh` wipes the whole `GPBMetadata` directory and used to
regenerate only `engine.proto`'s half of it. `pulse:health` reported
*Class "GPBMetadata\Health" not found* on every run from v3.1.0.

Two tests now guard both: one asserts the dist archive carries every path the
installed package reads — deriving them from the service provider rather than
repeating them — and one asserts every `GPBMetadata` class the generated code
initialises exists.

### The covering threshold, corrected against a real app

`withinRadius` is a pre-filter the caller narrows exactly afterwards, so excess
area is cheaper than predicates. The first threshold was too strict: it rejected
the coarse cell at 5 km, turning a 10-cell covering into 167, and a real demo
app's benchmark went from beating PostgreSQL to losing to it by 2.76x on wall
time. The cost is the request, not the search.

Measured in that app, 100,000 properties, 5 km radius:

| | before | after |
|---|---|---|
| covering | 167 cells | 10 cells |
| p50 wall | 3,082 us | 926 us |
| against PostgreSQL | 2.76x slower | 1.35x faster |

The coarse cell is now taken up to 3.0x the circle, which still rejects it at
2 km where it wastes 4.73x to 6.91x.

### Migrating

Nothing to change for the common case: index the same way, call `withinRadius`
the same way, and get results that are actually inside the radius you asked for.

If you pinned expectations to the old counts, they will move. If you passed an
explicit precision, pass one of the indexed precisions or drop the argument. If
you search above 80 degrees latitude at a large radius, catch the refusal.

### Everything else in this release

### The total on a paged search is not the number of matches

A paged search stops as soon as the page is full — that is what makes it cost
microseconds — so the total it reports is whatever it had counted when it
stopped. On a million entities, a query with 166,325 matches reported 10,866
when asked for a page of 100. Anything printing "page 1 of N" from that number
is wrong by an order of magnitude and looks entirely fine.

The result now says which it is, and there is a call that gets you the real one:

```php
$page = $client->search($query->limit(20));
$page->totalIsExact;   // false — the search early-exited
$page->exactTotal();   // null, rather than a number you should not divide

$both = $client->searchWithTotal($query->limit(20));
$both->exactTotal();   // the real total, at the cost of a second round trip
```

`limit(0)` still asks for the count alone and is exact by itself; nothing about
that changed, and `searchWithTotal` skips its second call when you already
passed it.


### `withinRadius` was returning nothing above 8 km

`optimalPrecisionForRadius` chose geohash precision 4 for any radius over 8 km,
and entities are only ever tagged at precisions 5 and 6. A covering at
precision 4 therefore matched **nothing at all**. Measured against a real
engine with 20,000 points around Riyadh:

| radius | true matches | returned, before | returned, after |
|--------|-------------:|-----------------:|----------------:|
| 2 km   | 7            | 38               | 9               |
| 5 km   | 36           | 109              | 42              |
| 15 km  | 386          | **0**            | 518             |
| 50 km  | 4,282        | **0**            | 4,800           |

The precision is now always one the index carries, and of those the finest
whose complete covering fits a cell budget. Small radii also tightened: 2 km
went from 5.4x the true count to 1.3x.

### A covering is no longer truncated in silence

The 64-cell limit stopped the search mid-covering and returned what it had, so
a 50 km circle came back covered 18% and a 1 km circle at fine precision came
back covered 30% — with no error either time. The limit is now a budget the
precision is chosen to fit, so the covering always completes. A radius too
large for any indexed precision is refused by name.

### `withinRadius` is a pre-filter, not an exact radius

Cells are rectangles and the query is a circle, so the result still contains
some points outside it — now about 1.1x to 1.8x the circle's area rather than
up to 6x. The engine stores no coordinates, so only you can filter the
remainder, from your own data after hydration. This was always true and was
never written down.


### Delete many entities in one call

`deleteEntity()` takes a single id, so clearing a catalogue meant one round trip
per row. There was no other way to do it through the API at all.

```php
foreach (array_chunk($idsToRemove, 10000) as $page) {
    $deleted = $client->batchDelete($page, 'acme');
}
```

Up to 10,000 ids per call. A larger page is refused by name rather than
truncated, so a page that is too big fails loudly instead of deleting part of
itself and reporting success.

Ids that are unknown or already deleted are skipped rather than refused, so
retrying a page that half-applied is safe. The return value is the number of
rows that actually changed, which is lower than `count($page)` whenever some
were already gone.

An id that is not a non-negative integer names its own position in the message
and nothing is sent, so a bad value in a page of ten thousand does not leave you
bisecting your own input against a server error.

### The outbox worker uses it

`OutboxWorker` batched its upserts and then deleted one row at a time, because
there was nothing else to call. A drain claiming a thousand deletes made a
thousand round trips. It is now one call per tenant, split at the engine's batch
maximum: 3,000 deletes went from three passes and a twenty-second stall to
0.018 s.

Nothing to change on your side — `outbox:work` and `pulseindex:reindex` behave
the same and finish sooner.

**Correcting what an earlier note here claimed.** This change was first
described as fixing rows that would "spend the whole per-key ceiling in one
drain" and "park as failed after twelve attempts". Both were measured
afterwards and both were wrong:

- A single drain of 1,000 deletes is **not** refused. Against a real engine the
  first refusal came at request 1,372.
- Rows **do not** park. A refused delete backs off 20 seconds, and the token
  bucket refills completely in that time, so the retry succeeds. Driving the
  pre-change worker through a 3,000-row backlog ended with **zero** rows at
  `failed_at`.

What is true is that continuous draining of a backlog over ~1,372 rows did get
refusals (344 then 663 across passes) and finished about twenty seconds later
than it needed to. Slower and noisier in `last_error`, not broken.

## 3.1.0

### A radius, and each whereIn, no longer merge into one OR

Every SHOULD predicate went into a single disjunction, because until now the
engine had only one. Two places in this SDK produce SHOULD predicates on their
own, and both were silently merging with each other and with yours.

`withinRadius` turns a circle into one SHOULD per covering geohash cell, so

```php
$client->query()->should('color:red')->should('color:blue')->withinRadius($lat, $lon, 5);
```

asked for "within 5 km **or** red **or** blue". And on the Laravel builder,

```php
Property::pulseSearch()->whereIn('amenity', ['parking','gym'])->whereIn('city', ['leon','madrid']);
```

asked for one OR of all four values rather than an amenity **and** a city.

Both now build a disjunction of their own, and each further radius or whereIn
takes another. A query that used only one of them is unaffected.

### Groups

`should()` takes a group number. Members of a group are OR'd together and the
groups are AND'd with each other:

```php
$client->query()
    ->should('color:red', 1)->should('color:blue', 1)
    ->should('size:s', 2)->should('size:m', 2);
```

Left unset it is 0, which is one disjunction — what every existing query does.

### Ordering

`sortAsc($field)`, `sortDesc($field)` and `sortBy($field, $descending)`:

```php
$client->query()->must('status:active')->sortAsc('price')->execute();
```

Rows carrying no value for the field sort last in both directions; they still
count towards the total, they simply have nothing to be ordered by.

An ordered search cannot stop as soon as the page is full — the cheapest
remaining row may be anywhere in the tenant — so it costs more than the same
filter unordered. `offset + limit` is capped at 100,000 and a request past it
is refused with the ceiling named.

## 3.0.0

### Breaking: the Eloquent fallback is off, and no longer guesses

When the engine was unreachable, the fallback rewrote attribute filters into
database columns by splitting each tag on its colon: `feature:pool` became
`where('feature', '=', 'pool')`. Most models have no `feature` column, so the
query either failed in SQL or matched nothing — and the caller was told only by
a line in the log, while holding rows that looked like an answer.

Two changes. `fallback_enabled` now defaults to **false**: an unreachable
engine raises rather than quietly answering a near-enough question. And where
it is switched on, a model says what its schema can answer:

```php
public function pulseFallbackMap(): array
{
    return ['status' => 'status', 'city' => 'city_name'];
}
```

A query touching an attribute that is not mapped raises
`PulseIndexFallbackUnavailable` instead of running. To keep the old behaviour,
set `PULSEINDEX_FALLBACK_ENABLED=true` and declare the map — there is no
setting that restores the guessing.

### Breaking: a query returns a page instead of everything

`QueryBuilder` defaulted to a limit of 0, which the engine read as "no
ceiling" and answered with every matching id the tenant held. Nobody writing
`->get()` meant to ask for that, and the cost of it landed on the service
rather than on the caller who never mentioned a limit.

The default is now `QueryBuilder::DEFAULT_LIMIT`, a hundred, on both the plain
builder and the Laravel one. If you relied on getting every match back, say so:

```php
Property::pulseSearch()->where('city', 'Riyadh')->limit(5000)->get();
```

A limit above the engine's maximum is refused with the maximum named, rather
than quietly trimmed — a short page that looks complete is worse than an error.

### Zero now means the count

`limit(0)` no longer means "no ceiling". It asks the engine for the number of
matches and no ids at all, which is the cheap way to count:

```php
$total = $client->search($client->query()->tenant('acme')->must('status:active')->limit(0))
    ->totalMatches;
```

Requires an engine that speaks this contract. Against an older engine, a limit
of 0 still returns every id.

## 2.0.0

### Breaking: the operator surface is gone

`AdminHttpClient` and `pulse:reindex --recovery` are removed, with the
`admin_url`, `admin_port` and `internal_token` configuration behind them.

Neither could ever work from an application. They were shipped tooling for a
capability that was never the client's. Remove those three keys from your
published `config/pulseindex.php` when you upgrade; nothing reads them.

`pulse:reindex` still rebuilds the index from your models. If the service stops
answering queries, run it — the service resumes once the outbox drains.

### Breaking: `getRecoveryState()` and `RecoveryState` are gone

No API key could call it — every attempt returned a permission error — so
nothing that worked stops working.

**Checking readiness:** `health()`, or `servingStatus()` when you need to tell
"not answering" apart from "not reachable". Both work with any key.

Three commands changed with it:

- `pulse:reindex --recovery` gates on the health service instead. Same
  behaviour: it refuses to run while the service is answering normally.
- `pulse:reconcile` lost its shortcut: every run now walks the models. Slower,
  same answer. `--full` is accepted and ignored.
- `pulse:health` no longer reports `indexed_count`; the JSON carries `null`.

### Breaking: `ClientInterface` gained two methods

```php
public function servingStatus(string $service = ''): int;
public function health(): bool;
```

If you implement `PulseIndex\ClientInterface` — most likely a fake in your test
suite — add both. Nothing else is required; the real `Client` implements them
already.

The major bump is for that alone. Nothing else in the public API changed, and
no behaviour you were relying on has been removed.

```php
// A test double needs these two, and can answer them statically:
public function servingStatus(string $service = ''): int
{
    return \Grpc\Health\V1\HealthCheckResponse\ServingStatus::SERVING;
}

public function health(): bool
{
    return true;
}
```

### `pulse:health` reported a healthy service as unreachable

`pulse:health` reported `engine_reachable: false` for a service that was
perfectly healthy, and `pulse:reconcile` aborted before doing any work.

Both now read the standard `grpc.health.v1.Health` protocol. `pulse:health`
still reports `indexed_count` when it can, but that now happens **after**
readiness has been decided, so failing to get the number no longer condemns the
service.

### Added

- `Client::health(): bool` and `Client::servingStatus(string $service = ''): int`.
- `Grpc\Health\V1\HealthClient` and its message classes, under `generated/`.

## 1.0.0

Initial release.
