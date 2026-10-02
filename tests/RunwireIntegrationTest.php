<?php

declare(strict_types=1);

use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMailboxFactory;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\EmailReceiverFactory;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Grpc\GrpcClientFactory;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpClientFactory;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

function talkingBytesRunwireContext(bool $cooperative = false): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            ownsEventLoop: $cooperative,
            runwireLoopAvailable: $cooperative,
            supportsAsyncIo: $cooperative,
            supportsRunwireCoroutines: $cooperative,
        ),
        mode: 'native',
        concurrent: $cooperative,
    );
}

function talkingBytesRunwireRequest(
    RuntimeContext $runtime,
    ?float $maxExecutionSeconds = null,
    ?int $startNanoseconds = null,
): RequestContext {
    return RequestContext::create(
        $runtime,
        new RequestExecutionPolicy(maxExecutionSeconds: $maxExecutionSeconds),
        startNanoseconds: $startNanoseconds,
    );
}

function talkingBytesRunwireMessage(): EmailMessage
{
    return EmailMessage::new()
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('Runwire')
        ->text('payload');
}

it('bounds borrowed Runwire stream readiness without owning the scope', function (): void {
    $runtime = talkingBytesRunwireContext(cooperative: true);
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Unable to create stream pair for Runwire readiness test.');
    }

    try {
        $events = [];
        $read = (new CoroutineRuntime())->run(
            static function (CoroutineScope $scope) use ($runtime, $pair, &$events): string {
                $waiter = (new RunwireBinding($runtime, scope: $scope))->streamWaiter();
                expect($waiter)->not->toBeNull();

                $scope->spawn(static function () use ($scope, $pair, &$events): void {
                    $scope->sleep(0.02);
                    fwrite($pair[1], 'ready');
                    $events[] = 'writer';
                });

                $ready = $waiter?->waitReadable($pair[0], OperationDeadline::after(0.2));
                expect($ready)->toBeTrue();
                $events[] = 'reader';

                return (string) fread($pair[0], 5);
            },
        );

        expect($read)->toBe('ready')
            ->and($events)->toBe(['writer', 'reader']);

        $timedOut = (new CoroutineRuntime())->run(
            static function (CoroutineScope $scope) use ($runtime, $pair): bool {
                $waiter = (new RunwireBinding($runtime, scope: $scope))->streamWaiter();

                return $waiter?->waitReadable($pair[0], OperationDeadline::after(0.03)) ?? true;
            },
        );

        expect($timedOut)->toBeFalse();
    } finally {
        fclose($pair[0]);
        fclose($pair[1]);
    }
});

