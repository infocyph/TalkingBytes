<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\Middleware\IdempotencyMiddleware;

final readonly class HttpPipeline
{
    /** @var Closure(HttpRequest): CommunicationResult */
    private Closure $handler;

    /** @param list<HttpMiddleware> $middlewares */
    public function __construct(HttpTransport $transport, array $middlewares = [])
    {
        $ordered = [];
        foreach ($middlewares as $middleware) {
            if ($middleware instanceof IdempotencyMiddleware) {
                array_unshift($ordered, $middleware);

                continue;
            }

            $ordered[] = $middleware;
        }

        $next = static fn(HttpRequest $request): CommunicationResult => $transport->send(
            $request->prepareForTransport(),
        );

        foreach (array_reverse($ordered) as $middleware) {
            $currentNext = $next;
            $next = static fn(HttpRequest $request): CommunicationResult => $middleware->handle($request, $currentNext);
        }

        $this->handler = $next;
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        return ($this->handler)($request);
    }
}
