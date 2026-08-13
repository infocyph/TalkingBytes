<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Http\Contract;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpRequest;

interface HttpTransport
{
    public function send(HttpRequest $request): CommunicationResult;
}
