<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use InvalidArgumentException;

final class ImapPartNumberGuard
{
    public static function assertValid(string $partNumber): void
    {
        $normalized = strtoupper(trim($partNumber));
        if ($normalized === 'HEADER' || $normalized === 'TEXT') {
            return;
        }

        if (preg_match('/^\d+(?:\.\d+)*$/', $partNumber) === 1) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Invalid IMAP part number: %s', $partNumber));
    }
}
