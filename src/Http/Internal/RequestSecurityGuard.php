<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use Infocyph\TalkingBytes\Http\HttpRequest;
use InvalidArgumentException;

final class RequestSecurityGuard
{
    public static function assertAllowed(HttpRequest $request, ?string $url = null): void
    {
        $url ??= $request->buildUrl();
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }
        $host = self::normalizeHost($host);

        $allowHosts = self::normalizeHosts($request->metadata['security_allow_hosts'] ?? []);
        if ($allowHosts !== [] && !in_array(strtolower($host), $allowHosts, true)) {
            throw new InvalidArgumentException(sprintf('HTTP request host is not allowed: %s', $host));
        }

        $blockHosts = self::normalizeHosts($request->metadata['security_block_hosts'] ?? []);
        if (in_array(strtolower($host), $blockHosts, true)) {
            throw new InvalidArgumentException(sprintf('HTTP request host is blocked: %s', $host));
        }

        if (($request->metadata['security_block_private_networks'] ?? false) !== true) {
            return;
        }

        if (self::isPrivateOrLocalHost($host)) {
            throw new InvalidArgumentException(sprintf('HTTP request host resolves to a private or reserved address: %s', $host));
        }
    }

    private static function isPrivateOrLocalHost(string $host): bool
    {
        $normalized = strtolower(self::normalizeHost($host));
        if ($normalized === 'localhost') {
            return true;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP) !== false) {
            return self::isPrivateOrReservedIp($normalized);
        }

        $resolved = self::resolveHostAddresses($normalized);

        return array_any($resolved, static fn(string $ip): bool => self::isPrivateOrReservedIp($ip));
    }

    private static function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            return
                ($long >= ip2long('0.0.0.0') && $long <= ip2long('0.255.255.255'))
                || ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255'))
                || ($long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255'));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $normalized = strtolower($ip);

            return $normalized === '::'
                || $normalized === '::1'
                || str_starts_with($normalized, 'fc')
                || str_starts_with($normalized, 'fd')
                || str_starts_with($normalized, 'fe8')
                || str_starts_with($normalized, 'fe9')
                || str_starts_with($normalized, 'fea')
                || str_starts_with($normalized, 'feb');
        }

        return true;
    }

    private static function normalizeHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * @return list<string>
     */
    private static function normalizeHosts(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $hosts = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $host = strtolower(self::normalizeHost(trim($item)));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * @return list<string>
     */
    private static function resolveHostAddresses(string $host): array
    {
        $addresses = [];

        set_error_handler(static fn(): bool => true);

        try {
            $records = dns_get_record($host, DNS_A + DNS_AAAA);
        } finally {
            restore_error_handler();
        }

        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $addresses[] = $ip;
                }
            }
        }

        if ($addresses !== []) {
            return $addresses;
        }

        $fallback = gethostbynamel($host);
        if ($fallback === false) {
            return [];
        }

        return array_values(array_filter(
            $fallback,
            static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_IP) !== false,
        ));
    }
}
