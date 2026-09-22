<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;

final readonly class EmailMailboxFactory
{
    private Clock $clock;

    private EventDispatcher $events;

    private Sleeper $sleeper;

    public function __construct(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function usingImap(ImapConfig $config): Mailbox
    {
        return Mailbox::usingImap($config, $this->events, $this->clock, $this->sleeper);
    }

    public function usingPop3(Pop3Config $config): Pop3Mailbox
    {
        return Pop3Mailbox::usingConfig($config, $this->events, $this->clock, $this->sleeper);
    }
}
