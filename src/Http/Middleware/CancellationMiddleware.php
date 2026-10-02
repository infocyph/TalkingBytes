<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Middleware;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class CancellationMiddleware implements HttpMiddleware
{
    public function __construct(private CancellationSignal $cancellation) {}

    public function handle(HttpRequest $request, Closure $next): CommunicationResult
    {
        if ($this->cancellation->isRequested()) {
            return CommunicationResult::failure(
                'HTTP operation cancelled.',
                metadata: [
                    'cancelled' => true,
                    'attempts' => 0,
                    'transport' => 'http',
                ],
            );
        }

        return $next($request);
    }
}
