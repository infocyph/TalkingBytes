<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

final readonly class WebhookDeliveryResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $deliveryId,
        public string $event,
        public string $url,
        public int $attempts,
        public bool $delivered,
        public ?int $statusCode = null,
        public ?string $error = null,
        public array $metadata = [],
    ) {}
}
