<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpRequest;
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
