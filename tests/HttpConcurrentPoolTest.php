<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Concurrent\CurlMultiTransport;
use Infocyph\TalkingBytes\Http\Concurrent\PoolResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;

it('preserves request keys in concurrent pool results', function (): void {
    $requests = [
        'users' => HttpRequest::get('https://example.com/users')->blockHosts(['example.com']),
        'orders' => HttpRequest::get('https://example.com/orders')->blockHosts(['example.com']),
    ];

    $pool = (new CurlMultiTransport())->sendMany($requests, maxConcurrency: 10, stopOnFailure: false);

    expect(array_keys($pool->all()))->toBe(['users', 'orders']);
    expect($pool->get('users'))->toBeInstanceOf(CommunicationResult::class);
    expect($pool->get('orders'))->toBeInstanceOf(CommunicationResult::class);
});

it('supports pool result helper methods', function (): void {
    $pool = new PoolResult([
        'ok' => CommunicationResult::success(statusCode: 200),
        'bad' => CommunicationResult::failure('boom', statusCode: 500),
    ]);

    expect($pool->successfulCount())->toBe(1);
    expect($pool->failedCount())->toBe(1);
    expect(array_keys($pool->successful()))->toBe(['ok']);
    expect(array_keys($pool->failed()))->toBe(['bad']);
    expect($pool->firstError()?->error)->toBe('boom');
    expect($pool->get('missing'))->toBeNull();
});

it('stops scheduling new work after a failure when enabled', function (): void {
    $poolClient = HttpClient::multi(maxConcurrency: 1)->stopSchedulingOnFailure();
    $requests = [
        'first' => HttpRequest::get('https://example.com/first')->blockHosts(['example.com']),
        'second' => HttpRequest::get('https://example.com/second')->blockHosts(['example.com']),
        'third' => HttpRequest::get('https://example.com/third')->blockHosts(['example.com']),
    ];

    $result = $poolClient->sendMany($requests);

    expect(array_keys($result->all()))->toBe(['first']);
    expect($result->metadata['stopped_scheduling'] ?? null)->toBeTrue();
});

it('uses the same request configuration path in single and multi transports', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-upload-');
    expect($path)->toBeString();
    file_put_contents($path, 'payload');

    $request = HttpRequest::post('https://example.com/upload')
        ->uploadFromFile($path)
        ->raw('body');

    $singleResult = (new CurlTransport())->send($request);
    $multiResult = (new CurlMultiTransport())->sendMany(['x' => $request])->get('x');

    if (is_file($path)) {
        unlink($path);
    }

    expect($singleResult->successful)->toBeFalse();
    expect($multiResult)->toBeInstanceOf(CommunicationResult::class);
    expect($multiResult?->successful)->toBeFalse();
    expect($singleResult->error)->toContain('cannot combine uploadFromFile/uploadFromStream');
    expect($multiResult?->error)->toContain('cannot combine uploadFromFile/uploadFromStream');
});
