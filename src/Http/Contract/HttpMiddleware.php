<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Contract;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

interface HttpMiddleware
{
    /** @param Closure(HttpRequest): CommunicationResult $next */
    public function handle(HttpRequest $request, Closure $next): CommunicationResult;
}
