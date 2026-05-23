<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Enum\Priority;
use Infocyph\TalkingBytes\Email\Exception\MissingMessageDataException;
use Infocyph\TalkingBytes\Email\System\HeaderValueGuard;

final readonly class EmailHeaders
{
    /**
     * @param list<string> $references
     * @param array<string, string|list<string>> $customHeaders
     */
    public function __construct(
        public string $subject = '',
        public ?EmailAddress $replyTo = null,
        public ?EmailAddress $sender = null,
        public string $language = '',
        public ?Priority $priority = null,
        public string $mailer = '',
        public ?string $listId = null,
        public ?string $listUnsubscribe = null,
        public ?string $listUnsubscribePost = null,
        public ?string $listSubscribe = null,
        public ?string $listArchive = null,
        public ?bool $confirmedOptIn = null,
        public ?string $spamStatus = null,
        public ?string $organization = null,
        public ?EmailAddress $dispositionNotificationTo = null,
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $customHeaders = [],
        public bool $dsnNotifySuccess = false,
        public bool $dsnNotifyFailure = false,
        public bool $dsnNotifyDelay = false,
        public bool $dsnReturnFull = true,
    ) {
        foreach ([
            'Subject' => $this->subject,
            'Content-Language' => $this->language,
            'X-Mailer' => $this->mailer,
            'List-Id' => $this->listId,
            'List-Unsubscribe' => $this->listUnsubscribe,
            'List-Unsubscribe-Post' => $this->listUnsubscribePost,
            'List-Subscribe' => $this->listSubscribe,
            'List-Archive' => $this->listArchive,
            'X-Spam-Status' => $this->spamStatus,
            'Organization' => $this->organization,
            'Message-ID' => $this->messageId,
            'In-Reply-To' => $this->inReplyTo,
        ] as $name => $value) {
            if ($value !== null && $value !== '') {
                HeaderValueGuard::assertNoCrlf($value, $name);
            }
        }

        foreach ($this->references as $reference) {
            HeaderValueGuard::assertNoCrlf($reference, 'References');
        }

        foreach ($this->customHeaders as $name => $value) {
            HeaderValueGuard::assertHeaderName($name);

            if (is_array($value)) {
                foreach ($value as $singleValue) {
                    HeaderValueGuard::assertNoCrlf($singleValue, $name);
                }

                continue;
            }

            HeaderValueGuard::assertNoCrlf($value, $name);
        }
    }

    public function assertCanSend(): void
    {
        if (trim($this->subject) === '') {
            throw new MissingMessageDataException('Email subject is required before sending.');
        }
    }

    /**
     * @param string|list<string> $value
     */
    public function withCustomHeader(string $name, string|array $value): self
    {
        HeaderValueGuard::assertHeaderName($name);

        $headers = $this->customHeaders;
        $headers[$name] = $this->normalizeHeaderValue($value);

        return $this->clone(customHeaders: $headers);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function withCustomHeaders(array $headers, bool $replace = false): self
    {
        $current = $replace ? [] : $this->customHeaders;

        foreach ($headers as $name => $value) {
            HeaderValueGuard::assertHeaderName($name);
            $current[$name] = $this->normalizeHeaderValue($value);
        }

        return $this->clone(customHeaders: $current);
    }

    public function withDeliveryNotification(
        bool $success = false,
        bool $failure = true,
        bool $delay = true,
        bool $returnFull = true,
    ): self {
        return $this->clone(
            dsnNotifySuccess: $success,
            dsnNotifyFailure: $failure,
            dsnNotifyDelay: $delay,
            dsnReturnFull: $returnFull,
        );
    }

    public function withGeneralHeaders(string $language = '', ?Priority $priority = null, string $mailer = ''): self
    {
        return $this->clone(language: $language, priority: $priority, mailer: $mailer);
    }

    public function withListHeaders(
        ?string $listId = null,
        ?string $unsubscribe = null,
        ?string $subscribe = null,
        ?string $archive = null,
        ?string $unsubscribePost = null,
    ): self {
        return $this->clone(
            listId: $listId,
            listUnsubscribe: $unsubscribe,
            listSubscribe: $subscribe,
            listArchive: $archive,
            listUnsubscribePost: $unsubscribePost,
        );
    }

    /**
     * @param list<string> $references
     */
    public function withMessageDetails(?string $messageId = null, ?string $inReplyTo = null, array $references = []): self
    {
        return $this->clone(messageId: $messageId, inReplyTo: $inReplyTo, references: $references);
    }

    public function withMiscHeaders(
        ?bool $confirmedOptIn = null,
        ?string $spamStatus = null,
        ?string $organization = null,
        ?EmailAddress $dispositionNotificationTo = null,
    ): self {
        return $this->clone(
            confirmedOptIn: $confirmedOptIn,
            spamStatus: $spamStatus,
            organization: $organization,
            dispositionNotificationTo: $dispositionNotificationTo,
        );
    }

    public function withOneClickUnsubscribe(string $url): self
    {
        return $this->clone(
            listUnsubscribe: sprintf('<%s>', trim($url, '<>')),
            listUnsubscribePost: 'List-Unsubscribe=One-Click',
        );
    }

    public function withoutCustomHeader(string $name): self
    {
        $headers = $this->customHeaders;
        unset($headers[$name]);

        return $this->clone(customHeaders: $headers);
    }

    public function withReadReceiptTo(?EmailAddress $dispositionNotificationTo): self
    {
        return $this->clone(dispositionNotificationTo: $dispositionNotificationTo);
    }

    public function withReplyTo(?EmailAddress $replyTo): self
    {
        return $this->clone(replyTo: $replyTo);
    }

    public function withSender(?EmailAddress $sender): self
    {
        return $this->clone(sender: $sender);
    }

    public function withSubject(string $subject): self
    {
        return $this->clone(subject: $subject);
    }

    /**
     * @param list<string>|null $references
     * @param array<string, string|list<string>>|null $customHeaders
     */
    private function clone(
        ?string $subject = null,
        ?EmailAddress $replyTo = null,
        ?EmailAddress $sender = null,
        ?string $language = null,
        ?Priority $priority = null,
        ?string $mailer = null,
        ?string $listId = null,
        ?string $listUnsubscribe = null,
        ?string $listUnsubscribePost = null,
        ?string $listSubscribe = null,
        ?string $listArchive = null,
        ?bool $confirmedOptIn = null,
        ?string $spamStatus = null,
        ?string $organization = null,
        ?EmailAddress $dispositionNotificationTo = null,
        ?string $messageId = null,
        ?string $inReplyTo = null,
        ?array $references = null,
        ?array $customHeaders = null,
        ?bool $dsnNotifySuccess = null,
        ?bool $dsnNotifyFailure = null,
        ?bool $dsnNotifyDelay = null,
        ?bool $dsnReturnFull = null,
    ): self {
        return new self(
            $subject ?? $this->subject,
            $replyTo ?? $this->replyTo,
            $sender ?? $this->sender,
            $language ?? $this->language,
            $priority ?? $this->priority,
            $mailer ?? $this->mailer,
            $listId ?? $this->listId,
            $listUnsubscribe ?? $this->listUnsubscribe,
            $listUnsubscribePost ?? $this->listUnsubscribePost,
            $listSubscribe ?? $this->listSubscribe,
            $listArchive ?? $this->listArchive,
            $confirmedOptIn ?? $this->confirmedOptIn,
            $spamStatus ?? $this->spamStatus,
            $organization ?? $this->organization,
            $dispositionNotificationTo ?? $this->dispositionNotificationTo,
            $messageId ?? $this->messageId,
            $inReplyTo ?? $this->inReplyTo,
            $references ?? $this->references,
            $customHeaders ?? $this->customHeaders,
            $dsnNotifySuccess ?? $this->dsnNotifySuccess,
            $dsnNotifyFailure ?? $this->dsnNotifyFailure,
            $dsnNotifyDelay ?? $this->dsnNotifyDelay,
            $dsnReturnFull ?? $this->dsnReturnFull,
        );
    }

    /**
     * @param string|list<string> $value
     *
     * @return string|list<string>
     */
    private function normalizeHeaderValue(string|array $value): string|array
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($value as $item) {
            $normalized[] = (string) $item;
        }

        return $normalized;
    }
}
