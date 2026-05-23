<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Core\Contract;

use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

interface TransportInterface
{
    public function send(CommunicationRequest $request): CommunicationResult;
}
