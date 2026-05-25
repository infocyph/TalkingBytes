<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class WebhookReceiver
{
    public function __construct(
        private WebhookVerifier $verifier,
        private ?WebhookReplayStore $replayStore = null,
        private int $replayTtlSeconds = 86400,
    ) {}

    /**
     * @param array<array-key, mixed> $headers
     */
    public function receive(string $rawBody, array $headers): WebhookEvent
    {
        $signatureHeader = $this->header($headers, WebhookHeaders::SIGNATURE) ?? '';
        $timestampHeader = $this->header($headers, WebhookHeaders::TIMESTAMP);

        $verification = $this->verifier->verifyResult($rawBody, $signatureHeader, $timestampHeader);
        if (!$verification->valid) {
            throw new RuntimeException(sprintf('Webhook verification failed: %s', (string) $verification->reason));
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Webhook payload must be valid JSON.', previous: $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Webhook payload must decode to an object/array JSON value.');
        }

        $event = $this->header($headers, WebhookHeaders::EVENT);
        $deliveryId = $this->header($headers, WebhookHeaders::DELIVERY);
        if ($event === null) {
            throw new InvalidArgumentException('Webhook event header is missing.');
        }

        if ($deliveryId === null) {
            throw new InvalidArgumentException('Webhook delivery header is missing.');
        }

        WebhookNameGuard::assertEvent($event);
        WebhookNameGuard::assertDeliveryId($deliveryId);

        if ($this->replayStore !== null) {
            if ($this->replayStore->seen($deliveryId)) {
                throw new RuntimeException(sprintf('Webhook delivery "%s" has already been processed.', $deliveryId));
            }

            $this->replayStore->remember($deliveryId, $this->replayTtlSeconds);
        }

        $timestamp = $verification->timestamp ?? time();
        $normalizedPayload = $this->normalizePayload($decoded);
        $received = new WebhookEvent(
            event: $event,
            deliveryId: $deliveryId,
            payload: $normalizedPayload,
            timestamp: $timestamp,
            headers: $this->normalizeHeaders($headers),
            metadata: [
                'verification' => $verification->metadata,
            ],
        );

        CommunicationEventBus::dispatch('webhook.received', [
            'event' => $received->event,
            'delivery_id' => $received->deliveryId,
            'timestamp' => $received->timestamp,
        ]);

        return $received;
    }

    public function withReplayStore(WebhookReplayStore $store, int $ttlSeconds = 86400): self
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Webhook replay TTL must be greater than zero.');
        }

        return new self($this->verifier, $store, $ttlSeconds);
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (!is_string($key) || strcasecmp($key, $name) !== 0) {
                continue;
            }

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
