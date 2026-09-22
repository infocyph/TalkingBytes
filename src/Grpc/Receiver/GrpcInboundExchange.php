<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Receiver;

interface GrpcInboundExchange
{
    public function complete(GrpcInboundResponse $response): void;

    public function request(): GrpcInboundRequest;
}
