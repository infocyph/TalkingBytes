<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final class RetryExecutor
{
    /**
     * @param callable():CommunicationResult $attempt
     */
    public static function run(RetryPolicy $policy, callable $attempt): CommunicationResult
    {
        $count = 1;

        while (true) {
            try {
                $result = $attempt();
            } catch (Throwable $throwable) {
                if (!$policy->shouldRetry($count, null, $throwable)) {
                    throw $throwable;
                }

                usleep($policy->delayMs($count) * 1000);
                $count++;

                continue;
            }

            if (!$policy->shouldRetry($count, $result)) {
                return $result;
            }

            usleep($policy->delayMs($count) * 1000);
            $count++;
        }
    }
}
