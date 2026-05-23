<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final class SpyTransport implements TransportInterface
{
    /** @var list<CommunicationRequest> */
    private array $requests = [];

    public function __construct(private readonly TransportInterface $inner) {}

    /**
     * @return list<CommunicationRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function send(CommunicationRequest $request): CommunicationResult
    {
        $this->requests[] = $request;

        return $this->inner->send($request);
    }
}
