<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

use InvalidArgumentException;
use RuntimeException;

final class RateLimiter
{
    /** @var list<float> */
    private array $timestamps = [];

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $perSeconds,
    ) {
        if ($this->maxRequests < 1) {
            throw new InvalidArgumentException('maxRequests must be at least 1.');
        }

        if ($this->perSeconds < 1) {
            throw new InvalidArgumentException('perSeconds must be at least 1.');
        }
    }

    public function assertCanProceed(): void
    {
        $now = microtime(true);
        $windowStart = $now - $this->perSeconds;

        $activeTimestamps = [];
        foreach ($this->timestamps as $timestamp) {
            if ($timestamp >= $windowStart) {
                $activeTimestamps[] = $timestamp;
            }
        }

        $this->timestamps = $activeTimestamps;

        if (count($this->timestamps) >= $this->maxRequests) {
            throw new RuntimeException('Rate limit exceeded.');
        }

        $this->timestamps[] = $now;
    }
}
