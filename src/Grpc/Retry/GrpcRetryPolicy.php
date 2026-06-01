<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use InvalidArgumentException;
use Throwable;

final readonly class GrpcRetryPolicy implements RetryPolicy
{
    /**
     * @param list<GrpcStatus> $retryStatuses
     */
    public function __construct(
        private int $maxAttempts = 3,
        private int $baseDelayMs = 100,
        private array $retryStatuses = [
            GrpcStatus::Unavailable,
            GrpcStatus::DeadlineExceeded,
            GrpcStatus::ResourceExhausted,
            GrpcStatus::Aborted,
            GrpcStatus::Internal,
        ],
        private ?int $maxDelayMs = null,
        private float $jitterRatio = 0.0,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('gRPC maxAttempts must be at least 1.');
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('gRPC baseDelayMs must be greater than or equal to 0.');
        }

        if ($this->maxDelayMs !== null && $this->maxDelayMs < 0) {
            throw new InvalidArgumentException('gRPC maxDelayMs must be greater than or equal to 0.');
        }

        if ($this->jitterRatio < 0.0 || $this->jitterRatio > 1.0) {
            throw new InvalidArgumentException('gRPC jitterRatio must be between 0.0 and 1.0.');
        }
    }

    public static function standard(
        int $attempts = 3,
        int $baseDelayMs = 100,
        ?int $maxDelayMs = null,
        float $jitterRatio = 0.0,
    ): self {
        return new self($attempts, $baseDelayMs, maxDelayMs: $maxDelayMs, jitterRatio: $jitterRatio);
    }

    public function delayMs(int $attempt): int
    {
        $power = max(0, $attempt - 1);
        $base = $this->baseDelayMs * (2 ** $power);
        $delay = $base;

        if ($this->jitterRatio > 0.0 && $base > 0) {
            $maxJitter = (int) floor($base * $this->jitterRatio);
            if ($maxJitter > 0) {
                $delay = max(0, $base + random_int(-$maxJitter, $maxJitter));
            }
        }

        if ($this->maxDelayMs !== null) {
            $delay = min($delay, $this->maxDelayMs);
        }

        return $delay;
    }

    public function shouldRetry(int $attempt, ?CommunicationResult $result = null, ?Throwable $error = null): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($error !== null) {
            return true;
        }

        if ($result === null || $result->successful) {
            return false;
        }

        if (!$result->response instanceof GrpcResponse) {
            return true;
        }

        return array_any($this->retryStatuses, fn($status): bool => $result->response->status === $status);
    }
}
