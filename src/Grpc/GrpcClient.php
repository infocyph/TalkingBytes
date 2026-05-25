<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final readonly class GrpcClient
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    private function __construct(
        private GrpcTransport $transport,
        private array $middlewares = [],
        private ?NativeGrpcStreamingInvoker $streamingInvoker = null,
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

    public static function usingNativeStreaming(
        NativeGrpcInvoker $invoker,
        NativeGrpcStreamingInvoker $streamingInvoker,
    ): self {
        return new self(self::usingNative($invoker)->transport, streamingInvoker: $streamingInvoker);
    }

    /**
     * @param iterable<mixed> $messages
     * @param array<string, mixed> $metadata
     */
    public function bidiStream(
        string $method,
        iterable $messages,
        callable $onMessage,
        GrpcMetadata $headers = new GrpcMetadata(),
        ?float $deadlineSeconds = null,
        array $metadata = [],
    ): CommunicationResult {
        $request = new GrpcStreamRequest($method, $messages, $headers, $deadlineSeconds, $metadata);

        return $this->runStream(
            streamType: 'bidi',
            method: $request->method,
            execute: fn(NativeGrpcStreamingInvoker $invoker): NativeGrpcResult => $invoker->bidiStream(
                method: $request->method,
                messages: $request->messages,
                headers: $request->headers,
                onMessage: $onMessage,
                deadlineSeconds: $request->deadlineSeconds,
            ),
        );
    }

    /**
     * @param iterable<mixed> $messages
     * @param array<string, mixed> $metadata
     */
    public function clientStream(
        string $method,
        iterable $messages,
        GrpcMetadata $headers = new GrpcMetadata(),
        ?float $deadlineSeconds = null,
        array $metadata = [],
    ): CommunicationResult {
        $request = new GrpcStreamRequest($method, $messages, $headers, $deadlineSeconds, $metadata);

        return $this->runStream(
            streamType: 'client',
            method: $request->method,
            execute: fn(NativeGrpcStreamingInvoker $invoker): NativeGrpcResult => $invoker->clientStream(
                method: $request->method,
                messages: $request->messages,
                headers: $request->headers,
                deadlineSeconds: $request->deadlineSeconds,
            ),
        );
    }

    public function send(GrpcRequest $request): CommunicationResult
    {
        $pipeline = new MiddlewarePipeline($this->transport, $this->middlewares);

        return $pipeline->send(new CommunicationRequest('grpc', $request));
    }

    /**
     * @param callable(mixed):void $onMessage
     */
    public function serverStream(GrpcRequest $request, callable $onMessage): CommunicationResult
    {
        return $this->runStream(
            streamType: 'server',
            method: $request->method,
            execute: fn(NativeGrpcStreamingInvoker $invoker): NativeGrpcResult => $invoker->serverStream(
                method: $request->method,
                message: $request->message,
                headers: $request->headers,
                onMessage: $onMessage,
                deadlineSeconds: $request->deadlineSeconds,
            ),
        );
    }

    public function supportsStreaming(): bool
    {
        return $this->streamingInvoker !== null;
    }

    public function withGrpcRetry(?GrpcRetryPolicy $policy = null): self
    {
        return $this->withRetryPolicy($policy ?? GrpcRetryPolicy::standard());
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($this->transport, $middlewares, $this->streamingInvoker);
    }

    /**
     * @param list<MiddlewareInterface> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self($this->transport, $middlewares, $this->streamingInvoker);
    }

    public function withRetryPolicy(RetryPolicy $policy): self
    {
        return $this->withMiddleware(new RetryMiddleware($policy));
    }

    /**
     * @param callable(NativeGrpcStreamingInvoker):NativeGrpcResult $execute
     */
    private function runStream(string $streamType, string $method, callable $execute): CommunicationResult
    {
        if ($this->streamingInvoker === null) {
            return CommunicationResult::failure(
                sprintf('gRPC %s streaming is unavailable for this client.', $streamType),
            );
        }

        $startedAt = microtime(true);
        CommunicationEventBus::dispatch('grpc.stream.start', [
            'transport' => 'grpc',
            'type' => $streamType,
            'method' => $method,
        ]);

        try {
            $native = $execute($this->streamingInvoker);
        } catch (Throwable $exception) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            CommunicationEventBus::dispatch('grpc.stream.failed', [
                'transport' => 'grpc',
                'type' => $streamType,
                'method' => $method,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);

            $error = new GrpcCallError(
                method: $method,
                status: GrpcStatus::Unknown,
                message: sprintf('gRPC %s stream failed: %s', $streamType, $exception->getMessage()),
                durationMs: $durationMs,
                metadata: [
                    'exception' => $exception::class,
                    'stream_type' => $streamType,
                ],
            );

            return CommunicationResult::failure(
                $error->message,
                null,
                $error,
                [
                    'transport' => 'grpc',
                    'stream_type' => $streamType,
                    'method' => $method,
                    'duration_ms' => $durationMs,
                    'grpc_error' => $error,
                ],
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        $response = new GrpcResponse(
            status: GrpcStatus::fromCode($native->statusCode),
            message: $native->message,
            headers: $native->headers,
            trailers: $native->trailers,
            metadata: $native->metadata,
        );

        if (!$response->isOk()) {
            CommunicationEventBus::dispatch('grpc.stream.failed', [
                'transport' => 'grpc',
                'type' => $streamType,
                'method' => $method,
                'status_code' => $response->status->value,
                'status_name' => $response->status->name,
                'duration_ms' => $durationMs,
            ]);

            $error = new GrpcCallError(
                method: $method,
                status: $response->status,
                message: sprintf('gRPC %s stream failed with status %d (%s).', $streamType, $response->status->value, $response->status->name),
                durationMs: $durationMs,
                metadata: ['stream_type' => $streamType],
            );

            return CommunicationResult::failure(
                $error->message,
                $response->status->value,
                $response,
                [
                    'transport' => 'grpc',
                    'stream_type' => $streamType,
                    'method' => $method,
                    'duration_ms' => $durationMs,
                    'grpc_status' => $response->status->value,
                    'grpc_status_name' => $response->status->name,
                    'grpc_error' => $error,
                ],
            );
        }

        CommunicationEventBus::dispatch('grpc.stream.finish', [
            'transport' => 'grpc',
            'type' => $streamType,
            'method' => $method,
            'status_code' => $response->status->value,
            'duration_ms' => $durationMs,
        ]);

        return CommunicationResult::success(
            $response->status->value,
            $response,
            [
                'transport' => 'grpc',
                'stream_type' => $streamType,
                'method' => $method,
                'duration_ms' => $durationMs,
            ],
        );
    }
}
