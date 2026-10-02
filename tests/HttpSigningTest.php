<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\UploadHandleManager;
use Infocyph\TalkingBytes\Http\Signing\HmacSha256Signer;

it('applies deterministic signed request headers', function (): void {
    $signer = new HmacSha256Signer('secret-key');
    $request = HttpRequest::post('https://api.example.com/orders?debug=1')
        ->json(['order_id' => 1001])
        ->withAuthenticator(new SignedRequestAuth(
            $signer,
            static fn (): int => 1_700_000_000,
            static fn (): string => 'nonce-123',
        ));

    $resolved = $request->applyAuthenticators();
    $payloadHash = hash('sha256', '{"order_id":1001}');
    $canonical = implode("\n", [
        'POST',
        '/orders?debug=1',
        '1700000000',
        'nonce-123',
        $payloadHash,
    ]);

    expect($resolved->headers->get('X-TB-Timestamp'))->toBe('1700000000');
    expect($resolved->headers->get('X-TB-Nonce'))->toBe('nonce-123');
    expect($resolved->headers->get('X-TB-Signature'))->toBe($signer->sign($canonical));
});

it('applies signing authenticator from http client helper', function (): void {
    $client = HttpClient::curl()->withSigner(new HmacSha256Signer('secret-key'));
    $method = new ReflectionMethod($client, 'applyDefaults');
    /** @var HttpRequest $request */
    $request = $method->invoke($client, HttpRequest::get('https://api.example.com/orders'));
    $resolved = $request->applyAuthenticators();

    expect($resolved->headers->get('X-TB-Timestamp'))->toBeString();
    expect($resolved->headers->get('X-TB-Nonce'))->toBeString();
    expect($resolved->headers->get('X-TB-Signature'))->toBeString();
});

it('hashes raw and form payloads and marks multipart as unsigned payload', function (): void {
    $signer = new HmacSha256Signer('secret-key');
    $auth = new SignedRequestAuth(
        $signer,
        static fn (): int => 1_700_000_000,
        static fn (): string => 'nonce-abc',
    );

    $rawRequest = HttpRequest::post('https://api.example.com/raw')
        ->raw('<x/>', 'application/xml')
        ->withAuthenticator($auth)
        ->applyAuthenticators();
    $rawCanonical = implode("\n", [
        'POST',
        '/raw',
        '1700000000',
        'nonce-abc',
        hash('sha256', '<x/>'),
    ]);
    expect($rawRequest->headers->get('X-TB-Signature'))->toBe($signer->sign($rawCanonical));

    $formRequest = HttpRequest::post('https://api.example.com/form')
        ->form(['a' => '1', 'b' => 'two'])
        ->withAuthenticator($auth)
        ->applyAuthenticators();
    $formCanonical = implode("\n", [
        'POST',
        '/form',
        '1700000000',
        'nonce-abc',
        hash('sha256', 'a=1&b=two'),
    ]);
    expect($formRequest->headers->get('X-TB-Signature'))->toBe($signer->sign($formCanonical));

    $multipartRequest = HttpRequest::post('https://api.example.com/files')
        ->multipart(HttpClient::multipart()->addField('name', 'report'))
        ->withAuthenticator($auth)
        ->applyAuthenticators();
    $multipartCanonical = implode("\n", [
        'POST',
        '/files',
        '1700000000',
        'nonce-abc',
        'UNSIGNED-PAYLOAD',
    ]);
    expect($multipartRequest->headers->get('X-TB-Signature'))->toBe($signer->sign($multipartCanonical));
});

it('validates custom signature header names', function (): void {
    expect(fn () => new SignedRequestAuth(
        new HmacSha256Signer('secret-key'),
        signatureHeader: 'Bad Header',
    ))->toThrow(InvalidArgumentException::class, 'Invalid HTTP header name');
});

it('signs after middleware has completed request mutation', function (): void {
    $signer = new HmacSha256Signer('secret-key');
    $auth = new SignedRequestAuth(
        $signer,
        static fn(): int => 1_700_000_000,
        static fn(): string => 'nonce-final',
    );
    $transport = new class implements HttpTransport {
        public function send(HttpRequest $request): CommunicationResult
        {
            return CommunicationResult::success(response: $request);
        }
    };
    $middleware = new class implements HttpMiddleware {
        public function handle(HttpRequest $request, Closure $next): CommunicationResult
        {
            return $next($request->query('final', 'yes'));
        }
    };

    $result = HttpClient::using($transport)
        ->withAuthenticator($auth)
        ->withMiddleware($middleware)
        ->send(HttpRequest::post('https://api.example.com/orders')->raw('body'));

    expect($result->response)->toBeInstanceOf(HttpRequest::class);
    /** @var HttpRequest $sent */
    $sent = $result->response;
    $canonical = implode("\n", [
        'POST',
        '/orders?final=yes',
        '1700000000',
        'nonce-final',
        hash('sha256', 'body'),
    ]);

    expect($sent->headers->get('X-TB-Signature'))->toBe($signer->sign($canonical));
});


