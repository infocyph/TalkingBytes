<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\EmailSenderFactory;
use Infocyph\TalkingBytes\Email\Transport\DkimSigningTransport;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcClientFactory;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Http\Testing\SequenceHttpTransport;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;
use Infocyph\TalkingBytes\Webhook\Replay\InMemoryWebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

it('builds resolved http protocol composition without host-side middleware construction', function (): void {
    $transport = new FakeHttpTransport();
    $client = HttpClient::fromResolvedConfig([
        'timeoutSeconds' => 7,
        'auth' => [
            'driver' => 'bearer',
            'token' => 'resolved-token',
        ],
        'cookies' => ['enabled' => true],
        'retry' => [
            'enabled' => true,
            'attempts' => 2,
            'base_delay_ms' => 0,
            'max_retry_after_seconds' => 1,
        ],
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 10,
            'per_seconds' => 60,
        ],
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 3,
            'cool_down_seconds' => 5,
        ],
        'idempotency' => [
            'enabled' => true,
            'header' => 'Idempotency-Key',
        ],
    ], transport: $transport);

    $result = $client->send(HttpRequest::post('https://example.test/orders')->json(['id' => 1]));
    $request = $transport->sentRequests()[0];

    expect($result->successful)->toBeTrue()
        ->and($client->hasRetryMiddleware())->toBeTrue()
        ->and($request->headers->get('Authorization'))->toBe('Bearer resolved-token')
        ->and($request->headers->get('Idempotency-Key'))->toMatch('/^[a-f0-9]{32}$/')
        ->and($request->options->timeoutSeconds)->toBe(7);
});

it('reuses prevalidated typed HTTP config without losing resolved protocol composition', function (): void {
    $transport = new FakeHttpTransport();
    $transport->push(CommunicationResult::success(
        statusCode: 200,
        response: new HttpResponse(
            200,
            '{"ok":true}',
            ['Set-Cookie' => 'session=typed; Path=/; Secure; HttpOnly'],
        ),
    ));

    $baseConfig = new HttpClientConfig(
        timeoutSeconds: 7,
        connectTimeoutSeconds: 3,
        verifyPeer: true,
        verifyHost: true,
        defaultHeaders: ['X-Base-Config' => 'typed'],
    );
    $resolved = [
        // These base keys are intentionally conflicting. When baseConfig is
        // supplied they must not be reparsed or override the typed policy.
        'timeoutSeconds' => 99,
        'connectTimeoutSeconds' => 99,
        'verifyPeer' => false,
        'verifyHost' => false,
        'auth' => [
            'driver' => 'bearer',
            'token' => 'typed-token',
        ],
        'cookies' => ['enabled' => true],
        'retry' => [
            'enabled' => true,
            'attempts' => 2,
            'base_delay_ms' => 0,
            'max_retry_after_seconds' => 1,
        ],
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 10,
            'per_seconds' => 60,
        ],
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 3,
            'cool_down_seconds' => 5,
        ],
        'idempotency' => [
            'enabled' => true,
            'header' => 'Idempotency-Key',
        ],
    ];

    $client = HttpClient::fromResolvedConfig(
        $resolved,
        transport: $transport,
        baseConfig: $baseConfig,
    );

    $client->send(HttpRequest::post('https://example.test/orders')->json(['id' => 7]));
    $client->get('https://example.test/orders/7');

    [$first, $second] = $transport->sentRequests();

    expect($client->hasRetryMiddleware())->toBeTrue()
        ->and($first->options->timeoutSeconds)->toBe(7)
        ->and($first->options->connectTimeoutSeconds)->toBe(3)
        ->and($first->options->verifyPeer)->toBeTrue()
        ->and($first->options->verifyHost)->toBeTrue()
        ->and($first->headers->get('X-Base-Config'))->toBe('typed')
        ->and($first->headers->get('Authorization'))->toBe('Bearer typed-token')
        ->and($first->headers->get('Idempotency-Key'))->toMatch('/^[a-f0-9]{32}$/')
        ->and($second->headers->get('Cookie'))->toContain('session=typed');

    $reflection = new ReflectionClass($client);
    $middleware = $reflection->getProperty('middlewares')->getValue($client);
    $types = array_map(static fn(object $item): string => $item::class, $middleware);

    expect($types)
        ->toContain(Infocyph\TalkingBytes\Http\Middleware\RetryMiddleware::class)
        ->toContain(Infocyph\TalkingBytes\Http\Middleware\RateLimitMiddleware::class)
        ->toContain(Infocyph\TalkingBytes\Http\Middleware\CircuitBreakerMiddleware::class)
        ->toContain(Infocyph\TalkingBytes\Http\Middleware\IdempotencyMiddleware::class);
});

