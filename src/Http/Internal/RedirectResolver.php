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

        $base = parse_url($baseUrl);
        $reference = parse_url($location);
        if (
            !is_array($base)
            || !isset($base['scheme'], $base['host'])
            || $reference === false
        ) {
            throw new InvalidArgumentException('Unable to resolve HTTP redirect against the request URL.');
        }

        $target = self::resolveComponents($base, $reference);

        return self::buildUrl($target);
    }

    /**
     * @param array<string, int|string> $parts
     */
    private static function authority(array $parts): string
    {
        if (!isset($parts['host'])) {
            return '';
        }

        $authority = '';
        if (isset($parts['user'])) {
            $authority .= $parts['user'];
            if (isset($parts['pass'])) {
                $authority .= ':' . $parts['pass'];
            }
            $authority .= '@';
        }

        $authority .= self::authorityHost((string) $parts['host']);
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }

        return $authority;
    }

    private static function authorityHost(string $host): string
    {
        $host = self::normalizeHost($host);

        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    /**
     * @param array{scheme:string, authority?:string, path:string, query?:string, fragment?:string} $target
     */
    private static function buildUrl(array $target): string
    {
        $url = $target['scheme'] . ':';
        if (array_key_exists('authority', $target)) {
            $url .= '//' . $target['authority'];
        }
        $url .= $target['path'];
        if (array_key_exists('query', $target)) {
            $url .= '?' . $target['query'];
        }
        if (array_key_exists('fragment', $target)) {
            $url .= '#' . $target['fragment'];
        }

        return $url;
    }

    /**
     * @param array<string, int|string> $base
     */
    private static function mergePath(array $base, string $referencePath): string
    {
        $basePath = (string) ($base['path'] ?? '');
        if (isset($base['host']) && $basePath === '') {
            return '/' . $referencePath;
        }

        $slash = strrpos($basePath, '/');
        if ($slash === false) {
            return $referencePath;
        }

        return substr($basePath, 0, $slash + 1) . $referencePath;
    }

    private static function normalizeHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);

                continue;
            }
            if (str_starts_with($input, './')) {
                $input = substr($input, 2);

                continue;
            }
            if (str_starts_with($input, '/./')) {
                $input = substr($input, 2);

                continue;
            }
            if ($input === '/.') {
                $input = '/';

                continue;
            }
            if (str_starts_with($input, '/../')) {
                $input = substr($input, 3);
                $output = self::removeLastSegment($output);

                continue;
            }
            if ($input === '/..') {
                $input = '/';
                $output = self::removeLastSegment($output);

                continue;
            }
            if ($input === '.' || $input === '..') {
                $input = '';

                continue;
            }

            [$segment, $input] = self::shiftSegment($input);
            $output .= $segment;
        }

        return $output;
    }

    private static function removeLastSegment(string $path): string
    {
        $slash = strrpos($path, '/');

        return $slash === false ? '' : substr($path, 0, $slash);
    }

    /**
     * @param array<string, int|string> $base
     * @param array<string, int|string> $reference
     * @return array{scheme:string, authority?:string, path:string, query?:string, fragment?:string}
     */
    private static function resolveComponents(array $base, array $reference): array
    {
        if (isset($reference['scheme'])) {
            $target = [
                'scheme' => (string) $reference['scheme'],
                'path' => self::removeDotSegments((string) ($reference['path'] ?? '')),
            ];
            if (isset($reference['host'])) {
                $target['authority'] = self::authority($reference);
            }
            if (array_key_exists('query', $reference)) {
                $target['query'] = (string) $reference['query'];
            }

            return self::withFragment($target, $reference);
        }

        $target = [
            'scheme' => (string) $base['scheme'],
            'authority' => isset($reference['host'])
                ? self::authority($reference)
                : self::authority($base),
            'path' => '',
        ];

        if (isset($reference['host'])) {
            $target['path'] = self::removeDotSegments((string) ($reference['path'] ?? ''));
            if (array_key_exists('query', $reference)) {
                $target['query'] = (string) $reference['query'];
            }

            return self::withFragment($target, $reference);
        }

        $referencePath = (string) ($reference['path'] ?? '');
        if ($referencePath === '') {
            $target['path'] = (string) ($base['path'] ?? '');
            if (array_key_exists('query', $reference)) {
                $target['query'] = (string) $reference['query'];
            } elseif (array_key_exists('query', $base)) {
                $target['query'] = (string) $base['query'];
            }
        } else {
            $target['path'] = self::removeDotSegments(
                str_starts_with($referencePath, '/')
                    ? $referencePath
                    : self::mergePath($base, $referencePath),
            );
            if (array_key_exists('query', $reference)) {
                $target['query'] = (string) $reference['query'];
            }
        }

        return self::withFragment($target, $reference);
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function shiftSegment(string $input): array
    {
        $searchOffset = str_starts_with($input, '/') ? 1 : 0;
        $slash = strpos($input, '/', $searchOffset);
        if ($slash === false) {
            return [$input, ''];
        }

        return [substr($input, 0, $slash), substr($input, $slash)];
    }

    /**
     * @param array{scheme:string, authority?:string, path:string, query?:string, fragment?:string} $target
     * @param array<string, int|string> $reference
     * @return array{scheme:string, authority?:string, path:string, query?:string, fragment?:string}
     */
    private static function withFragment(array $target, array $reference): array
    {
        if (array_key_exists('fragment', $reference)) {
            $target['fragment'] = (string) $reference['fragment'];
        }

        return $target;
    }
}
