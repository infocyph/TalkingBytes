<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Retry;

use Infocyph\TalkingBytes\Core\Support\Sleeper;

final class RetryDelay
{
    public static function exponential(int $baseDelayMs, int $attempt, int $maximumMs = Sleeper::MAX_DELAY_MS): int
    {
        if ($baseDelayMs <= 0 || $maximumMs <= 0) {
            return 0;
        }

        $delay = min($baseDelayMs, $maximumMs);
        $doublings = max(0, $attempt - 1);

        while ($doublings > 0 && $delay < $maximumMs) {
            if ($delay > intdiv($maximumMs, 2)) {
                return $maximumMs;
            }

            $delay *= 2;
            $doublings--;
        }

        return min($delay, $maximumMs);
    }
}
