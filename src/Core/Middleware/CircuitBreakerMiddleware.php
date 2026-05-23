<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Throwable;

final readonly class CircuitBreakerMiddleware implements MiddlewareInterface
{
    public function __construct(private CircuitBreaker $circuitBreaker) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $this->circuitBreaker->assertCanProceed();

        try {
            $result = $next($request);
        } catch (Throwable $throwable) {
            $this->circuitBreaker->onFailure();

            throw $throwable;
        }

        if ($result->successful) {
            $this->circuitBreaker->onSuccess();

            return $result;
        }

        $this->circuitBreaker->onFailure();

        return $result;
    }
}
