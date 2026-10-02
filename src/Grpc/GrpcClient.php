<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\ObservabilitySanitizer;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Grpc\Contract\GrpcMiddleware;
use Infocyph\TalkingBytes\Grpc\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Grpc\Native\GeneratedStubGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcCallError;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcStreamRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransport;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Throwable;

final readonly class GrpcClient
{
    private Clock $clock;

    private EventDispatcher $events;

    private GrpcPipeline $pipeline;

    /**
     * @param list<GrpcMiddleware> $middlewares
     */
    private function __construct(
        private GrpcTransport $transport,
        private array $middlewares = [],
        private ?NativeGrpcStreamingInvoker $streamingInvoker = null,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        private ?CancellationSignal $cancellation = null,
        private ?OperationDeadline $operationDeadline = null,
    ) {
        $this->pipeline = new GrpcPipeline($transport, $middlewares);
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
    }

    /**
     * @param callable(GrpcRequest): GrpcResponse $caller
     */
    public static function using(callable $caller, ?EventDispatcher $events = null, ?Clock $clock = null): self
    {
        return new self(new GrpcTransport($caller, $events, $clock), events: $events, clock: $clock);
    }

    /**
     * @param array<string, string> $methodMap
     */
    public static function usingGeneratedStub(
        object $stubClient,
        array $methodMap = [],
        ?EventDispatcher $events = null,
        ?CancellationSignal $cancellation = null,
        ?Clock $clock = null,
    ): self {
        $invoker = new GeneratedStubGrpcInvoker($stubClient, $methodMap, $cancellation);
        $client = self::usingNativeStreaming($invoker, $invoker, $events, $clock);

        return $cancellation === null ? $client : $client->withCancellation($cancellation);
    }

    public static function usingNative(
        NativeGrpcInvoker $invoker,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): self {
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
            $events,
            $clock,
        );
    }

    public static function usingNativeStreaming(
        NativeGrpcInvoker $invoker,
        NativeGrpcStreamingInvoker $streamingInvoker,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): self {
        return new self(
            self::usingNative($invoker, $events, $clock)->transport,
            streamingInvoker: $streamingInvoker,
            events: $events,
            clock: $clock,
        );
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
        return $this->runOutboundStream('bidi', $method, $messages, $headers, $deadlineSeconds, $metadata, $onMessage);
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
        return $this->runOutboundStream('client', $method, $messages, $headers, $deadlineSeconds, $metadata);
    }

    public function send(GrpcRequest $request): CommunicationResult
    {
        if ($this->cancellation?->isRequested() === true) {
            return $this->cancelled($request->method);
        }

        $request = $this->boundedRequest($request);
        if ($request === null) {
            return $this->deadlineExceeded();
        }

        $result = $this->pipeline->send($request);
        if ($this->operationDeadlineExpired()
            && ($result->metadata['deadline_exceeded'] ?? false) !== true
        ) {
            return $this->deadlineExceeded();
        }

        return $result;
    }

    /**
     * @param callable(mixed):void $onMessage
     */
    public function serverStream(GrpcRequest $request, callable $onMessage): CommunicationResult
    {
        $request = $this->boundedRequest($request);
        if ($request === null) {
            return $this->deadlineExceeded('server');
        }

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

    public function withCancellation(CancellationSignal $cancellation): self
    {
        return new self(
            $this->transport,
            $this->middlewares,
            $this->streamingInvoker,
            $this->events,
            $this->clock,
            $cancellation,
            $this->operationDeadline,
        );
    }

    public function withGrpcRetry(
        ?GrpcRetryPolicy $policy = null,
        ?CancellationSignal $cancellation = null,
        ?Sleeper $sleeper = null,
    ): self {
        return $this->withRetryPolicy($policy ?? GrpcRetryPolicy::standard(), $cancellation, $sleeper);
    }

    public function withMiddleware(GrpcMiddleware $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self(
            $this->transport,
            $middlewares,
            $this->streamingInvoker,
            $this->events,
            $this->clock,
            $this->cancellation,
            $this->operationDeadline,
        );
    }

    /**
     * @param list<GrpcMiddleware> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self(
            $this->transport,
            $middlewares,
            $this->streamingInvoker,
            $this->events,
            $this->clock,
            $this->cancellation,
            $this->operationDeadline,
        );
    }

    public function withOperationDeadline(OperationDeadline $deadline): self
    {
        $deadline = $this->operationDeadline?->earliest($deadline) ?? $deadline;

        return new self(
            $this->transport,
            $this->middlewares,
            $this->streamingInvoker,
            $this->events,
            $this->clock,
            $this->cancellation,
            $deadline,
        );
    }

    public function withRetryPolicy(
        RetryPolicy $policy,
        ?CancellationSignal $cancellation = null,
        ?Sleeper $sleeper = null,
    ): self {
        return $this->withMiddleware(new RetryMiddleware($policy, $cancellation, $this->clock, $sleeper));
    }

    private function boundedDeadlineSeconds(?float $callerDeadline): ?float
    {
        if ($this->operationDeadline === null) {
            return $callerDeadline;
        }

        $remaining = $this->operationDeadline->remainingSeconds();
        if ($remaining <= 0.0) {
            return 0.0;
        }

        return $callerDeadline === null ? $remaining : min($callerDeadline, $remaining);
    }

    private function boundedRequest(GrpcRequest $request): ?GrpcRequest
    {
        $deadline = $this->boundedDeadlineSeconds($request->deadlineSeconds);
        if ($deadline === 0.0) {
            return null;
        }

        return $deadline === $request->deadlineSeconds
            ? $request
            : $request->withDeadlineSeconds($deadline);
    }

    private function cancelled(string $method, ?string $streamType = null): CommunicationResult
    {
        $metadata = [
            'cancelled' => true,
            'attempts' => 0,
            'transport' => 'grpc',
            'method' => $method,
        ];
        if ($streamType !== null) {
            $metadata['stream_type'] = $streamType;
        }

        return CommunicationResult::failure(
            'gRPC operation cancelled.',
            metadata: $metadata,
        );
    }

    private function deadlineExceeded(?string $streamType = null): CommunicationResult
    {
        $metadata = [
            'deadline_exceeded' => true,
            'attempts' => 0,
            'transport' => 'grpc',
        ];
        if ($streamType !== null) {
            $metadata['stream_type'] = $streamType;
        }

        return CommunicationResult::failure(
            'gRPC operation deadline exceeded.',
            GrpcStatus::DeadlineExceeded->value,
            metadata: $metadata,
        );
    }

    /** @phpstan-impure */
    private function operationDeadlineExpired(): bool
    {
        return $this->operationDeadline?->expired() === true;
    }

    /**
     * @param iterable<mixed> $messages
     * @param array<string, mixed> $metadata
     * @param null|callable(mixed):void $onMessage
     */
    private function runOutboundStream(
        string $streamType,
        string $method,
        iterable $messages,
        GrpcMetadata $headers,
        ?float $deadlineSeconds,
        array $metadata,
        ?callable $onMessage = null,
    ): CommunicationResult {
        $deadlineSeconds = $this->boundedDeadlineSeconds($deadlineSeconds);
        if ($deadlineSeconds === 0.0) {
            return $this->deadlineExceeded($streamType);
        }

        $request = new GrpcStreamRequest($method, $messages, $headers, $deadlineSeconds, $metadata);

        return $this->runStream(
            streamType: $streamType,
            method: $request->method,
            execute: fn(NativeGrpcStreamingInvoker $invoker): NativeGrpcResult => match ($streamType) {
                'bidi' => $invoker->bidiStream(
                    method: $request->method,
                    messages: $request->messages,
                    headers: $request->headers,
                    onMessage: $onMessage ?? static function (mixed $message): void {},
                    deadlineSeconds: $request->deadlineSeconds,
                ),
                'client' => $invoker->clientStream(
                    method: $request->method,
                    messages: $request->messages,
                    headers: $request->headers,
                    deadlineSeconds: $request->deadlineSeconds,
                ),
                default => throw new \InvalidArgumentException(sprintf('Unsupported stream type "%s".', $streamType)),
            },
        );
    }

    /**
     * @param callable(NativeGrpcStreamingInvoker):NativeGrpcResult $execute
     */
    private function runStream(string $streamType, string $method, callable $execute): CommunicationResult
    {
        if ($this->cancellation?->isRequested() === true) {
            return $this->cancelled($method, $streamType);
        }

        if ($this->operationDeadlineExpired()) {
            return $this->deadlineExceeded($streamType);
        }

        if ($this->streamingInvoker === null) {
            return CommunicationResult::failure(
                sprintf('gRPC %s streaming is unavailable for this client.', $streamType),
            );
        }

        $startedAt = $this->clock->monotonic();
        $this->events->dispatch('grpc.stream.start', [
            'transport' => 'grpc',
            'type' => $streamType,
            'method' => $method,
        ]);

        try {
            $native = $execute($this->streamingInvoker);
        } catch (Throwable $exception) {
            $durationMs = (int) (($this->clock->monotonic() - $startedAt) * 1000);
            $this->events->dispatch('grpc.stream.failed', [
                'transport' => 'grpc',
                'type' => $streamType,
                'method' => $method,
                'duration_ms' => $durationMs,
                ...ObservabilitySanitizer::throwableContext($exception),
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

        if ($this->operationDeadlineExpired()) {
            return $this->deadlineExceeded($streamType);
        }

        $durationMs = (int) (($this->clock->monotonic() - $startedAt) * 1000);
        $response = new GrpcResponse(
            status: GrpcStatus::fromCode($native->statusCode),
            message: $native->message,
            headers: $native->headers,
            trailers: $native->trailers,
            metadata: $native->metadata,
        );

        if (!$response->isOk()) {
            $this->events->dispatch('grpc.stream.failed', [
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

        $this->events->dispatch('grpc.stream.finish', [
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
