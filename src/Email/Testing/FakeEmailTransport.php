<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Testing;

use Closure;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;

final class FakeEmailTransport implements EmailTransport
{
    /**
     * @var list<CommunicationResult>
     */
    private array $queuedResults = [];

    /**
     * @var list<EmailMessage>
     */
    private array $sentMessages = [];

    public function pushResult(CommunicationResult $result): self
    {
        $this->queuedResults[] = $result;

        return $this;
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();
        $this->sentMessages[] = $message;

        if ($this->queuedResults === []) {
            return CommunicationResult::success(metadata: ['transport' => 'fake-email']);
        }

        return array_shift($this->queuedResults);
    }

    /**
     * @return list<EmailMessage>
     */
    public function sentMessages(): array
    {
        return $this->sentMessages;
    }

    public function wasSent(Closure $predicate): bool
    {
        return array_any($this->sentMessages, fn($message) => $predicate($message) === true);
    }
}
