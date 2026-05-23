<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum SmtpSecurity: string
{
    case None = 'none';

    case Ssl = 'ssl';

    case StartTlsOptional = 'starttls-optional';

    case StartTlsRequired = 'starttls-required';
}
