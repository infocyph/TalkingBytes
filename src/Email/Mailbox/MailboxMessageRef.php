<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use DateTimeImmutable;

final readonly class MailboxMessageRef
{
    /**
     * @param list<string>|null $flags
     */
    public function __construct(
        public int $uid,
        public ?string $subject = null,
        public ?DateTimeImmutable $date = null,
        public ?string $from = null,
        public ?array $flags = null,
        public ?int $sizeBytes = null,
        public ?string $messageId = null,
        public ?int $sequence = null,
        public ?string $externalId = null,
    ) {}
}
