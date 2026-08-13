<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

interface RetryPolicy
{
    public function decide(RetryContext $context): RetryDecision;
}
