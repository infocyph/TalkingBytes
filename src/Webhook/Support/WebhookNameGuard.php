<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Support;

use InvalidArgumentException;

final class WebhookNameGuard
{
    public static function assertDeliveryId(string $deliveryId): void
    {
        self::assertName($deliveryId, 'Webhook delivery ID');
    }

    public static function assertEvent(string $event): void
    {
        self::assertName($event, 'Webhook event');
    }

    public static function assertMetadataKey(string $key): void
    {
        self::assertName($key, 'Webhook metadata key');
    }

    public static function assertNamespace(string $namespace): void
    {
        self::assertName($namespace, 'Webhook replay namespace');
    }

    private static function assertName(string $value, string $label): void
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException(sprintf('%s must not be empty.', $label));
        }

        if ($trimmed !== $value) {
            throw new InvalidArgumentException(sprintf('%s must not contain surrounding whitespace.', $label));
        }

        if (strlen($trimmed) > 255) {
            throw new InvalidArgumentException(sprintf('%s must not exceed 255 characters.', $label));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $trimmed) === 1) {
            throw new InvalidArgumentException(sprintf('%s must not contain control characters.', $label));
        }
    }
}
