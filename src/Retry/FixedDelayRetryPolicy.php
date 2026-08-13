<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use InvalidArgumentException;

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

    public function decide(RetryContext $context): RetryDecision
    {
        if ($context->attempt >= $this->maxAttempts) {
            return RetryDecision::stop();
        }

        if ($context->error !== null || ($context->result !== null && !$context->result->successful)) {
            return RetryDecision::retryAfter($this->delayMsValue);
        }

        return RetryDecision::stop();
    }
}
