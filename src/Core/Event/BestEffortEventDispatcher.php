<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

use Throwable;

final readonly class BestEffortEventDispatcher implements EventDispatcher
{
    public function __construct(private EventDispatcher $dispatcher) {}

    /** @param array<string, mixed> $payload */
    public function dispatch(string $event, array $payload = []): void
    {
        try {
            $this->dispatcher->dispatch($event, $payload);
        } catch (Throwable) {
            // Observability must never change delivery outcomes.
        }
    }
}
