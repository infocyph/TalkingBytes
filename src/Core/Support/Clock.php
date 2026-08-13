<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Clock
{
    /** @var Closure(): float */
    private Closure $monotonicTime;

    /** @var Closure(): float */
    private Closure $wallTime;

    /**
     * @param callable(): float $wallTime
     * @param null|callable(): float $monotonicTime
     */
    public function __construct(callable $wallTime, ?callable $monotonicTime = null)
    {
        $this->wallTime = Closure::fromCallable($wallTime);
        $this->monotonicTime = Closure::fromCallable($monotonicTime ?? $wallTime);
    }

    public static function fixed(float $timestamp): self
    {
        self::assertFinite($timestamp);

        return new self(static fn(): float => $timestamp);
    }

    public static function system(): self
    {
        return new self(static fn(): float => microtime(true), static fn(): float => hrtime(true) / 1_000_000_000);
    }

    public function monotonic(): float
    {
        $value = ($this->monotonicTime)();
        self::assertFinite($value);

        return $value;
    }

    public function now(): DateTimeImmutable
    {
        $timestamp = $this->timestamp();
        $seconds = (int) floor($timestamp);
        $microseconds = (int) (($timestamp - $seconds) * 1_000_000);

        return DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%06d', $seconds, $microseconds),
            new DateTimeZone('UTC'),
        ) ?: throw new InvalidArgumentException('Clock returned an invalid timestamp.');
    }

    public function timestamp(): float
    {
        $value = ($this->wallTime)();
        self::assertFinite($value);

        return $value;
    }

    private static function assertFinite(float $value): void
    {
        if (!is_finite($value)) {
            throw new InvalidArgumentException('Clock values must be finite.');
        }
    }
}
