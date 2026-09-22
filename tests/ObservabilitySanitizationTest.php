<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Event\CallableEventDispatcher;
use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxCommandRedactor;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LoggingEmailTransport;
use Infocyph\TalkingBytes\Grpc\GrpcClient;
use Infocyph\TalkingBytes\Grpc\GrpcMetadata;
use Infocyph\TalkingBytes\Grpc\Sender\GrpcRequest;
use Infocyph\TalkingBytes\Http\HttpClient;
use Infocyph\TalkingBytes\Http\Support\HttpRedactor;
use Infocyph\TalkingBytes\Http\Testing\SequenceHttpTransport;
use Infocyph\TalkingBytes\Webhook\WebhookMessage;
use Infocyph\TalkingBytes\Webhook\WebhookSender;

function observabilityJson(array $events): string
{
    return json_encode($events, JSON_THROW_ON_ERROR);
}

it('redacts auth-like HTTP headers used by protocol layers', function (): void {
    $headers = HttpRedactor::redactHeaders([
        'Authorization' => 'Bearer sentinel-auth',
        'Proxy-Authorization' => 'Basic sentinel-proxy',
        'Cookie' => 'session=sentinel-cookie',
        'X-Api-Key' => 'sentinel-api-key',
        'X-TB-Signature' => 't=1,v1=sentinel-signature',
        'Accept' => 'application/json',
    ]);

    expect($headers['Authorization'])->toBe('[REDACTED]')
        ->and($headers['Proxy-Authorization'])->toBe('[REDACTED]')
        ->and($headers['Cookie'])->toBe('[REDACTED]')
        ->and($headers['X-Api-Key'])->toBe('[REDACTED]')
        ->and($headers['X-TB-Signature'])->toBe('[REDACTED]')
        ->and($headers['Accept'])->toBe('application/json');
});

it('keeps webhook failure events free of raw errors and secrets', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'webhook.')) {
            $events[] = [$event, $payload];
        }
    });
    $transport = new SequenceHttpTransport([
        CommunicationResult::failure('sentinel-webhook-error', 503),
    ]);
    $sender = (new WebhookSender(HttpClient::using($transport), events: $dispatcher))
        ->withSecret('sentinel-webhook-signing-secret');

    $sender->send(
        WebhookMessage::new('order.created')
            ->url('https://hooks.example.test/orders?token=sentinel-query-secret')
            ->payload(['private' => 'sentinel-body-secret']),
    );

    $encoded = observabilityJson($events);
    expect($encoded)->not->toContain('sentinel-webhook-error')
        ->and($encoded)->not->toContain('sentinel-webhook-signing-secret')
        ->and($encoded)->not->toContain('sentinel-body-secret')
        ->and($encoded)->not->toContain('sentinel-query-secret')
        ->and($encoded)->toContain('failure_category');
});

it('keeps email events and logging decorators free of subject and raw errors', function (): void {
    $events = [];
    $logs = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        $events[] = [$event, $payload];
    });
    $transport = new class implements EmailTransport {
        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            return CommunicationResult::failure(
                'sentinel-email-error',
                metadata: ['transport' => 'sentinel-transport', 'secret' => 'sentinel-metadata-secret'],
            );
        }
    };
    $message = EmailMessage::new()
        ->from('sender@example.test')
        ->to('private-person@example.test')
        ->subject('sentinel-private-subject')
        ->text('sentinel-private-body');

    (new Emailer($transport, $dispatcher))->send($message);
    (new LoggingEmailTransport(
        $transport,
        static function (string $event, array $context) use (&$logs): void {
            $logs[] = [$event, $context];
        },
    ))->send($message);

    $encoded = observabilityJson([...$events, ...$logs]);
    expect($encoded)->not->toContain('sentinel-email-error')
        ->and($encoded)->not->toContain('sentinel-private-subject')
        ->and($encoded)->not->toContain('private-person@example.test')
        ->and($encoded)->not->toContain('sentinel-private-body')
        ->and($encoded)->not->toContain('sentinel-metadata-secret')
        ->and($encoded)->toContain('failure_category');
});

it('keeps grpc events free of exception messages and metadata values', function (): void {
    $events = [];
    $dispatcher = new CallableEventDispatcher(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'grpc.')) {
            $events[] = [$event, $payload];
        }
    });
    $headers = (new GrpcMetadata())->withValue('authorization', 'sentinel-grpc-metadata');
    $client = GrpcClient::using(
        static function (GrpcRequest $request): never {
            expect($request->headers->first('authorization'))->toBe('sentinel-grpc-metadata');
            throw new RuntimeException('sentinel-grpc-error');
        },
        $dispatcher,
    );

    $client->send(new GrpcRequest('/example.Service/Call', ['secret' => 'sentinel-grpc-body'], $headers));

    $encoded = observabilityJson($events);
    expect($encoded)->not->toContain('sentinel-grpc-error')
        ->and($encoded)->not->toContain('sentinel-grpc-metadata')
        ->and($encoded)->not->toContain('sentinel-grpc-body')
        ->and($encoded)->toContain(RuntimeException::class);
});

it('removes mailbox auth usernames and credentials from diagnostics', function (): void {
    expect(MailboxCommandRedactor::redact('imap', 'LOGIN "sentinel-user" "sentinel-password"'))
        ->toBe('LOGIN [REDACTED] [REDACTED]')
        ->and(MailboxCommandRedactor::redact('pop3', 'USER sentinel-user'))->toBe('USER [REDACTED]')
        ->and(MailboxCommandRedactor::redact('pop3', 'PASS sentinel-password'))->toBe('PASS [REDACTED]')
        ->and(MailboxCommandRedactor::redact('pop3', 'APOP sentinel-user sentinel-digest'))
        ->toBe('APOP [REDACTED] [REDACTED]');
});
