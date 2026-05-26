<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface RawHeadersMailboxTransport
{
    /**
     * @return array<string, list<string>>
     */
    public function rawHeaders(string $folder, int $uid): array;
}
