<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class LoggingMiddleware implements MiddlewareInterface
{
    /**
     * @param Closure(string, array<string, mixed>): void $logger
     */
    public function __construct(private Closure $logger) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        ($this->logger)('request.start', ['transport' => $request->transport, 'metadata' => $request->metadata]);

        $result = $next($request);

        ($this->logger)('request.end', [
            'transport' => $request->transport,
            'successful' => $result->successful,
            'status_code' => $result->statusCode,
            'error' => $result->error,
        ]);

        return $result;
    }
}
