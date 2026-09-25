<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\RequestSecurityGuard;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;

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
    $transport = new CurlTransport();

    $blocked = $transport->send(
        HttpRequest::get('https://example.com')->blockHosts(['example.com']),
    );
    expect($blocked->successful)->toBeFalse();
    expect($blocked->error)->toContain('host is blocked');

    $notAllowed = $transport->send(
        HttpRequest::get('https://example.com')->allowHosts(['api.example.com']),
    );
    expect($notAllowed->successful)->toBeFalse();
    expect($notAllowed->error)->toContain('host is not allowed');
});

it('blocks private networks when configured', function (): void {
    $transport = new CurlTransport();

    $result = $transport->send(
        HttpRequest::get('http://127.0.0.1')->blockPrivateNetworks(),
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('private or reserved');
});

it('rejects surrounding URL whitespace before security inspection', function (): void {
    expect(fn() => HttpRequest::get(' http://127.0.0.1'))
        ->toThrow(InvalidArgumentException::class, 'surrounding whitespace');
});

it('canonicalizes a trailing root label in host block lists', function (): void {
    expect(fn() => RequestSecurityGuard::assertAllowed(
        HttpRequest::get('https://example.com./')->blockHosts(['example.com']),
    ))->toThrow(InvalidArgumentException::class, 'host is blocked');
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
        'http://[::ffff:127.0.0.1]',
    ];

    foreach ($reservedUrls as $url) {
        expect(fn() => RequestSecurityGuard::assertAllowed(HttpRequest::get($url)->blockPrivateNetworks()))
            ->toThrow(InvalidArgumentException::class, 'private or reserved');
    }
});

it('applies security guard checks to redirect destinations as well', function (): void {
    $request = HttpRequest::get('https://example.com/redirect')
        ->blockPrivateNetworks();

    expect(
        fn() => RequestSecurityGuard::assertAllowed($request, 'http://127.0.0.1/internal'),
    )->toThrow(InvalidArgumentException::class, 'private or reserved');
});

it('dispatches http pool lifecycle events', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'http.pool.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $pool = new CurlMultiTransport(events: $dispatcher);
    $result = $pool->sendMany([], 5);

    expect($result->results)->toBe([]);
    expect($events[0]['event'] ?? null)->toBe('http.pool.start');
    expect($events[1]['event'] ?? null)->toBe('http.pool.finish');
    expect($events[0]['payload']['max_concurrency'] ?? null)->toBe(5);
});

it('dispatches http request start and failure events for curl transport', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
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

    $result = (new CurlTransport($dispatcher))->send($request);

    expect($result->successful)->toBeFalse();
    expect($events[0]['event'] ?? null)->toBe('http.request.start');
    expect($events[1]['event'] ?? null)->toBe('http.request.failed');
    expect($events[0]['payload']['url'] ?? null)->toContain('token=%5BREDACTED%5D');
    expect($events[0]['payload']['url'] ?? null)->toContain('client_secret=%5BREDACTED%5D');
    expect($events[0]['payload']['headers']['Authorization'] ?? null)->toBe('[REDACTED]');
    expect($events[0]['payload']['headers']['Set-Cookie'] ?? null)->toBe('[REDACTED]');
});


it('tracks native and custom authentication fields for redirects and observability', function (): void {
    $prepared = HttpRequest::get('https://api.example.test/orders')
        ->withApiKeyHeader('X-Vendor-Credential', 'sentinel-header-secret')
        ->withApiKeyQuery('vendor_credential', 'sentinel-query-secret')
        ->prepareForTransport();

    expect(HttpRedactor::redactHeaders(
        $prepared->headers->all(),
        $prepared->sensitiveHeaderNames(),
    )['X-Vendor-Credential'] ?? null)->toBe('[REDACTED]');
    expect(HttpRedactor::redactUrl(
        $prepared->buildUrl(),
        $prepared->sensitiveQueryNames(),
    ))->not->toContain('sentinel-query-secret');

    $sameOrigin = $prepared->redirectedTo('https://api.example.test/next', 307, true)->prepareForTransport();
    expect($sameOrigin->headers->get('X-Vendor-Credential'))->toBe('sentinel-header-secret');

    $crossOrigin = $prepared->redirectedTo('https://other.example.test/next', 307, false)->prepareForTransport();
    expect($crossOrigin->headers->get('X-Vendor-Credential'))->toBeNull();
    expect($crossOrigin->authenticators)->toBe([]);
});

