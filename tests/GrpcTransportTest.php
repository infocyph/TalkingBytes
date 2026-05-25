<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Grpc\GrpcCallError;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\GrpcTransport;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;

it('sends grpc requests successfully and emits lifecycle events', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.request.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $client = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, ['echo' => $request->message]),
    );

    $result = $client->send(new GrpcRequest('Echo/Send', ['ping' => true]));
    CommunicationEventBus::listen(null);

    expect($result->successful)->toBeTrue()
        ->and($result->statusCode)->toBe(0)
        ->and($events)->toHaveCount(2)
        ->and($events[0]['event'])->toBe('grpc.request.start')
        ->and($events[1]['event'])->toBe('grpc.request.finish');
});

it('maps non-ok grpc response to failure result', function (): void {
    $client = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Unavailable, $request->message),
    );

    $result = $client->send(new GrpcRequest('Echo/Send', ['ping' => true]));

    expect($result->successful)->toBeFalse()
        ->and($result->statusCode)->toBe(GrpcStatus::Unavailable->value)
        ->and($result->error)->toContain('Unavailable')
        ->and($result->metadata['grpc_status_name'] ?? null)->toBe('Unavailable')
        ->and($result->metadata['grpc_error'] ?? null)->toBeInstanceOf(GrpcCallError::class);
});

it('maps grpc caller exceptions to failure results and failed event', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.request.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $client = GrpcClient::using(static function (): GrpcResponse {
        throw new RuntimeException('network down');
    });

    $result = $client->send(new GrpcRequest('Echo/Send', ['ping' => true]));
    CommunicationEventBus::listen(null);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('network down')
        ->and($result->response)->toBeInstanceOf(GrpcCallError::class)
        ->and($events[0]['event'])->toBe('grpc.request.start')
        ->and($events[1]['event'])->toBe('grpc.request.failed');
});

it('fails when grpc transport receives non-grpc payload', function (): void {
    $transport = new GrpcTransport(static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message));
    $result = $transport->send(new CommunicationRequest('grpc', ['invalid' => true]));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('expects GrpcRequest payload');
});

it('validates grpc request method and deadline', function (): void {
    expect(fn() => new GrpcRequest('', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcRequest("Svc/Call\nBad", ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcRequest(' Service/Call ', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcRequest('Service', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcRequest('Service/', ['x' => 1]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcRequest('Svc/Call', ['x' => 1], deadlineSeconds: 0.0))->toThrow(InvalidArgumentException::class);

    $request = new GrpcRequest('/Orders.Service/Create', ['x' => 1], deadlineSeconds: 1.2);
    expect($request->deadlineMicros())->toBe(1_200_000);
    expect(GrpcDeadline::secondsToMicros(0.5))->toBe(500_000);
});

it('validates grpc metadata names and values and supports helper accessors', function (): void {
    $metadata = (new GrpcMetadata())
        ->with('X-Request-Id', ['abc'])
        ->withValue('x-request-id', 'def');

    expect($metadata->values('x-request-id'))->toBe(['abc', 'def'])
        ->and($metadata->values('X-REQUEST-ID'))->toBe(['abc', 'def'])
        ->and($metadata->first('x-request-id'))->toBe('abc');

    expect(fn() => new GrpcMetadata(['Bad Header' => ['x']]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcMetadata(['x-token' => ["bad\r\nvalue"]]))->toThrow(InvalidArgumentException::class);
    expect(fn() => (new GrpcMetadata())->withValue('trace-bin', 'bytes'))->toThrow(InvalidArgumentException::class);
});

it('maps native grpc invoker contract and propagates deadline and metadata', function (): void {
    $invoker = new class implements NativeGrpcInvoker {
        /** @var array<string, mixed>|null */
        public ?array $captured = null;

        public function invoke(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            $this->captured = [
                'method' => $method,
                'message' => $message,
                'headers' => $headers->headers,
                'deadline' => $deadlineSeconds,
            ];

            return new NativeGrpcResult(
                statusCode: GrpcStatus::Ok->value,
                message: ['ok' => true],
                headers: new GrpcMetadata(['x-response-id' => ['res-1']]),
                trailers: new GrpcMetadata(['x-trailer' => ['trail-1']]),
            );
        }
    };

    $client = GrpcClient::usingNative($invoker);
    $request = (new GrpcRequest(
        method: 'Orders/Create',
        message: ['id' => 1001],
        headers: (new GrpcMetadata())->withValue('x-request-id', 'req-1'),
    ))->withDeadlineSeconds(1.25);

    $result = $client->send($request);

    expect($result->successful)->toBeTrue()
        ->and($invoker->captured)->toBeArray()
        ->and($invoker->captured['method'])->toBe('Orders/Create')
        ->and($invoker->captured['headers']['x-request-id'][0])->toBe('req-1')
        ->and($invoker->captured['deadline'])->toBe(1.25)
        ->and($result->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($result->response->trailers->first('x-trailer'))->toBe('trail-1');
});
