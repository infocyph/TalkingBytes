<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;
use RuntimeException;

final class SequenceHttpTransport implements TransportInterface
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

    public function send(CommunicationRequest $request): CommunicationResult
    {
        if (!$request->payload instanceof HttpRequest) {
            return CommunicationResult::failure('SequenceHttpTransport expects HttpRequest payload.');
        }

        $this->sentRequests[] = $request->payload;

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
