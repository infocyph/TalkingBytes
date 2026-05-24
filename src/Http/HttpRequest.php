<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Http\Body\FormBody;
use Infocyph\TalkingBytes\Http\Body\HttpBody;
use Infocyph\TalkingBytes\Http\Body\JsonBody;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Body\RawBody;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use InvalidArgumentException;

final readonly class HttpRequest
{
    /**
     * @param list<AuthenticatorInterface> $authenticators
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public HttpMethod $method,
        public string $url,
        public HeaderBag $headers = new HeaderBag(),
        public QueryParams $queryParams = new QueryParams(),
        public ?HttpBody $body = null,
        public CurlOptions $options = new CurlOptions(),
        public array $authenticators = [],
        public array $metadata = [],
    ) {
        $this->assertValidUrl($this->url);
    }

    public static function delete(string $url): self
    {
        return new self(HttpMethod::Delete, $url);
    }

    public static function get(string $url): self
    {
        return new self(HttpMethod::Get, $url);
    }

    public static function head(string $url): self
    {
        return new self(HttpMethod::Head, $url);
    }

    public static function options(string $url): self
    {
        return new self(HttpMethod::Options, $url);
    }

    public static function patch(string $url): self
    {
        return new self(HttpMethod::Patch, $url);
    }

    public static function post(string $url): self
    {
        return new self(HttpMethod::Post, $url);
    }

    public static function put(string $url): self
    {
        return new self(HttpMethod::Put, $url);
    }

    public function applyAuthenticators(): self
    {
        $request = $this;

        foreach ($this->authenticators as $authenticator) {
            $request = $authenticator->apply($request);
        }

        return new self(
            $request->method,
            $request->url,
            $request->headers,
            $request->queryParams,
            $request->body,
            $request->options,
            [],
            $request->metadata,
        );
    }

    public function body(HttpBody $body): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams,
            $body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function buildUrl(): string
    {
        $queryString = $this->queryParams->toQueryString();

        if ($queryString === '') {
            return $this->url;
        }

        return str_contains($this->url, '?')
            ? $this->url . '&' . $queryString
            : $this->url . '?' . $queryString;
    }

    public function connectTimeout(int $seconds): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $seconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    public function downloadTo(string $path): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $path,
                $this->options->additional,
            ),
        );
    }

    public function followRedirects(bool $enabled = true): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $enabled,
                $this->options->maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    /**
     * @param array<string, scalar|list<scalar>> $data
     */
    public function form(array $data): self
    {
        return $this->body(new FormBody($data));
    }

    /**
     * @param string|list<string> $value
     */
    public function header(string $name, string|array $value): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers->with($name, $this->normalizeHeaderValue($value)),
            $this->queryParams,
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function headers(array $headers): self
    {
        $bag = $this->headers;
        foreach ($headers as $name => $value) {
            $bag = $bag->with($name, $value);
        }

        return new self(
            $this->method,
            $this->url,
            $bag,
            $this->queryParams,
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function json(mixed $value): self
    {
        return $this->body(new JsonBody($value));
    }

    public function maxRedirects(int $maxRedirects): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams,
            $this->body,
            $this->options,
            $this->authenticators,
            $metadata,
        );
    }

    public function mtls(string $certificatePath, string $keyPath, ?string $passphrase = null): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $certificatePath,
                $keyPath,
                $passphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    public function multipart(MultipartBody $multipartBody): self
    {
        return $this->body($multipartBody);
    }

    public function option(int $option, mixed $value): self
    {
        return $this->withOptions($this->options->withAdditional($option, $value));
    }

    public function proxy(string $proxy): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    public function query(string $key, mixed $value): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams->with($key, $this->normalizeQueryValue($value)),
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function raw(string $content, string $contentType = 'text/plain'): self
    {
        return $this->body(new RawBody($content, $contentType));
    }

    public function timeout(int $seconds): self
    {
        return $this->withOptions(
            new CurlOptions(
                $seconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $this->options->proxy,
                $this->options->verifyPeer,
                $this->options->verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    public function toCommunicationRequest(): CommunicationRequest
    {
        return new CommunicationRequest(
            'http',
            $this,
            $this->headers->all(),
            [
                'method' => $this->method->value,
                'url' => $this->buildUrl(),
                'timeout' => $this->options->timeoutSeconds,
                'connect_timeout' => $this->options->connectTimeoutSeconds,
            ],
            $this->metadata,
        );
    }

    public function verifyTls(bool $verifyPeer = true, bool $verifyHost = true): self
    {
        return $this->withOptions(
            new CurlOptions(
                $this->options->timeoutSeconds,
                $this->options->connectTimeoutSeconds,
                $this->options->followRedirects,
                $this->options->maxRedirects,
                $this->options->proxy,
                $verifyPeer,
                $verifyHost,
                $this->options->clientCertificate,
                $this->options->clientKey,
                $this->options->clientKeyPassphrase,
                $this->options->downloadPath,
                $this->options->additional,
            ),
        );
    }

    public function withApiKeyHeader(string $header, string $value): self
    {
        return $this->withApiKey($header, $value, false);
    }

    public function withApiKeyQuery(string $key, string $value): self
    {
        return $this->withApiKey($key, $value, true);
    }

    public function withAuthenticator(AuthenticatorInterface $authenticator): self
    {
        $authenticators = $this->authenticators;
        $authenticators[] = $authenticator;

        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams,
            $this->body,
            $this->options,
            $authenticators,
            $this->metadata,
        );
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withAuthenticator(new BasicAuth(username: $username, password: $password));
    }

    public function withBearerToken(string $token): self
    {
        return $this->withAuthenticator(new BearerTokenAuth(token: $token));
    }

    private function assertValidUrl(string $url): void
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new InvalidArgumentException('HTTP URL must not be empty.');
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('Invalid HTTP URL: %s', $url));
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException('HTTP URL scheme must be http or https.');
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('HTTP URL host is required.');
        }
    }

    /**
     * @param string|array<int, mixed> $value
     * @return string|list<string>
     */
    private function normalizeHeaderValue(string|array $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Header values must be strings.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @return scalar|list<scalar>|null
     */
    private function normalizeQueryValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('Query value must be scalar, list of scalar values, or null.');
        }

        $normalized = [];

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                throw new InvalidArgumentException('Query list values must be scalar.');
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function withApiKey(string $key, string $value, bool $query): self
    {
        return $this->withAuthenticator(new ApiKeyAuth(key: $key, value: $value, inQuery: $query));
    }

    private function withOptions(CurlOptions $options): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams,
            $this->body,
            $options,
            $this->authenticators,
            $this->metadata,
        );
    }
}
