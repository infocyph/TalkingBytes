<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;

final readonly class Webhook
{
    public static function fake(): FakeWebhookSender
    {
        return new FakeWebhookSender();
    }

    public static function receiver(string $secret, int $maxAgeSeconds = 300): WebhookReceiver
    {
        return new WebhookReceiver(new WebhookVerifier($secret, $maxAgeSeconds));
    }

    public static function sender(HttpClient $httpClient): WebhookSender
    {
        return WebhookSender::usingHttp($httpClient);
    }

    public static function verifier(string $secret, int $maxAgeSeconds = 300): WebhookVerifier
    {
        return new WebhookVerifier($secret, $maxAgeSeconds);
    }
}
