<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

final readonly class GrpcRequest
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $method,
        public mixed $message,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public ?float $deadlineSeconds = null,
        public array $metadata = [],
    ) {}

    public function withDeadlineSeconds(?float $deadlineSeconds): self
    {
        return new self(
            $this->method,
            $this->message,
            $this->headers,
            $deadlineSeconds,
            $this->metadata,
        );
    }
}
