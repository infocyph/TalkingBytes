<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final readonly class ImapCommand
{
    public function __construct(
        public string $tag,
        public string $command,
    ) {}

    public function line(): string
    {
        return sprintf('%s %s', $this->tag, $this->command);
    }
}
