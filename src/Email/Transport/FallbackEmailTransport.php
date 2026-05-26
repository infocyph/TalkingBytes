<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class FallbackEmailTransport implements EmailTransport
{
    private CompositeEmailTransport $composite;

    /**
     * @param list<EmailTransport> $fallbackTransports
     */
    public function __construct(EmailTransport $primaryTransport, array $fallbackTransports = [])
    {
        $this->composite = new CompositeEmailTransport($primaryTransport, $fallbackTransports);
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        return $this->composite->send($message);
    }
}
