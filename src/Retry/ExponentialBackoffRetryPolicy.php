<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use InvalidArgumentException;
use Throwable;

final readonly class ExponentialBackoffRetryPolicy implements RetryPolicy
{
    public function __construct(
        private int $maxAttempts,
        private int $baseDelayMs,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('baseDelayMs must be greater than or equal to 0.');
        }
    }

    public function delayMs(int $attempt): int
    {
        $power = max(0, $attempt - 1);

        return $this->baseDelayMs * (2 ** $power);
    }

    public function shouldRetry(int $attempt, ?CommunicationResult $result = null, ?Throwable $error = null): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        if ($error !== null) {
            return true;
        }

        return $result !== null && !$result->successful;
    }
}
