<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

final class MimeBoundary
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
