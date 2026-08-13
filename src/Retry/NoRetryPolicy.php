<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

final readonly class NoRetryPolicy implements RetryPolicy
{
    public function decide(RetryContext $context): RetryDecision
    {
        unset($context);

        return RetryDecision::stop();
    }
}
