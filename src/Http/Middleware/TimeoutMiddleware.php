<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final readonly class TimeoutMiddleware implements HttpMiddleware
{
    public function __construct(private int $timeoutSeconds)
    {
        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('HTTP timeout must be greater than 0.');
        }
    }

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        return $next($request->timeout($this->timeoutSeconds));
    }
}
