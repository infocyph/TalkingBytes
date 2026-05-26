<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Webhook\Testing\FakeWebhookSender;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;

it('records sent webhooks and supports assertions', function (): void {
    $fake = new FakeWebhookSender;

    $fake->send(
        WebhookMessage::event('order.created')
            ->url('https://hooks.example.test/order')
            ->payload(['id' => 1]),
    );

    $assert = $fake->assert();
    $assert->assertSentCount(1);
    $assert->assertSentTo('https://hooks.example.test/order');
    $assert->assertEvent('order.created');
    $assert->assertPayloadWhere(static fn (mixed $payload): bool => is_array($payload) && $payload['id'] === 1);
    expect($assert->last())->not->toBeNull();
});
