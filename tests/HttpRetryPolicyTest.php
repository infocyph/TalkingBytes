<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Http\Retry\RetryAfter;
use Infocyph\TalkingBytes\Http\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Retry\RetryContext;

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

    $decision = $policy->decide(new RetryContext(1, $result));
    expect($decision->retry)->toBeTrue();
    expect($decision->delayMs)->toBe(7000);
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

    $cappedDecision = $policy->decide(new RetryContext(1, $capped));
    $fallbackDecision = $policy->decide(new RetryContext(2, $fallback));
    expect($cappedDecision->retry)->toBeTrue();
    expect($cappedDecision->delayMs)->toBe(3000);
    expect($fallbackDecision->retry)->toBeTrue();
    expect($fallbackDecision->delayMs)->toBe(400);
});

it('does not retry non-retryable statuses', function (): void {
    $policy = HttpRetryPolicy::standard();
    $result = CommunicationResult::failure('not found', statusCode: 404, response: new HttpResponse(404, ''));

    expect($policy->decide(new RetryContext(1, $result))->retry)->toBeFalse();
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
