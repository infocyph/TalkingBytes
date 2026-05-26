<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use RuntimeException;

final class SequenceTransport implements TransportInterface
{
    /**
     * @param list<CommunicationResult> $sequence
     */
    public function __construct(private array $sequence = []) {}

    public function push(CommunicationResult $result): self
    {
        $this->sequence[] = $result;

        return $this;
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        unset($request);

        $next = array_shift($this->sequence);

        if ($next === null) {
            throw new RuntimeException('SequenceTransport has no more queued responses.');
        }

        return $next;
    }
}
