<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Closure;
use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Middleware\CircuitBreakerMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\HeaderMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\IdempotencyMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\LoggingMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\RateLimitMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Middleware\TimeoutMiddleware;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\Options\CurlOptions;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Http\Testing\AssertableHttpTransport;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use Infocyph\TalkingBytes\Signing\RequestSignerInterface;
use RuntimeException;

final readonly class HttpClient
{
    private MiddlewarePipeline $pipeline;

    /**
     * @param list<MiddlewareInterface> $middlewares
     * @param array<string, string|list<string>> $defaultHeaders
     * @param list<AuthenticatorInterface> $authenticators
     */
    private function __construct(
        private TransportInterface $transport,
        private array $middlewares = [],
        private CurlOptions $defaultOptions = new CurlOptions(),
        private array $defaultHeaders = [],
        private array $authenticators = [],
        private ?CookieJar $cookieJar = null,
    ) {
        $this->pipeline = new MiddlewarePipeline($transport, $middlewares);
    }

    public static function curl(): self
    {
        return new self(new CurlTransport());
    }

    public static function fake(?FakeHttpTransport $transport = null): self
    {
        return new self($transport ?? new FakeHttpTransport());
    }

    public static function fromConfig(HttpClientConfig $config): self
    {
        return new self(
            transport: new CurlTransport(),
            defaultOptions: new CurlOptions(
                timeoutSeconds: $config->timeoutSeconds,
                connectTimeoutSeconds: $config->connectTimeoutSeconds,
                followRedirects: $config->followRedirects,
                maxRedirects: $config->maxRedirects,
                proxy: $config->proxy,
                proxyAuth: ($config->proxyUsername !== null || $config->proxyPassword !== null)
                    ? sprintf('%s:%s', (string) $config->proxyUsername, (string) $config->proxyPassword)
                    : null,
                verifyPeer: $config->verifyPeer,
                verifyHost: $config->verifyHost,
                caBundle: $config->caBundle,
                userAgent: $config->userAgent,
                maxResponseBytes: $config->maxResponseBytes,
            ),
            defaultHeaders: $config->defaultHeaders,
        );
    }

    public static function multi(int $maxConcurrency = 10): Concurrent\RequestPool
    {
        return new Concurrent\RequestPool(new Concurrent\CurlMultiTransport(), $maxConcurrency);
    }

    public static function multipart(): MultipartBody
    {
        return MultipartBody::new();
    }

    public static function using(TransportInterface $transport): self
    {
        return new self($transport);
    }

    public function assert(): AssertableHttpTransport
    {
        if (!$this->transport instanceof FakeHttpTransport) {
            throw new RuntimeException('HttpClient assertions are only available for fake transports.');
        }

        return new AssertableHttpTransport($this->transport);
    }

    public function connectTimeout(int $seconds): self
    {
        return new self($this->transport, $this->middlewares, $this->defaultOptions->withConnectTimeoutSeconds($seconds), $this->defaultHeaders, $this->authenticators, $this->cookieJar);
    }

    public function delete(string $url): CommunicationResult
    {
        return $this->send(HttpRequest::delete($url));
    }

    public function get(string $url): CommunicationResult
    {
        return $this->send(HttpRequest::get($url));
    }

    public function head(string $url): CommunicationResult
    {
        return $this->send(HttpRequest::head($url));
    }

    public function options(string $url): CommunicationResult
    {
        return $this->send(HttpRequest::options($url));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patch(string $url, array $payload): CommunicationResult
    {
        return $this->patchJson($url, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function patchJson(string $url, array $payload): CommunicationResult
    {
        return $this->send(HttpRequest::patch($url)->json($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function post(string $url, array $payload): CommunicationResult
    {
        return $this->postJson($url, $payload);
    }

    /**
     * @param array<string, scalar|list<scalar>> $payload
     */
    public function postForm(string $url, array $payload): CommunicationResult
    {
        return $this->send(HttpRequest::post($url)->form($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function postJson(string $url, array $payload): CommunicationResult
    {
        return $this->send(HttpRequest::post($url)->json($payload));
    }

    public function postMultipart(string $url, MultipartBody $payload): CommunicationResult
    {
        return $this->send(HttpRequest::post($url)->multipart($payload));
    }

    public function postRaw(string $url, string $payload, string $contentType = 'text/plain'): CommunicationResult
    {
        return $this->send(HttpRequest::post($url)->raw($payload, $contentType));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function put(string $url, array $payload): CommunicationResult
    {
        return $this->putJson($url, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function putJson(string $url, array $payload): CommunicationResult
    {
        return $this->send(HttpRequest::put($url)->json($payload));
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        $resolvedRequest = $this->applyDefaults($request);
        if ($this->cookieJar !== null) {
            $resolvedRequest = $this->cookieJar->applyToRequest($resolvedRequest);
        }

        $result = $this->pipeline->send($resolvedRequest->toCommunicationRequest());

        if ($this->cookieJar !== null && $result->response instanceof HttpResponse) {
            $this->cookieJar->storeFromResponse($result->response, $resolvedRequest->buildUrl());
        }

        return $result;
    }

    public function timeout(int $seconds): self
    {
        return new self($this->transport, $this->middlewares, $this->defaultOptions->withTimeoutSeconds($seconds), $this->defaultHeaders, $this->authenticators, $this->cookieJar);
    }

    public function withApiKey(string $header, string $value): self
    {
        return $this->withApiKeyHeader($header, $value);
    }

    public function withApiKeyHeader(string $header, string $value): self
    {
        return $this->withAuthenticator(new ApiKeyAuth($header, $value));
    }

    public function withApiKeyQuery(string $key, string $value): self
    {
        return $this->withAuthenticator(new ApiKeyAuth($key, $value, true));
    }

    public function withAuthenticator(AuthenticatorInterface $authenticator): self
    {
        $authenticators = $this->authenticators;
        $authenticators[] = $authenticator;

        return new self($this->transport, $this->middlewares, $this->defaultOptions, $this->defaultHeaders, $authenticators, $this->cookieJar);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withAuthenticator(new BasicAuth($username, $password));
    }

    public function withBearerToken(string $token): self
    {
        return $this->withAuthenticator(new BearerTokenAuth($token));
    }

    public function withCircuitBreaker(CircuitBreaker $circuitBreaker): self
    {
        return $this->withMiddleware(new CircuitBreakerMiddleware($circuitBreaker));
    }

    public function withCookieJar(CookieJar $cookieJar): self
    {
        return new self($this->transport, $this->middlewares, $this->defaultOptions, $this->defaultHeaders, $this->authenticators, $cookieJar);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withDefaultHeaders(array $headers): self
    {
        return $this->withMiddleware(new HeaderMiddleware($headers));
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->transport, $this->middlewares, $this->defaultOptions, $headers, $this->authenticators, $this->cookieJar);
    }

    public function withHttpRetry(?HttpRetryPolicy $policy = null): self
    {
        return $this->withRetry($policy ?? HttpRetryPolicy::standard());
    }

    public function withIdempotency(string $headerName = 'Idempotency-Key'): self
    {
        return $this->withMiddleware(new IdempotencyMiddleware($headerName));
    }

    /**
     * @param callable(string, array<string, mixed>): void $logger
     */
    public function withLogging(callable $logger): self
    {
        return $this->withMiddleware(new LoggingMiddleware(Closure::fromCallable($logger)));
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($this->transport, $middlewares, $this->defaultOptions, $this->defaultHeaders, $this->authenticators, $this->cookieJar);
    }

    public function withQueryAuth(string $key, string $value): self
    {
        return $this->withApiKeyQuery($key, $value);
    }

    public function withRateLimit(RateLimiter $rateLimiter): self
    {
        return $this->withMiddleware(new RateLimitMiddleware($rateLimiter));
    }

    public function withRetry(RetryPolicy $policy): self
    {
        return $this->withMiddleware(new RetryMiddleware($policy));
    }

    public function withSigner(RequestSignerInterface $signer): self
    {
        return $this->withAuthenticator(new SignedRequestAuth($signer));
    }

    public function withTimeout(int $seconds): self
    {
        return $this->withMiddleware(new TimeoutMiddleware($seconds));
    }

    private function applyCoreOptionDefaults(HttpRequest $request): HttpRequest
    {
        if ($request->options->timeoutSeconds !== $this->defaultOptions->timeoutSeconds) {
            $request = $request->timeout($this->defaultOptions->timeoutSeconds);
        }

        if ($request->options->connectTimeoutSeconds !== $this->defaultOptions->connectTimeoutSeconds) {
            $request = $request->connectTimeout($this->defaultOptions->connectTimeoutSeconds);
        }

        if (
            $request->options->followRedirects !== $this->defaultOptions->followRedirects
            || $request->options->maxRedirects !== $this->defaultOptions->maxRedirects
        ) {
            $request = $request->followRedirects(
                $this->defaultOptions->followRedirects,
                $this->defaultOptions->maxRedirects,
            );
        }

        if (
            $request->options->verifyPeer !== $this->defaultOptions->verifyPeer
            || $request->options->verifyHost !== $this->defaultOptions->verifyHost
        ) {
            $request = $request->verifyTls(
                $this->defaultOptions->verifyPeer,
                $this->defaultOptions->verifyHost,
            );
        }

        return $request;
    }

    private function applyDefaults(HttpRequest $request): HttpRequest
    {
        $request = $this->applyCoreOptionDefaults($request);
        $request = $this->applyOptionalOptionDefaults($request);

        if ($this->defaultHeaders !== []) {
            $request = $request->headers($this->defaultHeaders);
        }

        foreach ($this->authenticators as $authenticator) {
            $request = $request->withAuthenticator($authenticator);
        }

        return $request;
    }

    private function applyOptionalOptionDefaults(HttpRequest $request): HttpRequest
    {
        if ($this->defaultOptions->proxy !== null && $request->options->proxy !== $this->defaultOptions->proxy) {
            $request = $request->proxy($this->defaultOptions->proxy);
        }

        if (
            $this->defaultOptions->proxyAuth !== null
            && $request->options->proxyAuth !== $this->defaultOptions->proxyAuth
            && str_contains($this->defaultOptions->proxyAuth, ':')
        ) {
            [$username, $password] = explode(':', $this->defaultOptions->proxyAuth, 2);
            $request = $request->proxyAuth($username, $password);
        }

        if ($this->defaultOptions->caBundle !== null && $request->options->caBundle !== $this->defaultOptions->caBundle) {
            $request = $request->caBundle($this->defaultOptions->caBundle);
        }

        if ($this->defaultOptions->userAgent !== null && $request->options->userAgent !== $this->defaultOptions->userAgent) {
            $request = $request->userAgent($this->defaultOptions->userAgent);
        }

        if (
            $this->defaultOptions->maxResponseBytes !== null
            && $request->options->maxResponseBytes !== $this->defaultOptions->maxResponseBytes
        ) {
            $request = $request->maxResponseBytes($this->defaultOptions->maxResponseBytes);
        }

        return $request;
    }
}
