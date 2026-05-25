<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Native;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;

final readonly class NativeGrpcResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $statusCode,
        public mixed $message = null,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public GrpcMetadata $trailers = new GrpcMetadata(),
        public array $metadata = [],
    ) {}
}
