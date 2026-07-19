<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Cookie;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public string $domain,
        public string $path = '/',
        public ?DateTimeImmutable $expiresAt = null,
        public bool $secure = false,
        public bool $httpOnly = false,
        public bool $hostOnly = false,
    ) {
        if (preg_match('/^[!#$%&\'\*+\-.\^_`|~0-9A-Za-z]+$/', $this->name) !== 1) {
            throw new InvalidArgumentException('Cookie name contains invalid characters.');
        }

        $unquotedValue = $this->value;
        if (str_starts_with($unquotedValue, '"') || str_ends_with($unquotedValue, '"')) {
            if (strlen($unquotedValue) < 2 || !str_starts_with($unquotedValue, '"') || !str_ends_with($unquotedValue, '"')) {
                throw new InvalidArgumentException('Cookie value has mismatched quotes.');
            }

            $unquotedValue = substr($unquotedValue, 1, -1);
        }

        if (preg_match('/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]*$/D', $unquotedValue) !== 1) {
            throw new InvalidArgumentException('Cookie value contains invalid characters.');
        }

        if ($this->domain === '' || preg_match('/[\x00-\x20\x7F\/]/', $this->domain) === 1) {
            throw new InvalidArgumentException('Cookie domain is empty or contains invalid characters.');
        }

        if (!str_starts_with($this->path, '/') || preg_match('/[\x00-\x1F\x7F]/', $this->path) === 1) {
            throw new InvalidArgumentException('Cookie path must start with "/" and contain no control characters.');
        }
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now ??= new DateTimeImmutable();

        return $this->expiresAt <= $now;
    }

    public function key(): string
    {
        return strtolower($this->domain) . '|' . $this->path . '|' . $this->name;
    }

    public function matches(string $host, string $path, bool $secureRequest): bool
    {
        if ($this->secure && !$secureRequest) {
            return false;
        }

        if ($this->hostOnly) {
            if (strcasecmp($host, $this->domain) !== 0) {
                return false;
            }
        } else {
            $host = strtolower($host);
            $domain = strtolower($this->domain);

            if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
                return false;
            }
        }

        if ($path === $this->path) {
            return true;
        }

        if (!str_starts_with($path, $this->path)) {
            return false;
        }

        return str_ends_with($this->path, '/') || ($path[strlen($this->path)] ?? '') === '/';
    }

    public function pair(): string
    {
        return sprintf('%s=%s', $this->name, $this->value);
    }
}
