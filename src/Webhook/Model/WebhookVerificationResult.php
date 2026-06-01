<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Model;

final readonly class WebhookVerificationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public bool $valid,
        public ?string $reason = null,
        public ?int $timestamp = null,
        public bool $signaturePresent = false,
        public ?string $signaturePrefix = null,
        public array $metadata = [],
    ) {}
}
