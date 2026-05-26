<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Concurrent;

use Infocyph\TalkingBytes\Http\HttpRequest;

final readonly class RequestPool
{
    public function __construct(
        private CurlMultiTransport $transport,
        private int $maxConcurrency = 10,
        private bool $failFast = false,
    ) {}

    public function failFast(bool $enabled = true): self
    {
        return new self($this->transport, $this->maxConcurrency, $enabled);
    }

    public function maxConcurrency(int $maxConcurrency): self
    {
        return new self($this->transport, $maxConcurrency, $this->failFast);
    }

    /**
     * @param array<int|string, HttpRequest> $requests
     */
    public function sendMany(array $requests): PoolResult
    {
        return $this->transport->sendMany($requests, $this->maxConcurrency, $this->failFast);
    }
}
