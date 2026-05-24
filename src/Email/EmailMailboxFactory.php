<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;

final readonly class EmailMailboxFactory
{
    public function usingImap(ImapConfig $config): Mailbox
    {
        return Mailbox::usingImap($config);
    }

    public function usingPop3(Pop3Config $config): Pop3Mailbox
    {
        return Pop3Mailbox::usingConfig($config);
    }
}
