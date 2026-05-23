<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Auth\HeaderAuth;
use Infocyph\TalkingBytes\Auth\QueryAuth;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Middleware\HeaderMiddleware;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Transport\MailFunctionTransport;
use Infocyph\TalkingBytes\Email\Transport\SmtpTransport;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilityParser;
use Infocyph\TalkingBytes\Http\HeaderBag;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\Internal\CurlResultFactory;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\ExponentialBackoffRetryPolicy;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;
use Infocyph\TalkingBytes\Retry\JitterBackoffRetryPolicy;
use Infocyph\TalkingBytes\Webhook\WebhookVerifier;

it('validates retry policy constructor arguments', function (): void {
    expect(fn() => new FixedDelayRetryPolicy(0, 1))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new FixedDelayRetryPolicy(1, -1))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new ExponentialBackoffRetryPolicy(0, 1))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new ExponentialBackoffRetryPolicy(1, -1))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new JitterBackoffRetryPolicy(1, 0))->toThrow(\InvalidArgumentException::class);
});

it('validates http urls', function (): void {
    expect(fn() => HttpRequest::get(''))->toThrow(\InvalidArgumentException::class);
    expect(fn() => HttpRequest::get('not-a-url'))->toThrow(\InvalidArgumentException::class);
    expect(fn() => HttpRequest::get('ftp://example.com'))->toThrow(\InvalidArgumentException::class);

    $request = HttpRequest::get('https://example.com');

    expect($request->url)->toBe('https://example.com');
});

it('validates http header names and values', function (): void {
    expect(fn() => new HeaderBag(['Bad Header' => 'value']))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new HeaderBag(['X-Test' => "evil\r\nnext: bad"]))->toThrow(\InvalidArgumentException::class);

    $request = HttpRequest::get('https://example.com')->header('X-Test', 'ok');

    expect($request->headers->get('X-Test'))->toBe('ok');
});

it('validates auth inputs', function (): void {
    expect(fn() => new BasicAuth('', 'pw'))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new BasicAuth('user', ''))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new BearerTokenAuth(''))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new ApiKeyAuth('', 'value'))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new ApiKeyAuth('X-Api-Key', ''))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new HeaderAuth('', 'value'))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new QueryAuth('', 'value'))->toThrow(\InvalidArgumentException::class);
});

it('fails when download path cannot be written', function (): void {
    $request = HttpRequest::get('https://example.com')->downloadTo('/path/does/not/exist/out.txt');

    $result = CurlResultFactory::fromExecution(
        $request,
        'curl',
        'hello',
        0,
        '',
        ['http_code' => 200],
        [],
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('Download directory does not exist');
});

it('grpc client supports middleware extension', function (): void {
    $headerSeen = null;

    $client = GrpcClient::using(
        static fn(GrpcRequest $request): GrpcResponse => new GrpcResponse(GrpcStatus::Ok, $request->message),
    )
        ->withMiddleware(new HeaderMiddleware(['X-Trace' => '1']))
        ->withMiddleware(new class($headerSeen) implements \Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface {
            public function __construct(private ?string &$headerSeen) {}

            public function handle(CommunicationRequest $request, \Closure $next): CommunicationResult
            {
                $header = $request->headers['X-Trace'] ?? null;
                $this->headerSeen = is_string($header) ? $header : null;

                return $next($request);
            }
        });

    $result = $client->send(new GrpcRequest('Service/Method', ['ping' => true]));

    expect($result->successful)->toBeTrue();
    expect($headerSeen)->toBe('1');
});

it('webhook verifier rejects malformed timestamp and signature values', function (): void {
    $verifier = new WebhookVerifier('secret');

    expect($verifier->verify('{"x":1}', 't=abc,v1=abcdef'))->toBeFalse();
    expect($verifier->verify('{"x":1}', 't=1,v1=nothex'))->toBeFalse();
});

it('validates resilience constructor arguments', function (): void {
    expect(fn() => new RateLimiter(0, 60))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new RateLimiter(1, 0))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new CircuitBreaker(failureThreshold: 0, coolDownSeconds: 60))->toThrow(\InvalidArgumentException::class);
    expect(fn() => new CircuitBreaker(failureThreshold: 1, coolDownSeconds: 0))->toThrow(\InvalidArgumentException::class);
});

it('validates sendmail argument control characters', function (): void {
    expect(fn() => new SendmailConfig('/usr/sbin/sendmail', ["-t\r\n"], 10))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn() => new SendmailConfig('/usr/sbin/sendmail', ['-X /tmp/sendmail.log'], 10))
        ->toThrow(\InvalidArgumentException::class, 'must not contain whitespace');
});

it('validates explicit smtp auth mechanism against advertised capabilities', function (): void {
    $config = new SmtpConfig(
        host: 'smtp.example.com',
        credentials: new SmtpCredentials('user', 'pass'),
        authMechanism: SmtpAuthMechanism::Login,
    );

    $transport = new SmtpTransport($config);
    $capabilities = (new SmtpCapabilityParser())->parse([
        '250-mail.example.com',
        '250 AUTH PLAIN',
    ]);

    $reflection = new ReflectionMethod($transport, 'resolveAuthMechanism');

    expect(fn() => $reflection->invoke($transport, $capabilities))
        ->toThrow(RuntimeException::class, 'does not advertise AUTH LOGIN');
});

it('formats mail() envelope sender with spaced -f parameter', function (): void {
    $transport = new MailFunctionTransport();
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->returnPath('bounce@example.com');

    $reflection = new ReflectionMethod($transport, 'envelopeSenderParameter');
    $value = $reflection->invoke($transport, $message);

    expect($value)->toStartWith('-f ');
    expect($value)->toContain('bounce@example.com');
});
