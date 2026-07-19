<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Cookie;

use DateTimeImmutable;
use Infocyph\TalkingBytes\Http\HttpRequest;
use Infocyph\TalkingBytes\Http\HttpResponse;
use InvalidArgumentException;

final class CookieJar
{
    /**
     * @var array<string, Cookie>
     */
    private array $cookies = [];

    public function __construct(private readonly int $maxCookies = 3000)
    {
        if ($this->maxCookies < 1) {
            throw new InvalidArgumentException('Cookie jar maxCookies must be greater than zero.');
        }
    }

    /**
     * @return array<string, Cookie>
     */
    public function all(): array
    {
        return $this->cookies;
    }

    public function applyToRequest(HttpRequest $request): HttpRequest
    {
        $context = $this->requestContext($request->buildUrl());
        if ($context === null) {
            return $request;
        }

        $explicitCookiePairs = $this->extractExistingCookiePairs($request);
        $cookiePairs = $explicitCookiePairs;
        $selectedPathLengths = [];
        $now = new DateTimeImmutable();

        foreach ($this->cookies as $key => $cookie) {
            if ($cookie->isExpired($now)) {
                unset($this->cookies[$key]);

                continue;
            }

            if (!$cookie->matches($context['host'], $context['path'], $context['secure'])) {
                continue;
            }

            if (isset($explicitCookiePairs[$cookie->name])) {
                continue;
            }

            $pathLength = strlen($cookie->path);
            if (($selectedPathLengths[$cookie->name] ?? -1) >= $pathLength) {
                continue;
            }

            $cookiePairs[$cookie->name] = $cookie->value;
            $selectedPathLengths[$cookie->name] = $pathLength;
        }

        if ($cookiePairs === []) {
            return $request;
        }

        $pairs = [];
        foreach ($cookiePairs as $name => $value) {
            $pairs[] = sprintf('%s=%s', $name, $value);
        }

        return $request->header('Cookie', implode('; ', $pairs));
    }

    public function count(): int
    {
        return count($this->cookies);
    }

    public function remember(Cookie $cookie): void
    {
        $this->store($cookie);
    }

    public function storeFromResponse(HttpResponse $response, string $requestUrl): void
    {
        $setCookie = $response->header('Set-Cookie');
        if ($setCookie === null) {
            return;
        }

        $context = $this->requestContext($requestUrl);
        if ($context === null) {
            return;
        }

        $lines = is_array($setCookie) ? $setCookie : [$setCookie];

        foreach ($lines as $line) {
            $cookie = $this->parseSetCookie($line, $context);
            if ($cookie === null) {
                continue;
            }

            if ($cookie->isExpired()) {
                unset($this->cookies[$cookie->key()]);

                continue;
            }

            $this->store($cookie);
        }
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * }
     */
    private function applyAttribute(array $attributes, string $segment): array
    {
        if (!str_contains($segment, '=')) {
            $attribute = strtolower($segment);

            return match ($attribute) {
                'secure' => [...$attributes, 'secure' => true],
                'httponly' => [...$attributes, 'httpOnly' => true],
                default => $attributes,
            };
        }

        [$attributeName, $attributeValue] = explode('=', $segment, 2);
        $attributeName = strtolower(trim($attributeName));
        $attributeValue = trim($attributeValue, " \t\n\r\0\x0B\"");

        return match ($attributeName) {
            'domain' => $this->applyDomainAttribute($attributes, $attributeValue),
            'path' => $this->applyPathAttribute($attributes, $attributeValue),
            'expires' => $this->applyExpiresAttribute($attributes, $attributeValue),
            'max-age' => $this->applyMaxAgeAttribute($attributes, $attributeValue),
            default => $attributes,
        };
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * }
     */
    private function applyDomainAttribute(array $attributes, string $value): array
    {
        if ($value === '') {
            return $attributes;
        }

        return [
            ...$attributes,
            'domain' => rtrim(ltrim(strtolower($value), '.'), '.'),
            'hostOnly' => false,
        ];
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * }
     */
    private function applyExpiresAttribute(array $attributes, string $value): array
    {
        if ($value === '') {
            return $attributes;
        }

        return [
            ...$attributes,
            'expiresAt' => $this->parseExpires($value),
        ];
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * }
     */
    private function applyMaxAgeAttribute(array $attributes, string $value): array
    {
        $maxAge = filter_var($value, FILTER_VALIDATE_INT);
        if ($maxAge === false) {
            return $attributes;
        }

        return [
            ...$attributes,
            'expiresAt' => new DateTimeImmutable()->modify(sprintf('%+d seconds', $maxAge)),
        ];
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool
     * }
     */
    private function applyPathAttribute(array $attributes, string $value): array
    {
        if ($value === '') {
            return $attributes;
        }

        return [
            ...$attributes,
            'path' => str_starts_with($value, '/') ? $value : '/' . $value,
        ];
    }

