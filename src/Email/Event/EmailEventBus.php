<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;

final class EmailEventBus
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        CommunicationEventBus::dispatch($event, $payload);
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public static function listen(?callable $listener): void
    {
        CommunicationEventBus::listen($listener);
    }

    public static function useDispatcher(EmailEventDispatcher $dispatcher): void
    {
        CommunicationEventBus::useDispatcher($dispatcher);
    }
}
