<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Middleware;

use Closure;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthenticatorInterface $authenticator) {}

    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult
    {
        if ($request->payload instanceof HttpRequest) {
            return $next(
                new CommunicationRequest(
                    $request->transport,
                    $request->payload->withAuthenticator($this->authenticator),
                    $request->headers,
                    $request->options,
                    $request->metadata,
                ),
            );
        }

        return $next($request);
    }
}
