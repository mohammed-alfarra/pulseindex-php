#!/usr/bin/env bash
# Guard against proto drift.
#
#   (a) offline  — the vendored proto still matches proto/engine.proto.sha256
#                  (i.e. it was produced by scripts/compile-proto.sh, not hand-edited)
#   (b) engine   — when the engine's proto is reachable, the vendored copy matches it
#
# (a) always runs. (b) is skipped (not failed) when the engine checkout is absent.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTO_FILE="${ROOT}/proto/engine.proto"
HASH_FILE="${ROOT}/proto/engine.proto.sha256"

normalise() { grep -vE '^[[:space:]]*(option php_|$)' "$1"; }

fail=0

# (a) offline hash
if [[ ! -f "${HASH_FILE}" ]]; then
  echo "error: ${HASH_FILE} missing — run 'composer build-proto'." >&2
  exit 1
fi
have="$(normalise "${PROTO_FILE}" | shasum -a 256 | awk '{print $1}')"
want="$(tr -d '[:space:]' < "${HASH_FILE}")"
if [[ "${have}" != "${want}" ]]; then
  echo "error: proto/engine.proto was edited without regenerating." >&2
  echo "       run 'composer build-proto' and commit the result." >&2
  fail=1
else
  echo "ok: vendored proto matches its baseline hash"
fi

# (b) engine diff, best effort
SOURCE_PROTO="${PULSEINDEX_PROTO:-}"
if [[ -z "${SOURCE_PROTO}" ]]; then
  for candidate in \
    "${ROOT}/../pulseindex-engine/proto/engine.proto" \
    "${ROOT}/../PulseIndex/proto/engine.proto"; do
    [[ -f "${candidate}" ]] && SOURCE_PROTO="${candidate}" && break
  done
fi

if [[ -n "${SOURCE_PROTO}" && -f "${SOURCE_PROTO}" ]]; then
  if diff <(normalise "${SOURCE_PROTO}") <(normalise "${PROTO_FILE}") >/dev/null; then
    echo "ok: vendored proto matches the engine (${SOURCE_PROTO})"
  else
    echo "error: vendored proto is stale vs the engine:" >&2
    diff <(normalise "${SOURCE_PROTO}") <(normalise "${PROTO_FILE}") || true
    echo "       run 'composer build-proto' and commit." >&2
    fail=1
  fi
else
  echo "note: engine proto not found (set PULSEINDEX_PROTO or check out pulseindex-engine) — skipping engine diff"
fi

exit "${fail}"
