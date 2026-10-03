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
        if (!is_finite($seconds) || $seconds <= 0.0) {
            throw new InvalidArgumentException('Operation deadline duration must be finite and greater than zero.');
        }

        $clock ??= Clock::system();

        return self::at($clock->monotonic() + $seconds, $clock);
    }

    public static function at(float $monotonicSeconds, ?Clock $clock = null): self
    {
        if (!is_finite($monotonicSeconds) || $monotonicSeconds < 0.0) {
            throw new InvalidArgumentException('Operation deadline must be a finite non-negative monotonic timestamp.');
        }

        return new self($clock ?? Clock::system(), $monotonicSeconds);
    }

    public function earliest(self $other): self
    {
        return $this->remainingSeconds() <= $other->remainingSeconds()
            ? $this
            : $other;
    }

    /** @phpstan-impure */
    public function expired(): bool
    {
        return $this->remainingSeconds() <= 0.0;
    }

    /** @phpstan-impure */
    public function remainingMicroseconds(): int
    {
        return $this->remainingUnits(1_000_000);
    }

    /** @phpstan-impure */
    public function remainingMilliseconds(): int
    {
        return $this->remainingUnits(1_000);
    }

    /** @phpstan-impure */
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
