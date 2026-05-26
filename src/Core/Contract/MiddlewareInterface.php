<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Contract;

use Closure;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

interface MiddlewareInterface
{
    /**
     * @param Closure(CommunicationRequest): CommunicationResult $next
     */
    public function handle(CommunicationRequest $request, Closure $next): CommunicationResult;
}
