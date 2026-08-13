<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Options;

/**
 * @phpstan-type CurlOptionChanges array{
 *   timeoutSeconds?: int,
 *   connectTimeoutSeconds?: int,
 *   followRedirects?: bool,
 *   maxRedirects?: int,
 *   proxy?: ?string,
 *   proxyAuth?: ?string,
 *   verifyPeer?: bool,
 *   verifyHost?: bool,
 *   caBundle?: ?string,
 *   clientCertificate?: ?string,
 *   clientKey?: ?string,
 *   clientKeyPassphrase?: ?string,
 *   userAgent?: ?string,
 *   downloadPath?: ?string,
 *   streamDownloadPath?: ?string,
 *   maxResponseBytes?: ?int,
 *   maxDownloadBytes?: ?int,
 *   maxUploadBytes?: ?int,
 *   httpVersion?: ?int,
 *   explicit?: array<string, true>
 * }
 * @phpstan-type CurlOptionState array{
 *   timeoutSeconds: int,
 *   connectTimeoutSeconds: int,
 *   followRedirects: bool,
 *   maxRedirects: int,
 *   proxy: ?string,
 *   proxyAuth: ?string,
 *   verifyPeer: bool,
 *   verifyHost: bool,
 *   caBundle: ?string,
 *   clientCertificate: ?string,
 *   clientKey: ?string,
 *   clientKeyPassphrase: ?string,
 *   userAgent: ?string,
 *   downloadPath: ?string,
 *   streamDownloadPath: ?string,
 *   maxResponseBytes: ?int,
 *   maxDownloadBytes: ?int,
 *   maxUploadBytes: ?int,
 *   httpVersion: ?int,
 *   explicit: array<string, true>
 * }
 */
