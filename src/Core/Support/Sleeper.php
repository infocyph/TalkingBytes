<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Closure;
use InvalidArgumentException;

final readonly class Sleeper
{
    public const int MAX_DELAY_MS = 86_400_000;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @param callable(int): void $sleep Receives bounded microseconds. */
    public function __construct(callable $sleep)
    {
        $this->sleep = Closure::fromCallable($sleep);
    }

    public static function system(): self
    {
        return new self(usleep(...));
    }

    public function milliseconds(int $delayMs): void
    {
        $this->assertDelay($delayMs);

        if ($delayMs === 0) {
            return;
        }

        ($this->sleep)($delayMs * 1000);
    }

    public function millisecondsInterruptibly(
        int $delayMs,
        CancellationSignal $cancellation,
        int $sliceMs = 50,
    ): bool {
        $this->assertDelay($delayMs);
        if ($sliceMs < 1 || $sliceMs > 1000) {
            throw new InvalidArgumentException('Interruptible sleep slice must be between 1 and 1000 milliseconds.');
        }
        if ($cancellation->isRequested()) {
            return false;
        }

        $remaining = $delayMs;
        while ($remaining > 0) {
            $current = min($remaining, $sliceMs);
            ($this->sleep)($current * 1000);
            $remaining -= $current;

            if ($cancellation->isRequested()) {
                return false;
            }
        }

        return true;
    }

    private function assertDelay(int $delayMs): void
    {
        if ($delayMs < 0 || $delayMs > self::MAX_DELAY_MS) {
            throw new InvalidArgumentException(sprintf(
                'Sleep delay must be between 0 and %d milliseconds.',
                self::MAX_DELAY_MS,
            ));
        }
    }
}
