<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Contracts;

interface WebhookReplayStore
{
    /**
     * Atomically claim a delivery identity for the TTL.
     *
     * Returns true only for the first claimant.
     */
    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool;
}
