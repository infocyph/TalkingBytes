<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\AddressFormatter;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

final readonly class MailFunctionTransport implements EmailTransport
{
    public function __construct(
        private RawEmailBuilder $rawEmailBuilder = new RawEmailBuilder(),
        private AddressFormatter $addressFormatter = new AddressFormatter(),
        private ?int $maxMessageBytes = null,
    ) {
        if ($this->maxMessageBytes !== null && $this->maxMessageBytes < 1) {
            throw new \InvalidArgumentException('mail() max message bytes must be greater than zero when provided.');
        }
    }

    public function send(EmailMessage $message): CommunicationResult
    {
        $message->assertReadyToSend();

        $rawEmail = $this->rawEmailBuilder->build($message, includeSubject: false);
        if ($this->maxMessageBytes !== null && $rawEmail->sizeBytes > $this->maxMessageBytes) {
            return CommunicationResult::failure(sprintf(
                'Email size %d bytes exceeds configured mail() max message size %d bytes.',
                $rawEmail->sizeBytes,
                $this->maxMessageBytes,
            ));
        }

        $messageId = $this->extractMessageId($rawEmail->headers);
        $subject = $this->addressFormatter->encodeMimeHeader($message->headersData()->subject);

        $recipients = array_map(
            static fn($address): string => $address->email,
            $message->envelope()->recipients(),
        );

        $sent = mail(
            implode(',', $recipients),
            $subject,
            $rawEmail->body,
            $rawEmail->headers,
            $this->envelopeSenderParameter($message),
        );

        $result = new EmailSendResult(
            'mail-function',
            $messageId,
            $sent ? $recipients : [],
            $sent ? [] : array_fill_keys($recipients, 'mail() transport failed.'),
            [
                'transport' => 'mail-function',
                'size_bytes' => $rawEmail->sizeBytes,
            ],
        );

        if (!$sent) {
            return CommunicationResult::failure(
                'mail() transport failed.',
                response: $result,
                metadata: $result->metadata,
            );
        }

        return CommunicationResult::success(response: $result, metadata: $result->metadata);
    }

    private function envelopeSenderParameter(EmailMessage $message): string
    {
        $sender = $message->envelope()->envelopeSender();
        if ($sender === null) {
            return '';
        }

        return sprintf('-f %s', escapeshellarg($sender->email));
    }

    private function extractMessageId(string $headers): ?string
    {
        if (preg_match('/^Message-ID:\s*(.+)$/mi', $headers, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
