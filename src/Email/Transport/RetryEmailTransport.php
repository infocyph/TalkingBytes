<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\RetryExecutor;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Retry\RetryPolicy;

final readonly class RetryEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private RetryPolicy $retryPolicy,
        private ?CancellationSignal $cancellation = null,
        private ?Sleeper $sleeper = null,
        private ?OperationDeadline $deadline = null,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        return RetryExecutor::run(
            $this->retryPolicy,
            fn(): CommunicationResult => $this->innerTransport->send($message),
            sleeper: $this->sleeper,
            cancellation: $this->cancellation,
            deadline: $this->deadline,
        );
    }
}
