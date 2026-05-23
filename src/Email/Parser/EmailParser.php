<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Parser;

use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

interface EmailParser
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function parse(string $rawEmail, array $metadata = []): ParsedEmail;
}
