<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum Pop3Security: string
{
    case None = 'none';

    case Ssl = 'ssl';

    case StartTlsOptional = 'starttls-optional';

    case StartTlsRequired = 'starttls-required';
}
