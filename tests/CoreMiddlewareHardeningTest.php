<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpPipeline;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Middleware\CircuitBreakerMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\IdempotencyMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\LoggingMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\RateLimitMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\TimeoutMiddleware;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

function recordingHttpTransport(Closure $send): HttpTransport
{
    return new class($send) implements HttpTransport {
        public function __construct(private Closure $send) {}
        public function send(HttpRequest $request): CommunicationResult
        {
            return ($this->send)($request);
        }
    };
}

it('retries safe HTTP requests when policy allows retry', function (): void {
    $attempts = 0;
    $transport = recordingHttpTransport(static function () use (&$attempts): CommunicationResult {
        $attempts++;

        return $attempts === 1
            ? CommunicationResult::failure('temporary', 503)
            : CommunicationResult::success(statusCode: 200);
    });

    $result = (new HttpPipeline($transport, [new RetryMiddleware(new FixedDelayRetryPolicy(2, 0))]))
        ->send(HttpRequest::get('https://example.com'));

    expect($result->successful)->toBeTrue()->and($attempts)->toBe(2);
});

it('applies HTTP timeout and one stable idempotency key outside retry', function (): void {
    $seen = [];
    $transport = recordingHttpTransport(static function (HttpRequest $request) use (&$seen): CommunicationResult {
        $seen[] = [$request->options->timeoutSeconds, $request->headers->get('Idempotency-Key')];

        return count($seen) === 1
            ? CommunicationResult::failure('temporary', 503)
            : CommunicationResult::success(statusCode: 200);
    });
    $pipeline = new HttpPipeline($transport, [
        new RetryMiddleware(new FixedDelayRetryPolicy(2, 0)),
        new IdempotencyMiddleware(),
        new TimeoutMiddleware(33),
    ]);

    $pipeline->send(HttpRequest::post('https://example.com')->json(['ok' => true]));

    expect($seen)->toHaveCount(2)
        ->and($seen[0][0])->toBe(33)
        ->and($seen[0][1])->toMatch('/^[a-f0-9]{32}$/')
        ->and($seen[1][1])->toBe($seen[0][1]);
});

it('blocks excess HTTP requests with the token bucket', function (): void {
    $pipeline = new HttpPipeline(
        recordingHttpTransport(static fn(): CommunicationResult => CommunicationResult::success()),
        [new RateLimitMiddleware(new RateLimiter(1, 60))],
    );
    $pipeline->send(HttpRequest::get('https://example.com'));

    expect(fn() => $pipeline->send(HttpRequest::get('https://example.com')))
        ->toThrow(RuntimeException::class, 'Rate limit exceeded.');
});

it('does not over-refill the token bucket after a backward clock reading', function (): void {
    $times = [100.0, 90.0, 150.0];
    $clock = new Clock(
        static fn(): float => 0.0,
        static function () use (&$times): float {
            return array_shift($times) ?? 150.0;
        },
    );
    $limiter = new RateLimiter(1, 60, $clock);

    $limiter->assertCanProceed();

    expect(fn() => $limiter->assertCanProceed())
        ->toThrow(RuntimeException::class, 'Rate limit exceeded.');
});

it('opens an HTTP circuit after the configured failure threshold', function (): void {
    $pipeline = new HttpPipeline(
        recordingHttpTransport(static fn(): CommunicationResult => CommunicationResult::failure('down', 503)),
        [new CircuitBreakerMiddleware(new CircuitBreaker(1, 60))],
    );
    expect($pipeline->send(HttpRequest::get('https://example.com'))->successful)->toBeFalse();
    expect(fn() => $pipeline->send(HttpRequest::get('https://example.com')))
        ->toThrow(RuntimeException::class, 'Circuit breaker is open.');
});

it('keeps logging best effort and records transport exceptions', function (): void {
    $events = [];
    $pipeline = new HttpPipeline(
        recordingHttpTransport(static function (): never { throw new RuntimeException('boom'); }),
        [new LoggingMiddleware(static function (string $event, array $context) use (&$events): void {
            $events[] = [$event, $context];
        })],
    );

    expect(fn() => $pipeline->send(HttpRequest::get('https://example.com')))
        ->toThrow(RuntimeException::class, 'boom');
    expect($events)->toHaveCount(2)
        ->and($events[0][0])->toBe('http.request.start')
        ->and($events[1][1]['successful'])->toBeFalse();
});
