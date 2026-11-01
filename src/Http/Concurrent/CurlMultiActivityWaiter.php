<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\Sleeper;

final class CurlMultiActivityWaiter
{
    public static function wait(
        \CurlMultiHandle $multiHandle,
        Sleeper $sleeper,
        bool $cooperativeWait,
        ?CancellationSignal $cancellation,
        ?OperationDeadline $operationDeadline,
    ): void {
        if ($cooperativeWait) {
            if (curl_multi_select($multiHandle, 0.0) > 0) {
                return;
            }

            $delayMs = 5;
            if ($operationDeadline !== null) {
                $remainingMs = (int) ceil($operationDeadline->remainingSeconds() * 1000);
                $delayMs = min($delayMs, max(0, $remainingMs));
            }
            if ($delayMs === 0) {
                return;
            }

            if ($cancellation === null) {
                $sleeper->milliseconds($delayMs);

                return;
            }

            $sleeper->millisecondsInterruptibly($delayMs, $cancellation, $delayMs);

            return;
        }

        $timeoutSeconds = $cancellation === null ? 1.0 : 0.05;
        if ($operationDeadline !== null) {
            $timeoutSeconds = min($timeoutSeconds, max(0.0, $operationDeadline->remainingSeconds()));
        }
        if ($timeoutSeconds <= 0.0 || curl_multi_select($multiHandle, $timeoutSeconds) !== -1) {
            return;
        }

        if ($cancellation === null) {
            $sleeper->milliseconds(10);

            return;
        }

        $sleeper->millisecondsInterruptibly(10, $cancellation, 10);
    }
}
