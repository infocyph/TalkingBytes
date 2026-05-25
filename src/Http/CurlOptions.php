<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

final readonly class CurlOptions
{
    /**
     * @param array<int, mixed> $additional
     */
    public function __construct(
        public int $timeoutSeconds = 10,
        public int $connectTimeoutSeconds = 10,
        public bool $followRedirects = false,
        public int $maxRedirects = 5,
        public ?string $proxy = null,
        public ?string $proxyAuth = null,
        public bool $verifyPeer = true,
        public bool $verifyHost = true,
        public ?string $caBundle = null,
        public ?string $clientCertificate = null,
        public ?string $clientKey = null,
        public ?string $clientKeyPassphrase = null,
        public ?string $userAgent = null,
        public ?string $downloadPath = null,
        public ?string $streamDownloadPath = null,
        public ?int $maxResponseBytes = null,
        public ?int $maxDownloadBytes = null,
        public ?int $maxUploadBytes = null,
        public ?int $httpVersion = null,
        public array $additional = [],
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

        self::assertReadableFileIfSet($this->caBundle, 'caBundle');
        self::assertReadableFileIfSet($this->clientCertificate, 'clientCertificate');
        self::assertReadableFileIfSet($this->clientKey, 'clientKey');
    }

    public function withAdditional(int $option, mixed $value): self
    {
        $additional = $this->additional;
        $additional[$option] = $value;

        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $additional,
        );
    }

    public function withConnectTimeoutSeconds(int $seconds): self
    {
        return new self(
            $this->timeoutSeconds,
            $seconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withDownloadPath(?string $downloadPath): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withFollowRedirects(bool $enabled, ?int $maxRedirects = null): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $enabled,
            $maxRedirects ?? $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withMaxRedirects(int $maxRedirects): self
    {
        return $this->withFollowRedirects($this->followRedirects, $maxRedirects);
    }

    public function withMtls(string $certificatePath, string $keyPath, ?string $passphrase = null): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $certificatePath,
            $keyPath,
            $passphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withProxy(?string $proxy): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withProxyAuth(?string $proxyAuth): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withResponseLimits(?int $maxResponseBytes, ?int $maxDownloadBytes, ?int $maxUploadBytes): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $maxResponseBytes,
            $maxDownloadBytes,
            $maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withStreamDownloadPath(?string $streamDownloadPath): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withTimeoutSeconds(int $seconds): self
    {
        return new self(
            $seconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withTls(bool $verifyPeer = true, bool $verifyHost = true, ?string $caBundle = null): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $verifyPeer,
            $verifyHost,
            $caBundle ?? $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $this->userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
    }

    public function withUserAgent(?string $userAgent): self
    {
        return new self(
            $this->timeoutSeconds,
            $this->connectTimeoutSeconds,
            $this->followRedirects,
            $this->maxRedirects,
            $this->proxy,
            $this->proxyAuth,
            $this->verifyPeer,
            $this->verifyHost,
            $this->caBundle,
            $this->clientCertificate,
            $this->clientKey,
            $this->clientKeyPassphrase,
            $userAgent,
            $this->downloadPath,
            $this->streamDownloadPath,
            $this->maxResponseBytes,
            $this->maxDownloadBytes,
            $this->maxUploadBytes,
            $this->httpVersion,
            $this->additional,
        );
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
}
