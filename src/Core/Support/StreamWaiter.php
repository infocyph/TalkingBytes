<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Support;

use Closure;
use InvalidArgumentException;

final readonly class StreamWaiter
{
    /** @var Closure(resource, ?OperationDeadline): bool */
    private Closure $readable;

    /** @var Closure(resource, ?OperationDeadline): bool */
    private Closure $writable;

    /**
     * @param callable(resource, ?OperationDeadline): bool $readable
     * @param callable(resource, ?OperationDeadline): bool $writable
     */
    public function __construct(callable $readable, callable $writable)
    {
        $this->readable = Closure::fromCallable($readable);
        $this->writable = Closure::fromCallable($writable);
    }

    /**
     * @param resource $stream
     */
    public function waitReadable(mixed $stream, ?OperationDeadline $deadline = null): bool
    {
        $this->assertStream($stream);

        return (bool) ($this->readable)($stream, $deadline);
    }

    /**
     * @param resource $stream
     */
    public function waitWritable(mixed $stream, ?OperationDeadline $deadline = null): bool
    {
        $this->assertStream($stream);

        return (bool) ($this->writable)($stream, $deadline);
    }

    private function assertStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream waiter requires a stream resource.');
        }
    }
}
