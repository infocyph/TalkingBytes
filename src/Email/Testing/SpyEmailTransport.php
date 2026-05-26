<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Testing;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;

final class SpyEmailTransport implements EmailTransport
{
    /**
     * @var list<EmailMessage>
     */
    private array $sentMessages = [];

    public function __construct(private readonly EmailTransport $innerTransport) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        $this->sentMessages[] = $message;

        return $this->innerTransport->send($message);
    }

    /**
     * @return list<EmailMessage>
     */
    public function sentMessages(): array
    {
        return $this->sentMessages;
    }
}
