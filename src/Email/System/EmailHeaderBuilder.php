<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\System;

use DateTimeImmutable;
use DateTimeZone;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;
use InvalidArgumentException;

final readonly class EmailHeaderBuilder
{
    public function __construct(
        private AddressFormatter $addressFormatter = new AddressFormatter(),
        private HeaderFolder $headerFolder = new HeaderFolder(),
    ) {}

    public function build(EmailMessage $message, MimeMessage $mimeMessage, bool $includeSubject = true): string
    {
        $headerLines = $this->baseHeaders($message, $mimeMessage, $includeSubject);
        $headerLines = $this->addGeneralHeaders($message, $headerLines);
        $headerLines = $this->addListHeaders($message, $headerLines);
        $headerLines = $this->addMiscHeaders($message, $headerLines);
        $headerLines = $this->addMessageThreadHeaders($message, $headerLines);
        $headerLines = $this->addDkimHeaders($message, $headerLines);
        $headerLines = $this->addCustomHeaders($message, $headerLines);

        $rendered = implode("\r\n", array_map($this->headerFolder->fold(...), $headerLines));
        $this->assertHeaderBounds($rendered, count($headerLines));

        return $rendered;
    }

    public function resolveMessageId(EmailMessage $message): ?string
    {
        $from = $message->envelope()->from;

        if ($from === null) {
            return null;
        }

        return $this->normalizeMessageId($message->headersData()->messageId, $from);
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addCustomHeaders(EmailMessage $message, array $headerLines): array
    {
        foreach ($message->headersData()->customHeaders as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $lineValue) {
                    $headerLines[] = sprintf('%s: %s', $name, $lineValue);
                }

                continue;
            }

            $headerLines[] = sprintf('%s: %s', $name, $value);
        }

        return $headerLines;
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addDkimHeaders(EmailMessage $message, array $headerLines): array
    {
        foreach ($message->dkimSignatures() as $signature) {
            $headerLines[] = 'DKIM-Signature: ' . $signature;
        }

        return $headerLines;
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addGeneralHeaders(EmailMessage $message, array $headerLines): array
    {
        $envelope = $message->envelope();
        $headers = $message->headersData();

        if (count($envelope->cc) > 0) {
            $headerLines[] = 'Cc: ' . $this->addressFormatter->formatList($envelope->cc);
        }

        if ($headers->priority !== null) {
            $headerLines[] = 'X-Priority: ' . $headers->priority->value;
        }

        if ($headers->language !== '') {
            HeaderValueGuard::assertNoCrlf($headers->language, 'Content-Language');
            $headerLines[] = 'Content-Language: ' . $headers->language;
        }

        $headerLines[] = 'X-Mailer: ' . ($headers->mailer !== '' ? $headers->mailer : 'TalkingBytes');

        return $headerLines;
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addListHeaders(EmailMessage $message, array $headerLines): array
    {
        $headers = $message->headersData();
        $listValues = [
            'List-Id' => $headers->listId,
            'List-Unsubscribe' => $headers->listUnsubscribe,
            'List-Unsubscribe-Post' => $headers->listUnsubscribePost,
            'List-Subscribe' => $headers->listSubscribe,
            'List-Archive' => $headers->listArchive,
        ];

        foreach ($listValues as $headerName => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($headerName === 'List-Unsubscribe-Post') {
                $headerLines[] = sprintf('%s: %s', $headerName, $value);

                continue;
            }

            $headerLines[] = sprintf('%s: <%s>', $headerName, trim($value, '<>'));
        }

        return $headerLines;
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addMessageThreadHeaders(EmailMessage $message, array $headerLines): array
    {
        $envelope = $message->envelope();
        $headers = $message->headersData();
        $from = $envelope->from;

        if ($from === null) {
            throw new InvalidArgumentException('Cannot build message thread headers without a From address.');
        }

        $messageId = $this->normalizeMessageId($headers->messageId, $from);
        if ($messageId !== null) {
            $headerLines[] = 'Message-ID: ' . $messageId;
        }

        $inReplyTo = $this->normalizeMessageId($headers->inReplyTo, $from, false);
        if ($inReplyTo !== null) {
            $headerLines[] = 'In-Reply-To: ' . $inReplyTo;
        }

        $references = [];
        foreach ($headers->references as $reference) {
            $normalized = $this->normalizeMessageId($reference, $from, false);
            if ($normalized !== null) {
                $references[] = $normalized;
            }
        }

        if (count($references) > 0) {
            $headerLines[] = 'References: ' . implode(' ', $references);
        }

        return $headerLines;
    }

    /**
     * @param list<string> $headerLines
     * @return list<string>
     */
    private function addMiscHeaders(EmailMessage $message, array $headerLines): array
    {
        $headers = $message->headersData();

        if ($headers->confirmedOptIn !== null) {
            $headerLines[] = 'X-Confirmed-OptIn: ' . ($headers->confirmedOptIn ? 'Yes' : 'No');
        }

        if ($headers->spamStatus !== null && $headers->spamStatus !== '') {
            $headerLines[] = 'X-Spam-Status: ' . $headers->spamStatus;
        }

        if ($headers->organization !== null && $headers->organization !== '') {
            $headerLines[] = 'Organization: ' . $headers->organization;
        }

        if ($headers->dispositionNotificationTo !== null) {
            $headerLines[] = 'Disposition-Notification-To: '
                . $this->addressFormatter->format($headers->dispositionNotificationTo);
        }

        return $headerLines;
    }

    private function assertHeaderBounds(string $headers, int $fieldCount): void
    {
        if (strlen($headers) > 131_072 || $fieldCount > 2_000) {
            throw new InvalidArgumentException('Outbound email headers exceed configured protocol bounds.');
        }

        foreach (explode("\r\n", $headers) as $line) {
            if (strlen($line) > 998) {
                throw new InvalidArgumentException('Outbound email header line exceeds 998 bytes.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function baseHeaders(EmailMessage $message, MimeMessage $mimeMessage, bool $includeSubject): array
    {
        $envelope = $message->envelope();
        $headers = $message->headersData();

        if ($envelope->from === null) {
            throw new InvalidArgumentException('Cannot build headers without a From address.');
        }

        $headerLines = [
            'Date: ' . ($message->preparedDateHeader()
                ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('r')),
            'From: ' . $this->addressFormatter->format($envelope->from),
            'To: ' . $this->formatToHeaderValue($envelope->to),
            'MIME-Version: 1.0',
            'Content-Type: ' . $mimeMessage->contentType,
        ];

        if ($mimeMessage->contentTransferEncoding !== null) {
            $headerLines[] = 'Content-Transfer-Encoding: ' . $mimeMessage->contentTransferEncoding->value;
        }

        if ($headers->sender !== null) {
            $headerLines[] = 'Sender: ' . $this->addressFormatter->format($headers->sender);
        }

        if ($headers->replyTo !== null) {
            $headerLines[] = 'Reply-To: ' . $this->addressFormatter->format($headers->replyTo);
        }

        if ($includeSubject) {
            $headerLines[] = 'Subject: ' . $this->addressFormatter->encodeMimeHeader($headers->subject);
        }

        return $headerLines;
    }

    /**
     * @param list<EmailAddress> $toAddresses
     */
    private function formatToHeaderValue(array $toAddresses): string
    {
        if (count($toAddresses) === 0) {
            return 'undisclosed-recipients:;';
        }

        return $this->addressFormatter->formatList($toAddresses);
    }

    private function normalizeMessageId(?string $messageId, EmailAddress $from, bool $generateIfMissing = true): ?string
    {
        $value = trim((string) $messageId);
        if ($value === '') {
            if (!$generateIfMissing) {
                return null;
            }

            $domain = substr(strrchr($from->email, '@') ?: '@localhost', 1);

            return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
        }

        if (preg_match('/^<[^\s<>@]+@[^\s<>@]+>$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^[^\s<>@]+@[^\s<>@]+$/', $value) === 1) {
            return sprintf('<%s>', $value);
        }

        throw new InvalidArgumentException(sprintf('Invalid Message-ID value: %s', $value));
    }
}
