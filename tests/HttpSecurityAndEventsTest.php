<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\CurlTransport;
use Infocyph\TalkingBytes\Http\HttpRedactor;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;

it('redacts sensitive http headers and query parameters', function (): void {
    $redactedHeaders = HttpRedactor::redactHeaders([
        'Authorization' => 'Bearer super-secret',
        'authorization' => 'Bearer lower',
        'X-API-Key' => 'top-secret',
        'X-Auth-Token' => 'secret-token',
        'Set-Cookie' => 'session=abc',
        'Accept' => 'application/json',
    ]);

    expect($redactedHeaders['Authorization'])->toBe('[REDACTED]');
    expect($redactedHeaders['authorization'])->toBe('[REDACTED]');
    expect($redactedHeaders['X-API-Key'])->toBe('[REDACTED]');
    expect($redactedHeaders['X-Auth-Token'])->toBe('[REDACTED]');
    expect($redactedHeaders['Set-Cookie'])->toBe('[REDACTED]');
    expect($redactedHeaders['Accept'])->toBe('application/json');

    $url = HttpRedactor::redactUrl('https://api.example.test/orders?api_key=abc&token=xyz&client_secret=s3cr3t&signature=sig&refresh_token=rt&page=2');
    expect($url)->toContain('api_key=%5BREDACTED%5D');
    expect($url)->toContain('token=%5BREDACTED%5D');
    expect($url)->toContain('client_secret=%5BREDACTED%5D');
    expect($url)->toContain('signature=%5BREDACTED%5D');
    expect($url)->toContain('refresh_token=%5BREDACTED%5D');
    expect($url)->toContain('page=2');
});

it('enforces host allow and block lists before sending request', function (): void {
    $transport = new CurlTransport;

    $blocked = $transport->sendRequest(
        HttpRequest::get('https://example.com')->blockHosts(['example.com']),
    );
    expect($blocked->successful)->toBeFalse();
    expect($blocked->error)->toContain('host is blocked');

    $notAllowed = $transport->sendRequest(
        HttpRequest::get('https://example.com')->allowHosts(['api.example.com']),
    );
    expect($notAllowed->successful)->toBeFalse();
    expect($notAllowed->error)->toContain('host is not allowed');
});

it('blocks private networks when configured', function (): void {
    $transport = new CurlTransport;

    $result = $transport->sendRequest(
        HttpRequest::get('http://127.0.0.1')->blockPrivateNetworks(),
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('private or reserved');
});

it('blocks additional reserved host ranges when private network blocking is enabled', function (): void {
    $reservedUrls = [
        'http://0.0.0.0',
        'http://10.10.10.10',
        'http://172.16.5.4',
        'http://192.168.1.5',
        'http://169.254.1.20',
        'http://[::1]',
        'http://[fc00::1]',
        'http://[fe80::1]',
    ];

    foreach ($reservedUrls as $url) {
        expect(fn () => RequestSecurityGuard::assertAllowed(HttpRequest::get($url)->blockPrivateNetworks()))
            ->toThrow(InvalidArgumentException::class, 'private or reserved');
    }
});

it('applies security guard checks to redirect destinations as well', function (): void {
    $request = HttpRequest::get('https://example.com/redirect')
        ->blockPrivateNetworks();

    expect(
        fn () => RequestSecurityGuard::assertAllowed($request, 'http://127.0.0.1/internal'),
    )->toThrow(InvalidArgumentException::class, 'private or reserved');
});

it('dispatches http pool lifecycle events', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'http.pool.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $pool = new CurlMultiTransport;
    $result = $pool->sendMany([], 5);

    CommunicationEventBus::listen(null);

    expect($result->results)->toBe([]);
    expect($events[0]['event'] ?? null)->toBe('http.pool.start');
    expect($events[1]['event'] ?? null)->toBe('http.pool.finish');
    expect($events[0]['payload']['max_concurrency'] ?? null)->toBe(5);
});

it('dispatches http request start and failure events for curl transport', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'http.request.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $request = HttpRequest::get('http://127.0.0.1:1?token=secret&client_secret=very-secret')
        ->headers([
            'Authorization' => 'Bearer abc',
            'set-cookie' => 'session=abc',
            'Accept' => 'application/json',
        ])
        ->timeout(1)
        ->connectTimeout(1);

    $result = (new CurlTransport)->sendRequest($request);
    CommunicationEventBus::listen(null);

    expect($result->successful)->toBeFalse();
    expect($events[0]['event'] ?? null)->toBe('http.request.start');
    expect($events[1]['event'] ?? null)->toBe('http.request.failed');
    expect($events[0]['payload']['url'] ?? null)->toContain('token=%5BREDACTED%5D');
    expect($events[0]['payload']['url'] ?? null)->toContain('client_secret=%5BREDACTED%5D');
    expect($events[0]['payload']['headers']['Authorization'] ?? null)->toBe('[REDACTED]');
    expect($events[0]['payload']['headers']['Set-Cookie'] ?? null)->toBe('[REDACTED]');
});
