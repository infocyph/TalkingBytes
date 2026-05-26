<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use InvalidArgumentException;

final class Pop3MessageNumberGuard
{
    public static function assertValid(int $messageNumber): void
    {
        if ($messageNumber < 1) {
            throw new InvalidArgumentException('POP3 message number must be greater than zero.');
        }
    }
}
