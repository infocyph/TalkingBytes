<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Replay;

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Support\WebhookNameGuard;
use InvalidArgumentException;
use RuntimeException;

final class InMemoryWebhookReplayStore implements WebhookReplayStore
{
    private readonly Clock $clock;

    /**
     * @var array<string, int>
     */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = 10000, ?Clock $clock = null)
    {
        if ($this->maxEntries < 1) {
            throw new InvalidArgumentException('Replay store maxEntries must be greater than zero.');
        }
        $this->clock = $clock ?? Clock::system();
    }

    public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
    {
        WebhookNameGuard::assertNamespace($namespace);
        WebhookNameGuard::assertDeliveryId($deliveryId);
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Replay claim TTL must be greater than zero.');
        }

        $this->purgeExpired();
        $key = hash('sha256', $namespace . "\0" . $deliveryId);
        if (isset($this->entries[$key])) {
            return false;
        }

        if (count($this->entries) >= $this->maxEntries) {
            $this->purgeExpired();

            if (count($this->entries) >= $this->maxEntries) {
                throw new RuntimeException('In-memory webhook replay store capacity has been exhausted.');
            }
        }

        $now = (int) floor($this->clock->timestamp());
        $this->entries[$key] = $ttlSeconds > PHP_INT_MAX - $now
            ? PHP_INT_MAX
            : $now + $ttlSeconds;

        return true;
    }

    private function purgeExpired(): void
    {
        $now = (int) floor($this->clock->timestamp());

        foreach ($this->entries as $deliveryId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->entries[$deliveryId]);
            }
        }
    }
}
