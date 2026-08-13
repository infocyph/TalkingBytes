<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Config;

use InvalidArgumentException;

final class MailboxConfigValidator
{
    public static function assertHost(string $protocol, string $host): void
    {
        self::assertRequired(sprintf('%s host', $protocol), $host);
    }

    public static function assertPassword(string $protocol, string $password): void
    {
        if ($password === '') {
            throw new InvalidArgumentException(sprintf('%s password is required.', $protocol));
        }
        self::assertCredential($protocol, 'password', $password);
    }

    public static function assertPort(string $protocol, int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf('%s port must be between 1 and 65535.', $protocol));
        }
    }

    public static function assertRequired(string $label, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s is required.', $label));
        }
    }

    public static function assertTimeout(string $protocol, int $timeoutSeconds): void
    {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException(sprintf('%s timeout must be greater than zero.', $protocol));
        }
    }

    public static function assertUsername(string $protocol, string $username): void
    {
        self::assertRequired(sprintf('%s username', $protocol), $username);
        self::assertCredential($protocol, 'username', $username);
    }

    private static function assertCredential(string $protocol, string $kind, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf(
                '%s %s must not contain ASCII control characters.',
                $protocol,
                $kind,
            ));
        }
    }
}
