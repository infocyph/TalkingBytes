<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final class FakeTransport implements TransportInterface
{
    private readonly SequenceTransport $sequence;

    /** @var list<CommunicationRequest> */
    private array $requests = [];

    public function __construct(?SequenceTransport $sequence = null)
    {
        $this->sequence = $sequence ?? new SequenceTransport();
    }

    public static function sequence(): self
    {
        return new self(new SequenceTransport());
    }

    public function pushFailure(string $error, ?int $statusCode = null, mixed $response = null): self
    {
        $this->sequence->push(CommunicationResult::failure($error, $statusCode, $response));

        return $this;
    }

    public function pushSuccess(?int $statusCode = null, mixed $response = null): self
    {
        $this->sequence->push(CommunicationResult::success($statusCode, $response));

        return $this;
    }

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

        return $this->sequence->send($request);
    }
}
