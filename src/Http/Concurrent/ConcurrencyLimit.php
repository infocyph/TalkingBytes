<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use InvalidArgumentException;

final readonly class ConcurrencyLimit
{
    public function __construct(public int $value)
    {
        if ($this->value < 1) {
            throw new InvalidArgumentException('Concurrency limit must be at least 1.');
        }
    }
}
