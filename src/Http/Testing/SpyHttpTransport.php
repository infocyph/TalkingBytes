<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

final class SpyHttpTransport implements TransportInterface
{
    /**
     * @var list<HttpRequest>
     */
    private array $sentRequests = [];

    public function __construct(private readonly TransportInterface $inner) {}

    public function send(CommunicationRequest $request): CommunicationResult
    {
        if ($request->payload instanceof HttpRequest) {
            $this->sentRequests[] = $request->payload;
        }

        return $this->inner->send($request);
    }

    /**
     * @return list<HttpRequest>
     */
    public function sentRequests(): array
    {
        return $this->sentRequests;
    }
}
