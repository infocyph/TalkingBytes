<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Testing;

use Infocyph\TalkingBytes\Email\EmailMessage;
use RuntimeException;

final readonly class AssertableEmailTransport
{
    public function __construct(private FakeEmailTransport $fakeTransport) {}

    public function assertHasAttachment(string $filename): void
    {
        $this->assertSentWhere(static fn(EmailMessage $message): bool => array_any($message->attachments(), fn($attachment) => $attachment->name === $filename && !$attachment->isInline()), sprintf('Expected an email with attachment "%s".', $filename));
    }

    public function assertHasInlineAttachment(string $contentId): void
    {
        $this->assertSentWhere(static fn(EmailMessage $message): bool => array_any($message->attachments(), fn($attachment) => $attachment->isInline() && trim((string) $attachment->contentId, '<>') === trim($contentId, '<>')), sprintf('Expected an email with inline attachment "%s".', $contentId));
    }

    public function assertNothingSent(): void
    {
        if ($this->fakeTransport->sentMessages() !== []) {
            throw new RuntimeException('Expected no email to be sent, but at least one message was sent.');
        }
    }

    public function assertSent(): void
    {
        if ($this->fakeTransport->sentMessages() === []) {
            throw new RuntimeException('Expected at least one email to be sent.');
        }
    }

    public function assertSentCount(int $count): void
    {
        $actual = count($this->fakeTransport->sentMessages());

        if ($actual !== $count) {
            throw new RuntimeException(sprintf('Expected %d sent email(s), got %d.', $count, $actual));
        }
    }

    public function assertSentFrom(string $email): void
    {
        $this->assertSentWhere(
            static fn(EmailMessage $message): bool => $message->envelope()->from?->email === $email,
            sprintf('Expected an email sent from "%s".', $email),
        );
    }

    public function assertSentSubject(string $subject): void
    {
        $this->assertSentWhere(
            static fn(EmailMessage $message): bool => $message->headersData()->subject === $subject,
            sprintf('Expected an email with subject "%s".', $subject),
        );
    }

    public function assertSentTo(string $email): void
    {
        $this->assertSentWhere(static fn(EmailMessage $message): bool => array_any($message->envelope()->recipients(), fn($recipient) => $recipient->email === $email), sprintf('Expected an email sent to "%s".', $email));
    }

    public function assertSentWhere(callable $predicate, string $message = 'Expected a sent email matching predicate.'): void
    {
        foreach ($this->fakeTransport->sentMessages() as $sentMessage) {
            if ($predicate($sentMessage) === true) {
                return;
            }
        }

        throw new RuntimeException($message);
    }

    public function lastMessage(): ?EmailMessage
    {
        $messages = $this->fakeTransport->sentMessages();

        return $messages === [] ? null : $messages[array_key_last($messages)];
    }
}
