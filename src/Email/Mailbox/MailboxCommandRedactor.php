<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final class MailboxCommandRedactor
{
    public static function redact(string $protocol, string $command): string
    {
        $normalizedProtocol = strtolower($protocol);

        if ($normalizedProtocol === 'imap') {
            return self::redactImap($command);
        }

        if ($normalizedProtocol === 'pop3') {
            return self::redactPop3($command);
        }

        return $command;
    }

    private static function redactImap(string $command): string
    {
        if (preg_match('/^\s*LOGIN\s+(.+?)\s+(.+)$/i', $command) === 1) {
            return 'LOGIN [REDACTED] [REDACTED]';
        }

        if (preg_match('/^\s*AUTHENTICATE\s+(.+)$/i', $command) === 1) {
            return 'AUTHENTICATE [REDACTED]';
        }

        return $command;
    }

    private static function redactPop3(string $command): string
    {
        if (preg_match('/^\s*PASS\s+.+$/i', $command) === 1) {
            return 'PASS [REDACTED]';
        }

        if (preg_match('/^\s*USER\s+.+$/i', $command) === 1) {
            return 'USER [REDACTED]';
        }

        if (preg_match('/^\s*APOP\s+(.+?)\s+(.+)$/i', $command) === 1) {
            return 'APOP [REDACTED] [REDACTED]';
        }

        if (preg_match('/^\s*AUTH\s+.+$/i', $command) === 1) {
            return 'AUTH [REDACTED]';
        }

        return $command;
    }
}
