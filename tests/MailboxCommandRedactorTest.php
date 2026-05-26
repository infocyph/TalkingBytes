<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Event\EmailEventBus;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxCommandRedactor;

it('redacts sensitive imap login and authenticate commands', function (): void {
    expect(MailboxCommandRedactor::redact('imap', 'LOGIN "user" "secret"'))
        ->toBe('LOGIN "user" [REDACTED]');

    expect(MailboxCommandRedactor::redact('imap', 'AUTHENTICATE PLAIN dXNlcgB1c2VyAHNlY3JldA=='))
        ->toBe('AUTHENTICATE [REDACTED]');

    expect(MailboxCommandRedactor::redact('imap', 'SELECT "INBOX"'))
        ->toBe('SELECT "INBOX"');
});

it('redacts sensitive pop3 pass command', function (): void {
    expect(MailboxCommandRedactor::redact('pop3', 'PASS secret-password'))
        ->toBe('PASS [REDACTED]');

    expect(MailboxCommandRedactor::redact('pop3', 'APOP user deadbeef123'))
        ->toBe('APOP user [REDACTED]');

    expect(MailboxCommandRedactor::redact('pop3', 'AUTH PLAIN dGVzdA=='))
        ->toBe('AUTH [REDACTED]');

    expect(MailboxCommandRedactor::redact('pop3', 'USER test'))
        ->toBe('USER test');
});

it('dispatches redacted IMAP command payloads in start and finish events', function (): void {
    $events = [];
    EmailEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'mailbox.command.')) {
            $events[] = ['event' => $event, 'command' => (string) ($payload['command'] ?? '')];
        }
    });
    $redacted = MailboxCommandRedactor::redact('imap', 'LOGIN "user" "password"');
    EmailEventBus::dispatch('mailbox.command.start', ['command' => $redacted]);
    EmailEventBus::dispatch('mailbox.command.finish', ['command' => $redacted]);
    EmailEventBus::listen(null);

    expect($events[0]['event'] ?? null)->toBe('mailbox.command.start');
    expect($events[1]['event'] ?? null)->toBe('mailbox.command.finish');
    expect($events[0]['command'] ?? '')->toBe('LOGIN "user" [REDACTED]');
    expect($events[1]['command'] ?? '')->toBe('LOGIN "user" [REDACTED]');
});

it('dispatches redacted IMAP AUTHENTICATE payloads in mailbox events', function (): void {
    $events = [];
    EmailEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'mailbox.command.')) {
            $events[] = ['event' => $event, 'command' => (string) ($payload['command'] ?? '')];
        }
    });

    $redacted = MailboxCommandRedactor::redact('imap', 'AUTHENTICATE PLAIN dXNlcgB1c2VyAHNlY3JldA==');
    EmailEventBus::dispatch('mailbox.command.start', ['command' => $redacted]);
    EmailEventBus::dispatch('mailbox.command.finish', ['command' => $redacted]);
    EmailEventBus::listen(null);

    expect($events[0]['command'] ?? '')->toBe('AUTHENTICATE [REDACTED]');
    expect($events[1]['command'] ?? '')->toBe('AUTHENTICATE [REDACTED]');
});

it('dispatches redacted POP3 command payloads in start and finish events', function (): void {
    $events = [];
    EmailEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'mailbox.command.')) {
            $events[] = ['event' => $event, 'command' => (string) ($payload['command'] ?? '')];
        }
    });

    $commands = [
        MailboxCommandRedactor::redact('pop3', 'PASS super-secret'),
        MailboxCommandRedactor::redact('pop3', 'APOP user deadbeef'),
        MailboxCommandRedactor::redact('pop3', 'AUTH PLAIN dGVzdA=='),
    ];
    foreach ($commands as $command) {
        EmailEventBus::dispatch('mailbox.command.start', ['command' => $command]);
        EmailEventBus::dispatch('mailbox.command.finish', ['command' => $command]);
    }
    EmailEventBus::listen(null);

    expect($events[0]['event'] ?? null)->toBe('mailbox.command.start');
    expect($events[1]['event'] ?? null)->toBe('mailbox.command.finish');
    expect($events[0]['command'] ?? '')->toBe('PASS [REDACTED]');
    expect($events[1]['command'] ?? '')->toBe('PASS [REDACTED]');
    expect($events[2]['command'] ?? '')->toBe('APOP user [REDACTED]');
    expect($events[3]['command'] ?? '')->toBe('APOP user [REDACTED]');
    expect($events[4]['command'] ?? '')->toBe('AUTH [REDACTED]');
    expect($events[5]['command'] ?? '')->toBe('AUTH [REDACTED]');
});
