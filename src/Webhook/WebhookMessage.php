<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use JsonSerializable;

final readonly class WebhookMessage
{
    /**
     * @param array<string, mixed>|string $payload
     * @param array<string, string> $headers
     */
    private function __construct(
        public string $event,
        public ?string $url,
        public array|string $payload,
        public array $headers,
        public string $deliveryId,
    ) {}

    public static function new(string $event): self
    {
        return new self($event, null, [], [], bin2hex(random_bytes(16)));
    }

    public function header(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;

        return new self($this->event, $this->url, $this->payload, $headers, $this->deliveryId);
    }

    /**
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        return new self($this->event, $this->url, $this->payload, array_merge($this->headers, $headers), $this->deliveryId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function payload(array $payload): self
    {
        return new self($this->event, $this->url, $payload, $this->headers, $this->deliveryId);
    }

    public function rawPayload(string $payload): self
    {
        return new self($this->event, $this->url, $payload, $this->headers, $this->deliveryId);
    }

    public function url(string $url): self
    {
        return new self($this->event, $url, $this->payload, $this->headers, $this->deliveryId);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->event, $this->url, $this->payload, $headers, $this->deliveryId);
    }

    public function withPayloadObject(JsonSerializable $payload): self
    {
        $normalized = $payload->jsonSerialize();

        if (is_array($normalized)) {
            $payloadArray = [];

            foreach ($normalized as $key => $value) {
                $payloadArray[(string) $key] = $value;
            }

            $normalized = $payloadArray;
        } elseif (!is_string($normalized)) {
            $normalized = json_encode($normalized, JSON_THROW_ON_ERROR);
        }

        return new self($this->event, $this->url, $normalized, $this->headers, $this->deliveryId);
    }
}
