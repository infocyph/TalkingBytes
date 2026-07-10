<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CommunicationEventBus;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\HttpResponse;
use Infocyph\TalkingBytes\Http\Testing\SequenceHttpTransport;
use Infocyph\TalkingBytes\Webhook\Support\WebhookHeaders;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;
use Infocyph\TalkingBytes\Webhook\WebhookSender;

it('applies webhook retry profile and retries transient failures', function (): void {
    $transport = new SequenceHttpTransport([
        CommunicationResult::failure(
            'HTTP request failed with status code 500.',
            500,
            new HttpResponse(500, '{"error":true}'),
        ),
        CommunicationResult::success(
            200,
            new HttpResponse(200, '{"ok":true}'),
        ),
    ]);

    $sender = WebhookSender::usingHttpWithRetryProfile(HttpClient::using($transport), attempts: 2, baseDelayMs: 0);

    $delivery = $sender->send(
        WebhookMessage::new('order.created')->payload(['order_id' => 1001])->url('https://hooks.example.test/orders'),
    );

    expect($delivery->result->successful)->toBeTrue();
    expect($transport->sentRequests())->toHaveCount(2);
});

it('does not retry non-transient webhook client errors in retry profile', function (): void {
    $transport = new SequenceHttpTransport([
        CommunicationResult::failure(
            'HTTP request failed with status code 400.',
            400,
            new HttpResponse(400, '{"error":true}'),
        ),
    ]);

    $sender = WebhookSender::usingHttpWithRetryProfile(HttpClient::using($transport), attempts: 3, baseDelayMs: 0);

    $delivery = $sender->send(
        WebhookMessage::new('order.created')->payload(['order_id' => 1001])->url('https://hooks.example.test/orders'),
    );

    expect($delivery->result->successful)->toBeFalse();
    expect($transport->sentRequests())->toHaveCount(1);
});

it('retries transport errors in webhook retry profile', function (): void {
    $transport = new SequenceHttpTransport([
        CommunicationResult::failure('Connection refused'),
        CommunicationResult::success(
            200,
            new HttpResponse(200, '{"ok":true}'),
        ),
    ]);

    $sender = WebhookSender::usingHttpWithRetryProfile(HttpClient::using($transport), attempts: 2, baseDelayMs: 0);

    $delivery = $sender->send(
        WebhookMessage::new('order.created')->payload(['order_id' => 1001])->url('https://hooks.example.test/orders'),
    );

    expect($delivery->result->successful)->toBeTrue();
    expect($transport->sentRequests())->toHaveCount(2);
});

it('tracks webhook retry attempts and emits redacted retry events', function (): void {
    $events = [];
    CommunicationEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'webhook.')) {
            $events[] = ['event' => $event, 'payload' => $payload];
        }
    });

    $transport = new SequenceHttpTransport([
        CommunicationResult::failure('HTTP request failed with status code 500.', 500, new HttpResponse(500, '{"error":true}')),
        CommunicationResult::success(200, new HttpResponse(200, '{"ok":true}')),
    ]);

    $sender = WebhookSender::usingHttpWithRetryProfile(HttpClient::using($transport), attempts: 2, baseDelayMs: 0)
        ->withSecret('whsec_test');

    $delivery = $sender->send(
        WebhookMessage::new('order.created')
            ->url('https://hooks.example.test/orders?token=secret-value')
            ->payload(['order_id' => 1001]),
    );

    CommunicationEventBus::listen(null);

    $requests = $transport->sentRequests();
    expect($delivery->delivery?->attempts)->toBe(2)
        ->and($requests)->toHaveCount(2)
        ->and($requests[0]->headers->get(WebhookHeaders::ATTEMPT))->toBe('1')
        ->and($requests[1]->headers->get(WebhookHeaders::ATTEMPT))->toBe('2')
        ->and($requests[0]->headers->get(WebhookHeaders::DELIVERY))
        ->toBe($requests[1]->headers->get(WebhookHeaders::DELIVERY))
        ->and($events[1]['event'])->toBe('webhook.retry')
        ->and($events[1]['payload']['url'])->toContain('token=%5BREDACTED%5D')
        ->and((string) ($events[1]['payload']['signature'] ?? ''))->toBe('');
});

it('rejects overriding reserved webhook headers', function (): void {
    expect(fn() => WebhookMessage::new('order.created')->header('X-TB-Event', 'override'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn() => WebhookMessage::new('order.created')->headers(['Content-Type' => 'text/plain']))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts only valid json in raw payload helper', function (): void {
    $message = WebhookMessage::new('order.created')->rawJsonPayload('{"ok":true}');

    expect($message->payload)->toBe('{"ok":true}');
    expect(fn() => WebhookMessage::new('order.created')->rawPayload('{'))
        ->toThrow(JsonException::class);
});
