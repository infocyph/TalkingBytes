<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface BodyStructureMailboxTransport
{
    public function bodyStructure(string $folder, int $uid): string;
}
