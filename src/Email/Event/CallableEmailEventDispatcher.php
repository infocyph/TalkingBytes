<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;

final readonly class CallableEmailEventDispatcher implements EmailEventDispatcher
{
    private CallableEventDispatcher $dispatcher;

    /**
     * @param callable(string, array<string, mixed>):void $listener
     */
    public function __construct(mixed $listener)
    {
        $this->dispatcher = new CallableEventDispatcher($listener);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $this->dispatcher->dispatch($event, $payload);
    }
}