it('parses email limits and composes resolved sender transport decorators', function (): void {
    $limits = EmailLimits::fromArray([
        'maxMessageBytes' => '2048',
        'maxAttachmentBytes' => 4096,
        'maxHeaderLineBytes' => '900',
    ]);

    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();

    $privateKey = '';
    expect(openssl_pkey_export($key, $privateKey))->toBeTrue();

    $emailer = (new EmailSenderFactory())->fromResolvedConfig([
        'transport' => ['driver' => 'null'],
        'fallbacks' => [
            ['driver' => 'fake'],
        ],
        'retry' => [
            'enabled' => true,
            'policy' => 'fixed',
            'max_attempts' => 2,
            'delay_ms' => 0,
        ],
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 10,
            'per_seconds' => 60,
        ],
        'dkim' => [
            'enabled' => true,
            'domain' => 'example.test',
            'selector' => 'mail',
            'private_key' => $privateKey,
            'headers' => ['from', 'to', 'subject'],
        ],
    ]);

    $result = $emailer->send(
        EmailMessage::new()
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Resolved sender')
            ->text('ok'),
    );

    expect($limits->maxMessageBytes)->toBe(2048)
        ->and($limits->maxAttachmentBytes)->toBe(4096)
        ->and($limits->maxHeaderLineBytes)->toBe(900)
        ->and($emailer->transport())->toBeInstanceOf(DkimSigningTransport::class)
        ->and($result->successful)->toBeTrue();
});

it('builds grpc retry and generated stub clients from resolved protocol config', function (): void {
    $attempts = 0;
    $client = (new GrpcClientFactory())->using(
        static function (GrpcRequest $request) use (&$attempts): GrpcResponse {
            unset($request);
            $attempts++;

            return new GrpcResponse(
                status: $attempts === 1 ? GrpcStatus::Unavailable : GrpcStatus::Ok,
                message: ['attempt' => $attempts],
            );
        },
        [
            'retry' => [
                'enabled' => true,
                'attempts' => 2,
                'base_delay_ms' => 0,
                'jitter_ratio' => 0,
            ],
        ],
    );

    $result = $client->send(
        (new GrpcRequest('/orders.v1.OrderService/Create', ['id' => 1]))
            ->withRetrySafety(),
    );

    $stub = new class {
        public function Ping(mixed $message, array $metadata = [], array $options = []): object
        {
            unset($metadata, $options);

            return new class($message) {
                public function __construct(private readonly mixed $message) {}

                public function wait(): array
                {
                    return [['echo' => $this->message], ['code' => 0]];
                }
            };
        }
    };

    $generated = GrpcClient::usingGeneratedStub($stub);
    $generatedResult = $generated->send(new GrpcRequest('/example.PingService/Ping', ['ping' => true]));

    expect($result->successful)->toBeTrue()
        ->and($attempts)->toBe(2)
        ->and($generatedResult->successful)->toBeTrue()
        ->and($generatedResult->response->message['echo']['ping'] ?? false)->toBeTrue();
});

it('builds webhook signing retry and replay-aware receiver policies from resolved config', function (): void {
    $transport = new SequenceHttpTransport([
        CommunicationResult::failure(
            'temporary',
            503,
            new HttpResponse(503, '{"retry":true}'),
        ),
        CommunicationResult::success(
            200,
            new HttpResponse(200, '{"ok":true}'),
        ),
    ]);

    $sender = Webhook::senderFromResolvedConfig(
        HttpClient::using($transport),
        [
            'signing_secret' => 'whsec_resolved',
            'retry' => [
                'enabled' => true,
                'attempts' => 2,
                'base_delay_ms' => 0,
                'max_retry_after_seconds' => 1,
            ],
        ],
    );

    $delivery = $sender->send(
        WebhookMessage::new('order.created')
            ->url('https://hooks.example.test/orders')
            ->payload(['order_id' => 7]),
    );

    $requests = $transport->sentRequests();
    $receiver = Webhook::receiverFromResolvedConfig(
        'whsec_resolved',
        [
            'max_age_seconds' => 300,
            'replay' => [
                'enabled' => true,
                'ttl_seconds' => 60,
                'namespace' => 'resolved',
            ],
        ],
        new InMemoryWebhookReplayStore(),
    );

    expect($delivery->result->successful)->toBeTrue()
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->headers->get(WebhookHeaders::SIGNATURE))->not->toBeNull()
        ->and($receiver)->toBeInstanceOf(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class);
});


it('propagates the injected clock to resolved http resilience owners', function (): void {
    $now = 100.0;
    $clock = new Clock(
        static fn(): float => $now,
        static function () use (&$now): float {
            return $now;
        },
    );
    $transport = new FakeHttpTransport();
    $client = (new Infocyph\TalkingBytes\Http\HttpClientFactory(clock: $clock))->fromArray([
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 1,
            'per_seconds' => 1,
        ],
    ], $transport);

    expect($client->get('https://example.test/one')->successful)->toBeTrue();
    $now += 1.0;
    expect($client->get('https://example.test/two')->successful)->toBeTrue();

    $sequence = new SequenceHttpTransport([
        CommunicationResult::failure('temporary', 503, new HttpResponse(503, '')),
        CommunicationResult::success(200, new HttpResponse(200, '')),
    ]);
    $breaker = (new Infocyph\TalkingBytes\Http\HttpClientFactory(clock: $clock))->fromArray([
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 1,
            'cool_down_seconds' => 2,
        ],
    ], $sequence);

    expect($breaker->get('https://example.test/fail')->successful)->toBeFalse();
    expect(fn() => $breaker->get('https://example.test/blocked'))
        ->toThrow(RuntimeException::class, 'Circuit breaker is open');

    $now += 2.0;
    expect($breaker->get('https://example.test/recovered')->successful)->toBeTrue();
});

