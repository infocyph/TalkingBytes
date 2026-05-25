<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Retry\GrpcRetryPolicy;

it('retries transient grpc statuses and eventually succeeds', function (): void {
    $attempts = 0;
    $client = GrpcClient::using(static function (GrpcRequest $request) use (&$attempts): GrpcResponse {
        $attempts++;
        if ($attempts < 3) {
            return new GrpcResponse(GrpcStatus::Unavailable, ['attempt' => $attempts]);
        }

        return new GrpcResponse(GrpcStatus::Ok, ['attempt' => $attempts, 'message' => $request->message]);
    })->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 0));

    $result = $client->send(new GrpcRequest('Orders/Create', ['id' => 1]));

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
            throw new RuntimeException('temporary network failure');
        }

        return new GrpcResponse(GrpcStatus::Ok, ['ok' => true]);
    })->withGrpcRetry(GrpcRetryPolicy::standard(attempts: 2, baseDelayMs: 0));

    $result = $client->send(new GrpcRequest('Orders/Create', ['id' => 1]));

    expect($result->successful)->toBeTrue()
        ->and($attempts)->toBe(2);
});

it('supports grpc retry delay cap and optional jitter', function (): void {
    $capped = GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100, maxDelayMs: 150);
    expect($capped->delayMs(1))->toBe(100)
        ->and($capped->delayMs(2))->toBe(150)
        ->and($capped->delayMs(3))->toBe(150);

    $jittered = GrpcRetryPolicy::standard(attempts: 3, baseDelayMs: 100, maxDelayMs: 150, jitterRatio: 0.25);
    $delay = $jittered->delayMs(1);
    expect($delay)->toBeGreaterThanOrEqual(75)->toBeLessThanOrEqual(125);
});
