<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Testing;

use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse;
use LogicException;

final class FakeGrpcInboundExchange implements GrpcInboundExchange
{
    private bool $completed = false;

    private ?GrpcInboundResponse $response = null;

    public function __construct(private readonly GrpcInboundRequest $inboundRequest) {}

    public function complete(GrpcInboundResponse $response): void
    {
        if ($this->completed) {
            throw new LogicException('Inbound gRPC exchange has already been completed.');
        }

        $this->response = $response;
        $this->completed = true;
    }

    public function completed(): bool
    {
        return $this->completed;
    }

    public function request(): GrpcInboundRequest
    {
        return $this->inboundRequest;
    }

    public function response(): ?GrpcInboundResponse
    {
        return $this->response;
    }
}
