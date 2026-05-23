<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\CurlTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;

it('builds http request with query and auth', function (): void {
    $request = HttpRequest::post('https://example.com/orders')
        ->query('page', 2)
        ->json(['order_id' => 1001])
        ->withBearerToken('token123')
        ->header('Accept', 'application/json');

    $resolved = $request->applyAuthenticators();

    expect($resolved->buildUrl())->toBe('https://example.com/orders?page=2');
    expect($resolved->headers->get('Authorization'))->toBe('Bearer token123');
    expect($resolved->headers->get('Accept'))->toBe('application/json');
});

it('curl transport returns failure for invalid payload type', function (): void {
    $transport = new CurlTransport();

    $result = $transport->send(new Infocyph\TalkingBytes\Core\Message\CommunicationRequest('http', ['bad' => 'payload']));

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('expects HttpRequest payload');
});
