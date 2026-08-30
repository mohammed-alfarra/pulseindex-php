<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
// PulseIndex Engine gRPC API
//
// Package: pulseindex.engine.v1
//
// This is the sole wire contract for the PulseIndex Rust core engine.
// External client SDKs (Go, TypeScript, Python, etc.) SHOULD generate stubs
// directly from this file. The engine stores no entity payloads — only
// bitwise indexes — and returns matched entity IDs for the caller to hydrate
// from a primary data store.
//
// Authentication (transport metadata, not proto fields):
//   - Header `x-api-key: <key>`  OR
//   - Header `authorization: Bearer <key>`
//   Valid keys are configured on the server via `PULSEINDEX_API_KEYS`.
//
// Multi-tenancy:
//   Every mutating / query RPC that touches index state carries `tenant_id`.
//   Empty `tenant_id` is normalized server-side to `"default"`. Tenants are
//   fully isolated (separate in-memory bitset spaces).
//
namespace PulseIndex\Engine\V1;

/**
 * SearchEngineService is the primary PulseIndex control and query plane.
 */
class SearchEngineServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * IndexEntity upserts a single entity into the tenant's in-memory bit index.
     * Re-indexing the same entity_id within a tenant refreshes its attribute bits
     * and clears any soft-delete (tombstone) for that id.
     * @param \PulseIndex\Engine\V1\IndexEntityRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function IndexEntity(\PulseIndex\Engine\V1\IndexEntityRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/IndexEntity',
        $argument,
        ['\PulseIndex\Engine\V1\IndexEntityResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * BatchIndexEntities indexes many entities in one RPC.
     * Processing is ordered; capacity / auth failures abort the batch.
     * @param \PulseIndex\Engine\V1\BatchIndexEntitiesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function BatchIndexEntities(\PulseIndex\Engine\V1\BatchIndexEntitiesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/BatchIndexEntities',
        $argument,
        ['\PulseIndex\Engine\V1\BatchIndexEntitiesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * DeleteEntity soft-deletes an entity inside a tenant (tombstone).
     * The entity id is excluded from subsequent Search results for that tenant.
     * @param \PulseIndex\Engine\V1\DeleteEntityRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function DeleteEntity(\PulseIndex\Engine\V1\DeleteEntityRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/DeleteEntity',
        $argument,
        ['\PulseIndex\Engine\V1\DeleteEntityResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Search runs bitwise filter cascades (MUST / SHOULD / MUST_NOT) plus optional
     * numeric range predicates and returns matching entity IDs only.
     * When `limit` > 0 the engine may early-exit for microsecond latency and
     * `total_matches` reflects the scanned page path (exact totals when limit == 0).
     * @param \PulseIndex\Engine\V1\SearchQueryRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function Search(\PulseIndex\Engine\V1\SearchQueryRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/Search',
        $argument,
        ['\PulseIndex\Engine\V1\SearchQueryResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * CreateSnapshot forces an immediate binary mmap snapshot to disk.
     * @param \PulseIndex\Engine\V1\CreateSnapshotRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function CreateSnapshot(\PulseIndex\Engine\V1\CreateSnapshotRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/CreateSnapshot',
        $argument,
        ['\PulseIndex\Engine\V1\CreateSnapshotResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * GetRecoveryState returns CDC offset and coarse index sizing metrics.
     * @param \PulseIndex\Engine\V1\GetRecoveryStateRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetRecoveryState(\PulseIndex\Engine\V1\GetRecoveryStateRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/GetRecoveryState',
        $argument,
        ['\PulseIndex\Engine\V1\GetRecoveryStateResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * SetCdcOffset records the last applied CDC / mutation sequence number so
     * cold-boot recovery can resume from the correct upstream offset.
     * @param \PulseIndex\Engine\V1\SetCdcOffsetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SetCdcOffset(\PulseIndex\Engine\V1\SetCdcOffsetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/pulseindex.engine.v1.SearchEngineService/SetCdcOffset',
        $argument,
        ['\PulseIndex\Engine\V1\SetCdcOffsetResponse', 'decode'],
        $metadata, $options);
    }

}
