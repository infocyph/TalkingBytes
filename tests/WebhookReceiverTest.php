<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Webhook\Contracts\WebhookReplayStore;
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
        ->buildHeader($invalidJson, (int) $headers['X-TB-Timestamp'], $headers['X-TB-Event'], $headers['X-TB-Delivery']);

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
    expect($store->claim('tenant-a', 'evt_1', 3600))->toBeTrue();

    expect($store->claim('tenant-a', 'evt_1', 3600))->toBeFalse();
    expect(fn() => $store->claim('tenant-a', 'evt_2', 3600))
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

    $spacedEvent = $headers;
    $spacedEvent['X-TB-Event'] = ' order.created';
    expect(fn() => $receiver->receive($payload, $spacedEvent))
        ->toThrow(InvalidArgumentException::class, 'surrounding whitespace');
});


it('claims each replay identity only once and validates replay inputs', function (): void {
    $store = new InMemoryWebhookReplayStore();

    $claims = [
        $store->claim('tenant-a', 'evt_contention', 60),
        $store->claim('tenant-a', 'evt_contention', 60),
    ];

    expect(array_values(array_filter($claims)))->toHaveCount(1);
    expect(fn() => $store->claim('tenant-a', 'evt_ttl', 0))
        ->toThrow(InvalidArgumentException::class, 'TTL must be greater than zero');

    $receiver = (new WebhookReceiver(Webhook::verifier('whsec_test')))
        ->withReplayStore($store, 60);

    expect(fn() => $receiver->withReplayStore($store, 60, ''))
        ->toThrow(InvalidArgumentException::class, 'namespace must not be empty');
});

it('verifies webhook signatures before touching replay state', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 2],
        deliveryId: 'evt_verify_first',
    );

    $store = new class implements WebhookReplayStore {
        public int $claims = 0;

        public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
        {
            unset($namespace, $deliveryId, $ttlSeconds);
            $this->claims++;

            return true;
        }
    };

    $receiver = (new WebhookReceiver(Webhook::verifier('whsec_test')))
        ->withReplayStore($store, 60);

    $headers['X-TB-Signature'] = 't=1,v1=invalid';

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'Webhook verification failed');
    expect($store->claims)->toBe(0);
});

it('fails closed when the replay backend cannot claim a verified delivery', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson(
        secret: 'whsec_test',
        event: 'order.created',
        payload: ['id' => 3],
        deliveryId: 'evt_backend_failure',
    );

    $store = new class implements WebhookReplayStore {
        public function claim(string $namespace, string $deliveryId, int $ttlSeconds): bool
        {
            unset($namespace, $deliveryId, $ttlSeconds);

            throw new RuntimeException('replay backend unavailable');
        }
    };

    $receiver = (new WebhookReceiver(Webhook::verifier('whsec_test')))
        ->withReplayStore($store, 60);

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'replay backend unavailable');
});

it('authenticates delivery identity and event before claiming replay state', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson('secret', 'order.created', ['id' => 1], 'original');
    $receiver = Webhook::receiver('secret')->withReplayStore(new InMemoryWebhookReplayStore());

    foreach (['X-TB-Delivery' => 'altered', 'X-TB-Event' => 'order.refunded'] as $name => $value) {
        $tampered = $headers;
        $tampered[$name] = $value;
        expect(fn() => $receiver->receive($payload, $tampered))->toThrow(RuntimeException::class, 'signature_mismatch');
    }

    expect($receiver->receive($payload, $headers)->deliveryId)->toBe('original');
    expect(fn() => $receiver->receive($payload, $headers))->toThrow(RuntimeException::class, 'already been processed');
    $headers['X-TB-Delivery'] = 'replayed';
    expect(fn() => $receiver->receive($payload, $headers))->toThrow(RuntimeException::class, 'signature_mismatch');
});

it('rejects legacy signature downgrade even alongside an invalid bound signature', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson('secret', 'order.created', ['id' => 1]);
    $legacy = (new WebhookSignature('secret'))->buildHeader($payload, (int) $headers['X-TB-Timestamp']);
    $receiver = Webhook::receiver('secret');
    $headers['X-TB-Signature'] = $legacy;
    expect(fn() => $receiver->receive($payload, $headers))->toThrow(RuntimeException::class, 'malformed_signature');
    $headers['X-TB-Signature'] .= ',v2=' . str_repeat('0', 64);
    expect(fn() => $receiver->receive($payload, $headers))->toThrow(RuntimeException::class, 'signature_mismatch');
});

