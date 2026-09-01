<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
// PulseIndex Engine gRPC API
//
// Package: pulseindex.engine.v1
//
// The wire contract for the PulseIndex service. Client SDKs generate stubs
// directly from this file. Queries return matching entity IDs, which the caller
// hydrates from its own primary data store — record contents are never sent
// here and are never stored by the service.
//
// Authentication (transport metadata, not proto fields):
//   - Header `x-api-key: <key>`  OR
//   - Header `authorization: Bearer <key>`
//   Keys are issued and revoked from the PulseIndex dashboard.
//
// Multi-tenancy:
//   Every mutating / query RPC that touches index state carries `tenant_id`.
//   Empty `tenant_id` is normalized server-side to `"default"`. Tenants are
//   fully isolated from one another.
//
namespace PulseIndex\Engine\V1;

/**
 * SearchEngineService is the PulseIndex query and indexing API.
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
     * IndexEntity upserts a single entity into the tenant's index.
     * Re-indexing the same entity_id within a tenant replaces its attributes and
     * clears any soft-delete for that id.
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
     * DeleteEntity soft-deletes an entity inside a tenant.
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
     * Search applies the boolean filters (MUST / SHOULD / MUST_NOT) plus optional
     * numeric range predicates and returns matching entity IDs only.
     * When `limit` > 0, `total_matches` may be approximate; it is exact when
     * `limit` is 0.
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




}
