<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Testing;

use Infocyph\TalkingBytes\Webhook\WebhookMessage;
use RuntimeException;

final readonly class AssertableWebhookSender
{
    public function __construct(private FakeWebhookSender $fakeSender) {}

    public function assertEvent(string $event): void
    {
        $this->assertSent(
            static fn(WebhookMessage $message): bool => $message->event === $event,
            sprintf('Expected webhook event "%s" to be sent.', $event),
        );
    }

    public function assertHeader(string $name, string $value): void
    {
        $this->assertSent(
            static fn(WebhookMessage $message): bool => ($message->headers[$name] ?? null) === $value,
            sprintf('Expected webhook header "%s: %s" to be sent.', $name, $value),
        );
    }

    public function assertNothingSent(): void
    {
        if ($this->fakeSender->sentMessages() !== []) {
            throw new RuntimeException('Expected no webhook message to be sent.');
        }
    }

    /**
     * @param array<string, mixed>|string $expected
     */
    public function assertPayload(array|string $expected): void
    {
        $this->assertSent(
            static fn(WebhookMessage $message): bool => $message->payload === $expected,
            'Expected webhook payload to match expected value.',
        );
    }

    public function assertPayloadWhere(callable $predicate): void
    {
        $this->assertSent(
            static fn(WebhookMessage $message): bool => $predicate($message->payload) === true,
            'Expected webhook payload to satisfy predicate.',
        );
    }

    public function assertSent(?callable $predicate = null, string $message = 'Expected webhook message to be sent.'): void
    {
        foreach ($this->fakeSender->sentMessages() as $sent) {
            if ($predicate === null || $predicate($sent) === true) {
                return;
            }
        }

        throw new RuntimeException($message);
    }

    public function assertSentCount(int $count): void
    {
        $actual = count($this->fakeSender->sentMessages());
        if ($actual !== $count) {
            throw new RuntimeException(sprintf('Expected %d webhook message(s), got %d.', $count, $actual));
        }
    }

    public function assertSentTo(string $url): void
    {
        $this->assertSent(
            static fn(WebhookMessage $message): bool => $message->url === $url,
            sprintf('Expected webhook to be sent to "%s".', $url),
        );
    }

    public function first(): ?WebhookMessage
    {
        $messages = $this->fakeSender->sentMessages();

        return $messages[0] ?? null;
    }

    public function last(): ?WebhookMessage
    {
        $messages = $this->fakeSender->sentMessages();
        if ($messages === []) {
            return null;
        }

        return $messages[array_key_last($messages)];
    }
}
