<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Testing\SequenceHttpTransport;
use Infocyph\TalkingBytes\Http\Testing\SpyHttpTransport;

it('supports sequence http transport queued responses', function (): void {
    $transport = new SequenceHttpTransport([
        CommunicationResult::success(200, new HttpResponse(200, '{"ok":1}')),
        CommunicationResult::failure('HTTP request failed with status code 500.', 500, new HttpResponse(500, '{"ok":0}')),
    ]);

    $client = HttpClient::using($transport);

    $first = $client->get('https://api.example.test/one');
    $second = $client->get('https://api.example.test/two');

    expect($first->successful)->toBeTrue();
    expect($second->successful)->toBeFalse();
    expect($transport->sentRequests())->toHaveCount(2);
});

it('supports spy http transport request recording with delegated send', function (): void {
    $sequence = new SequenceHttpTransport([
        CommunicationResult::success(200, new HttpResponse(200, '{"ok":true}')),
    ]);
    $spy = new SpyHttpTransport($sequence);

    $client = HttpClient::using($spy);
    $result = $client->postRaw('https://api.example.test/logs', 'hello', 'text/plain');

    expect($result->successful)->toBeTrue();
    expect($spy->sentRequests())->toHaveCount(1);
    expect($spy->sentRequests()[0]->buildUrl())->toBe('https://api.example.test/logs');
});
