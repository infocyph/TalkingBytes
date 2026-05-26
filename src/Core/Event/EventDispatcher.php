<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Event;

interface EventDispatcher
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void;
}
