<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class IdempotencyMiddleware implements MiddlewareInterface
{
    public function __construct(private string $headerName = 'Idempotency-Key') {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        $headers = $request->headers;
        $payload = $request->payload;

        $alreadyPresent = array_key_exists($this->headerName, $headers)
            || ($payload instanceof HttpRequest && $payload->headers->get($this->headerName) !== null);

        if (!$alreadyPresent) {
            $key = bin2hex(random_bytes(16));
            $headers[$this->headerName] = $key;

            if ($payload instanceof HttpRequest) {
                $payload = $payload->header($this->headerName, $key);
            }
        }

        return $next(new CommunicationRequest(
            $request->transport,
            $payload,
            $headers,
            $request->options,
            $request->metadata,
        ));
    }
}
