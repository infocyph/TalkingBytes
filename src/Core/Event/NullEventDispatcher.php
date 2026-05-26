<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

final class NullEventDispatcher implements EventDispatcher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        // Intentionally no-op.
    }
}
