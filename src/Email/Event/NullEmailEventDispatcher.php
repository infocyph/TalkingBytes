<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

final class NullEmailEventDispatcher implements EmailEventDispatcher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        // Intentionally no-op.
    }
}
