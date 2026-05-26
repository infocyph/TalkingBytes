<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

interface Pop3Transport
{
    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function connect(): void;

    public function delete(int $messageNumber): void;

    /**
     * @return array<int, int>
     */
    public function list(): array;

    public function logout(): void;

    public function noop(): void;

    public function rawMessage(int $messageNumber): string;

    public function reset(): void;

    /**
     * @return list<int>
     */
    public function search(MailboxSearch $search): array;

    public function status(): MailboxStatus;

    /**
     * @return array<int, string>
     */
    public function uidl(): array;

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void;
}
