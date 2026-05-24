<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface RawPartMailboxTransport
{
    public function rawPart(string $folder, int $uid, string $partNumber): string;
}
