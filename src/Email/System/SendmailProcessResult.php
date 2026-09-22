<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final readonly class SendmailProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
