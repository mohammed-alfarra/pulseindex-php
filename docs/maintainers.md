# Maintainer notes

Excluded from the Composer archive by `.gitattributes` on purpose. This
describes how the SDK is built and kept in step with the service, which is of
no use to someone installing it.

## Keeping the proto in sync

The SDK vendors `proto/engine.proto`. Two guards cover different failures.

**`vendor/bin/phpunit --testsuite Unit`** pins the vendored schema — every RPC, message,
field number and type, and enum value — in `tests/Unit/ProtoSchemaTest.php`. Any change to
the proto fails the suite until the fixture is updated, so the change lands in the
pull-request diff instead of passing unseen. Seven meta-tests prove the guard bites rather
than merely existing.

It cannot tell you the vendored copy matches the engine. The fixture pins what this
repository believes; if the engine moves ahead and nobody syncs, the file and the fixture
stay stale and agree with each other.

**`composer check:proto`** closes that, in three layers:

1. the vendored proto still matches the stubs generated from it (`engine.proto.sha256`)
2. its schema matches the engine's, reported as semantic differences
3. textual-only drift (comments, ordering) — a warning, not an error

It finds the engine at `PULSEINDEX_PROTO` or a sibling checkout. With neither it exits 0
but prints `NOT VERIFIED` — never `ok` — because a guard that could not reach its source
must not look like a passing guard. `composer check:proto:ci` (`--require-engine`) makes
that state an error instead; CI uses it, so a missing or expired `ENGINE_REPO_TOKEN`
breaks the build rather than silently downgrading the check. A `PULSEINDEX_PROTO` that
points nowhere is always an error — there is no fallback that could hide it.

Regenerate with `composer build-proto` (which rewrites the hash and the stubs), update the
fixture, and commit, whenever the engine's contract changes.

---

