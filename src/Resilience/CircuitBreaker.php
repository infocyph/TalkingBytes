<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

use InvalidArgumentException;
use RuntimeException;

final class CircuitBreaker
{
    private int $failureCount = 0;

    private ?int $openedAt = null;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $coolDownSeconds = 30,
    ) {
        if ($this->failureThreshold < 1) {
            throw new InvalidArgumentException('failureThreshold must be at least 1.');
        }

        if ($this->coolDownSeconds < 1) {
            throw new InvalidArgumentException('coolDownSeconds must be at least 1.');
        }
    }

    public function assertCanProceed(): void
    {
        if ($this->openedAt === null) {
            return;
        }

        if ((time() - $this->openedAt) >= $this->coolDownSeconds) {
            $this->openedAt = null;
            $this->failureCount = 0;

            return;
        }

        throw new RuntimeException('Circuit breaker is open.');
    }

    public function onFailure(): void
    {
        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->openedAt = time();
        }
    }

    public function onSuccess(): void
    {
        $this->failureCount = 0;
        $this->openedAt = null;
    }
}
