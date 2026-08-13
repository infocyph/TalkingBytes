<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use InvalidArgumentException;

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

    public function decide(RetryContext $context): RetryDecision
    {
        if ($context->attempt >= $this->maxAttempts) {
            return RetryDecision::stop();
        }

        if ($context->error !== null || ($context->result !== null && !$context->result->successful)) {
            return RetryDecision::retryAfter(RetryDelay::exponential($this->baseDelayMs, $context->attempt));
        }

        return RetryDecision::stop();
    }
}
