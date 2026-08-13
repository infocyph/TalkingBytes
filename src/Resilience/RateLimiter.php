<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

use Infocyph\TalkingBytes\Core\Support\Clock;
use InvalidArgumentException;
use RuntimeException;

final class RateLimiter
{
    private readonly Clock $clock;

    private float $lastRefill;

    private float $tokens;

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $perSeconds,
        ?Clock $clock = null,
    ) {
        if ($this->maxRequests < 1) {
            throw new InvalidArgumentException('maxRequests must be at least 1.');
        }

        if ($this->perSeconds < 1) {
            throw new InvalidArgumentException('perSeconds must be at least 1.');
        }

        $this->clock = $clock ?? Clock::system();
        $this->lastRefill = $this->clock->monotonic();
        $this->tokens = (float) $this->maxRequests;
    }

    public function assertCanProceed(): void
    {
        $now = $this->clock->monotonic();
        $effectiveNow = max($now, $this->lastRefill);
        $elapsed = $effectiveNow - $this->lastRefill;
        $this->tokens = min(
            (float) $this->maxRequests,
            $this->tokens + ($elapsed * $this->maxRequests / $this->perSeconds),
        );
        $this->lastRefill = $effectiveNow;

        if ($this->tokens < 1.0) {
            throw new RuntimeException('Rate limit exceeded.');
        }

        $this->tokens--;
    }
}