it('lets custom authenticators mark nonstandard credentials as sensitive', function (): void {
    $authenticator = new class implements AuthenticatorInterface
    {
        public function apply(HttpRequest $request): HttpRequest
        {
            return $request
                ->markSensitiveHeader('X-Custom-Credential')
                ->markSensitiveQuery('custom_credential')
                ->header('X-Custom-Credential', 'sentinel-custom-header')
                ->query('custom_credential', 'sentinel-custom-query');
        }
    };

    $prepared = HttpRequest::get('https://example.test')
        ->withAuthenticator($authenticator)
        ->prepareForTransport();

    expect(HttpRedactor::redactHeaders(
        $prepared->headers->all(),
        $prepared->sensitiveHeaderNames(),
    )['X-Custom-Credential'] ?? null)->toBe('[REDACTED]');
    expect(HttpRedactor::redactUrl(
        $prepared->buildUrl(),
        $prepared->sensitiveQueryNames(),
    ))->not->toContain('sentinel-custom-query');
    expect($prepared->redirectedTo('https://different.test/', 302, false)->headers->get('X-Custom-Credential'))->toBeNull();
});

it('redacts nested and dynamically named query credentials', function (): void {
    $url = HttpRedactor::redactUrl(
        'https://example.test/path?auth%5Btoken%5D=sentinel-nested&vendor_key=sentinel-vendor&page=2',
        ['vendor_key'],
    );

    expect($url)->not->toContain('sentinel-nested');
    expect($url)->not->toContain('sentinel-vendor');
    expect($url)->toContain('auth%5Btoken%5D=%5BREDACTED%5D');
    expect($url)->toContain('vendor_key=%5BREDACTED%5D');
    expect($url)->toContain('page=2');
});

it('rejects explicit proxies with strict private-network protection', function (): void {
    expect(fn() => RequestSecurityGuard::assertAllowed(
        HttpRequest::get('https://8.8.8.8')->proxy('http://127.0.0.1:8080')->blockPrivateNetworks(),
    ))->toThrow(InvalidArgumentException::class, 'cannot be combined with a remote proxy');
});


it('keeps accepted sensitive header names trackable at the configured boundary', function (): void {
    $acceptedName = str_repeat('A', 256);
    $secret = 'sentinel-boundary-secret';
    $prepared = HttpRequest::get('https://api.example.test/orders')
        ->withApiKeyHeader($acceptedName, $secret)
        ->prepareForTransport();

    expect($prepared->sensitiveHeaderNames())->toContain(strtolower($acceptedName));
    expect(HttpRedactor::redactHeaders(
        $prepared->headers->all(),
        $prepared->sensitiveHeaderNames(),
    )[$acceptedName] ?? null)->toBe('[REDACTED]');

    $crossOrigin = $prepared->redirectedTo('https://other.example.test/orders', 307, false);
    expect($crossOrigin->headers->get($acceptedName))->toBeNull();

    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if ($event === 'http.request.start') {
            $events[] = $payload;
        }
    });
    (new CurlTransport($dispatcher))->send(
        HttpRequest::get('http://127.0.0.1:1')
            ->withApiKeyHeader($acceptedName, $secret)
            ->connectTimeout(1)
            ->timeout(1),
    );
    expect($events[0]['headers'][$acceptedName] ?? null)->toBe('[REDACTED]');

    $rejectedName = str_repeat('B', 257);
    expect(fn() => HttpRequest::get('https://api.example.test/orders')
        ->withApiKeyHeader($rejectedName, $secret)
        ->prepareForTransport())
        ->toThrow(InvalidArgumentException::class, 'cannot exceed 256 bytes');
});
