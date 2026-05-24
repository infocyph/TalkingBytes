<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum BounceType: string
{
    case Blocked = 'blocked';

    case DomainNotFound = 'domain_not_found';

    case Hard = 'hard';

    case MailboxFull = 'mailbox_full';

    case Soft = 'soft';

    case SpamRejected = 'spam_rejected';

    case Temporary = 'temporary';

    case Unknown = 'unknown';

    case UserUnknown = 'user_unknown';
}
