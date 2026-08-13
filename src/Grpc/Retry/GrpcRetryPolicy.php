<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Retry;

use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransportException;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Retry\RetryDecision;
use Infocyph\TalkingBytes\Retry\RetryDelay;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use InvalidArgumentException;

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
            GrpcStatus::ResourceExhausted,
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

        if ($this->maxDelayMs !== null && ($this->maxDelayMs < 0 || $this->maxDelayMs > Sleeper::MAX_DELAY_MS)) {
            throw new InvalidArgumentException(sprintf(
                'gRPC maxDelayMs must be between 0 and %d.',
                Sleeper::MAX_DELAY_MS,
            ));
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

    public function decide(RetryContext $context): RetryDecision
    {
        if ($context->attempt >= $this->maxAttempts) {
            return RetryDecision::stop();
        }

        if ($context->error !== null) {
            if (!$context->error instanceof GrpcTransportException || !$context->error->retryable) {
                return RetryDecision::stop();
            }

            return RetryDecision::retryAfter($this->delay($context->attempt));
        }

        $result = $context->result;
        if ($result === null || $result->successful) {
            return RetryDecision::stop();
        }

        if (!$result->response instanceof GrpcResponse) {
            return ($result->metadata['grpc_transport_retryable'] ?? false) === true
                ? RetryDecision::retryAfter($this->delay($context->attempt))
                : RetryDecision::stop();
        }

        $retry = array_any($this->retryStatuses, fn($status): bool => $result->response->status === $status);

        return $retry
            ? RetryDecision::retryAfter($this->delay($context->attempt))
            : RetryDecision::stop();
    }

    private function delay(int $attempt): int
    {
        $limit = $this->maxDelayMs ?? Sleeper::MAX_DELAY_MS;
        $base = RetryDelay::exponential($this->baseDelayMs, $attempt, $limit);
        if ($this->jitterRatio <= 0.0 || $base === 0) {
            return $base;
        }

        $maxJitter = (int) floor($base * $this->jitterRatio);
        if ($maxJitter === 0) {
            return $base;
        }

        return max(0, min($limit, $base + random_int(-$maxJitter, $maxJitter)));
    }
}
