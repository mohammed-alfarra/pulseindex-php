# Changelog

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
