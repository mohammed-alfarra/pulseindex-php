#!/usr/bin/env bash
# Sync engine.proto and regenerate PHP protobuf + gRPC stubs.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTO_DIR="${ROOT}/proto"
OUT_DIR="${ROOT}/generated"
PROTO_FILE="${PROTO_DIR}/engine.proto"

# Prefer an explicit path, then sibling pulseindex-engine checkout, else vendored copy.
SOURCE_PROTO="${PULSEINDEX_PROTO:-}"
if [[ -z "${SOURCE_PROTO}" ]]; then
  CANDIDATES=(
    "${ROOT}/../pulseindex-engine/proto/engine.proto"
    "${ROOT}/../PulseIndex/proto/engine.proto"
  )
  for candidate in "${CANDIDATES[@]}"; do
    if [[ -f "${candidate}" ]]; then
      SOURCE_PROTO="${candidate}"
      break
    fi
  done
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

rm -rf "${OUT_DIR}/PulseIndex" "${OUT_DIR}/GPBMetadata"
mkdir -p "${OUT_DIR}"

echo "Generating PHP message classes..."
protoc \
  --php_out="${OUT_DIR}" \
  -I "${PROTO_DIR}" \
  "${PROTO_FILE}"

CLIENT_OUT="${OUT_DIR}/PulseIndex/Engine/V1/SearchEngineServiceClient.php"
mkdir -p "$(dirname "${CLIENT_OUT}")"

if command -v grpc_php_plugin >/dev/null 2>&1; then
  echo "Generating gRPC client via grpc_php_plugin..."
  protoc \
    --plugin=protoc-gen-grpc="$(command -v grpc_php_plugin)" \
    --grpc_out="${OUT_DIR}" \
    -I "${PROTO_DIR}" \
    "${PROTO_FILE}"
else
  echo "grpc_php_plugin not found; writing SearchEngineServiceClient stub..."
  cat > "${CLIENT_OUT}" <<'PHP'
<?php
/**
 * gRPC client stub for SearchEngineService.
 * Written by scripts/compile-proto.sh when grpc_php_plugin is unavailable.
 */

namespace PulseIndex\Engine\V1;

class SearchEngineServiceClient extends \Grpc\BaseStub
{
    /**
     * @param string $hostname
     * @param array<string, mixed> $opts
     * @param mixed $channel
     */
    public function __construct($hostname, $opts, $channel = null)
    {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param IndexEntityRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function IndexEntity(IndexEntityRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/IndexEntity',
            $argument,
            [IndexEntityResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param BatchIndexEntitiesRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function BatchIndexEntities(BatchIndexEntitiesRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/BatchIndexEntities',
            $argument,
            [BatchIndexEntitiesResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param DeleteEntityRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function DeleteEntity(DeleteEntityRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/DeleteEntity',
            $argument,
            [DeleteEntityResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param SearchQueryRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function Search(SearchQueryRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/Search',
            $argument,
            [SearchQueryResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param CreateSnapshotRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function CreateSnapshot(CreateSnapshotRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/CreateSnapshot',
            $argument,
            [CreateSnapshotResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param GetRecoveryStateRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function GetRecoveryState(GetRecoveryStateRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/GetRecoveryState',
            $argument,
            [GetRecoveryStateResponse::class, 'decode'],
            $metadata,
            $options
        );
    }

    /**
     * @param SetCdcOffsetRequest $argument
     * @param array<string, array<int, string>> $metadata
     * @param array<string, mixed> $options
     * @return mixed
     */
    public function SetCdcOffset(SetCdcOffsetRequest $argument, $metadata = [], $options = [])
    {
        return $this->_simpleRequest(
            '/pulseindex.engine.v1.SearchEngineService/SetCdcOffset',
            $argument,
            [SetCdcOffsetResponse::class, 'decode'],
            $metadata,
            $options
        );
    }
}
PHP
fi

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
