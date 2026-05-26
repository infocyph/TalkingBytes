<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http;

final class HttpRedactor
{
    /**
     * @var list<string>
     */
    private const array SENSITIVE_HEADERS = [
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'api-key',
        'x-auth-token',
        'x-access-token',
    ];

    /**
     * @var list<string>
     */
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
     * @return array<string, string|list<string>>
     */
    public static function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);
            if (in_array($normalized, self::SENSITIVE_HEADERS, true)) {
                $redacted[$name] = '[REDACTED]';

                continue;
            }

            $redacted[$name] = $value;
        }

        return $redacted;
    }

    public static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach ($query as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!in_array(strtolower($key), self::SENSITIVE_QUERY_KEYS, true)) {
                continue;
            }

            $query[$key] = '[REDACTED]';
        }

        $base = sprintf('%s://%s', $parts['scheme'], $parts['host']);
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        $base .= $parts['path'] ?? '';
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($queryString !== '') {
            $base .= '?' . $queryString;
        }

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $base .= '#' . $parts['fragment'];
        }

        return $base;
    }
}
