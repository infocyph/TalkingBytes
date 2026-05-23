<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final readonly class RawEmailMessage
{
    public function __construct(
        public string $headers,
        public string $body,
        public string $raw,
        public int $sizeBytes,
    ) {}
}