it('propagates injected sleepers through resolved http email and grpc retries', function (): void {
    $slept = [];
    $sleeper = new Sleeper(static function (int $microseconds) use (&$slept): void {
        $slept[] = $microseconds;
    });

    $http = HttpClient::fromResolvedConfig(
        [
            'retry' => [
                'enabled' => true,
                'attempts' => 2,
                'base_delay_ms' => 100,
                'max_retry_after_seconds' => 1,
            ],
        ],
        transport: new SequenceHttpTransport([
            CommunicationResult::failure('temporary', 503, new HttpResponse(503, '')),
            CommunicationResult::success(200, new HttpResponse(200, '')),
        ]),
        sleeper: $sleeper,
    );
    expect($http->get('https://example.test/retry')->successful)->toBeTrue();

    $emailAttempts = 0;
    $emailTransport = new class($emailAttempts) implements EmailTransport {
        public function __construct(private int &$attempts) {}

        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);
            $this->attempts++;

            return $this->attempts === 1
                ? CommunicationResult::failure('temporary')
                : CommunicationResult::success();
        }
    };
    $emailer = (new EmailSenderFactory(sleeper: $sleeper))
        ->usingNull()
        ->withTransport($emailTransport)
        ->withRetry(new FixedDelayRetryPolicy(2, 125));

    $message = EmailMessage::new()
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('Retry')
        ->text('ok');
    expect($emailer->send($message)->successful)->toBeTrue();

    $grpcAttempts = 0;
    $grpc = (new GrpcClientFactory(sleeper: $sleeper))->using(
        static function (GrpcRequest $request) use (&$grpcAttempts): GrpcResponse {
            unset($request);
            $grpcAttempts++;

            return new GrpcResponse(
                $grpcAttempts === 1 ? GrpcStatus::Unavailable : GrpcStatus::Ok,
            );
        },
        [
            'retry' => [
                'enabled' => true,
                'attempts' => 2,
                'base_delay_ms' => 150,
                'jitter_ratio' => 0,
            ],
        ],
    );
    expect($grpc->send(
        (new GrpcRequest('/example.Service/Retry', []))->withRetrySafety(),
    )->successful)->toBeTrue();

    expect($slept)->toBe([100_000, 125_000, 150_000]);
});

it('propagates the injected clock to resolved email rate limiting', function (): void {
    $now = 200.0;
    $clock = new Clock(
        static fn(): float => $now,
        static function () use (&$now): float {
            return $now;
        },
    );
    $emailer = (new EmailSenderFactory(clock: $clock))->fromResolvedConfig([
        'transport' => ['driver' => 'null'],
        'rate_limit' => [
            'enabled' => true,
            'max_requests' => 1,
            'per_seconds' => 1,
        ],
    ]);
    $message = EmailMessage::new()
        ->from('sender@example.test')
        ->to('recipient@example.test')
        ->subject('Clock')
        ->text('ok');

    expect($emailer->send($message)->successful)->toBeTrue();
    $now += 1.0;
    expect($emailer->send($message)->successful)->toBeTrue();
});


it('honors resolved cancellation before http email and grpc side effects when retry is disabled', function (): void {
    $cancellation = CancellationSignal::fromCallable(static fn(): bool => true);

    $httpTransport = new FakeHttpTransport();
    $http = HttpClient::fromResolvedConfig(
        [],
        cancellation: $cancellation,
        transport: $httpTransport,
    );
    $httpResult = $http->send(HttpRequest::post('https://example.test/orders')->raw('payload'));

    $email = (new EmailSenderFactory())->fromResolvedConfig(
        ['transport' => ['driver' => 'null']],
        $cancellation,
    );
    $emailResult = $email->send(
        EmailMessage::new()
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Cancelled')
            ->text('payload'),
    );

    $grpcCalls = 0;
    $grpc = (new GrpcClientFactory(cancellation: $cancellation))->using(
        static function (GrpcRequest $request) use (&$grpcCalls): GrpcResponse {
            unset($request);
            $grpcCalls++;

            return new GrpcResponse(GrpcStatus::Ok);
        },
    );
    $grpcResult = $grpc->send(new GrpcRequest('/example.Service/Create', []));

    expect($httpResult->successful)->toBeFalse()
        ->and($httpResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($httpTransport->sentRequests())->toBe([])
        ->and($emailResult->successful)->toBeFalse()
        ->and($emailResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($grpcResult->successful)->toBeFalse()
        ->and($grpcResult->metadata['cancelled'] ?? false)->toBeTrue()
        ->and($grpcCalls)->toBe(0);
});
