<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

final readonly class InboundEmailAddress
{
    public function __construct(
        public ?string $email,
        public ?string $name = null,
        public ?string $raw = null,
    ) {}

    public function isValid(): bool
    {
        return $this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
