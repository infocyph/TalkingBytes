<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface WatchableMailboxTransport
{
    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(string $folder, callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void;
}
