<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Grpc\Contract\GrpcMiddleware;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Retry\RetryContext;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class RetryMiddleware implements GrpcMiddleware
{
    private Clock $clock;

    private Sleeper $sleeper;

    public function __construct(
        private RetryPolicy $policy,
        private ?CancellationSignal $cancellation = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function handle(GrpcRequest $request, Closure $next): CommunicationResult
    {
        if (!$request->retrySafe()) {
            return $this->cancellation?->isRequested() === true
                ? $this->cancelled(0)
                : $next($request);
        }

        $startedAt = $this->clock->monotonic();
        $attempt = 1;
        while (true) {
            if ($this->cancellation?->isRequested() === true) {
                return $this->cancelled($attempt - 1);
            }

            $attemptRequest = $this->withRemainingDeadline($request, $startedAt);
            if ($attemptRequest === null) {
                return $this->deadlineExceeded($attempt - 1);
            }

            $result = $next($attemptRequest);
            $decision = $this->policy->decide(new RetryContext($attempt, $result));
            if (!$decision->retry) {
                return $result;
            }
            $failure = $this->retryWaitFailure($request, $startedAt, $decision->delayMs, $attempt);
            if ($failure !== null) {
                return $failure;
            }

            $attempt++;
        }
    }

    private function cancelled(int $attempts): CommunicationResult
    {
        return CommunicationResult::failure(
            'gRPC operation cancelled.',
            metadata: [
                'cancelled' => true,
                'attempts' => max(0, $attempts),
                'transport' => 'grpc',
            ],
        );
    }

    private function deadlineExceeded(int $attempts): CommunicationResult
    {
        return CommunicationResult::failure(
            'gRPC operation deadline exceeded.',
            statusCode: GrpcStatus::DeadlineExceeded->value,
            metadata: [
                'deadline_exceeded' => true,
                'attempts' => max(0, $attempts),
                'transport' => 'grpc',
            ],
        );
    }

    private function delayFitsDeadline(GrpcRequest $request, float $startedAt, int $delayMs): bool
    {
        if ($request->deadlineSeconds === null) {
            return true;
        }

        return ($this->clock->monotonic() - $startedAt) + ($delayMs / 1000) < $request->deadlineSeconds;
    }

    private function retryWaitFailure(
        GrpcRequest $request,
        float $startedAt,
        int $delayMs,
        int $attempt,
    ): ?CommunicationResult {
        if (!$this->delayFitsDeadline($request, $startedAt, $delayMs)) {
            return $this->deadlineExceeded($attempt);
        }
        if (!$this->waitBeforeRetry($delayMs)) {
            return $this->cancelled($attempt);
        }

        return null;
    }

    private function waitBeforeRetry(int $delayMs): bool
    {
        if ($this->cancellation === null) {
            $this->sleeper->milliseconds($delayMs);

            return true;
        }

        return $this->sleeper->millisecondsInterruptibly($delayMs, $this->cancellation);
    }

    private function withRemainingDeadline(GrpcRequest $request, float $startedAt): ?GrpcRequest
    {
        if ($request->deadlineSeconds === null) {
            return $request;
        }

        $remaining = $request->deadlineSeconds - ($this->clock->monotonic() - $startedAt);
        if ($remaining <= 0.0) {
            return null;
        }

        return $request->withDeadlineSeconds($remaining);
    }
}
