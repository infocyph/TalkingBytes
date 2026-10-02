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

    /**
     * @param list<string> $allowedParentDomains
     */
    public function __construct(
        private readonly int $maxCookies = 3000,
        private readonly bool $allowDomainCookies = false,
        private readonly array $allowedParentDomains = [],
    ) {
        if ($this->maxCookies < 1) {
            throw new InvalidArgumentException('Cookie jar maxCookies must be greater than zero.');
        }

        if (count($this->allowedParentDomains) > 64) {
            throw new InvalidArgumentException('Cookie jar allowedParentDomains cannot contain more than 64 entries.');
        }

        foreach ($this->allowedParentDomains as $domain) {
            if (!$this->isValidAllowedParentDomain($domain)) {
                throw new InvalidArgumentException('Cookie jar allowed parent domain is invalid.');
            }
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

            if ($this->isInsecureOverlay($cookie, $context)) {
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
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
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
            'expires' => $attributes['maxAgeApplied']
                ? $attributes
                : $this->applyExpiresAttribute($attributes, $attributeValue),
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
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * }
     */
    private function applyDomainAttribute(array $attributes, string $value): array
    {
        if ($value === '' || !$this->allowDomainCookies) {
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
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
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
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
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
            'maxAgeApplied' => true,
        ];
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @return array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
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

    private function domainCookieScopeAllowed(string $cookieDomain): bool
    {
        $cookieDomain = strtolower(rtrim($cookieDomain, '.'));

        return array_any(
            $this->allowedParentDomains,
            static fn(string $allowed): bool => strtolower(rtrim($allowed, '.')) === $cookieDomain,
        );
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

    private function isValidAllowedParentDomain(string $domain): bool
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        return $domain !== ''
            && strlen($domain) <= 253
            && str_contains($domain, '.')
            && filter_var($domain, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) === 1;
    }

    private function domainsOverlap(Cookie $first, Cookie $second): bool
    {
        $firstDomain = strtolower(rtrim($first->domain, '.'));
        $secondDomain = strtolower(rtrim($second->domain, '.'));

        return $firstDomain === $secondDomain
            || (!$first->hostOnly && str_ends_with($secondDomain, '.' . $firstDomain))
            || (!$second->hostOnly && str_ends_with($firstDomain, '.' . $secondDomain));
    }

    /**
     * @param array{host: string, path: string, secure: bool} $context
     */
    private function isInsecureOverlay(Cookie $cookie, array $context): bool
    {
        if ($context['secure']) {
            return false;
        }

        foreach ($this->cookies as $existing) {
            if (
                $existing->secure
                && $existing->name === $cookie->name
                && $this->domainsOverlap($existing, $cookie)
                && $this->pathsOverlap($existing->path, $cookie->path)
            ) {
                return true;
            }
        }

        return false;
    }

    private function pathsOverlap(string $first, string $second): bool
    {
        return $this->pathMatches($first, $second) || $this->pathMatches($second, $first);
    }

    private function pathMatches(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }
        if (!str_starts_with($requestPath, $cookiePath)) {
            return false;
        }

        return str_ends_with($cookiePath, '/') || ($requestPath[strlen($cookiePath)] ?? '') === '/';
    }

    /**
     * @param array{
     *   domain: string,
     *   path: string,
     *   expiresAt: ?DateTimeImmutable,
     *   secure: bool,
     *   httpOnly: bool,
     *   hostOnly: bool,
     *   maxAgeApplied: bool
     * } $attributes
     * @param list<string> $segments
     * @param array{host: string, path: string, secure: bool} $context
     */
    private function prefixAndSecurePolicyAllows(
        string $name,
        array $attributes,
        array $segments,
        array $context,
    ): bool {
        if ($attributes['secure'] && !$context['secure']) {
            return false;
        }

        if (str_starts_with($name, '__Secure-') && (!$context['secure'] || !$attributes['secure'])) {
            return false;
        }

        if (!str_starts_with($name, '__Host-')) {
            return true;
        }

        return $context['secure']
            && $attributes['secure']
            && $attributes['hostOnly']
            && $attributes['path'] === '/'
            && !$this->hasAttribute($segments, 'domain');
    }

    /**
     * @param list<string> $segments
     */
    private function hasAttribute(array $segments, string $name): bool
    {
        foreach ($segments as $segment) {
            $attributeName = strtolower(trim(explode('=', $segment, 2)[0]));
            if ($attributeName === $name) {
                return true;
            }
        }

        return false;
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
            'maxAgeApplied' => false,
        ];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $attributes = $this->applyAttribute($attributes, $segment);
        }

        if (!$attributes['hostOnly']) {
            if (!$this->domainMatchesOrigin($context['host'], $attributes['domain'])) {
                return null;
            }

            if (!$this->domainCookieScopeAllowed($attributes['domain'])) {
                return null;
            }
        }

        if (!$this->prefixAndSecurePolicyAllows($name, $attributes, $segments, $context)) {
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
