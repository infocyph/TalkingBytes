<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use Infocyph\TalkingBytes\Grpc\GrpcStatus;

final readonly class GrpcCallError
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $method,
        public GrpcStatus $status,
        public string $message,
        public int $durationMs,
        public array $metadata = [],
    ) {}
}
