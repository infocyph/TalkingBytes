<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

final readonly class ImapResponse
{
    /**
     * @param list<string> $lines
     * @param list<string> $literals
     */
    public function __construct(
        public string $tag,
        public string $status,
        public array $lines,
        public array $literals = [],
    ) {}

    public function isOk(): bool
    {
        return strtoupper($this->status) === 'OK';
    }
}
