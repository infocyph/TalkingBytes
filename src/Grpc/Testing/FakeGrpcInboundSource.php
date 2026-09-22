<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Testing;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundExchange;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest;
use Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundSource;

final class FakeGrpcInboundSource implements GrpcInboundSource
{
    /** @var list<FakeGrpcInboundExchange> */
    private array $accepted = [];

    /** @var list<FakeGrpcInboundExchange> */
    private array $pending = [];

    public function accept(?CancellationSignal $cancellation = null): ?GrpcInboundExchange
    {
        if ($cancellation?->isRequested() === true) {
            return null;
        }

        $exchange = array_shift($this->pending);
        if (!$exchange instanceof FakeGrpcInboundExchange) {
            return null;
        }

        $this->accepted[] = $exchange;

        return $exchange;
    }

    /** @return list<FakeGrpcInboundExchange> */
    public function accepted(): array
    {
        return $this->accepted;
    }

    public function enqueue(GrpcInboundRequest $request): FakeGrpcInboundExchange
    {
        $exchange = new FakeGrpcInboundExchange($request);
        $this->pending[] = $exchange;

        return $exchange;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }
}
