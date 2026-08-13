<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\Contract\GrpcMiddleware;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransport;

final readonly class GrpcPipeline
{
    /** @var Closure(GrpcRequest): CommunicationResult */
    private Closure $handler;

    /** @param list<GrpcMiddleware> $middlewares */
    public function __construct(GrpcTransport $transport, array $middlewares = [])
    {
        $next = static fn(GrpcRequest $request): CommunicationResult => $transport->send($request);
        foreach (array_reverse($middlewares) as $middleware) {
            $currentNext = $next;
            $next = static fn(GrpcRequest $request): CommunicationResult => $middleware->handle($request, $currentNext);
        }
        $this->handler = $next;
    }

    public function send(GrpcRequest $request): CommunicationResult
    {
        return ($this->handler)($request);
    }
}
