<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Replay;

use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use InvalidArgumentException;
use RuntimeException;

final class InMemoryWebhookReplayStore implements WebhookReplayStore
{
    /**
     * @var array<string, int>
     */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = 10000)
    {
        if ($this->maxEntries < 1) {
            throw new InvalidArgumentException('Replay store maxEntries must be greater than zero.');
        }
    }

    public function remember(string $deliveryId, int $ttlSeconds): void
    {
        if (!isset($this->entries[$deliveryId]) && count($this->entries) >= $this->maxEntries) {
            $this->purgeExpired();

            if (count($this->entries) >= $this->maxEntries) {
                throw new RuntimeException('In-memory webhook replay store capacity has been exhausted.');
            }
        }

        $now = time();
        $ttlSeconds = max(1, $ttlSeconds);
        $this->entries[$deliveryId] = $ttlSeconds > PHP_INT_MAX - $now
            ? PHP_INT_MAX
            : $now + $ttlSeconds;
    }

    public function seen(string $deliveryId): bool
    {
        $this->purgeExpired();

        return isset($this->entries[$deliveryId]);
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
