<?php

declare(strict_types=1);

namespace PulseIndex\Exception;

final class GrpcException extends PulseIndexException
{
    public function __construct(
        string $message,
        public readonly int $grpcStatusCode,
        public readonly string $grpcDetails = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $grpcStatusCode, $previous);
    }
}
