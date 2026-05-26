<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Closure;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Throwable;

final readonly class GrpcTransport implements TransportInterface
{
    /**
     * @var Closure(GrpcRequest): GrpcResponse
     */
    private Closure $caller;

    /**
     * @param callable(GrpcRequest): GrpcResponse $caller
     */
    public function __construct(callable $caller)
    {
        $this->caller = Closure::fromCallable($caller);
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof GrpcRequest) {
            return CommunicationResult::failure('GrpcTransport expects GrpcRequest payload.');
        }

        $grpcRequest = $request->payload;
        $startedAt = microtime(true);
        CommunicationEventBus::dispatch('grpc.request.start', [
            'transport' => 'grpc',
            'method' => $grpcRequest->method,
            'deadline_seconds' => $grpcRequest->deadlineSeconds,
            'metadata_header_count' => count($grpcRequest->headers->headers),
        ]);

        try {
            $response = ($this->caller)($grpcRequest);
        } catch (Throwable $exception) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            CommunicationEventBus::dispatch('grpc.request.failed', [
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
                ],
            );
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
        if (!$response->isOk()) {
            CommunicationEventBus::dispatch('grpc.request.failed', [
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

        CommunicationEventBus::dispatch('grpc.request.finish', [
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
