<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Transport;

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\System\AddressFormatter;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;

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
        $message = $message->prepare();

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
            static fn(EmailAddress $address): string => $address->email,
            $message->envelope()->recipients(),
        );

        $availabilityError = $this->mailFunctionAvailabilityError();
        if ($availabilityError !== null) {
            $result = $this->failedResult(
                $messageId,
                $recipients,
                $rawEmail->sizeBytes,
                ['mail_unavailable' => $availabilityError],
            );

            return CommunicationResult::failure(
                'mail() transport failed.',
                response: $result,
                metadata: $result->metadata,
            );
        }

        $sent = $this->invokeMail(
            implode(',', $recipients),
            $subject,
            $rawEmail->body,
            $rawEmail->headers,
            $this->envelopeSenderParameter($message),
        );

        $result = $sent
            ? $this->successResult($messageId, $recipients, $rawEmail->sizeBytes)
            : $this->failedResult($messageId, $recipients, $rawEmail->sizeBytes);

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

    /**
     * @param list<string> $recipients
     * @param array<string, mixed> $extraMetadata
     */
    private function failedResult(
        ?string $messageId,
        array $recipients,
        int $sizeBytes,
        array $extraMetadata = [],
    ): EmailSendResult {
        return new EmailSendResult(
            'mail-function',
            $messageId,
            [],
            array_fill_keys($recipients, 'mail() transport failed.'),
            array_merge(
                [
                    'transport' => 'mail-function',
                    'size_bytes' => $sizeBytes,
                ],
                $extraMetadata,
            ),
        );
    }

    private function invokeMail(
        string $to,
        string $subject,
        string $body,
        string $headers,
        string $parameters,
    ): bool {
        set_error_handler(
            static fn(): bool => true,
            E_WARNING,
        );

        try {
            return mail($to, $subject, $body, $headers, $parameters);
        } finally {
            restore_error_handler();
        }
    }

    private function mailFunctionAvailabilityError(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $sendmailPath = (string) ini_get('sendmail_path');
        if ($sendmailPath === '') {
            return null;
        }

        if (preg_match('/^\s*(?:"([^"]+)"|\'([^\']+)\'|(\S+))/', $sendmailPath, $matches) !== 1) {
            return null;
        }

        $binary = $matches[1] !== '' ? $matches[1] : ($matches[2] !== '' ? $matches[2] : $matches[3]);
        if ($binary[0] !== '/') {
            return null;
        }

        if (!is_file($binary)) {
            return sprintf('sendmail binary does not exist: %s', $binary);
        }

        if (!is_executable($binary)) {
            return sprintf('sendmail binary is not executable: %s', $binary);
        }

        return null;
    }

    /**
     * @param list<string> $recipients
     */
    private function successResult(?string $messageId, array $recipients, int $sizeBytes): EmailSendResult
    {
        return new EmailSendResult(
            'mail-function',
            $messageId,
            $recipients,
            [],
            [
                'transport' => 'mail-function',
                'size_bytes' => $sizeBytes,
            ],
        );
    }
}
