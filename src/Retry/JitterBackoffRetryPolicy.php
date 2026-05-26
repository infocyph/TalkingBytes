<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use InvalidArgumentException;
use Throwable;

final readonly class JitterBackoffRetryPolicy implements RetryPolicy
{
    public function __construct(
        private int $maxAttempts,
        private int $baseDelayMs,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($this->baseDelayMs < 1) {
            throw new InvalidArgumentException('baseDelayMs must be at least 1 for jitter strategy.');
        }
    }

    public function delayMs(int $attempt): int
    {
        $exponentialDelay = $this->baseDelayMs * (2 ** max(0, $attempt - 1));

        return random_int((int) floor($exponentialDelay / 2), $exponentialDelay);
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
