<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Stats;

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

        return new self(
            totalTimeMs: self::secondsToMs($info['total_time'] ?? null),
            dnsTimeMs: self::secondsToMs($info['namelookup_time'] ?? null),
            connectTimeMs: self::secondsToMs($info['connect_time'] ?? null),
            ttfbMs: self::secondsToMs($info['starttransfer_time'] ?? null),
            effectiveUrl: self::stringOrNull($info['url'] ?? null),
            primaryIp: self::stringOrNull($info['primary_ip'] ?? null),
            redirectCount: self::intOrNull($info['redirect_count'] ?? null),
            uploadedBytes: self::intOrNull($info['size_upload'] ?? null),
            downloadedBytes: self::intOrNull($info['size_download'] ?? null),
            uploadSpeed: self::floatOrNull($info['speed_upload'] ?? null),
            downloadSpeed: self::floatOrNull($info['speed_download'] ?? null),
            contentType: self::stringOrNull($info['content_type'] ?? null),
            httpVersion: self::httpVersion($info['http_version'] ?? null),
        );
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    private static function httpVersion(mixed $value): ?string
    {
        if (!is_int($value)) {
            return null;
        }

        return match ($value) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
            CURL_HTTP_VERSION_3 => '3',
            default => null,
        };
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    private static function secondsToMs(mixed $seconds): ?int
    {
        if (!is_int($seconds) && !is_float($seconds)) {
            return null;
        }

        return (int) round($seconds * 1000);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
