<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\Sleeper;

final readonly class Email
{
    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public static function events(?callable $listener): void
    {
        CommunicationEventBus::listen($listener);
    }

    public static function mailbox(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
    ): EmailMailboxFactory {
        return new EmailMailboxFactory($events, $clock, $sleeper);
    }

    public static function receiver(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): EmailReceiverFactory {
        return new EmailReceiverFactory($events, $clock);
    }

    public static function sender(
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): EmailSenderFactory {
        return new EmailSenderFactory($events, $clock);
    }
}
