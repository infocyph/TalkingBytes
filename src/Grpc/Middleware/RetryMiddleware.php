<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Grpc\Contract\GrpcMiddleware;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class RetryMiddleware implements GrpcMiddleware
{
    private Clock $clock;

    private Sleeper $sleeper;

    public function __construct(
        private RetryPolicy $policy,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function handle(GrpcRequest $request, Closure $next): CommunicationResult
    {
        if (!$request->retrySafe()) {
            return $next($request);
        }

        $startedAt = $this->clock->monotonic();
        $attempt = 1;
        while (true) {
            $attemptRequest = $this->withRemainingDeadline($request, $startedAt);
            $result = $next($attemptRequest);
            $decision = $this->policy->decide(new RetryContext($attempt, $result));
            if (!$decision->retry || !$this->delayFitsDeadline($request, $startedAt, $decision->delayMs)) {
                return $result;
            }

            $this->sleeper->milliseconds($decision->delayMs);
            $attempt++;
        }
    }

    private function delayFitsDeadline(GrpcRequest $request, float $startedAt, int $delayMs): bool
    {
        if ($request->deadlineSeconds === null) {
            return true;
        }

        return ($this->clock->monotonic() - $startedAt) + ($delayMs / 1000) < $request->deadlineSeconds;
    }

    private function withRemainingDeadline(GrpcRequest $request, float $startedAt): GrpcRequest
    {
        if ($request->deadlineSeconds === null) {
            return $request;
        }

        $remaining = $request->deadlineSeconds - ($this->clock->monotonic() - $startedAt);
        if ($remaining <= 0.0) {
            return $request->withDeadlineSeconds(0.000001);
        }

        return $request->withDeadlineSeconds($remaining);
    }
}
