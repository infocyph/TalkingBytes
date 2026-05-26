<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\RetryExecutor;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class RetryMiddleware implements MiddlewareInterface
{
    public function __construct(private RetryPolicy $policy) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        return RetryExecutor::run($this->policy, static fn(): CommunicationResult => $next($request));
    }
}
