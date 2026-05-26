<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class AuthenticationResults
{
    /**
     * @param list<AuthenticationCheckResult> $checks
     * @param list<string> $warnings
     */
    public function __construct(
        public array $checks,
        public ?string $authservId = null,
        public array $warnings = [],
        public ?string $raw = null,
    ) {}

    public function check(string $method): ?AuthenticationCheckResult
    {
        $normalized = strtolower($method);

        foreach ($this->checks as $check) {
            if (strtolower($check->method) === $normalized) {
                return $check;
            }
        }

        return null;
    }

    public function isAuthenticated(): bool
    {
        return $this->passedDkim() || $this->passedSpf() || $this->passedDmarc();
    }

    public function passedArc(): bool
    {
        return $this->check('arc')?->passed() ?? false;
    }

    public function passedDkim(): bool
    {
        return $this->check('dkim')?->passed() ?? false;
    }

    public function passedDmarc(): bool
    {
        return $this->check('dmarc')?->passed() ?? false;
    }

    public function passedSpf(): bool
    {
        return $this->check('spf')?->passed() ?? false;
    }

    public function resultFor(string $method): ?AuthenticationCheckResult
    {
        return $this->check($method);
    }
}
