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
