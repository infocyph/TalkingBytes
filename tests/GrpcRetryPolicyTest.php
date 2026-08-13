<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcTransportException;
use Infocyph\TalkingBytes\Retry\RetryContext;

it('retries transient grpc statuses and eventually succeeds', function (): void {
    $attempts = 0;
    $client = GrpcClient::using(static function (GrpcRequest $request) use (&$attempts): GrpcResponse {
        $attempts++;
        if ($attempts < 3) {
            return new GrpcResponse(GrpcStatus::Unavailable, ['attempt' => $attempts]);
        }

        return new GrpcResponse(GrpcStatus::Ok, ['attempt' => $attempts, 'message' => $request->message]);
    })->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 0));

    $result = $client->send((new GrpcRequest('Orders/Create', ['id' => 1]))->withRetrySafety());

    expect($result->successful)->toBeTrue()
        ->and($attempts)->toBe(3);
});

it('does not retry non-transient grpc statuses', function (): void {
    $attempts = 0;
    $client = GrpcClient::using(static function () use (&$attempts): GrpcResponse {
        $attempts++;

        return new GrpcResponse(GrpcStatus::InvalidArgument, ['error' => 'bad']);
    })->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 0));

    $result = $client->send(new GrpcRequest('Orders/Create', ['id' => 1]));

    expect($result->successful)->toBeFalse()
        ->and($result->statusCode)->toBe(GrpcStatus::InvalidArgument->value)
        ->and($attempts)->toBe(1);
});

it('retries grpc transport failures produced by caller exceptions', function (): void {
    $attempts = 0;
    $client = GrpcClient::using(static function () use (&$attempts): GrpcResponse {
        $attempts++;
        if ($attempts < 2) {
            throw new GrpcTransportException('temporary network failure', retryable: true);
        }

        return new GrpcResponse(GrpcStatus::Ok, ['ok' => true]);
    })->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 2, baseDelayMs: 0));

    $result = $client->send((new GrpcRequest('Orders/Create', ['id' => 1]))->withRetrySafety());

    expect($result->successful)->toBeTrue()
        ->and($attempts)->toBe(2);
});

it('supports grpc retry delay cap and optional jitter', function (): void {
    $capped = GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100, maxDelayMs: 150);
    $failure = CommunicationResult::failure('unavailable', response: new GrpcResponse(GrpcStatus::Unavailable, null));
    expect($capped->decide(new RetryContext(1, $failure))->delayMs)->toBe(100)
        ->and($capped->decide(new RetryContext(2, $failure))->delayMs)->toBe(150)
        ->and($capped->decide(new RetryContext(3, $failure))->retry)->toBeFalse();

    $jittered = GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100, maxDelayMs: 150, jitterRatio: 0.25);
    $delay = $jittered->decide(new RetryContext(1, $failure))->delayMs;
    expect($delay)->toBeGreaterThanOrEqual(75)->toBeLessThanOrEqual(125);

    expect(fn() => GrpcRetryPolicy::standard(maxDelayMs: 86_400_001))
        ->toThrow(InvalidArgumentException::class, 'maxDelayMs');
});
