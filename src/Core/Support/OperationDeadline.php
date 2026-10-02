<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use InvalidArgumentException;

final readonly class OperationDeadline
{
    private function __construct(
        private Clock $clock,
        private float $deadlineAt,
    ) {}

    public static function after(float $seconds, ?Clock $clock = null): self
    {
        if (! is_finite($seconds) || $seconds <= 0.0) {
            throw new InvalidArgumentException('Operation deadline duration must be finite and greater than zero.');
        }

        $clock ??= Clock::system();
        $deadlineAt = $clock->monotonic() + $seconds;
        if (! is_finite($deadlineAt)) {
            throw new InvalidArgumentException('Operation deadline is not representable.');
        }

        return new self($clock, $deadlineAt);
    }

    public function expired(): bool
    {
        return $this->remainingSeconds() <= 0.0;
    }

    public function remainingMicroseconds(): int
    {
        return $this->remainingUnits(1_000_000);
    }

    public function remainingMilliseconds(): int
    {
        return $this->remainingUnits(1_000);
    }

    public function remainingSeconds(): float
    {
        return max(0.0, $this->deadlineAt - $this->clock->monotonic());
    }

    private function remainingUnits(int $unitsPerSecond): int
    {
        $remaining = $this->remainingSeconds();
        if ($remaining <= 0.0) {
            return 0;
        }

        $scaled = $remaining * $unitsPerSecond;
        if ($scaled >= PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return max(1, (int) ceil($scaled));
    }
}
