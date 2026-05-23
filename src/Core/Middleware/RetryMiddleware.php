<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final readonly class RetryMiddleware implements MiddlewareInterface
{
    public function __construct(private RetryPolicy $policy) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $attempt = 1;

        while (true) {
            try {
                $result = $next($request);
            } catch (Throwable $throwable) {
                if (!$this->policy->shouldRetry($attempt, null, $throwable)) {
                    throw $throwable;
                }

                usleep($this->policy->delayMs($attempt) * 1000);
                $attempt++;

                continue;
            }

            if (!$this->policy->shouldRetry($attempt, $result)) {
                return $result;
            }

            usleep($this->policy->delayMs($attempt) * 1000);
            $attempt++;
        }
    }
}
