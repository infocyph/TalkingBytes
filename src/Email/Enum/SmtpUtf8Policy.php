<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum SmtpUtf8Policy: string
{
    case Auto = 'auto';

    case Reject = 'reject';

    case Require = 'require';
}
