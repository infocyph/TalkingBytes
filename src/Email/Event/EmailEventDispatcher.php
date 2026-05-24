<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

interface EmailEventDispatcher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void;
}
