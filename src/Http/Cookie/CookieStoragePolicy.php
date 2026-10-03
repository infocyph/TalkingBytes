<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Cookie;

final class CookieStoragePolicy
{
    /**
     * @param array{host: string, path: string, secure: bool} $context
     * @param list<string> $segments
     */
    public static function accepts(Cookie $cookie, array $context, array $segments): bool
    {
        if ($cookie->secure && !$context['secure']) {
            return false;
        }
        if (str_starts_with($cookie->name, '__Secure-')) {
            return $context['secure'] && $cookie->secure;
        }
        if (!str_starts_with($cookie->name, '__Host-')) {
            return true;
        }

        return $context['secure']
            && $cookie->secure
            && $cookie->hostOnly
            && $cookie->path === '/'
            && !self::hasAttribute($segments, 'domain');
    }

    /**
     * @param array{host: string, path: string, secure: bool} $context
     * @param array<string, Cookie> $cookies
     */
    public static function isInsecureOverlay(Cookie $cookie, array $context, array $cookies): bool
    {
        if ($context['secure']) {
            return false;
        }

        return array_any(
            $cookies,
            static fn(Cookie $existing): bool => $existing->secure
                && $existing->name === $cookie->name
                && self::domainsOverlap($existing, $cookie)
                && self::pathsOverlap($existing->path, $cookie->path),
        );
    }

    private static function domainsOverlap(Cookie $first, Cookie $second): bool
    {
        $firstDomain = strtolower(rtrim($first->domain, '.'));
        $secondDomain = strtolower(rtrim($second->domain, '.'));

        return $firstDomain === $secondDomain
            || (!$first->hostOnly && str_ends_with($secondDomain, '.' . $firstDomain))
            || (!$second->hostOnly && str_ends_with($firstDomain, '.' . $secondDomain));
    }

    /**
     * @param list<string> $segments
     */
    private static function hasAttribute(array $segments, string $name): bool
    {
        return array_any(
            $segments,
            static fn(string $segment): bool => strtolower(trim(explode('=', $segment, 2)[0])) === $name,
        );
    }

    private static function pathMatches(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }
        if (!str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        return str_ends_with($cookiePath, '/') || ($requestPath[strlen($cookiePath)] ?? '') === '/';
    }

    private static function pathsOverlap(string $first, string $second): bool
    {
        return self::pathMatches($first, $second) || self::pathMatches($second, $first);
    }
}
