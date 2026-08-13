<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Resilience;

use Infocyph\TalkingBytes\Core\Support\Clock;
use InvalidArgumentException;
use RuntimeException;

final class CircuitBreaker
{
    private readonly Clock $clock;

    private int $failureCount = 0;

    private ?float $openedAt = null;

    private bool $probeInFlight = false;

    private CircuitState $state = CircuitState::Closed;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $coolDownSeconds = 30,
        ?Clock $clock = null,
    ) {
        if ($this->failureThreshold < 1) {
            throw new InvalidArgumentException('failureThreshold must be at least 1.');
        }

        if ($this->coolDownSeconds < 1) {
            throw new InvalidArgumentException('coolDownSeconds must be at least 1.');
        }

        $this->clock = $clock ?? Clock::system();
    }

    public function assertCanProceed(): void
    {
        if ($this->state === CircuitState::Closed) {
            return;
        }

        if ($this->state === CircuitState::Open && $this->cooldownElapsed()) {
            $this->state = CircuitState::HalfOpen;
        }

        if ($this->state === CircuitState::HalfOpen && !$this->probeInFlight) {
            $this->probeInFlight = true;

            return;
        }

        throw new RuntimeException('Circuit breaker is open.');
    }

    public function onFailure(): void
    {
        if ($this->state === CircuitState::HalfOpen) {
            $this->open();

            return;
        }

        $this->failureCount++;

        if ($this->failureCount >= $this->failureThreshold) {
            $this->open();
        }
    }

    public function onSuccess(): void
    {
        $this->failureCount = 0;
        $this->openedAt = null;
        $this->probeInFlight = false;
        $this->state = CircuitState::Closed;
    }

    public function state(): CircuitState
    {
        return $this->state;
    }

    private function cooldownElapsed(): bool
    {
        return $this->openedAt !== null
            && ($this->clock->monotonic() - $this->openedAt) >= $this->coolDownSeconds;
    }

    private function open(): void
    {
        $this->state = CircuitState::Open;
        $this->openedAt = $this->clock->monotonic();
        $this->probeInFlight = false;
    }
}
