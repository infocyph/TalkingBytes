<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

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

    public static function usingNative(NativeGrpcInvoker $invoker): self
    {
        return self::using(
            static function (GrpcRequest $request) use ($invoker): GrpcResponse {
                $native = $invoker->invoke(
                    method: $request->method,
                    message: $request->message,
                    headers: $request->headers,
                    deadlineSeconds: $request->deadlineSeconds,
                );

                return new GrpcResponse(
                    status: GrpcStatus::fromCode($native->statusCode),
                    message: $native->message,
                    headers: $native->headers,
                    trailers: $native->trailers,
                    metadata: $native->metadata,
                );
            },
        );
    }

    public function send(GrpcRequest $request): CommunicationResult
    {
        $pipeline = new MiddlewarePipeline($this->transport, $this->middlewares);

        return $pipeline->send(new CommunicationRequest('grpc', $request));
    }

    public function withGrpcRetry(?GrpcRetryPolicy $policy = null): self
    {
        return $this->withRetryPolicy($policy ?? GrpcRetryPolicy::standard());
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

    public function withRetryPolicy(RetryPolicy $policy): self
    {
        return $this->withMiddleware(new RetryMiddleware($policy));
    }
}
