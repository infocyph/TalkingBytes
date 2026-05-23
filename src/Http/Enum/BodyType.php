<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Enum;

enum BodyType: string
{
    case Form = 'form';

    case Json = 'json';

    case Multipart = 'multipart';

    case Raw = 'raw';
}
