<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Throwable;

final readonly class NoRetryPolicy implements RetryPolicy
{
    public function delayMs(int $attempt): int
    {
        return 0;
    }

    public function shouldRetry(int $attempt, ?CommunicationResult $result = null, ?Throwable $error = null): bool
    {
        return false;
    }
}
