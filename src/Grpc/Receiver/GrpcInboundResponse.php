<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Receiver;

use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;

final readonly class GrpcInboundResponse
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public GrpcStatus $status,
        public mixed $message = null,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public GrpcMetadata $trailers = new GrpcMetadata(),
        public array $metadata = [],
    ) {}

    public static function ok(
        mixed $message = null,
        GrpcMetadata $headers = new GrpcMetadata(),
        GrpcMetadata $trailers = new GrpcMetadata(),
    ): self {
        return new self(GrpcStatus::Ok, $message, $headers, $trailers);
    }

    public static function unimplemented(string $message = 'Method is not implemented.'): self
    {
        return new self(GrpcStatus::Unimplemented, $message);
    }

    public function isOk(): bool
    {
        return $this->status === GrpcStatus::Ok;
    }
}
