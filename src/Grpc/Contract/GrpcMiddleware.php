<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Grpc\Contract;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;

interface GrpcMiddleware
{
    /** @param Closure(GrpcRequest): CommunicationResult $next */
    public function handle(GrpcRequest $request, Closure $next): CommunicationResult;
}
