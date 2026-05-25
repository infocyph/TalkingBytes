<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $event,
        public string $deliveryId,
        public array $payload,
        public int $timestamp,
        public array $headers = [],
        public array $metadata = [],
    ) {}
}
