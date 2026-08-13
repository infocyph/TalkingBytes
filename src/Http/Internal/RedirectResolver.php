<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Internal;

use InvalidArgumentException;

final class RedirectResolver
{
    public static function resolve(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location) === 1) {
            throw new InvalidArgumentException('HTTP redirect Location is empty or contains control characters.');
        }

        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new InvalidArgumentException('Unable to resolve HTTP redirect against the request URL.');
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $origin = $base['scheme'] . '://' . self::authorityHost($base['host']);
        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }

        if (str_starts_with($location, '?')) {
            return $origin . ($base['path'] ?? '/') . $location;
        }

        if (str_starts_with($location, '#')) {
            $withoutFragment = explode('#', $baseUrl, 2)[0];

            return $withoutFragment . $location;
        }

        $fragmentParts = explode('#', $location, 2);
        $pathAndQuery = $fragmentParts[0];
        $fragment = $fragmentParts[1] ?? null;
        $queryParts = explode('?', $pathAndQuery, 2);
        $path = $queryParts[0];
        $query = $queryParts[1] ?? null;
        $resolvedPath = str_starts_with($path, '/')
            ? $path
            : rtrim(dirname($base['path'] ?? '/'), '/') . '/' . $path;
        $url = $origin . self::removeDotSegments($resolvedPath);

        if ($query !== null) {
            $url .= '?' . $query;
        }
        if ($fragment !== null) {
            $url .= '#' . $fragment;
        }

        return $url;
    }

    private static function authorityHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
