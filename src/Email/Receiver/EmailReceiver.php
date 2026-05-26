<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Receiver;

use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;

interface EmailReceiver
{
    public function receive(): ?ParsedEmail;
}
