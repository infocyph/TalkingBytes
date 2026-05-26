<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Parser\HeaderParser;

final class MailboxRawHeaderMap
{
    /**
     * @return array<string, list<string>>
     */
    public static function fromRawMessage(string $raw): array
    {
        $normalized = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $raw));
        [$headerBlock] = explode("\r\n\r\n", $normalized, 2);

        return new HeaderParser()->parse($headerBlock)->asMap();
    }
}
