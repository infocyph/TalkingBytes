<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;

it('can fake http client requests and assert interactions', function (): void {
    $client = HttpClient::fake();

    $client->send(HttpRequest::get('https://api.example.test/users'));
    $client->postJson('https://api.example.test/orders', ['id' => 1001]);

    $assert = $client->assert();
    $assert->assertRequestCount(2);
    $assert->assertRequested('GET', 'https://api.example.test/users');
    $assert->assertRequestedWhere(
        static fn (HttpRequest $request): bool => $request->method->value === 'POST'
            && $request->headers->get('Accept') === 'application/json',
        'Expected a JSON POST request.',
    );
    expect($assert->lastRequest())->not->toBeNull();
});

it('supports queued fake responses', function (): void {
    $fake = new FakeHttpTransport;
    $fake->pushJson(['ok' => true], 200);
    $fake->pushJson(['error' => true], 500);

    $client = HttpClient::fake($fake);

    $success = $client->get('https://api.example.test/success');
    $failure = $client->get('https://api.example.test/fail');

    expect($success->successful)->toBeTrue();
    expect($success->response?->json())->toBe(['ok' => true]);
    expect($failure->successful)->toBeFalse();
    expect($failure->response?->statusCode)->toBe(500);
});

it('throws when assertions are requested for non-fake client', function (): void {
    $client = HttpClient::curl();

    expect(fn () => $client->assert())
        ->toThrow(RuntimeException::class, 'HttpClient assertions are only available for fake transports.');
});
