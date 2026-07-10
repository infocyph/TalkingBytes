<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Grpc\Testing\FakeGrpcCaller;

it('records grpc calls and supports assertions', function (): void {
    $fake = (new FakeGrpcCaller())
        ->pushOk(['first' => true])
        ->pushStatus(GrpcStatus::Unavailable, ['second' => true]);

    $client = GrpcClient::using($fake);

    $first = $client->send(new GrpcRequest('Billing/Create', ['id' => 1]));
    $second = $client->send(new GrpcRequest('Billing/Create', ['id' => 2]));

    $fake->assert()->assertCallCount(2);
    $fake->assert()->assertCalledMethod('Billing/Create');

    expect($first->successful)->toBeTrue()
        ->and($second->successful)->toBeFalse()
        ->and($second->statusCode)->toBe(GrpcStatus::Unavailable->value);
});

it('fails fake grpc caller when response queue is empty', function (): void {
    $client = GrpcClient::using(new FakeGrpcCaller());
    $result = $client->send(new GrpcRequest('Billing/Create', ['id' => 1]));

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('No fake gRPC response queued.');
});

it('supports richer grpc fake caller assertions', function (): void {
    $fake = (new FakeGrpcCaller())->pushOk(['ok' => true]);
    $client = GrpcClient::using($fake);

    $client->send(new GrpcRequest(
        'Billing/Create',
        ['id' => 7],
        headers: (new GrpcMetadata())->withValue('x-request-id', 'req-7'),
    ));

    $assert = $fake->assert();
    $assert->assertCalledWithMessage('Billing/Create', ['id' => 7]);
    $assert->assertCalledWithMetadata('Billing/Create', 'X-Request-Id', 'req-7');
    expect($assert->firstRequest()?->method)->toBe('Billing/Create')
        ->and($assert->lastRequest()?->method)->toBe('Billing/Create');
});
