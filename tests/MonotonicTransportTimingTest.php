<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcInvoker;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcResult;
use Infocyph\TalkingBytes\Grpc\Native\NativeGrpcStreamingInvoker;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;

it('uses the injected monotonic clock for HTTP transport duration', function (): void {
    $now = 10.0;
    $clock = new Clock(
        static fn(): float => 1_700_000_000.0,
        static function () use (&$now): float {
            $current = $now;
            $now += 0.25;

            return $current;
        },
    );
    $events = [];
    $dispatcher = new CallableEventDispatcher(
        static function (string $event, array $payload) use (&$events): void {
            $events[$event] = $payload;
        },
    );

    $result = (new CurlTransport($dispatcher, $clock))->send(
        HttpRequest::get('https://example.test')->blockHosts(['example.test']),
    );

    expect($result->successful)->toBeFalse()
        ->and($events['http.request.failed']['duration_ms'] ?? null)->toBe(250);
});

it('uses the injected monotonic clock for unary gRPC duration', function (): void {
    $now = 20.0;
    $clock = new Clock(
        static fn(): float => 1_700_000_000.0,
        static function () use (&$now): float {
            $current = $now;
            $now += 0.125;

            return $current;
        },
    );
    $events = [];
    $dispatcher = new CallableEventDispatcher(
        static function (string $event, array $payload) use (&$events): void {
            $events[$event] = $payload;
        },
    );
    $transport = new GrpcTransport(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message),
        $dispatcher,
        $clock,
    );

    $result = $transport->send(new GrpcRequest('/runtime.v1.Health/Check', ['ok' => true]));

    expect($result->successful)->toBeTrue()
        ->and($events['grpc.request.finish']['duration_ms'] ?? null)->toBe(125);
});

it('preserves the injected monotonic clock across gRPC immutable streaming graphs', function (): void {
    $now = 30.0;
    $clock = new Clock(
        static fn(): float => 1_700_000_000.0,
        static function () use (&$now): float {
            $current = $now;
            $now += 0.25;

            return $current;
        },
    );
    $events = [];
    $dispatcher = new CallableEventDispatcher(
        static function (string $event, array $payload) use (&$events): void {
            $events[$event] = $payload;
        },
    );

    $invoker = new class implements NativeGrpcInvoker, NativeGrpcStreamingInvoker {
        public function invoke(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            unset($method, $headers, $deadlineSeconds);

            return new NativeGrpcResult(GrpcStatus::Ok->value, $message);
        }

        public function bidiStream(
            string $method,
            iterable $messages,
            GrpcMetadata $headers,
            callable $onMessage,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            unset($method, $headers, $deadlineSeconds);

            foreach ($messages as $message) {
                $onMessage($message);
            }

            return new NativeGrpcResult(GrpcStatus::Ok->value);
        }

        public function clientStream(
            string $method,
            iterable $messages,
            GrpcMetadata $headers,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            unset($method, $headers, $deadlineSeconds);

            foreach ($messages as $message) {
                unset($message);
            }

            return new NativeGrpcResult(GrpcStatus::Ok->value);
        }

        public function serverStream(
            string $method,
            mixed $message,
            GrpcMetadata $headers,
            callable $onMessage,
            ?float $deadlineSeconds = null,
        ): NativeGrpcResult {
            unset($method, $headers, $deadlineSeconds);

            $onMessage($message);

            return new NativeGrpcResult(GrpcStatus::Ok->value);
        }
    };

    $client = GrpcClient::usingNativeStreaming($invoker, $invoker, $dispatcher, $clock)
        ->withMiddlewares([]);

    $result = $client->clientStream('/runtime.v1.Health/Stream', [['ok' => true]]);

    expect($result->successful)->toBeTrue()
        ->and($events['grpc.stream.finish']['duration_ms'] ?? null)->toBe(250);
});
