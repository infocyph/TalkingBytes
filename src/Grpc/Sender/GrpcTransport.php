<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Sender;

use Closure;
use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Throwable;

final readonly class GrpcTransport
{
    /**
     * @var Closure(GrpcRequest): GrpcResponse
     */
    private Closure $caller;

    private EventDispatcher $events;

    /**
     * @param callable(GrpcRequest): GrpcResponse $caller
     */
    public function __construct(callable $caller, ?EventDispatcher $events = null)
    {
        $this->caller = Closure::fromCallable($caller);
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    public function send(GrpcRequest $grpcRequest): CommunicationResult
    {
        $startedAt = microtime(true);
        $this->events->dispatch('grpc.request.start', [
            'transport' => 'grpc',
            'method' => $grpcRequest->method,
            'deadline_seconds' => $grpcRequest->deadlineSeconds,
            'metadata_header_count' => count($grpcRequest->headers->headers),
        ]);

        try {
            $response = ($this->caller)($grpcRequest);
        } catch (Throwable $exception) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $this->events->dispatch('grpc.request.failed', [
                'transport' => 'grpc',
                'method' => $grpcRequest->method,
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);

            $error = new GrpcCallError(
                method: $grpcRequest->method,
                status: GrpcStatus::Unknown,
                message: $exception->getMessage(),
                durationMs: $durationMs,
                metadata: [
                    'exception' => $exception::class,
                ],
            );

            return CommunicationResult::failure(
                sprintf('gRPC call failed: %s', $exception->getMessage()),
                null,
                $error,
                [
                    'transport' => 'grpc',
                    'method' => $grpcRequest->method,
                    'duration_ms' => $durationMs,
                    'grpc_error' => $error,
                    'grpc_transport_retryable' => $exception instanceof GrpcTransportException && $exception->retryable,
                ],
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        if (!$response->isOk()) {
            $this->events->dispatch('grpc.request.failed', [
                'transport' => 'grpc',
                'method' => $grpcRequest->method,
                'status_code' => $response->status->value,
                'status_name' => $response->status->name,
                'duration_ms' => $durationMs,
            ]);

            $error = new GrpcCallError(
                method: $grpcRequest->method,
                status: $response->status,
                message: sprintf('gRPC call failed with status %d (%s).', $response->status->value, $response->status->name),
                durationMs: $durationMs,
            );

            return CommunicationResult::failure(
                $error->message,
                $response->status->value,
                $response,
                [
                    'transport' => 'grpc',
                    'method' => $grpcRequest->method,
                    'duration_ms' => $durationMs,
                    'grpc_status' => $response->status->value,
                    'grpc_status_name' => $response->status->name,
                    'grpc_error' => $error,
                ],
            );
        }

        $this->events->dispatch('grpc.request.finish', [
            'transport' => 'grpc',
            'method' => $grpcRequest->method,
            'status_code' => $response->status->value,
            'duration_ms' => $durationMs,
        ]);

        return CommunicationResult::success(
            $response->status->value,
            $response,
            [
                'transport' => 'grpc',
                'method' => $grpcRequest->method,
                'duration_ms' => $durationMs,
            ],
        );
    }
}
