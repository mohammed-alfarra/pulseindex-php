# Changelog

## 2.0.0

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

Both console commands used an operator-only call that customer API keys are not
permitted to make, so it came back `PERMISSION_DENIED`.

`pulse:health` caught that and reported `engine_reachable: false`. The engine
was fine; the key was simply not allowed to ask. A single `catch` could not tell
*denied* from *down*, so it called it down.

`pulse:reconcile` did not catch it at all and aborted before doing any work.

Both now read the standard `grpc.health.v1.Health` protocol, which needs no
particular scope. `pulse:health` still reports `indexed_count` when the key is
allowed to read it, but that now happens **after** readiness has been decided,
so failing to get the number no longer condemns the service.

### Added

- `Client::health(): bool` and `Client::servingStatus(string $service = ''): int`.
- `Grpc\Health\V1\HealthClient` and its message classes, under `generated/`.

## 1.0.0

Initial release.
