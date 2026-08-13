<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcCallError;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcDeadline;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcStreamRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransport;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;

it('sends grpc requests successfully and emits lifecycle events', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.request.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $client = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, ['echo' => $request->message]),
        $dispatcher,
    );

    $result = $client->send(new GrpcRequest('Echo/Send', ['ping' => true]));
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
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.request.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $client = GrpcClient::using(static function (): GrpcResponse {
        throw new RuntimeException('network down');
    }, $dispatcher);

    $result = $client->send(new GrpcRequest('Echo/Send', ['ping' => true]));
    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('network down')
        ->and($result->response)->toBeInstanceOf(GrpcCallError::class)
        ->and($events[0]['event'])->toBe('grpc.request.start')
        ->and($events[1]['event'])->toBe('grpc.request.failed');
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
    expect(GrpcDeadline::secondsToMicros(0.0000001))->toBe(1);
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
    expect(fn() => new GrpcMetadata(['x-token' => ['bad' => 'shape']]))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcMetadata(['x-token' => ["bad\x01value"]]))->toThrow(InvalidArgumentException::class);
    expect(fn() => (new GrpcMetadata())->withValue('trace-bin', 'bytes'))->toThrow(InvalidArgumentException::class);

    $bin = (new GrpcMetadata())
        ->withBinaryValue('trace-bin', "\x01\x02")
        ->withBinaryValue('trace-bin', "\x03");
    expect($bin->binaryValues('trace-bin'))->toHaveCount(2)
        ->and($bin->firstBinary('trace-bin'))->toBe("\x01\x02")
        ->and(fn() => $bin->values('trace-bin'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $metadata->binaryValues('x-request-id'))->toThrow(InvalidArgumentException::class);
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
        ->and($invoker->captured['method'])->toBe('/Orders/Create')
        ->and($invoker->captured['headers']['x-request-id'][0])->toBe('req-1')
        ->and($invoker->captured['deadline'])->toBe(1.25)
        ->and($result->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($result->response->trailers->first('x-trailer'))->toBe('trail-1');
});

it('supports native grpc streaming for server/client/bidi calls', function (): void {
    $invoker = new class implements NativeGrpcInvoker, NativeGrpcStreamingInvoker {
        public function bidiStream(
            string $method,
            iterable $messages,
            GrpcMetadata $headers,
            callable $onMessage,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            $onMessage([
                'method' => $method,
                'headers' => $headers->headers,
                'deadline' => $deadlineSeconds,
            ]);

            foreach ($messages as $message) {
                $onMessage(['echo' => $message]);
            }

            return new NativeGrpcResult(GrpcStatus::Ok->value, ['done' => true]);
        }

        public function clientStream(
            string $method,
            iterable $messages,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            $count = 0;
            foreach ($messages as $_message) {
                $count++;
            }

            return new NativeGrpcResult(GrpcStatus::Ok->value, [
                'received' => $count,
                'method' => $method,
                'has_header' => $headers->has('x-request-id'),
                'deadline' => $deadlineSeconds,
            ]);
        }

        public function invoke(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            return new NativeGrpcResult(GrpcStatus::Ok->value, [
                'ok' => true,
                'method' => $method,
                'message' => $message,
                'headers' => $headers->headers,
                'deadline' => $deadlineSeconds,
            ]);
        }

        public function serverStream(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            callable $onMessage,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            $onMessage([
                'chunk' => 1,
                'method' => $method,
                'deadline' => $deadlineSeconds,
                'request' => $message,
            ]);
            $onMessage(['chunk' => 2, 'headers' => $headers->first('x-request-id')]);

            return new NativeGrpcResult(GrpcStatus::Ok->value);
        }
    };

    $client = GrpcClient::usingNativeStreaming($invoker, $invoker);

    expect($client->supportsStreaming())->toBeTrue();

    $serverChunks = [];
    $serverResult = $client->serverStream(
        (new GrpcRequest(
            method: 'Orders/Stream',
            message: ['cursor' => 1],
            headers: (new GrpcMetadata())->withValue('x-request-id', 'req-stream'),
            deadlineSeconds: 2.5,
        )),
        static function (mixed $chunk) use (&$serverChunks): void {
            $serverChunks[] = $chunk;
        },
    );

    $clientResult = $client->clientStream(
        method: 'Orders/Upload',
        messages: [['id' => 1], ['id' => 2]],
    );

    $bidiChunks = [];
    $bidiResult = $client->bidiStream(
        method: 'Orders/Bidi',
        messages: ['a', 'b'],
        onMessage: static function (mixed $chunk) use (&$bidiChunks): void {
            $bidiChunks[] = $chunk;
        },
    );

    expect($serverResult->successful)->toBeTrue()
        ->and($clientResult->successful)->toBeTrue()
        ->and($bidiResult->successful)->toBeTrue()
        ->and($clientResult->response)->toBeInstanceOf(GrpcResponse::class)
        ->and($clientResult->response->message['received'])->toBe(2)
        ->and($bidiResult->response->message['done'])->toBeTrue()
        ->and($serverChunks)->toHaveCount(2)
        ->and($bidiChunks)->toHaveCount(3)
        ->and($bidiChunks[0]['method'])->toBe('/Orders/Bidi');
});

it('fails streaming calls when native streaming invoker is not configured', function (): void {
    $client = GrpcClient::using(static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message));

    $result = $client->clientStream('Orders/Upload', [['id' => 1]]);

    expect($client->supportsStreaming())->toBeFalse()
        ->and($result->successful)->toBeFalse()
        ->and($result->error)->toContain('streaming is unavailable');
});

it('validates grpc stream request method and deadline', function (): void {
    expect(fn() => new GrpcStreamRequest('', []))->toThrow(InvalidArgumentException::class);
    expect(fn() => new GrpcStreamRequest('Orders/Stream', [], deadlineSeconds: 0.0))->toThrow(InvalidArgumentException::class);

    $request = new GrpcStreamRequest('Orders/Stream', [['id' => 1]], deadlineSeconds: 1.5);
    expect($request->deadlineMicros())->toBe(1_500_000);
});
