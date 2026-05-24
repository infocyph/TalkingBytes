<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

final class EmailEventBus
{
    private static ?EmailEventDispatcher $dispatcher = null;

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
            ? new NullEmailEventDispatcher()
            : new CallableEmailEventDispatcher($listener);
    }

    public static function useDispatcher(EmailEventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    private static function dispatcher(): EmailEventDispatcher
    {
        self::$dispatcher ??= new NullEmailEventDispatcher();

        return self::$dispatcher;
    }
}
