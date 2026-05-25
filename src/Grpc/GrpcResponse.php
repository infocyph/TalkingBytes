<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

final readonly class GrpcResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public GrpcStatus $status,
        public mixed $message,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public GrpcMetadata $trailers = new GrpcMetadata(),
        public array $metadata = [],
    ) {}

    public function isOk(): bool
    {
        return $this->status === GrpcStatus::Ok;
    }
}