it('accepts bound signatures across secret rotation and rejects payload and timestamp tampering', function (): void {
    [$payload, $headers] = WebhookTestFactory::signedJson('old-secret', 'order.created', ['id' => 1]);
    $receiver = new WebhookReceiver(new \Infocyph\TalkingBytes\Webhook\WebhookVerifier(['new-secret', 'old-secret']));
    expect($receiver->receive($payload, $headers)->payload)->toBe(['id' => 1]);
    expect(fn() => $receiver->receive('{"id":2}', $headers))->toThrow(RuntimeException::class, 'signature_mismatch');
    $headers['X-TB-Timestamp'] = (string) ((int) $headers['X-TB-Timestamp'] + 1);
    expect(fn() => $receiver->receive($payload, $headers))->toThrow(RuntimeException::class, 'signature_mismatch');
});

it('receives the signed request produced by the native sender', function (): void {
    $transport = new \Infocyph\TalkingBytes\Http\Testing\FakeHttpTransport();
    Webhook::sender(\Infocyph\TalkingBytes\Http\HttpClient::using($transport))->withSecret('secret')->send(
        \Infocyph\TalkingBytes\Webhook\WebhookMessage::event('order.created')->url('https://example.test/hook')->payload(['id' => 1]),
    );
    $request = $transport->sentRequests()[0];
    $headers = [];
    foreach (['X-TB-Event', 'X-TB-Delivery', 'X-TB-Timestamp', 'X-TB-Signature'] as $name) {
        $headers[$name] = (string) $request->headers->get($name);
    }
    expect(Webhook::receiver('secret')->receive('{"id":1}', $headers)->event)->toBe('order.created');
});


it('retains replay claims for the full remaining signature acceptance window', function (): void {
    $now = 1_000_000.0;
    $clock = new Clock(static function () use (&$now): float {
        return $now;
    });
    $verifier = new \Infocyph\TalkingBytes\Webhook\WebhookVerifier(
        'secret',
        maxAgeSeconds: 300,
        clock: $clock,
    );
    $store = new InMemoryWebhookReplayStore(clock: $clock);
    $receiver = (new WebhookReceiver($verifier))->withReplayStore($store, 1);

    [$payload, $headers] = WebhookTestFactory::signedJson(
        'secret',
        'order.created',
        ['id' => 10],
        'evt_short_ttl',
        (int) $now,
    );

    expect($receiver->receive($payload, $headers)->deliveryId)->toBe('evt_short_ttl');

    $now += 2.0;

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'already been processed');
});

it('covers accepted future timestamps until they leave the verification window', function (): void {
    $now = 2_000_000.0;
    $clock = new Clock(static function () use (&$now): float {
        return $now;
    });
    $verifier = new \Infocyph\TalkingBytes\Webhook\WebhookVerifier(
        'secret',
        maxAgeSeconds: 300,
        clock: $clock,
    );
    $store = new InMemoryWebhookReplayStore(clock: $clock);
    $receiver = (new WebhookReceiver($verifier))->withReplayStore($store, 1);
    $futureTimestamp = (int) $now + 300;

    [$payload, $headers] = WebhookTestFactory::signedJson(
        'secret',
        'order.created',
        ['id' => 11],
        'evt_future',
        $futureTimestamp,
    );

    expect($receiver->receive($payload, $headers)->deliveryId)->toBe('evt_future');

    $now += 301.0;

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'already been processed');

    $now += 300.0;

    expect(fn() => $receiver->receive($payload, $headers))
        ->toThrow(RuntimeException::class, 'expired_timestamp');
});


it('uses monotonic retention for in-memory replay claims across wall-clock jumps', function (): void {
    $wall = 10_000.0;
    $mono = 100.0;
    $clock = new Clock(
        static function () use (&$wall): float {
            return $wall;
        },
        static function () use (&$mono): float {
            return $mono;
        },
    );
    $store = new InMemoryWebhookReplayStore(clock: $clock);

    expect($store->claim('tenant-a', 'evt_clock_jump', 60))->toBeTrue();

    $wall += 86_400.0;
    $mono += 1.0;
    expect($store->claim('tenant-a', 'evt_clock_jump', 60))->toBeFalse();

    $wall -= 172_800.0;
    $mono += 58.0;
    expect($store->claim('tenant-a', 'evt_clock_jump', 60))->toBeFalse();

    $mono += 1.0;
    expect($store->claim('tenant-a', 'evt_clock_jump', 60))->toBeTrue();
});
