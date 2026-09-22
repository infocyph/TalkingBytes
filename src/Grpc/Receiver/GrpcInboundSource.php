<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Receiver;

use Infocyph\TalkingBytes\Core\Support\CancellationSignal;

interface GrpcInboundSource
{
    public function accept(?CancellationSignal $cancellation = null): ?GrpcInboundExchange;
}
