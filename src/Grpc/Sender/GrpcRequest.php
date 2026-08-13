<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcMethodGuard;
use InvalidArgumentException;

final readonly class GrpcRequest
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
        self::assertDeadline($deadlineSeconds);
    }

    public function deadlineMicros(): ?int
    {
        if ($this->deadlineSeconds === null) {
            return null;
        }

        return GrpcDeadline::secondsToMicros($this->deadlineSeconds);
    }

    public function retrySafe(): bool
    {
        return ($this->metadata['retry_safe'] ?? false) === true;
    }

    public function withDeadlineSeconds(?float $deadlineSeconds): self
    {
        self::assertDeadline($deadlineSeconds);

        return new self(
            $this->method,
            $this->message,
            $this->headers,
            $deadlineSeconds,
            $this->metadata,
        );
    }

    public function withHeaders(GrpcMetadata $headers): self
    {
        return new self(
            $this->method,
            $this->message,
            $headers,
            $this->deadlineSeconds,
            $this->metadata,
        );
    }

    public function withRetrySafety(bool $retrySafe = true): self
    {
        return new self(
            $this->method,
            $this->message,
            $this->headers,
            $this->deadlineSeconds,
            [...$this->metadata, 'retry_safe' => $retrySafe],
        );
    }

    private static function assertDeadline(?float $deadlineSeconds): void
    {
        if ($deadlineSeconds !== null && (!is_finite($deadlineSeconds) || $deadlineSeconds <= 0.0)) {
            throw new InvalidArgumentException('gRPC deadline must be greater than zero.');
        }
    }
}