    private function defaultPath(string $requestPath): string
    {
        if ($requestPath === '' || $requestPath[0] !== '/') {
            return '/';
        }

        $lastSlash = strrpos($requestPath, '/');
        if ($lastSlash === false || $lastSlash === 0) {
            return '/';
        }

        return substr($requestPath, 0, $lastSlash);
    }

    private function domainMatchesOrigin(string $originHost, string $cookieDomain): bool
    {
        $originHost = strtolower(rtrim($originHost, '.'));
        $cookieDomain = strtolower(rtrim($cookieDomain, '.'));

        if ($originHost === $cookieDomain) {
            return true;
        }

        if (filter_var($originHost, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($cookieDomain === '' || !str_contains($cookieDomain, '.')) {
            return false;
        }

        return str_ends_with($originHost, '.' . $cookieDomain);
    }

    /**
     * @return array<string, string>
     */
    private function extractExistingCookiePairs(HttpRequest $request): array
    {
        $header = $request->headers->get('Cookie');

        if ($header === null) {
            return [];
        }

        $lines = is_array($header) ? $header : [$header];
        $pairs = [];

        foreach ($lines as $line) {
            $segments = explode(';', $line);
            foreach ($segments as $segment) {
                $segment = trim($segment);
                if ($segment === '' || !str_contains($segment, '=')) {
                    continue;
                }

                [$name, $value] = explode('=', $segment, 2);
                $name = trim($name);
                if ($name === '') {
                    continue;
                }

                $pairs[$name] = trim($value);
            }
        }

        return $pairs;
    }

    private function parseExpires(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array{host: string, path: string, secure: bool} $context
     */
    private function parseSetCookie(string $line, array $context): ?Cookie
    {
        $segments = array_map(trim(...), explode(';', $line));
        $nameValue = array_shift($segments);

        if (!str_contains($nameValue, '=')) {
            return null;
        }

        [$name, $value] = explode('=', $nameValue, 2);
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $attributes = [
            'domain' => $context['host'],
            'path' => $this->defaultPath($context['path']),
            'expiresAt' => null,
            'secure' => false,
            'httpOnly' => false,
            'hostOnly' => true,
        ];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $attributes = $this->applyAttribute($attributes, $segment);
        }

        if (!$attributes['hostOnly'] && !$this->domainMatchesOrigin($context['host'], $attributes['domain'])) {
            return null;
        }

        try {
            return new Cookie(
                $name,
                $value,
                $attributes['domain'],
                $attributes['path'],
                $attributes['expiresAt'],
                $attributes['secure'],
                $attributes['httpOnly'],
                $attributes['hostOnly'],
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function purgeExpired(): void
    {
        $now = new DateTimeImmutable();

        foreach ($this->cookies as $key => $cookie) {
            if ($cookie->isExpired($now)) {
                unset($this->cookies[$key]);
            }
        }
    }

    /**
     * @return array{host: string, path: string, secure: bool}|null
     */
    private function requestContext(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        return [
            'host' => $host,
            'path' => $path,
            'secure' => strtolower((string) ($parts['scheme'] ?? 'http')) === 'https',
        ];
    }

    private function store(Cookie $cookie): void
    {
        $key = $cookie->key();
        if (isset($this->cookies[$key])) {
            $this->cookies[$key] = $cookie;

            return;
        }

        if (count($this->cookies) >= $this->maxCookies) {
            $this->purgeExpired();
        }

        if (count($this->cookies) < $this->maxCookies) {
            $this->cookies[$key] = $cookie;
        }
    }
}
