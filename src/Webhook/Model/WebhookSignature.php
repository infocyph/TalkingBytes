<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Model;

use Infocyph\TalkingBytes\Webhook\Signing\HmacWebhookSigner;
use Infocyph\TalkingBytes\Webhook\Support\WebhookNameGuard;
use InvalidArgumentException;

final readonly class WebhookSignature
{
    public function __construct(#[\SensitiveParameter] private string $secret)
    {
        if ($this->secret === '') {
            throw new InvalidArgumentException('Webhook signing secret must not be empty.');
        }
    }

    public static function deliveryPayload(string $payload, string $event, string $deliveryId): string
    {
        WebhookNameGuard::assertEvent($event);
        WebhookNameGuard::assertDeliveryId($deliveryId);

        return "talkingbytes.webhook.v2\0" . $event . "\0" . $deliveryId . "\0" . $payload;
    }

    public function buildHeader(string $payload, int $timestamp, ?string $event = null, ?string $deliveryId = null): string
    {
        $version = 'v1';
        if ($event !== null || $deliveryId !== null) {
            $payload = self::deliveryPayload($payload, $event ?? '', $deliveryId ?? '');
            $version = 'v2';
        }
        $signature = new HmacWebhookSigner()->sign($payload, $timestamp, $this->secret);

        return sprintf('t=%d,%s=%s', $timestamp, $version, $signature);
    }
}
