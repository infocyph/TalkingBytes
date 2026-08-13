<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Webhook;

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;

final readonly class Webhook
{
    public static function fake(): FakeWebhookSender
    {
        return new FakeWebhookSender();
    }

    /** @param string|list<string> $secret */
    public static function receiver(#[\SensitiveParameter] string|array $secret, int $maxAgeSeconds = 300, ?EventDispatcher $events = null): WebhookReceiver
    {
        return new WebhookReceiver(new WebhookVerifier($secret, $maxAgeSeconds, events: $events), events: $events);
    }

    public static function sender(HttpClient $httpClient, ?EventDispatcher $events = null): WebhookSender
    {
        return new WebhookSender($httpClient, events: $events);
    }

    /** @param string|list<string> $secret */
    public static function verifier(#[\SensitiveParameter] string|array $secret, int $maxAgeSeconds = 300, ?EventDispatcher $events = null): WebhookVerifier
    {
        return new WebhookVerifier($secret, $maxAgeSeconds, events: $events);
    }
}
