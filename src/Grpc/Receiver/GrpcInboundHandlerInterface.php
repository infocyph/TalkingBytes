<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Receiver;

interface GrpcInboundHandlerInterface
{
    public function handle(GrpcInboundRequest $request): GrpcInboundResponse;
}
