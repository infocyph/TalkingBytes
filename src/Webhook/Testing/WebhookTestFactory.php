<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook\Testing;

use Infocyph\TalkingBytes\Webhook\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\WebhookNameGuard;
use Infocyph\TalkingBytes\Webhook\WebhookSignature;

final class WebhookTestFactory
{
    /**
     * @param array<string, mixed> $payload
     * @return array{0:string,1:array<string,string>}
     */
    public static function signedJson(
        string $secret,
        string $event,
        array $payload,
        ?string $deliveryId = null,
        ?int $timestamp = null,
    ): array {
        WebhookNameGuard::assertEvent($event);
        $rawPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $issuedAt = $timestamp ?? time();
        $delivery = $deliveryId ?? bin2hex(random_bytes(16));
        WebhookNameGuard::assertDeliveryId($delivery);
        $signature = new WebhookSignature($secret)->buildHeader($rawPayload, $issuedAt);

        return [
            $rawPayload,
            [
                WebhookHeaders::EVENT => $event,
                WebhookHeaders::DELIVERY => $delivery,
                WebhookHeaders::TIMESTAMP => (string) $issuedAt,
                WebhookHeaders::SIGNATURE => $signature,
                WebhookHeaders::CONTENT_TYPE => 'application/json',
            ],
        ];
    }
}
