<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundSource;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcInboundSource;

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
        ->and($response->message)->toBe('Inbound gRPC handler failed.')
        ->and($response->metadata)->toBe([])
        ->and($events)->toHaveCount(2)
        ->and($events[0][0])->toBe('grpc.inbound.start')
        ->and($events[1][0])->toBe('grpc.inbound.failed')
        ->and($events[1][1]['exception'] ?? null)->toBe(RuntimeException::class)
        ->and(json_encode($response, JSON_THROW_ON_ERROR))->not->toContain('handler exploded')
        ->and(json_encode($response, JSON_THROW_ON_ERROR))->not->toContain(RuntimeException::class);
});

it('validates inbound grpc request method and deadline', function (): void {
    expect(fn() => new GrpcInboundRequest('', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcInboundRequest('Service', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcInboundRequest('/Svc/Call', ['x' => 1], deadlineSeconds: 0.0))->toThrow(InvalidArgumentException::class);
});


it('serves one inbound grpc exchange through a host-controlled source', function (): void {
    $source = new FakeGrpcInboundSource();
    $exchange = $source->enqueue(new GrpcInboundRequest(
        '/orders.v1.OrderService/Create',
        ['order_id' => 44],
    ));
    $server = GrpcInboundDispatcher::new()->withHandler(
        '/orders.v1.OrderService/Create',
        static fn(GrpcInboundRequest $request): GrpcInboundResponse => GrpcInboundResponse::ok([
            'order_id' => $request->message['order_id'] ?? null,
            'accepted' => true,
        ]),
    );

    $served = $server->serveOne($source);

    expect($served)->toBeTrue()
        ->and($source->pendingCount())->toBe(0)
        ->and($source->accepted())->toHaveCount(1)
        ->and($exchange->completed())->toBeTrue()
        ->and($exchange->response()?->status)->toBe(GrpcStatus::Ok)
        ->and($exchange->response()?->message['order_id'] ?? null)->toBe(44);
});

it('does not accept another inbound grpc exchange after host cancellation', function (): void {
    $source = new FakeGrpcInboundSource();
    $source->enqueue(new GrpcInboundRequest('/orders.v1.OrderService/Create', ['order_id' => 45]));

    $served = GrpcInboundDispatcher::new()->serveOne(
        $source,
        CancellationSignal::fromCallable(static fn(): bool => true),
    );

    expect($served)->toBeFalse()
        ->and($source->pendingCount())->toBe(1)
        ->and($source->accepted())->toBe([]);
});

it('completes an already accepted grpc exchange as cancelled when host cancellation arrives', function (): void {
    $exchange = new FakeGrpcInboundExchange(
        new GrpcInboundRequest('/orders.v1.OrderService/Create', ['order_id' => 46]),
    );
    $source = new class($exchange) implements GrpcInboundSource {
        public function __construct(private readonly GrpcInboundExchange $exchange) {}

        public function accept(?CancellationSignal $cancellation = null): ?GrpcInboundExchange
        {
            unset($cancellation);

            return $this->exchange;
        }
    };
    $checks = 0;
    $cancellation = CancellationSignal::fromCallable(static function () use (&$checks): bool {
        $checks++;

        return $checks > 1;
    });

    $served = GrpcInboundDispatcher::new()->serveOne($source, $cancellation);

    expect($served)->toBeTrue()
        ->and($exchange->completed())->toBeTrue()
        ->and($exchange->response()?->status)->toBe(GrpcStatus::Cancelled)
        ->and($exchange->response()?->message)->toBe('Inbound gRPC call cancelled.');
});

it('prevents fake grpc inbound exchanges from completing twice', function (): void {
    $exchange = new FakeGrpcInboundExchange(new GrpcInboundRequest('/Svc/Call', ['ok' => true]));
    $exchange->complete(GrpcInboundResponse::ok(['ok' => true]));

    expect(fn() => $exchange->complete(GrpcInboundResponse::ok()))
        ->toThrow(LogicException::class, 'already been completed');
});
