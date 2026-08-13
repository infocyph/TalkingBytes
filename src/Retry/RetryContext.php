<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Throwable;

final readonly class RetryContext
{
    public function __construct(
        public int $attempt,
        public ?CommunicationResult $result = null,
        public ?Throwable $error = null,
    ) {}
}
