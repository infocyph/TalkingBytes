<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;

final readonly class Email
{
    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public static function events(?callable $listener): void
    {
        CommunicationEventBus::listen($listener);
    }

    public static function mailbox(): EmailMailboxFactory
    {
        return new EmailMailboxFactory();
    }

    public static function receiver(): EmailReceiverFactory
    {
        return new EmailReceiverFactory();
    }

    public static function sender(): EmailSenderFactory
    {
        return new EmailSenderFactory();
    }
}
