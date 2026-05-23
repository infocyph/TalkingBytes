<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;

final readonly class LoggingEmailTransport implements EmailTransport
{
    /**
     * @param callable(string, array<string,mixed>):void $logger
     */
    public function __construct(
        private EmailTransport $innerTransport,
        private mixed $logger,
    ) {}

    public function send(EmailMessage $message): CommunicationResult
    {
        ($this->logger)('email.send.start', [
            'to_count' => count($message->envelope()->to),
            'cc_count' => count($message->envelope()->cc),
            'bcc_count' => count($message->envelope()->bcc),
            'subject' => $message->headersData()->subject,
        ]);

        $result = $this->innerTransport->send($message);

        ($this->logger)('email.send.finish', [
            'successful' => $result->successful,
            'error' => $result->error,
            'metadata' => $result->metadata,
        ]);

        return $result;
    }
}
