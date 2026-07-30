<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Http\Body\FormBody;
use Infocyph\TalkingBytes\Http\Body\HttpBody;
use Infocyph\TalkingBytes\Http\Body\JsonBody;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Body\RawBody;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\Options\CurlOptions;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use Infocyph\TalkingBytes\Http\Support\QueryParams;
use Infocyph\TalkingBytes\Signing\RequestSignerInterface;
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

    public function acceptJson(): self
    {
        return $this->header('Accept', 'application/json');
    }

    /**
     * @param list<string> $hosts
     */
    public function allowHosts(array $hosts): self
    {
        return $this->metadata([
            ...$this->metadata,
            'security_allow_hosts' => $hosts,
        ]);
    }

    public function applyAuthenticators(): self
    {
        if ($this->authenticators === []) {
            return $this;
        }

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

    /**
     * @param list<string> $hosts
     */
    public function blockHosts(array $hosts): self
    {
        return $this->metadata([
            ...$this->metadata,
            'security_block_hosts' => $hosts,
        ]);
    }

    public function blockPrivateNetworks(bool $enabled = true): self
    {
        return $this->metadata([
            ...$this->metadata,
            'security_block_private_networks' => $enabled,
        ]);
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
        $additional = $this->queryParams->all();
        if ($additional === []) {
            return $this->url;
        }

        $parts = parse_url($this->url);
        if ($parts === false) {
            return $this->url;
        }

        $existing = [];
        parse_str((string) ($parts['query'] ?? ''), $existing);
        foreach ($additional as $key => $value) {
            if ($value === null) {
                unset($existing[$key]);

                continue;
            }

            $existing[$key] = $value;
        }

        $query = http_build_query($existing, '', '&', PHP_QUERY_RFC3986);

        $base = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        $base .= $parts['path'] ?? '';
        if ($query !== '') {
            $base .= '?' . $query;
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $base .= '#' . $parts['fragment'];
        }

        return $base;
    }

    public function caBundle(string $path): self
    {
        return $this->withOptions($this->options->withTls(caBundle: $path));
    }

    public function connectTimeout(int $seconds): self
    {
        return $this->withOptions($this->options->withConnectTimeoutSeconds($seconds));
    }

    public function contentType(string $contentType): self
    {
        return $this->header('Content-Type', $contentType);
    }

    public function downloadTo(string $path): self
    {
        return $this->withOptions($this->options->withDownloadPath($path)->withStreamDownloadPath(null));
    }

    public function followRedirects(bool $enabled = true, ?int $max = null): self
    {
        return $this->withOptions($this->options->withFollowRedirects($enabled, $max));
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
        return $this->body(new JsonBody($value))->acceptJson();
    }

    public function jsonWithFlags(mixed $value, int $flags): self
    {
        return $this->body(new JsonBody($value, $flags))->acceptJson();
    }

    public function maxDownloadBytes(int $bytes): self
    {
        return $this->withOptions($this->options->withResponseLimits($this->options->maxResponseBytes, $bytes, $this->options->maxUploadBytes));
    }

    public function maxRedirects(int $maxRedirects): self
    {
        return $this->withOptions($this->options->withMaxRedirects($maxRedirects));
    }

    public function maxResponseBytes(int $bytes): self
    {
        return $this->withOptions($this->options->withResponseLimits($bytes, $this->options->maxDownloadBytes, $this->options->maxUploadBytes));
    }

    public function maxUploadBytes(int $bytes): self
    {
        return $this->withOptions($this->options->withResponseLimits($this->options->maxResponseBytes, $this->options->maxDownloadBytes, $bytes));
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
        return $this->withOptions($this->options->withMtls($certificatePath, $keyPath, $passphrase));
    }

    public function multipart(?MultipartBody $multipartBody = null): self
    {
        return $this->body($multipartBody ?? MultipartBody::new());
    }

    public function option(int $option, mixed $value): self
    {
        return $this->withOptions($this->options->withAdditional($option, $value));
    }

    public function proxy(string $proxy): self
    {
        return $this->withOptions($this->options->withProxy($proxy));
    }

    public function proxyAuth(string $username, string $password): self
    {
        return $this->withOptions($this->options->withProxyAuth(sprintf('%s:%s', $username, $password)));
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $values
     */
    public function queries(array $values): self
    {
        $params = $this->queryParams;
        foreach ($values as $name => $value) {
            $params = $params->with($name, $value);
        }

        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $params,
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
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

    public function streamDownloadTo(string $path): self
    {
        return $this->withOptions($this->options->withStreamDownloadPath($path)->withDownloadPath(null));
    }

    public function timeout(int $seconds): self
    {
        return $this->withOptions($this->options->withTimeoutSeconds($seconds));
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

    public function uploadFromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException(sprintf('Upload file is missing or unreadable: %s', $path));
        }

        $size = filesize($path);
        if (!is_int($size)) {
            throw new InvalidArgumentException(sprintf('Unable to determine upload file size: %s', $path));
        }

        return $this->metadata([
            ...$this->metadata,
            'upload_file_path' => $path,
            'upload_size' => $size,
        ]);
    }

    /**
     * @param resource $stream
     */
    public function uploadFromStream(mixed $stream, int $size): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Upload stream must be a valid stream resource.');
        }

        if ($size < 0) {
            throw new InvalidArgumentException('Upload stream size must be greater than or equal to 0.');
        }

        return $this->metadata([
            ...$this->metadata,
            'upload_stream' => $stream,
            'upload_size' => $size,
        ]);
    }

    public function userAgent(string $userAgent): self
    {
        return $this->withOptions($this->options->withUserAgent($userAgent));
    }

    public function verifyTls(bool $verifyPeer = true, bool $verifyHost = true): self
    {
        return $this->withOptions($this->options->withTls($verifyPeer, $verifyHost));
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

    public function withoutHeader(string $name): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers->without($name),
            $this->queryParams,
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function withoutQuery(string $name): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams->with($name, null),
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function withoutTlsVerification(): self
    {
        return $this->verifyTls(false, false);
    }

    public function withSigner(RequestSignerInterface $signer): self
    {
        return $this->withAuthenticator(new SignedRequestAuth($signer));
    }

    private function assertValidUrl(string $url): void
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new InvalidArgumentException('HTTP URL must not be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new InvalidArgumentException('HTTP URL contains control characters.');
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
