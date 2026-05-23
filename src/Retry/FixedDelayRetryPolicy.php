<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use InvalidArgumentException;
use Throwable;

final readonly class FixedDelayRetryPolicy implements RetryPolicy
{
    public function __construct(
        private int $maxAttempts,
        private int $delayMsValue,
    ) {
        if ($this->maxAttempts < 1) {
            throw new InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($this->delayMsValue < 0) {
            throw new InvalidArgumentException('delayMsValue must be greater than or equal to 0.');
        }
    }

    public function delayMs(int $attempt): int
    {
        unset($attempt);

        return $this->delayMsValue;
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
