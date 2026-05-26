<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Http\HeaderBag;
use InvalidArgumentException;
use JsonSerializable;

final readonly class WebhookMessage
{
    /**
     * @param array<string, mixed>|string $payload
     * @param array<string, string> $headers
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public string $event,
        public ?string $url,
        public array|string $payload,
        public array $headers,
        public string $deliveryId,
        public array $metadata = [],
    ) {}

    public static function event(string $event): self
    {
        WebhookNameGuard::assertEvent($event);

        return new self(
            event: $event,
            url: null,
            payload: [],
            headers: [],
            deliveryId: bin2hex(random_bytes(16)),
            metadata: [],
        );
    }

    public static function new(string $event): self
    {
        return self::event($event);
    }

    public function deliveryId(string $deliveryId): self
    {
        WebhookNameGuard::assertDeliveryId($deliveryId);

        return new self($this->event, $this->url, $this->payload, $this->headers, $deliveryId, $this->metadata);
    }

    public function header(string $name, string $value): self
    {
        HeaderBag::assertValidHeaderName($name);
        HeaderBag::assertValidHeaderValue($value);
        self::assertAllowedHeaderName($name);

        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->event, $this->url, $this->payload, $headers, $this->deliveryId, $this->metadata);
    }

    /**
     * @param array<array-key, string> $headers
     */
    public function headers(array $headers): self
    {
        $validated = self::validateHeaders($headers);

        /** @var array<string, string> $merged */
        $merged = array_merge($this->headers, $validated);

        return new self(
            $this->event,
            $this->url,
            $this->payload,
            $merged,
            $this->deliveryId,
            $this->metadata,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        return new self($this->event, $this->url, $this->payload, $this->headers, $this->deliveryId, $metadata);
    }

    /**
     * @param array<string, mixed>|JsonSerializable $payload
     */
    public function payload(array|JsonSerializable $payload): self
    {
        if ($payload instanceof JsonSerializable) {
            return $this->withPayloadObject($payload);
        }

        return new self($this->event, $this->url, $payload, $this->headers, $this->deliveryId, $this->metadata);
    }

    public function rawJsonPayload(string $payload): self
    {
        return $this->rawPayload($payload);
    }

    public function rawPayload(string $payload): self
    {
        json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return new self($this->event, $this->url, $payload, $this->headers, $this->deliveryId, $this->metadata);
    }

    public function tag(string $key, mixed $value): self
    {
        WebhookNameGuard::assertMetadataKey($key);

        $metadata = $this->metadata;
        $metadata[$key] = $value;

        return new self($this->event, $this->url, $this->payload, $this->headers, $this->deliveryId, $metadata);
    }

    public function url(string $url): self
    {
        return new self($this->event, $url, $this->payload, $this->headers, $this->deliveryId, $this->metadata);
    }

    /**
     * @param array<array-key, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $validated = self::validateHeaders($headers);

        return new self($this->event, $this->url, $this->payload, $validated, $this->deliveryId, $this->metadata);
    }

    public function withPayloadObject(JsonSerializable $payload): self
    {
        $normalized = $payload->jsonSerialize();
        if (is_array($normalized)) {
            /** @var array<string, mixed> $asArray */
            $asArray = [];
            foreach ($normalized as $key => $value) {
                $asArray[(string) $key] = $value;
            }

            $normalized = $asArray;
        } elseif (!is_string($normalized)) {
            $normalized = json_encode($normalized, JSON_THROW_ON_ERROR);
        }

        return new self($this->event, $this->url, $normalized, $this->headers, $this->deliveryId, $this->metadata);
    }

    private static function assertAllowedHeaderName(string $name): void
    {
        if (WebhookHeaders::isReserved($name)) {
            throw new InvalidArgumentException(sprintf('Webhook header "%s" is reserved and cannot be overridden.', $name));
        }
    }

    /**
     * @param array<array-key, string> $headers
     * @return array<string, string>
     */
    private static function validateHeaders(array $headers): array
    {
        $validated = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            HeaderBag::assertValidHeaderName($name);
            HeaderBag::assertValidHeaderValue($value);
            self::assertAllowedHeaderName($name);
            $validated[$name] = $value;
        }

        return $validated;
    }
}
