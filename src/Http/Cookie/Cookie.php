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
        if ($this->name === '') {
            throw new InvalidArgumentException('Cookie name must not be empty.');
        }

        if ($this->domain === '') {
            throw new InvalidArgumentException('Cookie domain must not be empty.');
        }

        if (!str_starts_with($this->path, '/')) {
            throw new InvalidArgumentException('Cookie path must start with "/".');
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

        return str_starts_with($path, $this->path);
    }

    public function pair(): string
    {
        return sprintf('%s=%s', $this->name, $this->value);
    }
}
