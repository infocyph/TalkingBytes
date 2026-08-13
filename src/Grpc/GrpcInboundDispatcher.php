<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc;

use Closure;
use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Throwable;

final class GrpcInboundDispatcher
{
    private readonly EventDispatcher $events;

    /**
     * @var array<string, Closure(GrpcInboundRequest):GrpcInboundResponse>
     */
    private array $handlers;

    /**
     * @param array<string, callable(GrpcInboundRequest):GrpcInboundResponse> $handlers
     */
    public function __construct(array $handlers = [], ?EventDispatcher $events = null)
    {
        $normalized = [];
        foreach ($handlers as $method => $handler) {
            $normalized[GrpcMethodGuard::normalize($method)] = Closure::fromCallable($handler);
        }

        $this->handlers = $normalized;
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    public static function new(): self
    {
        return new self();
    }

    public function handle(GrpcInboundRequest $request): GrpcInboundResponse
    {
        $startedAt = microtime(true);
        $this->events->dispatch('grpc.inbound.start', [
            'method' => $request->method,
            'deadline_seconds' => $request->deadlineSeconds,
            'metadata_header_count' => count($request->headers->headers),
        ]);

        $handler = $this->handlers[$request->method] ?? null;
        if ($handler === null) {
            $response = GrpcInboundResponse::unimplemented(sprintf('No inbound handler registered for method "%s".', $request->method));

            $this->events->dispatch('grpc.inbound.finish', [
                'method' => $request->method,
                'status_code' => $response->status->value,
                'status_name' => $response->status->name,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return $response;
        }

        try {
            $response = $handler($request);
        } catch (Throwable $exception) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $this->events->dispatch('grpc.inbound.failed', [
                'method' => $request->method,
                'duration_ms' => $durationMs,
                'exception' => $exception::class,
            ]);

            return new GrpcInboundResponse(
                status: GrpcStatus::Internal,
                message: 'Inbound gRPC handler failed.',
                metadata: [
                    'exception' => $exception::class,
                ],
            );
        }

        $this->events->dispatch('grpc.inbound.finish', [
            'method' => $request->method,
            'status_code' => $response->status->value,
            'status_name' => $response->status->name,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    public function receive(
        string $method,
        mixed $message,
        GrpcMetadata $headers = new GrpcMetadata(),
        ?float $deadlineSeconds = null,
    ): GrpcInboundResponse {
        return $this->handle(new GrpcInboundRequest($method, $message, $headers, $deadlineSeconds));
    }

    /**
     * @param callable(GrpcInboundRequest):GrpcInboundResponse|GrpcInboundHandlerInterface $handler
     */
    public function withHandler(string $method, callable|GrpcInboundHandlerInterface $handler): self
    {
        $method = GrpcMethodGuard::normalize($method);

        $handlers = $this->handlers;
        $handlers[$method] = $handler instanceof GrpcInboundHandlerInterface
            ? $handler->handle(...)
            : Closure::fromCallable($handler);

        return new self($handlers, $this->events);
    }
}
