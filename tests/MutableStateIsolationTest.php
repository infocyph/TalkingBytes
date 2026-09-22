<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\Cookie\Cookie;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\CircuitState;
use Infocyph\TalkingBytes\Resilience\RateLimiter;

it('keeps fluent HTTP client derivation immutable', function (): void {
    $transport = new FakeHttpTransport();
    $base = HttpClient::fake($transport);
    $derived = $base
        ->withDefaultHeaders(['X-Profile' => 'derived'])
        ->withBearerToken('derived-token');

    $base->get('https://example.test/base');
    $derived->get('https://example.test/derived');

    $requests = $transport->sentRequests();

    expect($requests)->toHaveCount(2)
        ->and($requests[0]->headers->get('X-Profile'))->toBeNull()
        ->and($requests[0]->headers->get('Authorization'))->toBeNull()
        ->and($requests[1]->headers->get('X-Profile'))->toBe('derived')
        ->and($requests[1]->headers->get('Authorization'))->toBe('Bearer derived-token');
});

it('keeps cookie jars isolated by instance', function (): void {
    $first = new CookieJar();
    $second = new CookieJar();

    $first->remember(new Cookie('session', 'first', 'example.test'));

    expect($first->count())->toBe(1)
        ->and($second->count())->toBe(0)
        ->and($second->all())->toBe([]);
});

it('keeps circuit breaker state isolated by instance', function (): void {
    $first = new CircuitBreaker(failureThreshold: 1);
    $second = new CircuitBreaker(failureThreshold: 1);

    $first->onFailure();

    expect($first->state())->toBe(CircuitState::Open)
        ->and($second->state())->toBe(CircuitState::Closed);
});

it('keeps rate limiter token state isolated by instance', function (): void {
    $first = new RateLimiter(1, 60);
    $second = new RateLimiter(1, 60);

    $first->assertCanProceed();

    expect(static fn() => $first->assertCanProceed())
        ->toThrow(RuntimeException::class, 'Rate limit exceeded.');

    $second->assertCanProceed();
    expect(true)->toBeTrue();
});

it('keeps cookie sessions isolated when persistent-runtime fibers interleave', function (): void {
    $transportA = (new FakeHttpTransport())
        ->pushJson(['ok' => true], 200, ['Set-Cookie' => 'session=a; Path=/'])
        ->pushJson(['ok' => true], 200);
    $transportB = (new FakeHttpTransport())
        ->pushJson(['ok' => true], 200, ['Set-Cookie' => 'session=b; Path=/'])
        ->pushJson(['ok' => true], 200);

    $clientA = HttpClient::fake($transportA)->withCookieJar(new CookieJar());
    $clientB = HttpClient::fake($transportB)->withCookieJar(new CookieJar());

    $fiberA = new Fiber(static function () use ($clientA): void {
        $clientA->get('https://a.example.test/login');
        Fiber::suspend();
        $clientA->get('https://a.example.test/orders');
    });

    $fiberA->start();
    $clientB->get('https://b.example.test/login');
    $clientB->get('https://b.example.test/orders');
    $fiberA->resume();

    $requestsA = $transportA->sentRequests();
    $requestsB = $transportB->sentRequests();

    expect((string) $requestsA[1]->headers->get('Cookie'))->toContain('session=a')
        ->and((string) $requestsA[1]->headers->get('Cookie'))->not->toContain('session=b')
        ->and((string) $requestsB[1]->headers->get('Cookie'))->toContain('session=b')
        ->and((string) $requestsB[1]->headers->get('Cookie'))->not->toContain('session=a')
        ->and($fiberA->isTerminated())->toBeTrue();
});
