<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

use Throwable;

final readonly class BestEffortEventDispatcher implements EventDispatcher
{
    public function __construct(private EventDispatcher $dispatcher) {}

    public static function wrap(?EventDispatcher $dispatcher): self
    {
        return $dispatcher instanceof self
            ? $dispatcher
            : new self($dispatcher ?? new NullEventDispatcher());
    }

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
