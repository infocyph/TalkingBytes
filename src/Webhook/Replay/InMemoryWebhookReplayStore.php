<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Replay;

use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;

final class InMemoryWebhookReplayStore implements WebhookReplayStore
{
    /**
     * @var array<string, int>
     */
    private array $entries = [];

    public function remember(string $deliveryId, int $ttlSeconds): void
    {
        $this->entries[$deliveryId] = time() + max(1, $ttlSeconds);
    }

    public function seen(string $deliveryId): bool
    {
        $this->purgeExpired();

        return array_key_exists($deliveryId, $this->entries);
    }

    private function purgeExpired(): void
    {
        $now = time();

        foreach ($this->entries as $deliveryId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->entries[$deliveryId]);
            }
        }
    }
}
