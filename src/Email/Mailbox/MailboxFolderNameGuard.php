<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use InvalidArgumentException;

final class MailboxFolderNameGuard
{
    public static function assertValid(string $folder): void
    {
        $trimmed = trim($folder);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Mailbox folder name must not be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $folder) === 1) {
            throw new InvalidArgumentException('Mailbox folder name contains invalid control characters.');
        }

        if (strlen($folder) > 255) {
            throw new InvalidArgumentException('Mailbox folder name exceeds maximum length of 255 bytes.');
        }
    }
}
