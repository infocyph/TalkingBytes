<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Support;

final class HttpRedactor
{
    /** @var list<string> */
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-access-token',
        'x-tb-signature',
        'x-tb-timestamp',
        'x-tb-nonce',
    ];

    /** @var list<string> */
    private const array SENSITIVE_QUERY_KEYS = [
        'token',
        'api_key',
        'access_token',
        'refresh_token',
        'client_secret',
        'password',
        'secret',
        'signature',
    ];

    /**
     * @param array<string, string|list<string>> $headers
     * @param list<string> $sensitiveHeaders
     * @return array<string, string|list<string>>
     */
    public static function redactHeaders(array $headers, array $sensitiveHeaders = []): array
    {
        $sensitive = self::sensitiveLookup(self::SENSITIVE_HEADERS, $sensitiveHeaders);
        $redacted = [];

        foreach ($headers as $name => $value) {
            $redacted[$name] = isset($sensitive[strtolower($name)]) ? '[REDACTED]' : $value;
        }

        return $redacted;
    }

    /**
     * @param list<string> $sensitiveQueryKeys
     */
    public static function redactUrl(string $url, array $sensitiveQueryKeys = []): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $sensitive = self::sensitiveLookup(self::SENSITIVE_QUERY_KEYS, $sensitiveQueryKeys);
        $query = self::redactQuery((string) ($parts['query'] ?? ''), $sensitive);
        $host = (string) $parts['host'];

        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $base = sprintf('%s://%s', $parts['scheme'], $host);
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

    /** @param array<string, true> $sensitive */
    private static function isSensitiveQueryName(string $name, array $sensitive): bool
    {
        $normalized = strtolower($name);
        if (isset($sensitive[$normalized])) {
            return true;
        }

        $segments = preg_split('/[\[\]]+/', $normalized) ?: [];

        return array_any(
            $segments,
            static fn(string $segment): bool => $segment !== '' && isset($sensitive[$segment]),
        );
    }

    /** @param array<string, true> $sensitive */
    private static function redactQuery(string $query, array $sensitive): string
    {
        if ($query === '') {
            return '';
        }

        $redacted = [];
        foreach (explode('&', $query) as $pair) {
            [$rawName] = explode('=', $pair, 2);
            if (!self::isSensitiveQueryName(urldecode($rawName), $sensitive)) {
                $redacted[] = $pair;

                continue;
            }

            $redacted[] = $rawName . '=' . rawurlencode('[REDACTED]');
        }

        return implode('&', $redacted);
    }

    /**
     * @param list<string> $defaults
     * @param list<string> $additional
     * @return array<string, true>
     */
    private static function sensitiveLookup(array $defaults, array $additional): array
    {
        $lookup = [];
        foreach ([...$defaults, ...array_slice($additional, 0, 64)] as $name) {
            $name = strtolower(trim($name));
            if ($name !== '' && strlen($name) <= 256) {
                $lookup[$name] = true;
            }
        }

        return $lookup;
    }
}
