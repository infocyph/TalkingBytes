<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Contracts;

interface WebhookReplayStore
{
    /**
     * Atomically claim a delivery identity for the TTL.
     *
     * Production implementations must provide one-winner semantics across
     * competing processes. Returns true only for the first claimant.
     * The requested TTL is an elapsed-duration lower bound: implementations
     * must not expire the claim early because their wall clock moves.
     * Backend failures must throw; callers treat them as fail-closed.
     */
    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool;
}
