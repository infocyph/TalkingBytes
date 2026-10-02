<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class OperationDeadlineEmailTransport implements EmailTransport
{
    public function __construct(
        private EmailTransport $innerTransport,
        private OperationDeadline $deadline,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        if ($this->deadline->expired()) {
            return $this->deadlineExceeded();
        }

        $result = $this->innerTransport->send($message);
        if ($this->deadline->expired() && ($result->metadata['deadline_exceeded'] ?? false) !== true) {
            return $this->deadlineExceeded();
        }

        return $result;
    }

    private function deadlineExceeded(): CommunicationResult
    {
        return CommunicationResult::failure(
            'Email operation deadline exceeded.',
            metadata: [
                'deadline_exceeded' => true,
                'attempts' => 0,
                'transport' => 'email',
            ],
        );
    }
}
