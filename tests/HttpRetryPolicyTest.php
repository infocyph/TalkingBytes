<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Http\Retry\RetryAfter;

it('parses retry-after seconds and http date values', function (): void {
    expect(RetryAfter::parseDelaySeconds('5'))->toBe(5);
    expect(RetryAfter::parseDelaySeconds('Wed, 21 Oct 2015 07:28:00 GMT', new DateTimeImmutable('Wed, 21 Oct 2015 07:27:55 GMT')))
        ->toBe(5);
});

it('uses retry-after header delay for retryable statuses', function (): void {
    $policy = HttpRetryPolicy::standard(attempts: 3, baseDelayMs: 100, maxRetryAfterSeconds: 10);
    $result = CommunicationResult::failure(
        'too many requests',
        statusCode: 429,
        response: new HttpResponse(429, '', ['Retry-After' => '7']),
    );

    expect($policy->shouldRetry(1, $result))->toBeTrue();
    expect($policy->delayMs(1))->toBe(7000);
});

it('caps retry-after delay and falls back to exponential backoff when invalid', function (): void {
    $policy = HttpRetryPolicy::standard(attempts: 3, baseDelayMs: 200, maxRetryAfterSeconds: 3);
    $capped = CommunicationResult::failure(
        'service unavailable',
        statusCode: 503,
        response: new HttpResponse(503, '', ['Retry-After' => '99']),
    );
    $fallback = CommunicationResult::failure(
        'service unavailable',
        statusCode: 503,
        response: new HttpResponse(503, '', ['Retry-After' => 'not-a-value']),
    );

    expect($policy->shouldRetry(1, $capped))->toBeTrue();
    expect($policy->delayMs(1))->toBe(3000);
    expect($policy->shouldRetry(2, $fallback))->toBeTrue();
    expect($policy->delayMs(2))->toBe(400);
});

it('does not retry non-retryable statuses', function (): void {
    $policy = HttpRetryPolicy::standard();
    $result = CommunicationResult::failure('not found', statusCode: 404, response: new HttpResponse(404, ''));

    expect($policy->shouldRetry(1, $result))->toBeFalse();
});

it('adds http retry middleware helper to client defaults', function (): void {
    $client = HttpClient::curl()->withHttpRetry();
    $reflection = new ReflectionClass($client);
    $middlewares = $reflection->getProperty('middlewares');

    /** @var list<object> $resolved */
    $resolved = $middlewares->getValue($client);
    expect($resolved)->toHaveCount(1);
    expect($resolved[0])->toBeInstanceOf(RetryMiddleware::class);
});
