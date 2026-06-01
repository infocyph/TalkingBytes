<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlHandleConfigurator;
use Infocyph\TalkingBytes\Http\Internal\ResponseBodyCollector;
use Infocyph\TalkingBytes\Http\Internal\ResponseHeaderCollector;

it('streams download chunks to a temp file and finalizes atomically', function (): void {
    $target = sys_get_temp_dir() . '/tb-http-stream-' . bin2hex(random_bytes(6)) . '.txt';
    $request = HttpRequest::get('https://example.com')->streamDownloadTo($target);
    $collector = new ResponseBodyCollector($request);

    expect($collector->collect('hello '))->toBe(6);
    expect($collector->collect('world'))->toBe(5);
    expect($collector->finalize())->toBeNull();
    expect($collector->responseBody())->toBe('');
    expect(file_get_contents($target))->toBe('hello world');

    if (is_file($target)) {
        unlink($target);
    }
});

it('enforces max download bytes during streamed download collection', function (): void {
    $target = sys_get_temp_dir() . '/tb-http-stream-' . bin2hex(random_bytes(6)) . '.txt';
    $request = HttpRequest::get('https://example.com')
        ->streamDownloadTo($target)
        ->maxDownloadBytes(3);
    $collector = new ResponseBodyCollector($request);

    expect($collector->collect('abcd'))->toBe(0);
    expect($collector->error())->toContain('max allowed bytes');
    expect($collector->finalize())->toContain('max allowed bytes');
    expect(is_file($target))->toBeFalse();
});

it('allows streamed download when size is exactly the configured max', function (): void {
    $target = sys_get_temp_dir() . '/tb-http-stream-' . bin2hex(random_bytes(6)) . '.txt';
    $request = HttpRequest::get('https://example.com')
        ->streamDownloadTo($target)
        ->maxDownloadBytes(11);
    $collector = new ResponseBodyCollector($request);

    expect($collector->collect('hello world'))->toBe(11);
    expect($collector->finalize())->toBeNull();
    expect(file_get_contents($target))->toBe('hello world');

    if (is_file($target)) {
        unlink($target);
    }
});

it('enforces max response bytes for non-streaming collection', function (): void {
    $request = HttpRequest::get('https://example.com')->maxResponseBytes(5);
    $collector = new ResponseBodyCollector($request);

    expect($collector->collect('hello'))->toBe(5);
    expect($collector->collect('!'))->toBe(0);
    expect($collector->error())->toContain('max allowed bytes (5)');
});

it('configures upload from file and stream sources', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-upload-');
    expect($path)->toBeString();
    file_put_contents($path, 'payload');

    $handle = curl_init();
    expect($handle)->toBeInstanceOf(CurlHandle::class);

    $request = HttpRequest::put('https://example.com/upload')->uploadFromFile($path);
    $resolved = (new CurlHandleConfigurator())->configure(
        $handle,
        $request,
        new ResponseHeaderCollector(),
        new ResponseBodyCollector($request),
    );

    expect($resolved->metadata['_upload_opened_by_configurator'] ?? null)->toBeTrue();
    expect(is_resource($resolved->metadata['_upload_handle'] ?? null))->toBeTrue();

    if (is_resource($resolved->metadata['_upload_handle'])) {
        fclose($resolved->metadata['_upload_handle']);
    }
    unset($handle);
    if (is_file($path)) {
        unlink($path);
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream-payload');
    fseek($stream, 5);

    $streamHandle = curl_init();
    expect($streamHandle)->toBeInstanceOf(CurlHandle::class);

    $streamRequest = HttpRequest::put('https://example.com/upload')->uploadFromStream($stream, 14);
    $resolvedStream = (new CurlHandleConfigurator())->configure(
        $streamHandle,
        $streamRequest,
        new ResponseHeaderCollector(),
        new ResponseBodyCollector($streamRequest),
    );

    expect($resolvedStream->metadata['_upload_opened_by_configurator'] ?? null)->toBeFalse();
    expect($resolvedStream->metadata['_upload_handle'] ?? null)->toBe($stream);
    expect(ftell($stream))->toBe(0);

    unset($streamHandle);
    fclose($stream);
});

it('rejects combining upload source with regular request body', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-upload-');
    expect($path)->toBeString();
    file_put_contents($path, 'payload');

    $request = HttpRequest::post('https://example.com/upload')
        ->uploadFromFile($path)
        ->raw('body');

    $handle = curl_init();
    expect($handle)->toBeInstanceOf(CurlHandle::class);

    expect(
        fn(): HttpRequest => (new CurlHandleConfigurator())->configure(
            $handle,
            $request,
            new ResponseHeaderCollector(),
            new ResponseBodyCollector($request),
        ),
    )->toThrow(InvalidArgumentException::class, 'cannot combine uploadFromFile/uploadFromStream');

    unset($handle);
    if (is_file($path)) {
        unlink($path);
    }
});

it('enforces max upload bytes for file and stream uploads', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-upload-');
    expect($path)->toBeString();
    file_put_contents($path, str_repeat('a', 8));

    $handle = curl_init();
    expect($handle)->toBeInstanceOf(CurlHandle::class);

    $request = HttpRequest::put('https://example.com/upload')
        ->uploadFromFile($path)
        ->maxUploadBytes(4);

    expect(
        fn(): HttpRequest => (new CurlHandleConfigurator())->configure(
            $handle,
            $request,
            new ResponseHeaderCollector(),
            new ResponseBodyCollector($request),
        ),
    )->toThrow(InvalidArgumentException::class, 'max upload bytes');

    unset($handle);
    if (is_file($path)) {
        unlink($path);
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream-data');
    rewind($stream);

    $streamHandle = curl_init();
    expect($streamHandle)->toBeInstanceOf(CurlHandle::class);

    $streamRequest = HttpRequest::put('https://example.com/upload')
        ->uploadFromStream($stream, 10)
        ->maxUploadBytes(4);

    expect(
        fn(): HttpRequest => (new CurlHandleConfigurator())->configure(
            $streamHandle,
            $streamRequest,
            new ResponseHeaderCollector(),
            new ResponseBodyCollector($streamRequest),
        ),
    )->toThrow(InvalidArgumentException::class, 'max upload bytes');

    unset($streamHandle);
    fclose($stream);
});
