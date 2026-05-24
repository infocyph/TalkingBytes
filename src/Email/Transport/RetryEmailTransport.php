<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\RetryExecutor;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class RetryEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private RetryPolicy $retryPolicy,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        return RetryExecutor::run($this->retryPolicy, fn(): CommunicationResult => $this->innerTransport->send($message));
    }
}
