<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Model\WebhookEvent;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\Support\WebhookNameGuard;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class WebhookReceiver
{
    private EventDispatcher $events;

    public function __construct(
        private WebhookVerifier $verifier,
        private ?WebhookReplayStore $replayStore = null,
        private int $replayTtlSeconds = 86400,
        private string $replayNamespace = 'default',
        private int $maxPayloadBytes = 1_048_576,
        ?EventDispatcher $events = null,
    ) {
        WebhookNameGuard::assertNamespace($this->replayNamespace);
        if ($this->replayTtlSeconds < 1 || $this->maxPayloadBytes < 1) {
            throw new InvalidArgumentException('Webhook replay TTL and payload limit must be greater than zero.');
        }
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    public function receive(string $rawBody, array $headers): WebhookEvent
    {
        if (strlen($rawBody) > $this->maxPayloadBytes) {
            throw new InvalidArgumentException(sprintf('Webhook payload exceeded %d bytes.', $this->maxPayloadBytes));
        }

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
            if (!$this->replayStore->claim($this->replayNamespace, $deliveryId, $this->replayTtlSeconds)) {
                throw new RuntimeException(sprintf('Webhook delivery "%s" has already been processed.', $deliveryId));
            }
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

        $this->events->dispatch('webhook.received', [
            'event' => $received->event,
            'delivery_id' => $received->deliveryId,
            'timestamp' => $received->timestamp,
        ]);

        return $received;
    }

    public function withReplayStore(WebhookReplayStore $store, int $ttlSeconds = 86400, string $namespace = 'default'): self
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Webhook replay TTL must be greater than zero.');
        }

        WebhookNameGuard::assertNamespace($namespace);

        return new self($this->verifier, $store, $ttlSeconds, $namespace, $this->maxPayloadBytes, $this->events);
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
