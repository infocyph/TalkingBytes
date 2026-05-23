<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final readonly class SmtpCapabilities
{
    /**
     * @param array<string, string> $values
     * @param list<string> $authMechanisms
     */
    public function __construct(
        public array $values = [],
        public array $authMechanisms = [],
    ) {}

    public function has(string $name): bool
    {
        return array_key_exists(strtoupper($name), $this->values);
    }

    public function sizeLimit(): ?int
    {
        $value = $this->values['SIZE'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
