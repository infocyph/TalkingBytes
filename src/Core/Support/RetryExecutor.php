<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final class RetryExecutor
{
    /**
     * @param callable():CommunicationResult $attempt
     */
    public static function run(
        RetryPolicy $policy,
        callable $attempt,
        ?Sleeper $sleeper = null,
        ?CancellationSignal $cancellation = null,
        ?OperationDeadline $deadline = null,
    ): CommunicationResult {
        $sleeper ??= Sleeper::system();
        $count = 1;

        while (true) {
            if ($cancellation?->isRequested() === true) {
                return self::cancelled($count - 1);
            }
            if ($deadline?->expired() === true) {
                return self::deadlineExceeded($count - 1);
            }

            try {
                $result = $attempt();
            } catch (Throwable $throwable) {
                $decision = $policy->decide(new RetryContext($count, error: $throwable));
                if (!$decision->retry) {
                    throw $throwable;
                }

                $wait = self::boundedWaitMs($decision->delayMs, $deadline);
                if ($wait === null) {
                    return self::deadlineExceeded($count);
                }
                if (!self::wait($sleeper, $wait['delay_ms'], $cancellation)) {
                    return self::cancelled($count);
                }
                if ($wait['deadline_limited'] || $deadline?->expired() === true) {
                    return self::deadlineExceeded($count);
                }
                $count++;

                continue;
            }

            $decision = $policy->decide(new RetryContext($count, $result));
            if (!$decision->retry) {
                return $result;
            }

            $wait = self::boundedWaitMs($decision->delayMs, $deadline);
            if ($wait === null) {
                return self::deadlineExceeded($count);
            }
            if (!self::wait($sleeper, $wait['delay_ms'], $cancellation)) {
                return self::cancelled($count);
            }
            if ($wait['deadline_limited'] || $deadline?->expired() === true) {
                return self::deadlineExceeded($count);
            }
            $count++;
        }
    }

    /**
     * @return array{delay_ms:int,deadline_limited:bool}|null
     */
    private static function boundedWaitMs(int $delayMs, ?OperationDeadline $deadline): ?array
    {
        if ($deadline === null) {
            return ['delay_ms' => $delayMs, 'deadline_limited' => false];
        }

        $remainingMs = $deadline->remainingMilliseconds();
        if ($remainingMs === 0) {
            return null;
        }

        return [
            'delay_ms' => min($delayMs, $remainingMs),
            'deadline_limited' => $delayMs >= $remainingMs,
        ];
    }

    private static function cancelled(int $attempts): CommunicationResult
    {
        return CommunicationResult::failure(
            'Operation cancelled.',
            metadata: ['cancelled' => true, 'attempts' => max(0, $attempts)],
        );
    }

    private static function deadlineExceeded(int $attempts): CommunicationResult
    {
        return CommunicationResult::failure(
            'Operation deadline exceeded.',
            metadata: [
                'deadline_exceeded' => true,
                'attempts' => max(0, $attempts),
            ],
        );
    }

    private static function wait(
        Sleeper $sleeper,
        int $delayMs,
        ?CancellationSignal $cancellation,
    ): bool {
        if ($cancellation === null) {
            $sleeper->milliseconds($delayMs);

            return true;
        }

        return $sleeper->millisecondsInterruptibly($delayMs, $cancellation);
    }
}
