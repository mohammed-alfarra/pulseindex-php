#!/usr/bin/env bash
# Sync engine.proto and regenerate PHP protobuf + gRPC stubs.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTO_DIR="${ROOT}/proto"
OUT_DIR="${ROOT}/generated"
PROTO_FILE="${PROTO_DIR}/engine.proto"

# Compiles the VENDORED proto. It is deliberately a subset of the service's —
# the operator RPCs are omitted because no customer key can call them — so
# copying the service's file over it would silently undo that.
#
# Syncing is therefore an explicit act: pass the source path and re-trim by hand.
#   PULSEINDEX_PROTO=../pulseindex-engine/proto/engine.proto scripts/compile-proto.sh --sync
SOURCE_PROTO=""
if [[ "${1:-}" == "--sync" ]]; then
  SOURCE_PROTO="${PULSEINDEX_PROTO:-}"
  if [[ -z "${SOURCE_PROTO}" ]]; then
    echo "error: --sync needs PULSEINDEX_PROTO pointing at the service's proto" >&2
    exit 1
  fi
  echo "WARNING: overwriting the vendored proto. Re-remove the operator RPCs"
  echo "         before committing, or the client will publish them again." >&2
fi

mkdir -p "${PROTO_DIR}" "${OUT_DIR}"

if [[ -n "${SOURCE_PROTO}" && -f "${SOURCE_PROTO}" ]]; then
  echo "Copying proto from ${SOURCE_PROTO}"
  cp "${SOURCE_PROTO}" "${PROTO_FILE}"
else
  echo "Using vendored ${PROTO_FILE}"
fi

if ! grep -q 'option php_namespace' "${PROTO_FILE}"; then
  # Insert PHP namespace options after the package declaration.
  tmp="$(mktemp)"
  awk '
    BEGIN { inserted=0 }
    {
      print
      if (!inserted && $0 ~ /^package pulseindex\.engine\.v1;/) {
        print ""
        print "option php_namespace = \"PulseIndex\\\\Engine\\\\V1\";"
        print "option php_metadata_namespace = \"GPBMetadata\\\\PulseIndex\";"
        inserted=1
      }
    }
  ' "${PROTO_FILE}" > "${tmp}"
  mv "${tmp}" "${PROTO_FILE}"
fi

command -v protoc >/dev/null 2>&1 || {
  echo "error: protoc is required" >&2
  exit 1
}

# Refuse rather than write a client that contradicts the proto beside it.
#
# There used to be a fallback here: with no plugin installed, the script wrote a
# hand-maintained client by heredoc. That client still declared CreateSnapshot,
# GetRecoveryState and SetCdcOffset — the operator RPCs this vendored proto
# deliberately omits — so running the script on a machine without the plugin
# quietly published all three and undid the trim the file above spends a comment
# explaining. It also drifted: any RPC added to the proto was simply missing from
# the client until somebody noticed by hand.
#
# A generator that half-works is worse than one that stops, because its output
# looks generated.
#
# Checked before the wipe below, not after. Sitting after it, a missing plugin
# deleted the committed client and only then refused, so the failure left the
# repository worse than it found it.
if ! command -v grpc_php_plugin >/dev/null 2>&1; then
  cat >&2 <<'MSG'
error: grpc_php_plugin is required and was not found on PATH.

  macOS:  brew install grpc
  Linux:  build it from grpc/grpc, or use a distro package that ships it

There is no fallback on purpose. The one that used to be here wrote a client
declaring the operator RPCs that proto/engine.proto leaves out, so generating
without the plugin published them to every customer.
MSG
  exit 1
fi

rm -rf "${OUT_DIR}/PulseIndex" "${OUT_DIR}/GPBMetadata"
mkdir -p "${OUT_DIR}"

echo "Generating PHP message classes..."
protoc \
  --php_out="${OUT_DIR}" \
  -I "${PROTO_DIR}" \
  "${PROTO_FILE}"

echo "Generating gRPC client via grpc_php_plugin..."
protoc \
  --plugin=protoc-gen-grpc="$(command -v grpc_php_plugin)" \
  --grpc_out="${OUT_DIR}" \
  -I "${PROTO_DIR}" \
  "${PROTO_FILE}"

# Record a normalised hash so scripts/check-proto.sh can detect hand-edits to the
# vendored proto that were not produced by this script.
# Comments are stripped from the hash basis: this pin exists to catch a proto
# edited without regenerating the stubs, and comments do not reach the stubs.
# The vendored copy is published, so its comments are deliberately shorter than
# the engine's — hashing them would fail on every doc edit and teach everyone to
# regenerate without reading why.
sed -E 's://.*::' "${PROTO_FILE}" \
  | grep -vE '^[[:space:]]*(option php_|$)' \
  | shasum -a 256 | awk '{print $1}' \
  > "${PROTO_DIR}/engine.proto.sha256"

echo "Proto compile complete → ${OUT_DIR}"
echo "Baseline hash → ${PROTO_DIR}/engine.proto.sha256"
