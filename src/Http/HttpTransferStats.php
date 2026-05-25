<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

final readonly class HttpTransferStats
{
    public function __construct(
        public ?int $totalTimeMs = null,
        public ?int $dnsTimeMs = null,
        public ?int $connectTimeMs = null,
        public ?int $ttfbMs = null,
        public ?string $effectiveUrl = null,
        public ?string $primaryIp = null,
        public ?int $redirectCount = null,
        public ?int $uploadedBytes = null,
        public ?int $downloadedBytes = null,
        public ?float $uploadSpeed = null,
        public ?float $downloadSpeed = null,
        public ?string $contentType = null,
        public ?string $httpVersion = null,
    ) {}

    /**
     * @param array<string, mixed> $info
     */
    public static function fromCurlInfo(array $info): self
    {
        $httpVersion = null;
        if (is_int($info['http_version'] ?? null)) {
            $httpVersion = match ($info['http_version']) {
                CURL_HTTP_VERSION_1_0 => '1.0',
                CURL_HTTP_VERSION_1_1 => '1.1',
                CURL_HTTP_VERSION_2_0 => '2',
                CURL_HTTP_VERSION_3 => '3',
                default => null,
            };
        }

        return new self(
            totalTimeMs: self::secondsToMs($info['total_time'] ?? null),
            dnsTimeMs: self::secondsToMs($info['namelookup_time'] ?? null),
            connectTimeMs: self::secondsToMs($info['connect_time'] ?? null),
            ttfbMs: self::secondsToMs($info['starttransfer_time'] ?? null),
            effectiveUrl: is_string($info['url'] ?? null) ? $info['url'] : null,
            primaryIp: is_string($info['primary_ip'] ?? null) ? $info['primary_ip'] : null,
            redirectCount: is_int($info['redirect_count'] ?? null) ? $info['redirect_count'] : null,
            uploadedBytes: is_float($info['size_upload'] ?? null) || is_int($info['size_upload'] ?? null)
                ? (int) $info['size_upload']
                : null,
            downloadedBytes: is_float($info['size_download'] ?? null) || is_int($info['size_download'] ?? null)
                ? (int) $info['size_download']
                : null,
            uploadSpeed: is_float($info['speed_upload'] ?? null) || is_int($info['speed_upload'] ?? null)
                ? (float) $info['speed_upload']
                : null,
            downloadSpeed: is_float($info['speed_download'] ?? null) || is_int($info['speed_download'] ?? null)
                ? (float) $info['speed_download']
                : null,
            contentType: is_string($info['content_type'] ?? null) ? $info['content_type'] : null,
            httpVersion: $httpVersion,
        );
    }

    private static function secondsToMs(mixed $seconds): ?int
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return null;
        }

        return (int) round($seconds * 1000);
    }
}
