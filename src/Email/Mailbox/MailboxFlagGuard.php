<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use InvalidArgumentException;

final class MailboxFlagGuard
{
    public static function assertValid(string $flag): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $flag) === 1) {
            throw new InvalidArgumentException('Mailbox flag contains invalid control characters.');
        }

        $trimmed = trim($flag);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Mailbox flag must not be empty.');
        }

        if ($trimmed !== $flag) {
            throw new InvalidArgumentException('Mailbox flag must not contain leading or trailing whitespace.');
        }

        if (preg_match('/\s/', $trimmed) === 1) {
            throw new InvalidArgumentException('Mailbox flag must not contain whitespace.');
        }

        if (strlen($trimmed) > 64) {
            throw new InvalidArgumentException('Mailbox flag exceeds maximum length of 64 bytes.');
        }

        if (str_starts_with($trimmed, '\\')) {
            if (preg_match('/^\\\\[A-Za-z][A-Za-z0-9._-]*$/', $trimmed) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid system mailbox flag: %s', $flag));
            }

            return;
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $trimmed) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid custom mailbox keyword flag: %s', $flag));
        }
    }
}
