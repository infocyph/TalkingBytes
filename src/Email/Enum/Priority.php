<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Enum;

enum Priority: int
{
    case High = 1;

    case Low = 5;

    case Normal = 3;
}
