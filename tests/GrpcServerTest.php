<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;

it('handles inbound grpc requests with registered handlers', function (): void {
    $server = GrpcInboundDispatcher::new()->withHandler(
        '/orders.v1.OrderService/Create',
        static function (GrpcInboundRequest $request): GrpcInboundResponse {
            expect($request->headers->first('x-request-id'))->toBe('req-42');

            return GrpcInboundResponse::ok([
                'ok' => true,
                'received' => $request->message,
            ]);
        },
    );

    $response = $server->receive(
        method: '/orders.v1.OrderService/Create',
        message: ['order_id' => 1001],
        headers: (new GrpcMetadata())->withValue('x-request-id', 'req-42'),
        deadlineSeconds: 3.0,
    );

    expect($response->isOk())->toBeTrue()
        ->and($response->status)->toBe(GrpcStatus::Ok)
        ->and($response->message['ok'] ?? false)->toBeTrue();
});

it('returns unimplemented for unknown inbound grpc methods', function (): void {
    $server = GrpcInboundDispatcher::new();

    $response = $server->receive('/orders.v1.OrderService/Unknown', ['order_id' => 1]);

    expect($response->status)->toBe(GrpcStatus::Unimplemented)
        ->and((string) $response->message)->toContain('No inbound handler registered');
});

it('supports class-based inbound grpc handlers', function (): void {
    $handler = new class implements GrpcInboundHandlerInterface
    {
        public function handle(GrpcInboundRequest $request): GrpcInboundResponse
        {
            return new GrpcInboundResponse(
                status: GrpcStatus::Ok,
                message: ['method' => $request->method],
            );
        }
    };

    $server = GrpcInboundDispatcher::new()->withHandler('/orders.v1.OrderService/Ping', $handler);
    $response = $server->receive('/orders.v1.OrderService/Ping', ['ping' => true]);

    expect($response->status)->toBe(GrpcStatus::Ok)
        ->and($response->message['method'] ?? null)->toBe('/orders.v1.OrderService/Ping');
});

it('maps inbound handler exceptions to internal status and emits failed events', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.inbound.')) {
            $events[] = [$event, $payload];
        }
    });

    $server = (new GrpcInboundDispatcher(events: $dispatcher))->withHandler(
        '/orders.v1.OrderService/Create',
        static function (): GrpcInboundResponse {
            throw new RuntimeException('handler exploded');
        },
    );

    $response = $server->receive('/orders.v1.OrderService/Create', ['order_id' => 1]);
    expect($response->status)->toBe(GrpcStatus::Internal)
        ->and($response->metadata['exception'] ?? null)->toBe(RuntimeException::class)
        ->and($events)->toHaveCount(2)
        ->and($events[0][0])->toBe('grpc.inbound.start')
        ->and($events[1][0])->toBe('grpc.inbound.failed');
});

it('validates inbound grpc request method and deadline', function (): void {
    expect(fn() => new GrpcInboundRequest('', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcInboundRequest('Service', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcInboundRequest('/Svc/Call', ['x' => 1], deadlineSeconds: 0.0))->toThrow(InvalidArgumentException::class);
});
