<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Contracts;

interface WebhookReplayStore
{
    public function remember(string $deliveryId, int $ttlSeconds): void;

    public function seen(string $deliveryId): bool;
}
