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
            throw new InvalidArgumentException('HTTP request URL must contain a valid host.');
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

        if ($request->options->proxy !== null) {
            throw new InvalidArgumentException(
                'Strict private-network protection cannot be combined with a remote proxy.',
            );
        }

        if (self::isPrivateOrLocalHost($host)) {
            throw new InvalidArgumentException(sprintf('HTTP request host resolves to a private or reserved address: %s', $host));
        }
    }

    public static function pinnedResolution(HttpRequest $request, string $url): ?string
    {
        self::assertAllowed($request, $url);
        if (($request->metadata['security_block_private_networks'] ?? false) !== true) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $addresses = self::resolveHostAddresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException(sprintf('HTTP request host could not be resolved safely: %s', $host));
        }

        foreach ($addresses as $address) {
            if (self::isPrivateOrReservedIp($address)) {
                throw new InvalidArgumentException(sprintf(
                    'HTTP request host resolves to a private or reserved address: %s',
                    $host,
                ));
            }
        }

        $port = parse_url($url, PHP_URL_PORT);
        if (!is_int($port)) {
            $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
        }

        return sprintf('%s:%d:%s', $host, $port, implode(',', $addresses));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $addressBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
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
            return array_any([
                '0.0.0.0/8',
                '10.0.0.0/8',
                '100.64.0.0/10',
                '127.0.0.0/8',
                '169.254.0.0/16',
                '172.16.0.0/12',
                '192.0.0.0/24',
                '192.0.2.0/24',
                '192.88.99.0/24',
                '192.168.0.0/16',
                '198.18.0.0/15',
                '198.51.100.0/24',
                '203.0.113.0/24',
                '224.0.0.0/4',
                '240.0.0.0/4',
            ], static fn(string $range): bool => self::inCidr($ip, $range));
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return array_any([
                '::/128',
                '::1/128',
                '100::/64',
                '2001:db8::/32',
                'fc00::/7',
                'fe80::/10',
                'ff00::/8',
            ], static fn(string $range): bool => self::inCidr($ip, $range));
        }

        return true;
    }

    private static function normalizeHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return rtrim($host, '.');
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
