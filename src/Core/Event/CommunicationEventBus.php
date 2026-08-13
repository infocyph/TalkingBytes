<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

final class CommunicationEventBus
{
    private static ?EventDispatcher $dispatcher = null;

    /**
     * @param array<string, mixed> $payload
     */
    public static function dispatch(string $event, array $payload = []): void
    {
        self::dispatcher()->dispatch($event, $payload);
    }

    /**
     * @param null|callable(string, array<string, mixed>):void $listener
     */
    public static function listen(?callable $listener): void
    {
        self::$dispatcher = $listener === null
            ? new NullEventDispatcher()
            : new BestEffortEventDispatcher(new CallableEventDispatcher($listener));
    }

    public static function useDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = new BestEffortEventDispatcher($dispatcher);
    }

    private static function dispatcher(): EventDispatcher
    {
        self::$dispatcher ??= new NullEventDispatcher();

        return self::$dispatcher;
    }
}
