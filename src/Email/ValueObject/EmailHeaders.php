<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\ValueObject;

use Infocyph\TalkingBytes\Email\Enum\Priority;
use Infocyph\TalkingBytes\Email\Exception\MissingMessageDataException;
use Infocyph\TalkingBytes\Email\System\HeaderValueGuard;
use InvalidArgumentException;

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
        public ?string $dsnEnvelopeId = null,
    ) {
        $this->validateCommonHeaderValues();
        $this->validateDsnEnvelopeId();
        $this->validateReferences();
        $this->validateCustomHeaders();
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

        return $this->copy(['customHeaders' => $headers]);
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

        return $this->copy(['customHeaders' => $current]);
    }

    public function withDeliveryNotification(
        bool $success = false,
        bool $failure = true,
        bool $delay = true,
        bool $returnFull = true,
        ?string $envelopeId = null,
    ): self {
        return $this->copy([
            'dsnNotifySuccess' => $success,
            'dsnNotifyFailure' => $failure,
            'dsnNotifyDelay' => $delay,
            'dsnReturnFull' => $returnFull,
            'dsnEnvelopeId' => $envelopeId,
        ]);
    }

    public function withDsnEnvelopeId(?string $envelopeId): self
    {
        return $this->copy(['dsnEnvelopeId' => $envelopeId]);
    }

    public function withGeneralHeaders(string $language = '', ?Priority $priority = null, string $mailer = ''): self
    {
        return $this->copy(['language' => $language, 'priority' => $priority, 'mailer' => $mailer]);
    }

    public function withListHeaders(
        ?string $listId = null,
        ?string $unsubscribe = null,
        ?string $subscribe = null,
        ?string $archive = null,
        ?string $unsubscribePost = null,
    ): self {
        return $this->copy([
            'listId' => $listId,
            'listUnsubscribe' => $unsubscribe,
            'listSubscribe' => $subscribe,
            'listArchive' => $archive,
            'listUnsubscribePost' => $unsubscribePost,
        ]);
    }

    /**
     * @param list<string> $references
     */
    public function withMessageDetails(?string $messageId = null, ?string $inReplyTo = null, array $references = []): self
    {
        return $this->copy(['messageId' => $messageId, 'inReplyTo' => $inReplyTo, 'references' => $references]);
    }

    public function withMiscHeaders(
        ?bool $confirmedOptIn = null,
        ?string $spamStatus = null,
        ?string $organization = null,
        ?EmailAddress $dispositionNotificationTo = null,
    ): self {
        return $this->copy([
            'confirmedOptIn' => $confirmedOptIn,
            'spamStatus' => $spamStatus,
            'organization' => $organization,
            'dispositionNotificationTo' => $dispositionNotificationTo,
        ]);
    }

    public function withOneClickUnsubscribe(string $url): self
    {
        return $this->copy([
            'listUnsubscribe' => sprintf('<%s>', trim($url, '<>')),
            'listUnsubscribePost' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function withoutCustomHeader(string $name): self
    {
        $headers = $this->customHeaders;
        unset($headers[$name]);

        return $this->copy(['customHeaders' => $headers]);
    }

    public function withoutListHeaders(): self
    {
        return $this->copy([
            'listId' => null,
            'listUnsubscribe' => null,
            'listUnsubscribePost' => null,
            'listSubscribe' => null,
            'listArchive' => null,
        ]);
    }

    public function withoutReplyTo(): self
    {
        return $this->copy(['replyTo' => null]);
    }

    public function withoutSender(): self
    {
        return $this->copy(['sender' => null]);
    }

    public function withReadReceiptTo(?EmailAddress $dispositionNotificationTo): self
    {
        return $this->copy(['dispositionNotificationTo' => $dispositionNotificationTo]);
    }

    public function withReplyTo(?EmailAddress $replyTo): self
    {
        return $this->copy(['replyTo' => $replyTo]);
    }

    public function withSender(?EmailAddress $sender): self
    {
        return $this->copy(['sender' => $sender]);
    }

    public function withSubject(string $subject): self
    {
        return $this->copy(['subject' => $subject]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copy(array $overrides): self
    {
        $listId = $this->overrideNullableString($overrides, 'listId', $this->listId);
        $listUnsubscribe = $this->overrideNullableString($overrides, 'listUnsubscribe', $this->listUnsubscribe);
        $listUnsubscribePost = $this->overrideNullableString($overrides, 'listUnsubscribePost', $this->listUnsubscribePost);
        $listSubscribe = $this->overrideNullableString($overrides, 'listSubscribe', $this->listSubscribe);
        $listArchive = $this->overrideNullableString($overrides, 'listArchive', $this->listArchive);
        $spamStatus = $this->overrideNullableString($overrides, 'spamStatus', $this->spamStatus);
        $organization = $this->overrideNullableString($overrides, 'organization', $this->organization);
        $messageId = $this->overrideNullableString($overrides, 'messageId', $this->messageId);
        $inReplyTo = $this->overrideNullableString($overrides, 'inReplyTo', $this->inReplyTo);
        $dsnEnvelopeId = $this->overrideNullableString($overrides, 'dsnEnvelopeId', $this->dsnEnvelopeId);

        return new self(
            $this->overrideString($overrides, 'subject', $this->subject),
            $this->overrideEmailAddress($overrides, 'replyTo', $this->replyTo),
            $this->overrideEmailAddress($overrides, 'sender', $this->sender),
            $this->overrideString($overrides, 'language', $this->language),
            $this->overridePriority($overrides, 'priority', $this->priority),
            $this->overrideString($overrides, 'mailer', $this->mailer),
            $listId,
            $listUnsubscribe,
            $listUnsubscribePost,
            $listSubscribe,
            $listArchive,
            $this->overrideNullableBool($overrides, 'confirmedOptIn', $this->confirmedOptIn),
            $spamStatus,
            $organization,
            $this->overrideEmailAddress($overrides, 'dispositionNotificationTo', $this->dispositionNotificationTo),
            $messageId,
            $inReplyTo,
            $this->overrideReferences($overrides, 'references', $this->references),
            $this->overrideCustomHeaders($overrides, 'customHeaders', $this->customHeaders),
            $this->overrideBool($overrides, 'dsnNotifySuccess', $this->dsnNotifySuccess),
            $this->overrideBool($overrides, 'dsnNotifyFailure', $this->dsnNotifyFailure),
            $this->overrideBool($overrides, 'dsnNotifyDelay', $this->dsnNotifyDelay),
            $this->overrideBool($overrides, 'dsnReturnFull', $this->dsnReturnFull),
            $dsnEnvelopeId,
        );
    }

    /**
     * @return string|list<string>
     */
    private function normalizeCustomHeaderValue(mixed $value, string $key): string|array
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Override "%s" values must be strings or list of strings.', $key));
        }

        return $this->normalizeStringList($value, $key);
    }

    /**
     * @param string|list<string> $value
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

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values, string $key): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Override "%s" list values must be strings.', $key));
            }

            $normalized[] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideBool(array $overrides, string $key, bool $current): bool
    {
        /** @var bool $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => is_bool($value),
            'a bool',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, string|list<string>> $current
     * @return array<string, string|list<string>>
     */
    private function overrideCustomHeaders(array $overrides, string $key, array $current): array
    {
        $value = $this->overrideValue($overrides, $key, $current, static fn(mixed $value): bool => is_array($value), 'an array');
        if (!is_array($value)) {
            return $current;
        }

        $normalized = [];
        foreach ($value as $headerName => $headerValue) {
            if (!is_string($headerName)) {
                throw new InvalidArgumentException(sprintf('Override "%s" keys must be strings.', $key));
            }

            $normalized[$headerName] = $this->normalizeCustomHeaderValue($headerValue, $key);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideEmailAddress(array $overrides, string $key, ?EmailAddress $current): ?EmailAddress
    {
        /** @var ?EmailAddress $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => $value === null || $value instanceof EmailAddress,
            'an EmailAddress or null',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideNullableBool(array $overrides, string $key, ?bool $current): ?bool
    {
        /** @var ?bool $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => $value === null || is_bool($value),
            'a bool or null',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideNullableString(array $overrides, string $key, ?string $current): ?string
    {
        /** @var ?string $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => $value === null || is_string($value),
            'a string or null',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overridePriority(array $overrides, string $key, ?Priority $current): ?Priority
    {
        /** @var ?Priority $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => $value === null || $value instanceof Priority,
            'a Priority or null',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $current
     * @return list<string>
     */
    private function overrideReferences(array $overrides, string $key, array $current): array
    {
        $value = $this->overrideValue($overrides, $key, $current, static fn(mixed $value): bool => is_array($value), 'an array');
        if (!is_array($value)) {
            return $current;
        }

        return $this->normalizeStringList($value, $key);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideString(array $overrides, string $key, string $current): string
    {
        /** @var string $value */
        $value = $this->overrideValue(
            $overrides,
            $key,
            $current,
            static fn(mixed $value): bool => is_string($value),
            'a string',
        );

        return $value;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function overrideValue(
        array $overrides,
        string $key,
        mixed $current,
        callable $validator,
        string $expected,
    ): mixed {
        if (!array_key_exists($key, $overrides)) {
            return $current;
        }

        $value = $overrides[$key];
        if (!$validator($value)) {
            throw new InvalidArgumentException(sprintf('Override "%s" must be %s.', $key, $expected));
        }

        return $value;
    }

    private function validateCommonHeaderValues(): void
    {
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
            'DSN-ENVID' => $this->dsnEnvelopeId,
        ] as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            HeaderValueGuard::assertNoCrlf($value, $name);
        }
    }

    private function validateCustomHeaders(): void
    {
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

    private function validateDsnEnvelopeId(): void
    {
        if ($this->dsnEnvelopeId === null || $this->dsnEnvelopeId === '') {
            return;
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,200}$/', $this->dsnEnvelopeId) !== 1) {
            throw new InvalidArgumentException('DSN envelope ID must use only letters, numbers, dot, underscore, and hyphen.');
        }
    }

    private function validateReferences(): void
    {
        foreach ($this->references as $reference) {
            HeaderValueGuard::assertNoCrlf($reference, 'References');
        }
    }
}
