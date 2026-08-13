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
    ): CommunicationResult {
        $sleeper ??= Sleeper::system();
        $count = 1;

        while (true) {
            try {
                $result = $attempt();
            } catch (Throwable $throwable) {
                $decision = $policy->decide(new RetryContext($count, error: $throwable));
                if (!$decision->retry) {
                    throw $throwable;
                }

                $sleeper->milliseconds($decision->delayMs);
                $count++;

                continue;
            }

            $decision = $policy->decide(new RetryContext($count, $result));
            if (!$decision->retry) {
                return $result;
            }

            $sleeper->milliseconds($decision->delayMs);
            $count++;
        }
    }
}
