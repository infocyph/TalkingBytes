<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Resilience\RateLimiter;

final readonly class RateLimitMiddleware implements HttpMiddleware
{
    public function __construct(private RateLimiter $rateLimiter) {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        $this->rateLimiter->assertCanProceed();

        return $next($request);
    }
}
