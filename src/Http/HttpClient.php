<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Closure;
use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Contract\HttpMiddleware;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\Cookie\CookieJar;
use Infocyph\TalkingBytes\Http\Middleware\CircuitBreakerMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\IdempotencyMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\LoggingMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\RateLimitMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Http\Middleware\TimeoutMiddleware;
use Infocyph\TalkingBytes\Http\Options\CurlOptions;
use Infocyph\TalkingBytes\Http\Retry\HttpRetryPolicy;
use Infocyph\TalkingBytes\Http\Signing\RequestSigner;
use Infocyph\TalkingBytes\Http\Testing\AssertableHttpTransport;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Http\Transport\CurlTransport;
use Infocyph\TalkingBytes\Resilience\CircuitBreaker;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\RetryPolicy;
use RuntimeException;

final readonly class HttpClient
{
    private HttpPipeline $pipeline;

    /**
     * @param list<HttpMiddleware> $middlewares
     * @param array<string, string|list<string>> $defaultHeaders
     * @param list<AuthenticatorInterface> $authenticators
     */
    private function __construct(
        private HttpTransport $transport,
        private array $middlewares = [],
        private CurlOptions $defaultOptions = new CurlOptions(),
        private array $defaultHeaders = [],
        private array $authenticators = [],
        private ?CookieJar $cookieJar = null,
    ) {
        $this->pipeline = new HttpPipeline($transport, $middlewares);
    }

    public static function curl(?EventDispatcher $events = null): self
    {
        return new self(new CurlTransport($events));
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

    public static function multi(int $maxConcurrency = 10, ?EventDispatcher $events = null): Concurrent\RequestPool
    {
        return new Concurrent\RequestPool(new Concurrent\CurlMultiTransport(events: $events), $maxConcurrency);
    }

    public static function multipart(): MultipartBody
    {
        return MultipartBody::new();
    }

    public static function using(HttpTransport $transport): self
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

    public function hasRetryMiddleware(): bool
    {
        return array_any(
            $this->middlewares,
            static fn(HttpMiddleware $middleware): bool => $middleware instanceof RetryMiddleware,
        );
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

        $result = $this->pipeline->send($resolvedRequest);

        if ($this->cookieJar !== null && $result->response instanceof HttpResponse) {
            $this->cookieJar->storeFromResponse($result->response, $resolvedRequest->buildUrl());
        }

        return $result;
    }

    public function timeout(int $seconds): self
    {
        return new self($this->transport, $this->middlewares, $this->defaultOptions->withTimeoutSeconds($seconds), $this->defaultHeaders, $this->authenticators, $this->cookieJar);
    }

    public function withApiKey(string $header, #[\SensitiveParameter] string $value): self
    {
        return $this->withApiKeyHeader($header, $value);
    }

    public function withApiKeyHeader(string $header, #[\SensitiveParameter] string $value): self
    {
        return $this->withAuthenticator(new ApiKeyAuth($header, $value));
    }

    public function withApiKeyQuery(string $key, #[\SensitiveParameter] string $value): self
    {
        return $this->withAuthenticator(new ApiKeyAuth($key, $value, true));
    }

    public function withAuthenticator(AuthenticatorInterface $authenticator): self
    {
        $authenticators = $this->authenticators;
        $authenticators[] = $authenticator;

        return new self($this->transport, $this->middlewares, $this->defaultOptions, $this->defaultHeaders, $authenticators, $this->cookieJar);
    }

    public function withBasicAuth(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): self
    {
        return $this->withAuthenticator(new BasicAuth($username, $password));
    }

    public function withBearerToken(#[\SensitiveParameter] string $token): self
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
        return new self(
            $this->transport,
            $this->middlewares,
            $this->defaultOptions,
            [...$this->defaultHeaders, ...$headers],
            $this->authenticators,
            $this->cookieJar,
        );
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

    public function withMiddleware(HttpMiddleware $middleware): self
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

    public function withSigner(RequestSigner $signer): self
    {
        return $this->withAuthenticator(new SignedRequestAuth($signer));
    }

    public function withTimeout(int $seconds): self
    {
        return $this->withMiddleware(new TimeoutMiddleware($seconds));
    }

    private function applyCoreOptionDefaults(HttpRequest $request): HttpRequest
    {
        if (!$request->options->isExplicit('timeoutSeconds')) {
            $request = $request->timeout($this->defaultOptions->timeoutSeconds);
        }

        if (!$request->options->isExplicit('connectTimeoutSeconds')) {
            $request = $request->connectTimeout($this->defaultOptions->connectTimeoutSeconds);
        }

        if (
            !$request->options->isExplicit('followRedirects')
            || !$request->options->isExplicit('maxRedirects')
        ) {
            $request = $request->followRedirects(
                $request->options->isExplicit('followRedirects')
                    ? $request->options->followRedirects
                    : $this->defaultOptions->followRedirects,
                $request->options->isExplicit('maxRedirects')
                    ? $request->options->maxRedirects
                    : $this->defaultOptions->maxRedirects,
            );
        }

        if (
            !$request->options->isExplicit('verifyPeer')
            || !$request->options->isExplicit('verifyHost')
        ) {
            $request = $request->verifyTls(
                $request->options->isExplicit('verifyPeer')
                    ? $request->options->verifyPeer
                    : $this->defaultOptions->verifyPeer,
                $request->options->isExplicit('verifyHost')
                    ? $request->options->verifyHost
                    : $this->defaultOptions->verifyHost,
            );
        }

        return $request;
    }

    private function applyDefaults(HttpRequest $request): HttpRequest
    {
        $request = $this->applyCoreOptionDefaults($request);
        $request = $this->applyOptionalOptionDefaults($request);

        if ($this->defaultHeaders !== []) {
            $request = new HttpRequest(
                $request->method,
                $request->url,
                new \Infocyph\TalkingBytes\Http\Support\HeaderBag([
                    ...$this->defaultHeaders,
                    ...$request->headers->all(),
                ]),
                $request->queryParams,
                $request->body,
                $request->options,
                $request->authenticators,
                $request->metadata,
            );
        }

        foreach ($this->authenticators as $authenticator) {
            $request = $request->withAuthenticator($authenticator);
        }

        return $request;
    }

    private function applyOptionalOptionDefaults(HttpRequest $request): HttpRequest
    {
        if ($this->defaultOptions->proxy !== null && !$request->options->isExplicit('proxy')) {
            $request = $request->proxy($this->defaultOptions->proxy);
        }

        if (
            $this->defaultOptions->proxyAuth !== null
            && !$request->options->isExplicit('proxyAuth')
            && str_contains($this->defaultOptions->proxyAuth, ':')
        ) {
            [$username, $password] = explode(':', $this->defaultOptions->proxyAuth, 2);
            $request = $request->proxyAuth($username, $password);
        }

        if ($this->defaultOptions->caBundle !== null && !$request->options->isExplicit('caBundle')) {
            $request = $request->caBundle($this->defaultOptions->caBundle);
        }

        if ($this->defaultOptions->userAgent !== null && !$request->options->isExplicit('userAgent')) {
            $request = $request->userAgent($this->defaultOptions->userAgent);
        }

        if (
            $this->defaultOptions->maxResponseBytes !== null
            && !$request->options->isExplicit('maxResponseBytes')
        ) {
            $request = $request->maxResponseBytes($this->defaultOptions->maxResponseBytes);
        }

        return $request;
    }
}
