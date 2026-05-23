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

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values[0] ?? null;
    }
}
