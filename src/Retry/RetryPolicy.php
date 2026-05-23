<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Throwable;

interface RetryPolicy
{
    public function delayMs(int $attempt): int;

    public function shouldRetry(int $attempt, ?CommunicationResult $result = null, ?Throwable $error = null): bool;
}
