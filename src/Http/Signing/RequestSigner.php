<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Signing;

interface RequestSigner
{
    public function sign(string $payload): string;
}
