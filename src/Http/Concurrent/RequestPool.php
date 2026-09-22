<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class RequestPool
{
    public function __construct(
        private CurlMultiTransport $transport,
        private int $maxConcurrency = 10,
        private bool $stopOnFailure = false,
        private ?CancellationSignal $cancellation = null,
    ) {}

    public function maxConcurrency(int $maxConcurrency): self
    {
        return new self(
            $this->transport,
            $maxConcurrency,
            $this->stopOnFailure,
            $this->cancellation,
        );
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(array $requests): PoolResult
    {
        return $this->transport->sendMany(
            $requests,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $this->cancellation,
        );
    }

    public function stopSchedulingOnFailure(bool $enabled = true): self
    {
        return new self(
            $this->transport,
            $this->maxConcurrency,
            $enabled,
            $this->cancellation,
        );
    }

    public function withCancellation(?CancellationSignal $cancellation): self
    {
        return new self(
            $this->transport,
            $this->maxConcurrency,
            $this->stopOnFailure,
            $cancellation,
        );
    }
}
