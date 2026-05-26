<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum ContentTransferEncoding: string
{
    case Base64 = 'base64';

    case QuotedPrintable = 'quoted-printable';

    case SevenBit = '7bit';
}
