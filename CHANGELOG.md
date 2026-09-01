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

### `pulse:health` reported healthy engines as unreachable

Both console commands read `GetRecoveryState`, which requires the `admin` scope.
The engine refuses `admin` to any key bound to a tenant — every key the
PulseIndex dashboard issues — so the call came back `PERMISSION_DENIED`.

`pulse:health` caught that and reported `engine_reachable: false`. The engine
was fine; the key was simply not allowed to ask. A single `catch` could not tell
*denied* from *down*, so it called it down.

`pulse:reconcile` did not catch it at all and aborted before doing any work.

Both now read `grpc.health.v1.Health`, which the engine serves without its auth
interceptor — no scope needed. `pulse:health` still reports `indexed_count` when
the key is allowed to read it, but that now happens **after** reachability has
been decided, so failing to get the number no longer condemns the engine.

### Added

- `Client::health(): bool` and `Client::servingStatus(string $service = ''): int`.
- `Grpc\Health\V1\HealthClient` and its message classes, under `generated/`.

## 1.0.0

Initial release.