it('supports repeated intermediary Runwire binding without taking host lifecycle ownership', function (): void {
    $runtime = talkingBytesRunwireContext();
    $request = talkingBytesRunwireRequest($runtime);
    $transport = new FakeHttpTransport();

    $firstIntermediary = static fn(HttpClientFactory $factory): HttpClientFactory => $factory->withRunwire(
        $runtime,
        $request,
    );
    $secondIntermediary = static fn(HttpClientFactory $factory): HttpClientFactory => $factory->withRunwire(
        $runtime,
        $request,
    );

    $client = $secondIntermediary($firstIntermediary(new HttpClientFactory()))
        ->fromArray([], $transport);

    $request->cancel(CancellationReason::HOST_CANCELLED);
    $result = $client->get('https://example.test/intermediary');

    expect($result->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($transport->sentRequests())->toBe([])
        ->and($request->completed())->toBeFalse();

    $request->complete();
});

it('keeps sequential Runwire request bindings isolated when reusing an unbound factory', function (): void {
    $runtime = talkingBytesRunwireContext();
    $baseFactory = new HttpClientFactory();
    $requestA = talkingBytesRunwireRequest($runtime);
    $requestB = talkingBytesRunwireRequest($runtime);
    $transportA = new FakeHttpTransport();
    $transportB = new FakeHttpTransport();

    $clientA = $baseFactory->withRunwire($runtime, $requestA)->fromArray([], $transportA);
    $clientB = $baseFactory->withRunwire($runtime, $requestB)->fromArray([], $transportB);

    $requestA->cancel(CancellationReason::HOST_CANCELLED);

    $resultA = $clientA->get('https://tenant-a.example.test');
    $resultB = $clientB->get('https://tenant-b.example.test');

    expect($resultA->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($transportA->sentRequests())->toBe([])
        ->and($resultB->successful)->toBeTrue()
        ->and($transportB->sentRequests())->toHaveCount(1)
        ->and($requestB->completed())->toBeFalse();

    $requestA->complete();
    $requestB->complete();
});

it('supports runtime-only binding without changing the normal protocol path', function (): void {
    $runtime = talkingBytesRunwireContext();
    $transport = new FakeHttpTransport();
    $client = (new HttpClientFactory())
        ->withRunwire($runtime)
        ->fromArray([], $transport);

    $result = $client->get('https://example.test/runtime-only');

    expect($result->successful)->toBeTrue()
        ->and($transport->sentRequests())->toHaveCount(1);
});

it('rejects completed mismatched and capability-invalid Runwire bindings', function (): void {
    $runtime = talkingBytesRunwireContext();
    $otherRuntime = talkingBytesRunwireContext();
    $request = talkingBytesRunwireRequest($runtime);

    expect(fn() => (new HttpClientFactory())->withRunwire($otherRuntime, $request))
        ->toThrow(LogicException::class, 'different runtime context');

    $request->complete();

    expect(fn() => (new EmailSenderFactory())->withRunwire($runtime, $request))
        ->toThrow(LogicException::class, 'Completed Runwire request context');

    (new CoroutineRuntime())->run(
        static function (CoroutineScope $scope) use ($runtime): void {
            expect(fn() => (new GrpcClientFactory())->withRunwire($runtime, scope: $scope))
                ->toThrow(LogicException::class, 'coroutine scope requires coroutine and loop capabilities');
        },
    );
});

it('stops bound protocol work before side effects after host cancellation', function (): void {
    $runtime = talkingBytesRunwireContext();
    $request = talkingBytesRunwireRequest($runtime);
    $request->cancel(CancellationReason::HOST_CANCELLED);

    $httpTransport = new FakeHttpTransport();
    $httpResult = (new HttpClientFactory())
        ->withRunwire($runtime, $request)
        ->fromArray([], $httpTransport)
        ->get('https://example.test/cancelled');

    $emailResult = (new EmailSenderFactory())
        ->withRunwire($runtime, $request)
        ->usingNull()
        ->send(talkingBytesRunwireMessage());

    $grpcCalls = 0;
    $grpcResult = (new GrpcClientFactory())
        ->withRunwire($runtime, $request)
        ->using(static function (GrpcRequest $grpcRequest) use (&$grpcCalls): GrpcResponse {
            unset($grpcRequest);
            $grpcCalls++;

            return new GrpcResponse(GrpcStatus::Ok, null);
        })
        ->send(new GrpcRequest('/runwire.v1.Test/Call', []));

    $webhook = Webhook::sender(
        (new HttpClientFactory())
            ->withRunwire($runtime, $request)
            ->fromArray([], $httpTransport),
    )->send(
        WebhookMessage::new('runwire.cancelled')
            ->url('https://example.test/webhook')
            ->payload(['ok' => false]),
    );

    $mailbox = (new EmailMailboxFactory())
        ->withRunwire($runtime, $request)
        ->usingImap(new ImapConfig(
            host: '127.0.0.1',
            username: 'user',
            password: 'pass',
            security: ImapSecurity::None,
            port: 9,
        ));

    $receiver = (new EmailReceiverFactory())
        ->withRunwire($runtime, $request)
        ->usingSpool(new SpoolConfig(sys_get_temp_dir() . '/talkingbytes-runwire-cancelled'));

    expect($httpResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($httpTransport->sentRequests())->toBe([])
        ->and($emailResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($grpcResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($grpcCalls)->toBe(0)
        ->and($webhook->result->metadata['cancelled'] ?? false)->toBeTrue()
        ->and(fn() => $mailbox->connect())
        ->toThrow(MailboxConnectionException::class, 'operation cancelled')
        ->and(fn() => $receiver->peek())
        ->toThrow(RuntimeException::class, 'operation cancelled');
});

it('propagates an already-expired host deadline without starting protocol work', function (): void {
    $runtime = talkingBytesRunwireContext();
    $request = talkingBytesRunwireRequest(
        $runtime,
        maxExecutionSeconds: 0.01,
        startNanoseconds: hrtime(true) - 1_000_000_000,
    );

    $httpTransport = new FakeHttpTransport();
    $httpResult = (new HttpClientFactory())
        ->withRunwire($runtime, $request)
        ->fromArray([], $httpTransport)
        ->get('https://example.test/expired');

    $emailResult = (new EmailSenderFactory())
        ->withRunwire($runtime, $request)
        ->usingNull()
        ->send(talkingBytesRunwireMessage());

    $grpcCalls = 0;
    $grpcResult = (new GrpcClientFactory())
        ->withRunwire($runtime, $request)
        ->using(static function (GrpcRequest $grpcRequest) use (&$grpcCalls): GrpcResponse {
            unset($grpcRequest);
            $grpcCalls++;

            return new GrpcResponse(GrpcStatus::Ok, null);
        })
        ->send(new GrpcRequest('/runwire.v1.Test/Deadline', []));

    expect($httpResult->metadata['deadline_exceeded'] ?? false)->toBeTrue()
        ->and($httpTransport->sentRequests())->toBe([])
        ->and($emailResult->metadata['deadline_exceeded'] ?? false)->toBeTrue()
        ->and($grpcResult->statusCode)->toBe(GrpcStatus::DeadlineExceeded->value)
        ->and($grpcResult->metadata['deadline_exceeded'] ?? false)->toBeTrue()
        ->and($grpcCalls)->toBe(0);
});

it('uses a borrowed Runwire scope for retry waits without closing or driving it', function (): void {
    $runtime = talkingBytesRunwireContext(cooperative: true);
    $events = [];
    $attempts = 0;

    (new CoroutineRuntime())->run(
        static function (CoroutineScope $scope) use ($runtime, &$events, &$attempts): void {
            $scope->spawn(static function () use (&$events): void {
                $events[] = 'peer';
            });

            $transport = new class($events, $attempts) implements HttpTransport {
                public function __construct(
                    private array &$events,
                    private int &$attempts,
                ) {}

                public function send(HttpRequest $request): CommunicationResult
                {
                    unset($request);
                    $this->attempts++;
                    $this->events[] = 'attempt-' . $this->attempts;

                    return $this->attempts === 1
                        ? CommunicationResult::failure(
                            'temporary',
                            503,
                            new HttpResponse(503, ''),
                        )
                        : CommunicationResult::success(
                            200,
                            new HttpResponse(200, 'ok'),
                        );
                }
            };

            $client = (new HttpClientFactory())
                ->withRunwire($runtime, scope: $scope)
                ->fromArray([
                    'retry' => [
                        'enabled' => true,
                        'attempts' => 2,
                        'base_delay_ms' => 10,
                        'max_retry_after_seconds' => 1,
                    ],
                ], $transport);

            expect($client->get('https://example.test/cooperative')->successful)->toBeTrue();

            $scope->spawn(static function () use (&$events): void {
                $events[] = 'after';
            });
            $scope->yieldNow();
        },
    );

    expect($attempts)->toBe(2)
        ->and($events)->toBe(['attempt-1', 'peer', 'attempt-2', 'after']);
});

it('maps host cancellation during cooperative backoff to the normal cancellation result', function (): void {
    $runtime = talkingBytesRunwireContext(cooperative: true);
    $request = talkingBytesRunwireRequest($runtime);
    $attempts = 0;

    $result = (new CoroutineRuntime())->run(
        static function (CoroutineScope $scope) use ($runtime, $request, &$attempts): CommunicationResult {
            $scope->spawn(static function () use ($request): void {
                $request->cancel(CancellationReason::HOST_CANCELLED);
            });

            $transport = new class($attempts) implements HttpTransport {
                public function __construct(private int &$attempts) {}

                public function send(HttpRequest $httpRequest): CommunicationResult
                {
                    unset($httpRequest);
                    $this->attempts++;

                    return CommunicationResult::failure(
                        'temporary',
                        503,
                        new HttpResponse(503, ''),
                    );
                }
            };

            return (new HttpClientFactory())
                ->withRunwire($runtime, $request, $scope)
                ->fromArray([
                    'retry' => [
                        'enabled' => true,
                        'attempts' => 3,
                        'base_delay_ms' => 50,
                        'max_retry_after_seconds' => 1,
                    ],
                ], $transport)
                ->get('https://example.test/cancel-during-wait');
        },
    );

    expect($result->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($attempts)->toBe(1)
        ->and($request->completed())->toBeFalse();

    $request->complete();
});
