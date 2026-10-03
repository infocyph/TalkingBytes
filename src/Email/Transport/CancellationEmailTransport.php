<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class CancellationEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private CancellationSignal $cancellation,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        if ($this->cancellation->isRequested()) {
            return CommunicationResult::failure(
                'Email operation cancelled.',
                metadata: [
                    'cancelled' => true,
                    'attempts' => 0,
                    'transport' => 'email',
                ],
            );
        }

        return $this->innerTransport->send($message);
    }
}
