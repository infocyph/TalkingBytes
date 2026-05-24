<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Result;

final readonly class EmailRecipientResult
{
    public function __construct(
        public string $email,
        public bool $accepted,
        public ?int $smtpCode = null,
        public ?string $smtpResponse = null,
    ) {}
}
