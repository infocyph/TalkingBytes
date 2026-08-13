<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email;

use DateTimeImmutable;
use DateTimeZone;
use Infocyph\TalkingBytes\Email\Enum\Priority;
use Infocyph\TalkingBytes\Email\Template\ArrayVariableRenderer;
use Infocyph\TalkingBytes\Email\Template\TemplateRendererInterface;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAddress;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;
use Infocyph\TalkingBytes\Email\ValueObject\EmailEnvelope;
use Infocyph\TalkingBytes\Email\ValueObject\EmailHeaders;
use InvalidArgumentException;

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
        ?int $knownSize = null,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromStream(
            $stream,
            $name,
            $mimeType,
            'inline',
            $contentId,
            $maxSizeBytes,
            $knownSize,
        );

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
        ?int $knownSize = null,
    ): self {
        $attachments = $this->attachments;
        $attachments[] = EmailAttachment::fromStream(
            $stream,
            $name,
            $mimeType,
            maxSizeBytes: $maxSizeBytes,
            knownSize: $knownSize,
        );

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

    public function bounceTo(string $mailbox): self
    {
        return $this->returnPath($mailbox);
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
        ?string $envelopeId = null,
    ): self {
        return new self(
            $this->envelope,
            $this->headers->withDeliveryNotification($success, $failure, $delay, $returnFull, $envelopeId),
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /** @return list<string> */
    public function dkimSignatures(): array
    {
        $values = $this->metadata['_dkim_signatures'] ?? [];
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_string(...)));
    }

    public function dsnEnvelopeId(?string $envelopeId): self
    {
        return new self(
            $this->envelope,
            $this->headers->withDsnEnvelopeId($envelopeId),
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

        return new self($this->envelope->withFrom($from), $this->headers, $this->htmlBody, $this->textBody, $this->attachments, $this->metadata);
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

    public function mimeBoundary(string $role): string
    {
        $seed = $this->metadata['_mime_boundary_seed'] ?? null;
        if (!is_string($seed) || $seed === '') {
            throw new \LogicException('Email must be prepared before MIME boundaries are resolved.');
        }

        return substr(hash_hmac('sha256', $role, $seed), 0, 32);
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
        return $this->withHeadersData($this->headers->withOneClickUnsubscribe($url));
    }

    public function prepare(): self
    {
        if (isset($this->metadata['_prepared_date'], $this->metadata['_mime_boundary_seed'])) {
            return $this;
        }

        $this->assertReadyToSend();
        $from = $this->envelope->from;
        if ($from === null) {
            throw new \LogicException('Email From address is required before preparation.');
        }

        $messageId = $this->headers->messageId;
        if ($messageId === null || trim($messageId) === '') {
            $domain = substr(strrchr($from->email, '@') ?: '@localhost', 1);
            $messageId = sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
        }

        $headers = $this->headers->withMessageDetails(
            $messageId,
            $this->headers->inReplyTo,
            $this->headers->references,
        );
        $metadata = $this->metadata;
        $metadata['_prepared_date'] = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('r');
        $metadata['_mime_boundary_seed'] = bin2hex(random_bytes(32));

        return new self($this->envelope, $headers, $this->htmlBody, $this->textBody, $this->attachments, $metadata);
    }

    public function preparedDateHeader(): ?string
    {
        $value = $this->metadata['_prepared_date'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function readReceiptTo(string $mailbox): self
    {
        return $this->withMailboxAddressHeader($mailbox, static fn(EmailHeaders $headers, EmailAddress $address): EmailHeaders => $headers->withReadReceiptTo($address));
    }

    public function replyTo(string $mailbox): self
    {
        return $this->withMailboxAddressHeader($mailbox, static fn(EmailHeaders $headers, EmailAddress $address): EmailHeaders => $headers->withReplyTo($address));
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
        return $this->withMailboxAddressHeader($mailbox, static fn(EmailHeaders $headers, EmailAddress $address): EmailHeaders => $headers->withSender($address));
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

    /**
     * @param array<string, scalar|\Stringable|null> $variables
     */
    public function template(
        string $template,
        array $variables = [],
        bool $asHtml = true,
        TemplateRendererInterface $renderer = new ArrayVariableRenderer(),
    ): self {
        $rendered = $renderer->render($template, $variables);

        if ($asHtml) {
            return $this->html($rendered);
        }

        return $this->text($rendered);
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

    public function withDkimSignature(string $value): self
    {
        if ($value === '' || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new InvalidArgumentException('DKIM signature value must not be empty or contain control characters.');
        }

        $metadata = $this->metadata;
        $signatures = $metadata['_dkim_signatures'] ?? [];
        if (!is_array($signatures)) {
            $signatures = [];
        }
        $signatures[] = $value;
        $metadata['_dkim_signatures'] = $signatures;

        return new self($this->envelope, $this->headers, $this->htmlBody, $this->textBody, $this->attachments, $metadata);
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

    private function withHeadersData(EmailHeaders $headers): self
    {
        return new self(
            $this->envelope,
            $headers,
            $this->htmlBody,
            $this->textBody,
            $this->attachments,
            $this->metadata,
        );
    }

    /**
     * @param callable(EmailHeaders, EmailAddress):EmailHeaders $apply
     */
    private function withMailboxAddressHeader(string $mailbox, callable $apply): self
    {
        $address = EmailAddress::fromMailbox($mailbox);

        return $this->withHeadersData($apply($this->headers, $address));
    }
}
