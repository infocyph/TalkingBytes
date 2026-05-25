<?php

declare(strict_types=1);

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
