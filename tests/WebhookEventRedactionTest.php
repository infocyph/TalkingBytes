<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

it('does not leak webhook secret, raw payload, or signature in event payloads', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'webhook.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $secret = 'whsec_super_secret';
    $rawPayload = '{"order_id":1001,"token":"payload-secret"}';

    $transport = new FakeHttpTransport();
    $sender = Webhook::sender(HttpClient::using($transport))->withSecret($secret);

    $delivery = $sender->send(
        WebhookMessage::new('order.created')
            ->url('https://hooks.example.test/orders?token=url-secret')
            ->rawJsonPayload($rawPayload),
    );

    $sent = $transport->sentRequests();
    expect($sent)->toHaveCount(1);

    /** @var string $signatureHeader */
    $signatureHeader = (string) $sent[0]->headers->get(WebhookHeaders::SIGNATURE);

    // Trigger verifier events as well.
    Webhook::verifier($secret)->verifyResult($rawPayload, $signatureHeader);
    Webhook::verifier($secret)->verifyResult($rawPayload, 't=1,v1=not-a-real-signature');

    CommunicationEventBus::listen(null);

    expect($delivery->result->successful)->toBeTrue()
        ->and($events)->not->toBeEmpty();

    foreach ($events as $captured) {
        $encoded = json_encode($captured['payload'], JSON_THROW_ON_ERROR);

        expect($encoded)->not->toContain($secret)
            ->not->toContain($rawPayload)
            ->not->toContain($signatureHeader)
            ->not->toContain('payload-secret')
            ->not->toContain('url-secret');
    }
});
