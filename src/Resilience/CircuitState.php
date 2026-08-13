<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

enum CircuitState
{
    case Closed;

    case HalfOpen;

    case Open;
}
