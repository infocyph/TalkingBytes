<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Signing;

interface RequestSignerInterface
{
    public function sign(string $payload): string;
}
