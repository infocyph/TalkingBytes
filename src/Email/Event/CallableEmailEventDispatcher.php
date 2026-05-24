<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

final readonly class CallableEmailEventDispatcher implements EmailEventDispatcher
{
    /**
     * @param callable(string, array<string, mixed>):void $listener
     */
    public function __construct(private mixed $listener) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $listener = $this->listener;
        $listener($event, $payload);
    }
}
