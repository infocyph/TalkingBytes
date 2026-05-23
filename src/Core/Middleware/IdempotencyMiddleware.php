<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(private string $headerName = 'Idempotency-Key') {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $headers = $request->headers;

        if (!array_key_exists($this->headerName, $headers)) {
            $headers[$this->headerName] = bin2hex(random_bytes(16));
        }

        return $next($request->withHeaders($headers));
    }
}
