<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Throwable;

final readonly class CircuitBreakerMiddleware implements HttpMiddleware
{
    public function __construct(private CircuitBreaker $circuitBreaker) {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        $this->circuitBreaker->assertCanProceed();

        try {
            $result = $next($request);
        } catch (Throwable $throwable) {
            $this->circuitBreaker->onFailure();

            throw $throwable;
        }

        $result->successful ? $this->circuitBreaker->onSuccess() : $this->circuitBreaker->onFailure();

        return $result;
    }
}
