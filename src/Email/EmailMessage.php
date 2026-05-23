<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use Infocyph\TalkingBytes\Email\Enum\Priority;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;
use Infocyph\TalkingBytes\Email\ValueObject\EmailEnvelope;
use Infocyph\TalkingBytes\Email\ValueObject\EmailHeaders;

final readonly class EmailMessage
{
    /**
     * @param list<EmailAttachment> $attachments
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        private EmailEnvelope $envelope,
        private EmailHeaders $headers,
        private string $htmlBody,
        private string $textBody,
        private array $attachments,
        private array $metadata,
    ) {}

    public static function new(): self
    {
        return new self(new EmailEnvelope(), new EmailHeaders(), '', '', [], []);
    }

    public function assertReadyToSend(): void
    {
        $this->envelope->assertCanSend();
        $this->headers->assertCanSend();

        if ($this->htmlBody === '' && $this->textBody === '') {
            throw new \LogicException('Email message body is required before sending.');
        }
    }

    public function attach(string $filePath, ?string $filename = null, int $maxSizeBytes = 26214400): self
    {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromPath($filePath, $filename, $maxSizeBytes);

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    public function attachData(
        string $content,
        string $name,
        string $mimeType = 'application/octet-stream',
        int $maxSizeBytes = 26214400,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromData($content, $name, $mimeType, maxSizeBytes: $maxSizeBytes);

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    public function attachInline(string $filePath, string $contentId, ?string $filename = null, int $maxSizeBytes = 26214400): self
    {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromPath($filePath, $filename, $maxSizeBytes, 'inline', $contentId);

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    public function attachInlineData(
        string $content,
        string $name,
        string $contentId,
        string $mimeType = 'application/octet-stream',
        int $maxSizeBytes = 26214400,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromData(
            $content,
            $name,
            $mimeType,
            'inline',
            $contentId,
            $maxSizeBytes,
        );

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    /**
     * @param resource $stream
     */
    public function attachInlineStream(
        mixed $stream,
        string $name,
        string $contentId,
        string $mimeType = 'application/octet-stream',
        int $maxSizeBytes = 26214400,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromStream($stream, $name, $mimeType, 'inline', $contentId, $maxSizeBytes);

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    /**
     * @return list<EmailAttachment>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * @param resource $stream
     */
    public function attachStream(
        mixed $stream,
        string $name,
        string $mimeType = 'application/octet-stream',
        int $maxSizeBytes = 26214400,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromStream($stream, $name, $mimeType, maxSizeBytes: $maxSizeBytes);

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $attachments, $this->metadata);
    }

    public function bcc(string ...$mailboxes): self
    {
        $bcc = array_merge($this->envelope->bcc, $this->parseMailboxes(...$mailboxes));

        return new self(
            $this->envelope->withBcc($bcc),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function cc(string ...$mailboxes): self
    {
        $cc = array_merge($this->envelope->cc, $this->parseMailboxes(...$mailboxes));

        return new self(
            $this->envelope->withCc($cc),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function deliveryNotification(
        bool $success = false,
        bool $failure = true,
        bool $delay = true,
        bool $returnFull = true,
    ): self {
        return new self(
            $this->envelope,
            $this->headers->withDeliveryNotification($success, $failure, $delay, $returnFull),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @return array{0:self,1:string}
     */
    public function embed(
        string $filePath,
        ?string $contentId = null,
        ?string $filename = null,
        int $maxSizeBytes = 26214400,
    ): array {
        $resolvedContentId = trim($contentId ?? bin2hex(random_bytes(8)), '<>');

        return [
            $this->attachInline($filePath, $resolvedContentId, $filename, $maxSizeBytes),
            $resolvedContentId,
        ];
    }

    public function envelope(): EmailEnvelope
    {
        return $this->envelope;
    }

    public function from(string $email, ?string $name = null): self
    {
        $from = new EmailAddress($email, $name);
        $nextHeaders = $this->headers;

        if ($this->headers->replyTo === null) {
            $nextHeaders = $nextHeaders->withReplyTo($from);
        }

        return new self($this->envelope->withFrom($from), $nextHeaders, $this->htmlBody, $this->textBody, $this->attachments, $this->metadata);
    }

    public function generalHeaders(string $language = '', ?Priority $priority = null, string $mailer = ''): self
    {
        return new self(
            $this->envelope,
            $this->headers->withGeneralHeaders($language, $priority, $mailer),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param string|list<string> $value
     */
    public function header(string $name, string|array $value): self
    {
        return new self(
            $this->envelope,
            $this->headers->withCustomHeader($name, $this->normalizeHeaderValue($value)),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function headers(array $headers): self
    {
        return new self(
            $this->envelope,
            $this->headers->withCustomHeaders($headers),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function headersData(): EmailHeaders
    {
        return $this->headers;
    }

    public function html(string $html): self
    {
        return new self($this->envelope, $this->headers, $html, $this->textBody, $this->attachments, $this->metadata);
    }

    public function htmlBody(): string
    {
        return $this->htmlBody;
    }

    public function listHeaders(
        ?string $listId = null,
        ?string $unsubscribe = null,
        ?string $subscribe = null,
        ?string $archive = null,
        ?string $unsubscribePost = null,
    ): self {
        return new self(
            $this->envelope,
            $this->headers->withListHeaders($listId, $unsubscribe, $subscribe, $archive, $unsubscribePost),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param list<string> $references
     */
    public function messageDetails(?string $messageId = null, ?string $inReplyTo = null, array $references = []): self
    {
        return new self(
            $this->envelope,
            $this->headers->withMessageDetails($messageId, $inReplyTo, $references),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function miscHeaders(
        ?bool $confirmedOptIn = null,
        ?string $spamStatus = null,
        ?string $organization = null,
        ?string $dispositionNotificationTo = null,
    ): self {
        $notificationTo = $dispositionNotificationTo !== null && $dispositionNotificationTo !== ''
            ? EmailAddress::fromMailbox($dispositionNotificationTo)
            : null;

        return new self(
            $this->envelope,
            $this->headers->withMiscHeaders($confirmedOptIn, $spamStatus, $organization, $notificationTo),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function oneClickUnsubscribe(string $url): self
    {
        return new self(
            $this->envelope,
            $this->headers->withOneClickUnsubscribe($url),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function readReceiptTo(string $mailbox): self
    {
        return new self(
            $this->envelope,
            $this->headers->withReadReceiptTo(EmailAddress::fromMailbox($mailbox)),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function replyTo(string $mailbox): self
    {
        return new self(
            $this->envelope,
            $this->headers->withReplyTo(EmailAddress::fromMailbox($mailbox)),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function returnPath(string $mailbox): self
    {
        return new self(
            $this->envelope->withReturnPath(EmailAddress::fromMailbox($mailbox)),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function sender(string $mailbox): self
    {
        return new self(
            $this->envelope,
            $this->headers->withSender(EmailAddress::fromMailbox($mailbox)),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function subject(string $subject): self
    {
        return new self($this->envelope, $this->headers->withSubject($subject), $this->htmlBody, $this->textBody, $this->attachments, $this->metadata);
    }

    public function tag(string $key, mixed $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $this->attachments, $metadata);
    }

    public function text(string $text): self
    {
        return new self($this->envelope, $this->headers, $this->htmlBody, $text, $this->attachments, $this->metadata);
    }

    public function textBody(): string
    {
        return $this->textBody;
    }

    public function to(string ...$mailboxes): self
    {
        $to = array_merge($this->envelope->to, $this->parseMailboxes(...$mailboxes));

        return new self(
            $this->envelope->withTo($to),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withBcc(string ...$mailboxes): self
    {
        return new self(
            $this->envelope->withBcc($this->parseMailboxes(...$mailboxes)),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withCc(string ...$mailboxes): self
    {
        return new self(
            $this->envelope->withCc($this->parseMailboxes(...$mailboxes)),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->envelope,
            $this->headers->withCustomHeaders($headers, true),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->envelope,
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $metadata,
        );
    }

    public function withoutDeliveryNotification(): self
    {
        return new self(
            $this->envelope,
            $this->headers->withDeliveryNotification(success: false, failure: false, delay: false, returnFull: true),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutHeader(string $name): self
    {
        return new self(
            $this->envelope,
            $this->headers->withoutCustomHeader($name),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutListHeaders(): self
    {
        return new self(
            $this->envelope,
            $this->headers->withoutListHeaders(),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutReadReceipt(): self
    {
        return new self(
            $this->envelope,
            $this->headers->withReadReceiptTo(null),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutReplyTo(): self
    {
        return new self(
            $this->envelope,
            $this->headers->withoutReplyTo(),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutReturnPath(): self
    {
        return new self(
            $this->envelope->withReturnPath(null),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withoutSender(): self
    {
        return new self(
            $this->envelope,
            $this->headers->withoutSender(),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    public function withTo(string ...$mailboxes): self
    {
        return new self(
            $this->envelope->withTo($this->parseMailboxes(...$mailboxes)),
            $this->headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param string|list<string> $value
     *
     * @return string|list<string>
     */
    private function normalizeHeaderValue(string|array $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = (string) $item;
        }

        return $normalized;
    }

    /**
     * @return list<EmailAddress>
     */
    private function parseMailboxes(string ...$mailboxes): array
    {
        $addresses = [];

        foreach ($mailboxes as $mailbox) {
            $addresses[] = EmailAddress::fromMailbox($mailbox);
        }

        return $addresses;
    }
}
