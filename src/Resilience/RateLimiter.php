<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

use RuntimeException;

final class RateLimiter
{
    /** @var list<float> */
    private array $timestamps = [];

    public function __construct(
        private readonly int $maxRequests,
        private readonly int $perSeconds,
    ) {}

    public function assertCanProceed(): void
    {
        $now = microtime(true);
        $windowStart = $now - $this->perSeconds;

        $this->timestamps = array_values(
            array_filter($this->timestamps, static fn(float $timestamp): bool => $timestamp >= $windowStart),
        );

        if (count($this->timestamps) >= $this->maxRequests) {
            throw new RuntimeException('Rate limit exceeded.');
        }

        $this->timestamps[] = $now;
    }
}
