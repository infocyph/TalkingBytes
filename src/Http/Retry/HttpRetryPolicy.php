<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use InvalidArgumentException;
use Throwable;

final class HttpRetryPolicy implements RetryPolicy
{
    /**
     * @var array<int, int>
     */
    private array $delaysByAttempt = [];

    /**
     * @var array<int, true>
     */
    private array $retryStatuses;

    /**
     * @param list<int> $retryStatuses
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 250,
        array $retryStatuses = [408, 425, 429, 500, 502, 503, 504],
        private readonly ?int $maxRetryAfterSeconds = 30,
        private readonly bool $retryOnTransportError = true,
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

    public function delayMs(int $attempt): int
    {
        return $this->delaysByAttempt[$attempt] ?? $this->exponentialDelayMs($attempt);
    }

    public function shouldRetry(int $attempt, ?CommunicationResult $result = null, ?Throwable $error = null): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($error !== null) {
            if (!$this->retryOnTransportError) {
                return false;
            }

            $this->delaysByAttempt[$attempt] = $this->exponentialDelayMs($attempt);

            return true;
        }

        if ($result === null) {
            return false;
        }

        if ($result->successful) {
            return false;
        }

        $status = $result->statusCode;
        if ($status === null) {
            if (!$this->retryOnTransportError) {
                return false;
            }

            $this->delaysByAttempt[$attempt] = $this->exponentialDelayMs($attempt);

            return true;
        }

        if (!isset($this->retryStatuses[$status])) {
            return false;
        }

        $this->delaysByAttempt[$attempt] = $this->resolveRetryDelayMs($attempt, $result);

        return true;
    }

    private function exponentialDelayMs(int $attempt): int
    {
        $power = max(0, $attempt - 1);

        return $this->baseDelayMs * (2 ** $power);
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

        return $seconds * 1000;
    }
}
