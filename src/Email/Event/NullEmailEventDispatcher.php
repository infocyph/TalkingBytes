<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Event;

use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;

final readonly class NullEmailEventDispatcher implements EmailEventDispatcher
{
    private NullEventDispatcher $dispatcher;

    public function __construct()
    {
        $this->dispatcher = new NullEventDispatcher();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): void
    {
        $this->dispatcher->dispatch($event, $payload);
    }
}
