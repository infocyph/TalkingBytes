<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Webhook\Replay\InMemoryWebhookReplayStore;
use Infocyph\TalkingBytes\Webhook\Testing\WebhookTestFactory;
use Infocyph\TalkingBytes\Webhook\Webhook;
use Infocyph\TalkingBytes\Webhook\WebhookReceiver;
use Infocyph\TalkingBytes\Webhook\Model\WebhookSignature;

it('receives, verifies and decodes webhook events', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 1],
    );

    $event = Webhook::receiver('whsec_test')->receive($payload, $headers);

    expect($event->event)->toBe('order.created');
    expect($event->payload['id'])->toBe(1);
    expect($event->deliveryId)->toBe($headers['X-TB-Delivery']);
});

it('rejects invalid payload json and invalid signatures', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 1],
    );

    $receiver = Webhook::receiver('whsec_test');

    $badHeaders = $headers;
    $badHeaders['X-TB-Signature'] = 't=1,v1=bad';

    expect(fn() => $receiver->receive($payload, $badHeaders))
        ->toThrow(RuntimeException::class, 'Webhook verification failed');

    $invalidJson = '{';
    $invalidJsonHeaders = $headers;
    $invalidJsonHeaders['X-TB-Signature'] = (new WebhookSignature('whsec_test'))
        ->buildHeader($invalidJson, (int) $headers['X-TB-Timestamp']);

    expect(fn() => $receiver->receive($invalidJson, $invalidJsonHeaders))
        ->toThrow(InvalidArgumentException::class, 'Webhook payload must be valid JSON.');
});

it('supports replay store duplicate detection', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 1],
        deliveryId: 'evt_123',
    );

    $receiver = (new WebhookReceiver(Webhook::verifier('whsec_test')))
        ->withReplayStore(new InMemoryWebhookReplayStore(), 3600);

    $receiver->receive($payload, $headers);

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'already been processed');
});

it('bounds the in-memory replay store and fails closed at capacity', function (): void {
    $store = new InMemoryWebhookReplayStore(maxEntries: 1);
    $store->remember('evt_1', 3600);
    $store->remember('evt_1', 3600);

    expect($store->seen('evt_1'))->toBeTrue();
    expect(fn() => $store->remember('evt_2', 3600))
        ->toThrow(RuntimeException::class, 'capacity has been exhausted');
    expect(fn() => new InMemoryWebhookReplayStore(maxEntries: 0))
        ->toThrow(InvalidArgumentException::class);
});

it('validates event and delivery header values using name guard', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 1],
    );

    $receiver = Webhook::receiver('whsec_test');

    $badEvent = $headers;
    $badEvent['X-TB-Event'] = "order.created\r\nbad";
    expect(fn() => $receiver->receive($payload, $badEvent))
        ->toThrow(InvalidArgumentException::class, 'Webhook event must not contain control characters.');

    $badDelivery = $headers;
    $badDelivery['X-TB-Delivery'] = '';
    expect(fn() => $receiver->receive($payload, $badDelivery))
        ->toThrow(InvalidArgumentException::class, 'Webhook delivery ID must not be empty.');
});
