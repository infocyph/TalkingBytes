<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Pipeline;

use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class MiddlewarePipeline
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function __construct(
        private TransportInterface $transport,
        private array $middlewares = [],
    ) {}

    public function send(CommunicationRequest $request): CommunicationResult
    {
        $next = fn(CommunicationRequest $request): CommunicationResult => $this->transport->send($request);

        foreach (array_reverse($this->middlewares) as $middleware) {
            $current = $middleware;
            $currentNext = $next;
            $next = fn(CommunicationRequest $request): CommunicationResult => $current->handle($request, $currentNext);
        }

        return $next($request);
    }

    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self($this->transport, $middlewares);
    }
}
