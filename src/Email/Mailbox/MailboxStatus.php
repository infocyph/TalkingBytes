<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final readonly class MailboxStatus
{
    public function __construct(
        public int $messages,
        public int $recent,
        public int $unseen,
        public ?int $uidValidity = null,
        public ?int $uidNext = null,
    ) {}
}
