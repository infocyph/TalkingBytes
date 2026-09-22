<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Core\Support\ObservabilitySanitizer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Throwable;

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
        ]);

        try {
            $result = $this->innerTransport->send($message);
        } catch (Throwable $throwable) {
            ($this->logger)('email.send.finish', [
                'successful' => false,
                ...ObservabilitySanitizer::throwableContext($throwable),
            ]);

            throw $throwable;
        }

        ($this->logger)('email.send.finish', ObservabilitySanitizer::resultContext($result));

        return $result;
    }
}
