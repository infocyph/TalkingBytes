<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class IdempotencyMiddleware implements HttpMiddleware
{
    public function __construct(private string $headerName = 'Idempotency-Key') {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        if (!$request->headers->has($this->headerName)) {
            $request = $request->header($this->headerName, bin2hex(random_bytes(16)));
        }

        return $next($request);
    }
}
