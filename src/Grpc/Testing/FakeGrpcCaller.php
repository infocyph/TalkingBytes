<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Testing;

use Infocyph\TalkingBytes\Grpc\GrpcRequest;
use Infocyph\TalkingBytes\Grpc\GrpcResponse;
use Infocyph\TalkingBytes\Grpc\GrpcStatus;
use LogicException;

final class FakeGrpcCaller
{
    /** @var list<GrpcResponse> */
    private array $queuedResponses = [];

    /** @var list<GrpcRequest> */
    private array $requests = [];

    public function __invoke(GrpcRequest $request): GrpcResponse
    {
        $this->requests[] = $request;

        if ($this->queuedResponses === []) {
            throw new LogicException('No fake gRPC response queued.');
        }

        return array_shift($this->queuedResponses);
    }

    public function assert(): AssertableGrpcCaller
    {
        return new AssertableGrpcCaller($this);
    }

    public function push(GrpcResponse $response): self
    {
        $this->queuedResponses[] = $response;

        return $this;
    }

    public function pushOk(mixed $message = null): self
    {
        return $this->push(new GrpcResponse(GrpcStatus::Ok, $message));
    }

    public function pushStatus(GrpcStatus $status, mixed $message = null): self
    {
        return $this->push(new GrpcResponse($status, $message));
    }

    /**
     * @return list<GrpcRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
