<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Resilience\RateLimiter;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private RateLimiter $rateLimiter) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $this->rateLimiter->assertCanProceed();

        return $next($request);
    }
}
