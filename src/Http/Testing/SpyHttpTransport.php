<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Testing;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\Contract\HttpTransport;
use Infocyph\TalkingBytes\Http\HttpRequest;

final class SpyHttpTransport implements HttpTransport
{
    /**
     * @var list<HttpRequest>
     */
    private array $sentRequests = [];

    public function __construct(private readonly HttpTransport $inner) {}

    public function send(HttpRequest $request): CommunicationResult
    {
        $this->sentRequests[] = $request;

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
