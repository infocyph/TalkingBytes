<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcMethodGuard;
use InvalidArgumentException;

final readonly class GrpcStreamRequest
{
    public string $method;

    /**
     * @param array<string, mixed> $metadata
     * @param iterable<mixed> $messages
     */
    public function __construct(
        string $method,
        public iterable $messages,
        public GrpcMetadata $headers = new GrpcMetadata(),
        public ?float $deadlineSeconds = null,
        public array $metadata = [],
    ) {
        $this->method = GrpcMethodGuard::normalize($method);

        if ($this->deadlineSeconds !== null && (!is_finite($this->deadlineSeconds) || $this->deadlineSeconds <= 0.0)) {
            throw new InvalidArgumentException('gRPC stream deadline must be greater than zero.');
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
