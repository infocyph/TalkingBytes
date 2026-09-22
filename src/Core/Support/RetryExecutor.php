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
    ): CommunicationResult {
        $sleeper ??= Sleeper::system();
        $count = 1;

        while (true) {
            if ($cancellation?->isRequested() === true) {
                return self::cancelled($count - 1);
            }
            try {
                $result = $attempt();
            } catch (Throwable $throwable) {
                $decision = $policy->decide(new RetryContext($count, error: $throwable));
                if (!$decision->retry) {
                    throw $throwable;
                }

                if (!self::wait($sleeper, $decision->delayMs, $cancellation)) {
                    return self::cancelled($count);
                }
                $count++;

                continue;
            }

            $decision = $policy->decide(new RetryContext($count, $result));
            if (!$decision->retry) {
                return $result;
            }

            if (!self::wait($sleeper, $decision->delayMs, $cancellation)) {
                return self::cancelled($count);
            }
            $count++;
        }
    }

    private static function cancelled(int $attempts): CommunicationResult
    {
        return CommunicationResult::failure(
            'Operation cancelled.',
            metadata: ['cancelled' => true, 'attempts' => max(0, $attempts)],
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
