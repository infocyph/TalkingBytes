<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface EnvelopeSummaryMailboxTransport
{
    public function envelopeSummary(string $folder, int $uid): MailboxMessageRef;
}
