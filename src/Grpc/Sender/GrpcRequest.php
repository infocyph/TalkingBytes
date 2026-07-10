<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcMethodGuard;
use InvalidArgumentException;

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
    ) {
        self::assertMethod($method);
        self::assertDeadline($deadlineSeconds);
    }

    public function deadlineMicros(): ?int
    {
        if ($this->deadlineSeconds === null) {
            return null;
        }

        return GrpcDeadline::secondsToMicros($this->deadlineSeconds);
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

    private static function assertDeadline(?float $deadlineSeconds): void
    {
        if ($deadlineSeconds !== null && $deadlineSeconds <= 0.0) {
            throw new InvalidArgumentException('gRPC deadline must be greater than zero.');
        }
    }

    private static function assertMethod(string $method): void
    {
        GrpcMethodGuard::assertValid($method);
    }
}
