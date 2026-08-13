<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;
use RuntimeException;

final class SequenceHttpTransport implements HttpTransport
{
    /**
     * @var list<HttpRequest>
     */
    private array $sentRequests = [];

    /**
     * @param list<CommunicationResult> $sequence
     */
    public function __construct(private array $sequence = []) {}

    public function push(CommunicationResult $result): self
    {
        $this->sequence[] = $result;

        return $this;
    }

    public function send(HttpRequest $request): CommunicationResult
    {
        $this->sentRequests[] = $request;

        $next = array_shift($this->sequence);
        if ($next === null) {
            throw new RuntimeException('SequenceHttpTransport has no more queued responses.');
        }

        return $next;
    }

    /**
     * @return list<HttpRequest>
     */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }
}
