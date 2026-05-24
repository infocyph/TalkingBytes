<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use DateTimeImmutable;

final readonly class ParsedEmail
{
    /**
     * @param list<string> $references
     * @param list<ReceivedAttachment> $attachments
     * @param list<ParsedEmailPart> $parts
     * @param array<string, list<string>> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public EmailAddressList $from,
        public EmailAddressList $to,
        public EmailAddressList $cc,
        public EmailAddressList $bcc,
        public ?string $subject,
        public ?DateTimeImmutable $date,
        public ?string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public ?string $textBody,
        public ?string $htmlBody,
        public array $attachments,
        public array $parts,
        public array $headers,
        public string $raw,
        public array $metadata = [],
    ) {}

    public function attachmentCount(): int
    {
        return count($this->attachments);
    }

    public function firstAttachment(): ?ReceivedAttachment
    {
        return $this->attachments[0] ?? null;
    }

    public function fromEmail(): ?string
    {
        return $this->from->first()?->email;
    }

    public function hasAttachments(): bool
    {
        return $this->attachmentCount() > 0;
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function isBounceCandidate(): bool
    {
        $contentType = strtolower($this->header('Content-Type') ?? '');
        if (str_contains($contentType, 'multipart/report') || str_contains($contentType, 'message/delivery-status')) {
            return true;
        }

        $subject = strtolower($this->subjectOrEmpty());
        $bounceHints = ['delivery status notification', 'mail delivery failed', 'undelivered'];

        return array_any($bounceHints, fn($hint) => str_contains($subject, (string) $hint));
    }

    public function isReply(): bool
    {
        return $this->inReplyTo !== null || $this->references !== [];
    }

    public function subjectOrEmpty(): string
    {
        return $this->subject ?? '';
    }
}
