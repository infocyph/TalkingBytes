<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final readonly class MimePart
{
    /**
     * @param list<string> $headers
     */
    public function __construct(
        private array $headers,
        private string $body,
    ) {}

    public function render(): string
    {
        return implode("\r\n", $this->headers) . "\r\n\r\n" . $this->body;
    }
}
