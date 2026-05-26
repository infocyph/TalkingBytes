<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

final readonly class FakeMailbox
{
    public function __construct(
        public Mailbox $mailbox,
        public FakeMailboxTransport $transport,
    ) {}

    public static function new(?EmailParser $parser = null): self
    {
        $transport = new FakeMailboxTransport();

        return new self(new Mailbox($transport, $parser ?? new RawEmailParser()), $transport);
    }
}