it('signs the exact bounded bytes of stream uploads and restores caller position', function (): void {
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->toBeResource();
    fwrite($stream, 'prefix-first-suffix');
    fseek($stream, 7);

    $signer = new HmacSha256Signer('secret-key');
    $prepared = HttpRequest::put('https://api.example.com/upload')
        ->uploadFromStream($stream, 5)
        ->withAuthenticator(new SignedRequestAuth(
            $signer,
            static fn(): int => 1_700_000_000,
            static fn(): string => 'nonce-upload',
        ))
        ->prepareForTransport();

    $canonical = implode("\n", [
        'PUT',
        '/upload',
        '1700000000',
        'nonce-upload',
        hash('sha256', 'first'),
    ]);

    expect($prepared->headers->get('X-TB-Signature'))->toBe($signer->sign($canonical))
        ->and(ftell($stream))->toBe(7);

    $snapshot = $prepared->metadata['_upload_handle'] ?? null;
    expect($snapshot)->toBeResource();
    fwrite($stream, 'other');
    fseek($snapshot, 0);
    expect(stream_get_contents($snapshot))->toBe('first');

    UploadHandleManager::cleanup($prepared);
    fclose($stream);
});

it('produces different signatures for different upload bytes', function (): void {
    $signer = new HmacSha256Signer('secret-key');
    $auth = new SignedRequestAuth(
        $signer,
        static fn(): int => 1_700_000_000,
        static fn(): string => 'nonce-upload',
    );

    $signatures = [];
    foreach (['first', 'other'] as $payload) {
        $stream = fopen('php://temp', 'w+b');
        expect($stream)->toBeResource();
        fwrite($stream, $payload);
        rewind($stream);

        $prepared = HttpRequest::put('https://api.example.com/upload')
            ->uploadFromStream($stream, 5)
            ->withAuthenticator($auth)
            ->prepareForTransport();

        $signatures[] = $prepared->headers->get('X-TB-Signature');
        UploadHandleManager::cleanup($prepared);
        fclose($stream);
    }

    expect($signatures[0])->not->toBe($signatures[1]);
});

it('keeps signed file upload bytes stable after the source path is replaced', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tb-signed-upload-');
    expect($path)->toBeString();
    file_put_contents($path, 'first');

    $signer = new HmacSha256Signer('secret-key');
    $prepared = HttpRequest::put('https://api.example.com/upload')
        ->uploadFromFile($path)
        ->withAuthenticator(new SignedRequestAuth(
            $signer,
            static fn(): int => 1_700_000_000,
            static fn(): string => 'nonce-file',
        ))
        ->prepareForTransport();

    file_put_contents($path, 'other');

    $snapshot = $prepared->metadata['_upload_handle'] ?? null;
    expect($snapshot)->toBeResource();
    fseek($snapshot, 0);
    expect(stream_get_contents($snapshot))->toBe('first');

    $canonical = implode("\n", [
        'PUT',
        '/upload',
        '1700000000',
        'nonce-file',
        hash('sha256', 'first'),
    ]);
    expect($prepared->headers->get('X-TB-Signature'))->toBe($signer->sign($canonical));

    UploadHandleManager::cleanup($prepared);
    unlink($path);
});

it('fails closed when a signed upload ends before its declared size', function (): void {
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->toBeResource();
    fwrite($stream, 'abc');
    rewind($stream);

    expect(fn(): HttpRequest => HttpRequest::put('https://api.example.com/upload')
        ->uploadFromStream($stream, 5)
        ->withSigner(new HmacSha256Signer('secret-key'))
        ->prepareForTransport())
        ->toThrow(InvalidArgumentException::class, 'ended before the declared upload size');

    fclose($stream);
});

it('preserves the signed stream source offset across 307 redirects', function (): void {
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->toBeResource();
    fwrite($stream, 'SECRETPAYLOAD');
    fseek($stream, 6);

    $auth = new SignedRequestAuth(
        new HmacSha256Signer('secret-key'),
        static fn(): int => 1_700_000_000,
        static fn(): string => 'nonce-redirect',
    );
    $prepared = HttpRequest::put('https://api.example.com/upload')
        ->uploadFromStream($stream, 7)
        ->withAuthenticator($auth)
        ->prepareForTransport();

    $snapshot = $prepared->metadata['_upload_handle'] ?? null;
    expect($snapshot)->toBeResource();
    fseek($snapshot, 0);
    expect(stream_get_contents($snapshot))->toBe('PAYLOAD');
    UploadHandleManager::cleanup($prepared);

    $redirected = $prepared
        ->redirectedTo('https://api.example.com/upload-next', 307, true)
        ->prepareForTransport();

    $redirectSnapshot = $redirected->metadata['_upload_handle'] ?? null;
    expect($redirectSnapshot)->toBeResource();
    fseek($redirectSnapshot, 0);

    expect(stream_get_contents($redirectSnapshot))->toBe('PAYLOAD')
        ->and($redirected->metadata['_upload_source_offset'] ?? null)->toBe(6)
        ->and(ftell($stream))->toBe(6);

    UploadHandleManager::cleanup($redirected);
    fclose($stream);
});
