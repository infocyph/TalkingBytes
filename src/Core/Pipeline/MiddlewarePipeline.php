<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Pipeline;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class MiddlewarePipeline
{
    /** @var Closure(CommunicationRequest): CommunicationResult */
    private Closure $handler;

    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function __construct(
        TransportInterface $transport,
        array $middlewares = [],
    ) {
        $next = static fn(CommunicationRequest $request): CommunicationResult => $transport->send($request);

        foreach (array_reverse($middlewares) as $middleware) {
            $current = $middleware;
            $currentNext = $next;
            $next = static fn(CommunicationRequest $request): CommunicationResult => $current->handle($request, $currentNext);
        }

        $this->handler = $next;
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        return ($this->handler)($request);
    }
}