final readonly class CurlOptions
{
    /** @param array<string, true> $explicit */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 10,
        public bool $followRedirects = false,
        public int $maxRedirects = 5,
        public ?string $proxy = null,
        #[\SensitiveParameter]
        public ?string $proxyAuth = null,
        public bool $verifyPeer = true,
        public bool $verifyHost = true,
        public ?string $caBundle = null,
        public ?string $clientCertificate = null,
        public ?string $clientKey = null,
        #[\SensitiveParameter]
        public ?string $clientKeyPassphrase = null,
        public ?string $userAgent = null,
        public ?string $downloadPath = null,
        public ?string $streamDownloadPath = null,
        public ?int $maxResponseBytes = null,
        public ?int $maxDownloadBytes = null,
        public ?int $maxUploadBytes = null,
        public ?int $httpVersion = null,
        private array $explicit = [],
    ) {
        if ($this->timeoutSeconds < 1) {
            throw new \InvalidArgumentException('timeoutSeconds must be greater than 0.');
        }

        if ($this->connectTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('connectTimeoutSeconds must be greater than 0.');
        }

        if ($this->maxRedirects < 0) {
            throw new \InvalidArgumentException('maxRedirects must be greater than or equal to 0.');
        }

        self::assertPositiveLimit($this->maxResponseBytes, 'maxResponseBytes');
        self::assertPositiveLimit($this->maxDownloadBytes, 'maxDownloadBytes');
        self::assertPositiveLimit($this->maxUploadBytes, 'maxUploadBytes');

        if ($this->proxy !== null) {
            self::assertValidProxy($this->proxy);
        }

        self::assertNoControlCharacters($this->proxyAuth, 'proxyAuth');
        self::assertNoControlCharacters($this->userAgent, 'userAgent');
        if ($this->proxyAuth !== null && !str_contains($this->proxyAuth, ':')) {
            throw new \InvalidArgumentException('proxyAuth must use the username:password form.');
        }

        self::assertReadableFileIfSet($this->caBundle, 'caBundle');
        self::assertReadableFileIfSet($this->clientCertificate, 'clientCertificate');
        self::assertReadableFileIfSet($this->clientKey, 'clientKey');
    }

    public function isExplicit(string $option): bool
    {
        return isset($this->explicit[$option]);
    }

    public function withCaBundle(?string $caBundle): self
    {
        return $this->with(['caBundle' => $caBundle]);
    }

    public function withConnectTimeoutSeconds(int $seconds): self
    {
        return $this->with(['connectTimeoutSeconds' => $seconds]);
    }

    public function withDownloadPath(?string $downloadPath): self
    {
        return $this->with(['downloadPath' => $downloadPath]);
    }

    public function withFollowRedirects(bool $enabled, ?int $maxRedirects = null): self
    {
        return $this->with([
            'followRedirects' => $enabled,
            'maxRedirects' => $maxRedirects ?? $this->maxRedirects,
        ]);
    }

    public function withMaxDownloadBytes(?int $bytes): self
    {
        return $this->with(['maxDownloadBytes' => $bytes]);
    }

    public function withMaxRedirects(int $maxRedirects): self
    {
        return $this->withFollowRedirects($this->followRedirects, $maxRedirects);
    }

    public function withMaxResponseBytes(?int $bytes): self
    {
        return $this->with(['maxResponseBytes' => $bytes]);
    }

    public function withMaxUploadBytes(?int $bytes): self
    {
        return $this->with(['maxUploadBytes' => $bytes]);
    }

    public function withMtls(string $certificatePath, string $keyPath, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        return $this->with([
            'clientCertificate' => $certificatePath,
            'clientKey' => $keyPath,
            'clientKeyPassphrase' => $passphrase,
        ]);
    }

    public function withProxy(?string $proxy): self
    {
        return $this->with(['proxy' => $proxy]);
    }

    public function withProxyAuth(#[\SensitiveParameter] ?string $proxyAuth): self
    {
        return $this->with(['proxyAuth' => $proxyAuth]);
    }

    public function withResponseLimits(?int $maxResponseBytes, ?int $maxDownloadBytes, ?int $maxUploadBytes): self
    {
        return $this->with([
            'maxResponseBytes' => $maxResponseBytes,
            'maxDownloadBytes' => $maxDownloadBytes,
            'maxUploadBytes' => $maxUploadBytes,
        ]);
    }

    public function withStreamDownloadPath(?string $streamDownloadPath): self
    {
        return $this->with(['streamDownloadPath' => $streamDownloadPath]);
    }

    public function withTimeoutSeconds(int $seconds): self
    {
        return $this->with(['timeoutSeconds' => $seconds]);
    }

    public function withTls(bool $verifyPeer = true, bool $verifyHost = true, ?string $caBundle = null): self
    {
        return $this->with([
            'verifyPeer' => $verifyPeer,
            'verifyHost' => $verifyHost,
            'caBundle' => $caBundle ?? $this->caBundle,
        ]);
    }

    public function withTlsVerification(bool $verifyPeer, bool $verifyHost): self
    {
        return $this->with(['verifyPeer' => $verifyPeer, 'verifyHost' => $verifyHost]);
    }

    public function withUserAgent(?string $userAgent): self
    {
        return $this->with(['userAgent' => $userAgent]);
    }

    private static function assertNoControlCharacters(?string $value, string $field): void
    {
        if ($value !== null && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('%s contains control characters.', $field));
        }
    }

    private static function assertPositiveLimit(?int $value, string $field): void
    {
        if ($value !== null && $value < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be greater than 0 when provided.', $field));
        }
    }

    private static function assertReadableFileIfSet(?string $path, string $field): void
    {
        if ($path === null) {
            return;
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('%s must point to a readable file: %s', $field, $path));
        }
    }

    private static function assertValidProxy(string $proxy): void
    {
        if (trim($proxy) === '') {
            throw new \InvalidArgumentException('proxy must not be empty when provided.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $proxy) === 1) {
            throw new \InvalidArgumentException('proxy contains control characters.');
        }

        $scheme = parse_url($proxy, PHP_URL_SCHEME);
        if (!is_string($scheme)) {
            throw new \InvalidArgumentException(sprintf('proxy must include a scheme: %s', $proxy));
        }

        if (!in_array(strtolower($scheme), ['http', 'https', 'socks5', 'socks5h'], true)) {
            throw new \InvalidArgumentException(sprintf('proxy scheme is not supported: %s', $proxy));
        }
    }

    /** @return CurlOptionState */
    private function state(): array
    {
        return [
            'timeoutSeconds' => $this->timeoutSeconds,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
            'followRedirects' => $this->followRedirects,
            'maxRedirects' => $this->maxRedirects,
            'proxy' => $this->proxy,
            'proxyAuth' => $this->proxyAuth,
            'verifyPeer' => $this->verifyPeer,
            'verifyHost' => $this->verifyHost,
            'caBundle' => $this->caBundle,
            'clientCertificate' => $this->clientCertificate,
            'clientKey' => $this->clientKey,
            'clientKeyPassphrase' => $this->clientKeyPassphrase,
            'userAgent' => $this->userAgent,
            'downloadPath' => $this->downloadPath,
            'streamDownloadPath' => $this->streamDownloadPath,
            'maxResponseBytes' => $this->maxResponseBytes,
            'maxDownloadBytes' => $this->maxDownloadBytes,
            'maxUploadBytes' => $this->maxUploadBytes,
            'httpVersion' => $this->httpVersion,
            'explicit' => $this->explicit,
        ];
    }

    /** @param CurlOptionChanges $changes */
    private function with(array $changes): self
    {
        $explicit = $this->explicit;
        foreach ($changes as $name => $value) {
            if ($name !== 'explicit') {
                $explicit[$name] = true;
            }
        }
        $changes['explicit'] = $explicit;

        return new self(...array_replace($this->state(), $changes));
    }
}
