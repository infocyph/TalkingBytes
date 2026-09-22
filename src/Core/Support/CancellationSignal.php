<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Closure;

final readonly class CancellationSignal
{
    /** @var Closure(): bool */
    private Closure $requested;

    /** @param callable(): bool $requested */
    public function __construct(callable $requested)
    {
        $this->requested = Closure::fromCallable($requested);
    }

    public static function fromCallable(callable $requested): self
    {
        return new self($requested);
    }

    public static function never(): self
    {
        return new self(static fn(): bool => false);
    }

    public function isRequested(): bool
    {
        return (bool) ($this->requested)();
    }
}
