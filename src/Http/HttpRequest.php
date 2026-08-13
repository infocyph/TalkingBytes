<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

use Infocyph\TalkingBytes\Auth\ApiKeyAuth;
use Infocyph\TalkingBytes\Auth\AuthenticatorInterface;
use Infocyph\TalkingBytes\Auth\BasicAuth;
use Infocyph\TalkingBytes\Auth\BearerTokenAuth;
use Infocyph\TalkingBytes\Auth\SignedRequestAuth;
use Infocyph\TalkingBytes\Http\Body\FormBody;
use Infocyph\TalkingBytes\Http\Body\HttpBody;
use Infocyph\TalkingBytes\Http\Body\JsonBody;
use Infocyph\TalkingBytes\Http\Body\MultipartBody;
use Infocyph\TalkingBytes\Http\Body\RawBody;
use Infocyph\TalkingBytes\Http\Enum\HttpMethod;
use Infocyph\TalkingBytes\Http\Options\CurlOptions;
use Infocyph\TalkingBytes\Http\Signing\RequestSigner;
use Infocyph\TalkingBytes\Http\Support\HeaderBag;
use Infocyph\TalkingBytes\Http\Support\QueryParams;
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

    public function allowHttpsDowngradeRedirect(bool $enabled = true): self
    {
        return $this->metadata([
            ...$this->metadata,
            'allow_https_downgrade_redirect' => $enabled,
        ]);
    }

    public function allowUnsafeMethodRetry(bool $enabled = true): self
    {
        return $this->metadata([
            ...$this->metadata,
            'retry_unsafe_method' => $enabled,
        ]);
    }

    public function appendQuery(string $key, mixed $value): self
    {
        return new self(
            $this->method,
            $this->url,
            $this->headers,
            $this->queryParams->append($key, $this->normalizeQueryValue($value)),
            $this->body,
            $this->options,
            $this->authenticators,
            $this->metadata,
        );
    }

    public function applyAuthenticators(): self
    {
        if ($this->authenticators === []) {
            return $this;
        }

        $request = $this;

        $signers = [];
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator instanceof SignedRequestAuth) {
                $signers[] = $authenticator;

                continue;
            }

            $request = $authenticator->apply($request);
        }

        foreach ($signers as $signer) {
            $request = $signer->apply($request);
        }

        return new self(
            $request->method,
            $request->url,
            $request->headers,
            $request->queryParams,
            $request->body,
            $request->options,
            $this->authenticators,
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
        if ($this->queryParams->all() === []) {
            return $this->url;
        }

        $fragmentParts = explode('#', $this->url, 2);
        $withoutFragment = $fragmentParts[0];
        $fragment = $fragmentParts[1] ?? null;
        $queryParts = explode('?', $withoutFragment, 2);
        $base = $queryParts[0];
        $existingQuery = $queryParts[1] ?? '';
        $query = $this->queryParams->applyTo($existingQuery);
        if ($query !== '') {
            $base .= '?' . $query;
        }
        if ($fragment !== null) {
            $base .= '#' . $fragment;
        }

        return $base;
    }

    public function caBundle(string $path): self
    {
        return $this->withOptions($this->options->withCaBundle($path));
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

    public function hasRepeatableUploadSource(): bool
    {
        if (!isset($this->metadata['upload_stream'])) {
            return true;
        }

        $stream = $this->metadata['upload_stream'];
        if (!is_resource($stream)) {
            return false;
        }

        $metadata = stream_get_meta_data($stream);

        return $metadata['seekable'];
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
        return $this->withOptions($this->options->withMaxDownloadBytes($bytes));
    }

    public function maxRedirects(int $maxRedirects): self
    {
        return $this->withOptions($this->options->withMaxRedirects($maxRedirects));
    }

    public function maxResponseBytes(int $bytes): self
    {
        return $this->withOptions($this->options->withMaxResponseBytes($bytes));
    }

    public function maxUploadBytes(int $bytes): self
    {
        return $this->withOptions($this->options->withMaxUploadBytes($bytes));
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

    public function mtls(string $certificatePath, string $keyPath, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        return $this->withOptions($this->options->withMtls($certificatePath, $keyPath, $passphrase));
    }

    public function multipart(?MultipartBody $multipartBody = null): self
    {
        return $this->body($multipartBody ?? MultipartBody::new());
    }

    public function prepareForTransport(): self
    {
        if (($this->metadata['_transport_prepared'] ?? false) === true) {
            return $this;
        }

        $request = $this;
        if ($request->body !== null && !$request->headers->has('Content-Type')) {
            $request = $request->header('Content-Type', $request->body->contentType());
        }

        $request = $request->applyAuthenticators();

        return $request->metadata([...$request->metadata, '_transport_prepared' => true]);
    }

    public function proxy(string $proxy): self
    {
        return $this->withOptions($this->options->withProxy($proxy));
    }

    public function proxyAuth(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): self
    {
        if ($username === '' || str_contains($username, ':')
            || preg_match('/[\x00-\x1F\x7F]/', $username . $password) === 1
        ) {
            throw new InvalidArgumentException('Proxy credentials are invalid.');
        }

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

    public function redirectedTo(string $url, int $statusCode, bool $preserveAuthentication): self
    {
        $headers = $this->headers;
        $authenticators = $this->authenticators;
        $metadata = $this->metadata;
        unset($metadata['_transport_prepared']);
        if (!$preserveAuthentication) {
            foreach (['Authorization', 'Proxy-Authorization', 'Cookie', 'X-TB-Signature', 'X-TB-Timestamp', 'X-TB-Nonce'] as $name) {
                $headers = $headers->without($name);
            }
            $authenticators = [];
        }

        $method = $this->method;
        $body = $this->body;
        $switchToGet = $statusCode === 303 && $this->method !== HttpMethod::Head;
        $switchToGet = $switchToGet || (in_array($statusCode, [301, 302], true) && $this->method === HttpMethod::Post);
        if ($switchToGet) {
            $method = HttpMethod::Get;
            $body = null;
            $headers = $headers->without('Content-Type')->without('Content-Length');
        }

        return new self(
            $method,
            $url,
            $headers,
            new QueryParams(),
            $body,
            $this->options,
            $authenticators,
            $metadata,
        );
    }

    public function streamDownloadTo(string $path): self
    {
        return $this->withOptions($this->options->withStreamDownloadPath($path)->withDownloadPath(null));
    }

    public function timeout(int $seconds): self
    {
        return $this->withOptions($this->options->withTimeoutSeconds($seconds));
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

        $streamMetadata = stream_get_meta_data($stream);
        if (!$streamMetadata['seekable']) {
            throw new InvalidArgumentException('HTTP upload streams must be seekable for safe repeatable sends.');
        }

        $offset = ftell($stream);
        if (!is_int($offset)) {
            throw new InvalidArgumentException('Unable to determine HTTP upload stream position.');
        }

        return $this->metadata([
            ...$this->metadata,
            'upload_stream' => $stream,
            'upload_size' => $size,
            'upload_offset' => $offset,
        ]);
    }

    public function userAgent(string $userAgent): self
    {
        return $this->withOptions($this->options->withUserAgent($userAgent));
    }

    public function verifyTls(bool $verifyPeer = true, bool $verifyHost = true): self
    {
        return $this->withOptions($this->options->withTlsVerification($verifyPeer, $verifyHost));
    }

    public function withApiKeyHeader(string $header, #[\SensitiveParameter] string $value): self
    {
        return $this->withApiKey($header, $value, false);
    }

    public function withApiKeyQuery(string $key, #[\SensitiveParameter] string $value): self
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

    public function withBasicAuth(#[\SensitiveParameter] string $username, #[\SensitiveParameter] string $password): self
    {
        return $this->withAuthenticator(new BasicAuth(username: $username, password: $password));
    }

    public function withBearerToken(#[\SensitiveParameter] string $token): self
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

    public function withSigner(RequestSigner $signer): self
    {
        return $this->withAuthenticator(new SignedRequestAuth($signer));
    }

    private function assertValidUrl(string $url): void
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            throw new InvalidArgumentException('HTTP URL must not be empty.');
        }

        if ($trimmed !== $url) {
            throw new InvalidArgumentException('HTTP URL must not contain surrounding whitespace.');
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

        if (parse_url($trimmed, PHP_URL_USER) !== null || parse_url($trimmed, PHP_URL_PASS) !== null) {
            throw new InvalidArgumentException('HTTP URL userinfo is not allowed; use an authentication API.');
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

    private function withApiKey(string $key, #[\SensitiveParameter] string $value, bool $query): self
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
