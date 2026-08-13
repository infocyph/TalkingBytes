<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Retry\RetryDecision;
use Infocyph\TalkingBytes\Retry\RetryDelay;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use InvalidArgumentException;

final readonly class HttpRetryPolicy implements RetryPolicy
{
    /**
     * @var array<int, true>
     */
    private array $retryStatuses;

    /**
     * @param list<int> $retryStatuses
     */
    public function __construct(
        private int $maxAttempts = 3,
        private int $baseDelayMs = 250,
        array $retryStatuses = [408, 425, 429, 500, 502, 503, 504],
        private ?int $maxRetryAfterSeconds = 30,
        private bool $retryOnTransportError = true,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('baseDelayMs must be greater than or equal to 0.');
        }

        if ($this->maxRetryAfterSeconds !== null && $this->maxRetryAfterSeconds < 0) {
            throw new InvalidArgumentException('maxRetryAfterSeconds must be greater than or equal to 0.');
        }

        $mapped = [];
        foreach ($retryStatuses as $status) {
            $mapped[$status] = true;
        }
        $this->retryStatuses = $mapped;
    }

    public static function standard(int $attempts = 3, int $baseDelayMs = 250, int $maxRetryAfterSeconds = 30): self
    {
        return new self(
            maxAttempts: $attempts,
            baseDelayMs: $baseDelayMs,
            retryStatuses: [408, 425, 429, 500, 502, 503, 504],
            maxRetryAfterSeconds: $maxRetryAfterSeconds,
            retryOnTransportError: true,
        );
    }

    public function decide(RetryContext $context): RetryDecision
    {
        if ($context->attempt >= $this->maxAttempts) {
            return RetryDecision::stop();
        }

        if ($context->error !== null) {
            return $this->retryOnTransportError
                ? RetryDecision::retryAfter($this->exponentialDelayMs($context->attempt))
                : RetryDecision::stop();
        }

        $result = $context->result;
        if ($result === null || $result->successful) {
            return RetryDecision::stop();
        }

        $status = $result->statusCode;
        if ($status === null) {
            return $this->retryOnTransportError
                ? RetryDecision::retryAfter($this->exponentialDelayMs($context->attempt))
                : RetryDecision::stop();
        }

        if (!isset($this->retryStatuses[$status])) {
            return RetryDecision::stop();
        }

        return RetryDecision::retryAfter($this->resolveRetryDelayMs($context->attempt, $result));
    }

    private function exponentialDelayMs(int $attempt): int
    {
        return RetryDelay::exponential($this->baseDelayMs, $attempt);
    }

    private function resolveRetryDelayMs(int $attempt, CommunicationResult $result): int
    {
        $response = $result->response;
        if (!$response instanceof HttpResponse) {
            return $this->exponentialDelayMs($attempt);
        }

        $retryAfter = $response->headerLine('Retry-After');
        if ($retryAfter === null || trim($retryAfter) === '') {
            return $this->exponentialDelayMs($attempt);
        }

        $seconds = RetryAfter::parseDelaySeconds($retryAfter);
        if ($seconds === null) {
            return $this->exponentialDelayMs($attempt);
        }

        if ($this->maxRetryAfterSeconds !== null) {
            $seconds = min($seconds, $this->maxRetryAfterSeconds);
        }

        if ($seconds > intdiv(\Infocyph\TalkingBytes\Core\Support\Sleeper::MAX_DELAY_MS, 1000)) {
            return \Infocyph\TalkingBytes\Core\Support\Sleeper::MAX_DELAY_MS;
        }

        return $seconds * 1000;
    }
}
