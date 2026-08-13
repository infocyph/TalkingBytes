<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Receiver;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcMethodGuard;
use InvalidArgumentException;

final readonly class GrpcInboundRequest
{
    public string $method;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $method,
        public mixed $message,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public ?float $deadlineSeconds = null,
        public array $metadata = [],
    ) {
        $this->method = GrpcMethodGuard::normalize($method);

        if ($deadlineSeconds !== null && (!is_finite($deadlineSeconds) || $deadlineSeconds <= 0.0)) {
            throw new InvalidArgumentException('gRPC inbound deadline must be greater than zero.');
        }
    }

    public function deadlineMicros(): ?int
    {
        if ($this->deadlineSeconds === null) {
            return null;
        }

        return GrpcDeadline::secondsToMicros($this->deadlineSeconds);
    }
}
