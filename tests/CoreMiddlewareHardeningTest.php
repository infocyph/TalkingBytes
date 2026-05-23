<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Middleware\CircuitBreakerMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\HeaderMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\RateLimitMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\TimeoutMiddleware;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

it('retries when transport throws and policy allows retry', function (): void {
    $attempts = 0;

    $transport = new class($attempts) implements TransportInterface {
        public function __construct(private int &$attempts) {}

        public function send(CommunicationRequest $request): CommunicationResult
        {
            unset($request);

            $this->attempts++;

            if ($this->attempts === 1) {
                throw new \RuntimeException('temporary failure');
            }

            return CommunicationResult::success(200);
        }
    };

    $pipeline = new MiddlewarePipeline($transport, [new RetryMiddleware(new FixedDelayRetryPolicy(2, 0))]);

    $result = $pipeline->send(new CommunicationRequest('test', ['hello' => 'world']));

    expect($result->successful)->toBeTrue();
    expect($attempts)->toBe(2);
});

it('applies timeout middleware to http request payload', function (): void {
    $timeoutSeen = null;

    $transport = new class($timeoutSeen) implements TransportInterface {
        public function __construct(private ?int &$timeoutSeen) {}

        public function send(CommunicationRequest $request): CommunicationResult
        {
            expect($request->payload)->toBeInstanceOf(HttpRequest::class);

            /** @var HttpRequest $httpRequest */
            $httpRequest = $request->payload;
            $this->timeoutSeen = $httpRequest->options->timeoutSeconds;

            return CommunicationResult::success(200);
        }
    };

    $pipeline = new MiddlewarePipeline($transport, [new TimeoutMiddleware(33)]);

    $pipeline->send(new CommunicationRequest('http', HttpRequest::get('https://example.com')));

    expect($timeoutSeen)->toBe(33);
});

it('applies timeout middleware to grpc request payload', function (): void {
    $deadlineSeen = null;

    $transport = new class($deadlineSeen) implements TransportInterface {
        public function __construct(private ?float &$deadlineSeen) {}

        public function send(CommunicationRequest $request): CommunicationResult
        {
            expect($request->payload)->toBeInstanceOf(GrpcRequest::class);

            /** @var GrpcRequest $grpcRequest */
            $grpcRequest = $request->payload;
            $this->deadlineSeen = $grpcRequest->deadlineSeconds;

            return CommunicationResult::success(200);
        }
    };

    $pipeline = new MiddlewarePipeline($transport, [new TimeoutMiddleware(12)]);

    $pipeline->send(new CommunicationRequest('grpc', new GrpcRequest('Svc/Call', ['ok' => true])));

    expect($deadlineSeen)->toBe(12.0);
});

it('applies header middleware to http request payload', function (): void {
    $headerValue = null;

    $transport = new class($headerValue) implements TransportInterface {
        public function __construct(private ?string &$headerValue) {}

        public function send(CommunicationRequest $request): CommunicationResult
        {
            expect($request->payload)->toBeInstanceOf(HttpRequest::class);

            /** @var HttpRequest $httpRequest */
            $httpRequest = $request->payload;
            $value = $httpRequest->headers->get('X-App');

            expect($value)->toBeString();
            $this->headerValue = $value;

            return CommunicationResult::success(200);
        }
    };

    $pipeline = new MiddlewarePipeline($transport, [new HeaderMiddleware(['X-App' => 'talkingbytes'])]);

    $pipeline->send(new CommunicationRequest('http', HttpRequest::get('https://example.com')));

    expect($headerValue)->toBe('talkingbytes');
});

it('rate limit middleware blocks excess requests', function (): void {
    $transport = new class implements TransportInterface {
        public function send(CommunicationRequest $request): CommunicationResult
        {
            unset($request);

            return CommunicationResult::success(200);
        }
    };

    $pipeline = new MiddlewarePipeline(
        $transport,
        [new RateLimitMiddleware(new RateLimiter(1, 60))],
    );

    $pipeline->send(new CommunicationRequest('test', null));

    expect(fn() => $pipeline->send(new CommunicationRequest('test', null)))
        ->toThrow(\RuntimeException::class, 'Rate limit exceeded.');
});

it('circuit breaker middleware opens after failures', function (): void {
    $transport = new class implements TransportInterface {
        public function send(CommunicationRequest $request): CommunicationResult
        {
            unset($request);

            return CommunicationResult::failure('downstream failed', 503);
        }
    };

    $pipeline = new MiddlewarePipeline(
        $transport,
        [new CircuitBreakerMiddleware(new CircuitBreaker(failureThreshold: 1, coolDownSeconds: 60))],
    );

    $first = $pipeline->send(new CommunicationRequest('test', null));

    expect($first->successful)->toBeFalse();
    expect(fn() => $pipeline->send(new CommunicationRequest('test', null)))
        ->toThrow(\RuntimeException::class, 'Circuit breaker is open.');
});
