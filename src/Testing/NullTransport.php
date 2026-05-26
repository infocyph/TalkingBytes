<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Testing;

use Infocyph\TalkingBytes\Core\Contract\TransportInterface;
use Infocyph\TalkingBytes\Core\Message\CommunicationRequest;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;

final class NullTransport implements TransportInterface
{
    public function send(CommunicationRequest $request): CommunicationResult
    {
        unset($request);

        return CommunicationResult::success(metadata: ['transport' => 'null']);
    }
}
