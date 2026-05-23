<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Core\Contract\MiddlewareInterface;
use Infocyph\TalkingBytes\Core\Middleware\RetryMiddleware;
use Infocyph\TalkingBytes\Core\Pipeline\MiddlewarePipeline;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class HttpClient
{
    /**
     * @param list<MiddlewareInterface> $middlewares
     * @param list<AuthenticatorInterface> $authenticators
     */
    private function __construct(
        private CurlTransport $transport,
        private array $middlewares = [],
        private int $timeoutSeconds = 10,
        private array $authenticators = [],
    ) {}

    public static function curl(): self
    {
        return new self(new CurlTransport());
    }

    public static function multi(int $maxConcurrency = 10): Concurrent\RequestPool
    {
        return new Concurrent\RequestPool(new Concurrent\CurlMultiTransport(), $maxConcurrency);
    }

    public static function multipart(): MultipartBody
    {
        return MultipartBody::new();
    }

    public function delete(string $url): CommunicationResult
    {
        return $this->send($this->applyDefaults(HttpRequest::delete($url)));
    }

    public function get(string $url): CommunicationResult
    {
        return $this->send($this->applyDefaults(HttpRequest::get($url)));
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
        return $this->send($this->applyDefaults(HttpRequest::patch($url)->json($payload)));
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
        return $this->send($this->applyDefaults(HttpRequest::post($url)->form($payload)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function postJson(string $url, array $payload): CommunicationResult
    {
        return $this->send($this->applyDefaults(HttpRequest::post($url)->json($payload)));
    }

    public function postMultipart(string $url, MultipartBody $payload): CommunicationResult
    {
        return $this->send($this->applyDefaults(HttpRequest::post($url)->multipart($payload)));
    }

    public function postRaw(string $url, string $payload, string $contentType = 'text/plain'): CommunicationResult
    {
        return $this->send($this->applyDefaults(HttpRequest::post($url)->raw($payload, $contentType)));
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
        return $this->send($this->applyDefaults(HttpRequest::put($url)->json($payload)));
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        $pipeline = new MiddlewarePipeline($this->transport, $this->middlewares);

        return $pipeline->send($this->applyDefaults($request)->toCommunicationRequest());
    }

    public function timeout(int $seconds): self
    {
        return new self($this->transport, $this->middlewares, $seconds, $this->authenticators);
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

        return new self($this->transport, $this->middlewares, $this->timeoutSeconds, $authenticators);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withAuthenticator(new BasicAuth($username, $password));
    }

    public function withBearerToken(string $token): self
    {
        return $this->withAuthenticator(new BearerTokenAuth($token));
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($this->transport, $middlewares, $this->timeoutSeconds, $this->authenticators);
    }

    public function withRetry(RetryPolicy $policy): self
    {
        return $this->withMiddleware(new RetryMiddleware($policy));
    }

    private function applyDefaults(HttpRequest $request): HttpRequest
    {
        $request = $request->timeout($this->timeoutSeconds);

        foreach ($this->authenticators as $authenticator) {
            $request = $request->withAuthenticator($authenticator);
        }

        return $request;
    }
}
