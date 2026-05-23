<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final readonly class GrpcClient
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    private function __construct(
        private GrpcTransport $transport,
        private array $middlewares = [],
    ) {}

    /**
     * @param callable(GrpcRequest): GrpcResponse $caller
     */
    public static function using(callable $caller): self
    {
        return new self(new GrpcTransport($caller));
    }

    public function send(GrpcRequest $request): CommunicationResult
    {
        $pipeline = new MiddlewarePipeline($this->transport, $this->middlewares);

        return $pipeline->send(new CommunicationRequest('grpc', $request));
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($this->transport, $middlewares);
    }

    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self($this->transport, $middlewares);
    }
}
