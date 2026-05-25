<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Http\CurlOptions;
use Infocyph\TalkingBytes\Http\CurlTransport;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpClientConfig;
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

it('merges existing query string with request query params and normalizes booleans', function (): void {
    $request = HttpRequest::get('https://example.com/orders?active=1&sort=asc')
        ->queries([
            'active' => false,
            'page' => 2,
            'debug' => true,
        ])
        ->withoutQuery('sort');

    expect($request->buildUrl())->toBe('https://example.com/orders?active=0&page=2&debug=1');
});

it('builds http client from config defaults', function (): void {
    $client = HttpClient::fromConfig(HttpClientConfig::fromArray([
        'timeoutSeconds' => 15,
        'connectTimeoutSeconds' => 5,
        'followRedirects' => true,
        'maxRedirects' => 3,
        'defaultHeaders' => [
            'X-App' => 'TalkingBytes',
        ],
        'userAgent' => 'TalkingBytes/1.0',
    ]));

    $request = HttpRequest::get('https://example.com');
    $method = new ReflectionMethod($client, 'applyDefaults');
    /** @var HttpRequest $defaulted */
    $defaulted = $method->invoke($client, $request);

    expect($defaulted->options->timeoutSeconds)->toBe(15);
    expect($defaulted->options->connectTimeoutSeconds)->toBe(5);
    expect($defaulted->options->followRedirects)->toBeTrue();
    expect($defaulted->options->maxRedirects)->toBe(3);
    expect($defaulted->headers->get('X-App'))->toBe('TalkingBytes');
    expect($defaulted->options->userAgent)->toBe('TalkingBytes/1.0');
});

it('validates http client config values', function (): void {
    expect(fn () => HttpClientConfig::fromArray([
        'timeoutSeconds' => 0,
    ]))->toThrow(InvalidArgumentException::class, 'timeoutSeconds must be greater than 0');

    expect(fn () => HttpClientConfig::fromArray([
        'connectTimeoutSeconds' => -1,
    ]))->toThrow(InvalidArgumentException::class, 'connectTimeoutSeconds must be greater than 0');

    expect(fn () => HttpClientConfig::fromArray([
        'maxRedirects' => -1,
    ]))->toThrow(InvalidArgumentException::class, 'maxRedirects must be greater than or equal to 0');

    expect(fn () => HttpClientConfig::fromArray([
        'defaultHeaders' => ['Bad Header' => 'x'],
    ]))->toThrow(InvalidArgumentException::class, 'Invalid HTTP header name');
});

it('curl transport returns failure for invalid payload type', function (): void {
    $transport = new CurlTransport;

    $result = $transport->send(new CommunicationRequest('http', ['bad' => 'payload']));

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('expects HttpRequest payload');
});

it('keeps redirects disabled by default', function (): void {
    $request = HttpRequest::get('https://example.com');

    expect($request->options->followRedirects)->toBeFalse();
});

it('validates curl options upfront', function (): void {
    expect(fn () => new CurlOptions(timeoutSeconds: 0))
        ->toThrow(InvalidArgumentException::class, 'timeoutSeconds must be greater than 0');

    expect(fn () => new CurlOptions(connectTimeoutSeconds: 0))
        ->toThrow(InvalidArgumentException::class, 'connectTimeoutSeconds must be greater than 0');

    expect(fn () => new CurlOptions(maxRedirects: -1))
        ->toThrow(InvalidArgumentException::class, 'maxRedirects must be greater than or equal to 0');

    expect(fn () => new CurlOptions(maxResponseBytes: 0))
        ->toThrow(InvalidArgumentException::class, 'maxResponseBytes must be greater than 0');

    expect(fn () => new CurlOptions(proxy: ''))
        ->toThrow(InvalidArgumentException::class, 'proxy must not be empty');

    expect(fn () => new CurlOptions(proxy: '127.0.0.1:8080'))
        ->toThrow(InvalidArgumentException::class, 'proxy must include a scheme');

    expect(fn () => new CurlOptions(caBundle: '/path/that/does/not/exist.pem'))
        ->toThrow(InvalidArgumentException::class, 'caBundle must point to a readable file');

    expect(fn () => new CurlOptions(clientCertificate: '/missing-cert.pem'))
        ->toThrow(InvalidArgumentException::class, 'clientCertificate must point to a readable file');
});
