<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Support\Sleeper;
use InvalidArgumentException;

final readonly class RetryDecision
{
    public function __construct(
        public bool $retry,
        public int $delayMs = 0,
    ) {
        if ($this->delayMs < 0 || $this->delayMs > Sleeper::MAX_DELAY_MS) {
            throw new InvalidArgumentException(sprintf(
                'Retry delay must be between 0 and %d milliseconds.',
                Sleeper::MAX_DELAY_MS,
            ));
        }

        if (!$this->retry && $this->delayMs !== 0) {
            throw new InvalidArgumentException('A non-retry decision cannot include a delay.');
        }
    }

    public static function retryAfter(int $delayMs): self
    {
        return new self(true, $delayMs);
    }

    public static function stop(): self
    {
        return new self(false);
    }
}
