<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

final readonly class CallableEventDispatcher implements EventDispatcher
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
