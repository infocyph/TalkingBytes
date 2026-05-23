<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum SmtpAuthMechanism: string
{
    case Auto = 'auto';

    case Login = 'login';

    case Plain = 'plain';
}
