<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\Cookie\Cookie;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;

it('stores set-cookie headers and applies cookies to next matching request', function (): void {
    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, [
            'Set-Cookie' => [
                'session=abc123; Path=/; HttpOnly',
                'theme=dark; Path=/',
            ],
        ])
        ->pushJson(['ok' => true], 200);

    $client = HttpClient::fake($transport)->withCookieJar(new CookieJar);

    $client->get('https://api.example.test/login');
    $client->get('https://api.example.test/orders');

    $sent = $transport->sentRequests();

    expect($sent)->toHaveCount(2);
    expect((string) $sent[1]->headers->get('Cookie'))->toContain('session=abc123');
    expect((string) $sent[1]->headers->get('Cookie'))->toContain('theme=dark');
});

it('respects secure and path cookie constraints', function (): void {
    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, [
            'Set-Cookie' => [
                'secure_cookie=1; Path=/secure; Secure',
                'public_cookie=1; Path=/',
            ],
        ])
        ->pushJson(['ok' => true], 200)
        ->pushJson(['ok' => true], 200);

    $client = HttpClient::fake($transport)->withCookieJar(new CookieJar);

    $client->get('https://api.example.test/secure/login');
    $client->get('http://api.example.test/secure/orders');
    $client->get('https://api.example.test/public');

    $sent = $transport->sentRequests();

    expect((string) $sent[1]->headers->get('Cookie'))->toContain('public_cookie=1');
    expect((string) $sent[1]->headers->get('Cookie'))->not->toContain('secure_cookie=1');

    expect((string) $sent[2]->headers->get('Cookie'))->toContain('public_cookie=1');
    expect((string) $sent[2]->headers->get('Cookie'))->not->toContain('secure_cookie=1');
});

it('does not override explicit request cookies', function (): void {
    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, [
            'Set-Cookie' => 'session=jar-value; Path=/',
        ])
        ->pushJson(['ok' => true], 200);

    $client = HttpClient::fake($transport)->withCookieJar(new CookieJar);

    $client->get('https://api.example.test/login');
    $client->send(
        HttpRequest::get('https://api.example.test/orders')
            ->header('Cookie', 'session=explicit-value'),
    );

    $sent = $transport->sentRequests();

    expect((string) $sent[1]->headers->get('Cookie'))->toContain('session=explicit-value');
    expect((string) $sent[1]->headers->get('Cookie'))->not->toContain('session=jar-value');
});

it('rejects cookies scoped outside the response origin', function (): void {
    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, [
            'Set-Cookie' => [
                'session=leaked; Domain=attacker.test; Path=/',
                'wide=leaked; Domain=test; Path=/',
            ],
        ])
        ->pushJson(['ok' => true], 200);

    $client = HttpClient::fake($transport)->withCookieJar(new CookieJar);

    $client->get('https://api.example.test/login');
    $client->get('https://attacker.test/orders');

    expect($transport->sentRequests()[1]->headers->get('Cookie'))->toBeNull();
});

it('accepts parent-domain cookies and applies rfc path boundaries', function (): void {
    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, [
            'Set-Cookie' => 'session=shared; Domain=example.test; Path=/api',
        ])
        ->pushJson(['ok' => true], 200)
        ->pushJson(['ok' => true], 200);

    $client = HttpClient::fake($transport)->withCookieJar(new CookieJar(allowDomainCookies: true));

    $client->get('https://api.example.test/api/login');
    $client->get('https://www.example.test/apix');
    $client->get('https://www.example.test/api/orders');

    $sent = $transport->sentRequests();

    expect($sent[1]->headers->get('Cookie'))->toBeNull();
    expect((string) $sent[2]->headers->get('Cookie'))->toContain('session=shared');
});

it('bounds cookie storage and ignores malformed response cookies', function (): void {
    $jar = new CookieJar(maxCookies: 1);
    $jar->remember(new Cookie('first', '1', 'example.test'));
    $jar->remember(new Cookie('second', '2', 'example.test'));

    $transport = (new FakeHttpTransport)
        ->pushJson(['ok' => true], 200, ['Set-Cookie' => 'bad name=value; Path=/']);
    HttpClient::fake($transport)
        ->withCookieJar($jar)
        ->get('https://example.test/');

    expect($jar->count())->toBe(1);
    expect($jar->all())->toHaveKey('example.test|/|first');
    expect(fn() => new CookieJar(maxCookies: 0))->toThrow(InvalidArgumentException::class);
});

it('validates cookie names and values at construction', function (): void {
    expect(fn() => new Cookie('bad name', 'value', 'example.test'))
        ->toThrow(InvalidArgumentException::class, 'Cookie name contains invalid characters.');
    expect(fn() => new Cookie('session', "value; injected=1", 'example.test'))
        ->toThrow(InvalidArgumentException::class, 'Cookie value contains invalid characters.');
});
