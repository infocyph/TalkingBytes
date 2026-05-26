<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use InvalidArgumentException;

final class MailboxUidGuard
{
    public static function assertValid(int $uid): void
    {
        if ($uid < 1) {
            throw new InvalidArgumentException('Mailbox UID must be greater than zero.');
        }
    }
}
