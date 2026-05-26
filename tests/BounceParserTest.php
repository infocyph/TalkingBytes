<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Enum\BounceType;
use Infocyph\TalkingBytes\Email\Event\EmailEventBus;
use Infocyph\TalkingBytes\Email\Parser\BounceParser;
use Infocyph\TalkingBytes\Email\Parser\DeliveryStatusParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;

it('parses standard dsn bounce report with delivery status fields', function (): void {
    $raw = implode("\r\n", [
        'From: MAILER-DAEMON@example.com',
        'To: sender@example.com',
        'Subject: Mail delivery failed',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="b1"',
        '',
        '--b1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Delivery has failed to these recipients:',
        '',
        '--b1',
        'Content-Type: message/delivery-status',
        '',
        'Reporting-MTA: dns; mx.example.net',
        '',
        'Final-Recipient: rfc822; user@example.com',
        'Action: failed',
        'Status: 5.1.1',
        'Diagnostic-Code: smtp; 550 5.1.1 User unknown',
        'Remote-MTA: dns; mx.remote.net',
        '',
        '--b1--',
    ]);

    $email = (new RawEmailParser)->parse($raw);
    $report = (new BounceParser)->parse($email);

    expect($report)->not->toBeNull();
    expect($report?->type)->toBe(BounceType::UserUnknown);
    expect($report?->recipient)->toBe('user@example.com');
    expect($report?->status)->toBe('5.1.1');
    expect($report?->diagnosticCode)->toContain('550 5.1.1 User unknown');
    expect($report?->metadata['source'] ?? null)->toBe('delivery-status');
});

it('classifies plain text heuristic bounce when dsn part is missing', function (): void {
    $raw = implode("\r\n", [
        'From: postmaster@example.com',
        'To: sender@example.com',
        'Subject: Undelivered Mail Returned to Sender',
        '',
        'This is the mail system at host mx.example.com.',
        'Diagnostic-Code: smtp; 552 5.2.2 Mailbox full',
        'Failed recipient: fullbox@example.com',
    ]);

    $email = (new RawEmailParser)->parse($raw);
    $report = (new BounceParser)->parse($email);

    expect($report)->not->toBeNull();
    expect($report?->type)->toBe(BounceType::MailboxFull);
    expect($report?->recipient)->toBe('fullbox@example.com');
    expect($report?->status)->toBe('5.2.2');
});

it('returns null for non-bounce emails', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Welcome',
        '',
        'Hello there.',
    ]);

    $email = (new RawEmailParser)->parse($raw);
    $report = (new BounceParser)->parse($email);

    expect($report)->toBeNull();
});

it('dispatches bounce.detected event when bounce is parsed', function (): void {
    $events = [];
    EmailEventBus::listen(static function (string $event, array $payload) use (&$events): void {
        $events[] = ['event' => $event, 'payload' => $payload];
    });

    $raw = implode("\r\n", [
        'From: postmaster@example.com',
        'To: sender@example.com',
        'Subject: Undelivered Mail Returned to Sender',
        '',
        'Diagnostic-Code: smtp; 552 5.2.2 Mailbox full',
        'Failed recipient: fullbox@example.com',
    ]);

    $email = (new RawEmailParser)->parse($raw);
    $report = (new BounceParser)->parse($email);

    expect($report)->not->toBeNull();
    expect($events)->not->toBeEmpty();
    expect($events[0]['event'])->toBe('bounce.detected');
    expect($events[0]['payload']['type'])->toBe(BounceType::MailboxFull->value);
});

it('parses many bounce reports from multi-recipient dsn payload', function (): void {
    $raw = implode("\r\n", [
        'From: MAILER-DAEMON@example.com',
        'To: sender@example.com',
        'Subject: Delivery Status Notification',
        'Content-Type: multipart/report; report-type=delivery-status; boundary="b1"',
        '',
        '--b1',
        'Content-Type: message/delivery-status',
        '',
        'Reporting-MTA: dns; mx.example.net',
        '',
        'Final-Recipient: rfc822; unknown@example.com',
        'Action: failed',
        'Status: 5.1.1',
        'Diagnostic-Code: smtp; 550 5.1.1 User unknown',
        '',
        'Final-Recipient: rfc822; full@example.com',
        'Action: failed',
        'Status: 5.2.2',
        'Diagnostic-Code: smtp; 552 5.2.2 Mailbox full',
        '',
        '--b1--',
    ]);

    $email = (new RawEmailParser)->parse($raw);
    $reports = (new BounceParser)->parseMany($email);

    expect($reports)->toHaveCount(2);
    expect($reports[0]->recipient)->toBe('unknown@example.com');
    expect($reports[0]->type)->toBe(BounceType::UserUnknown);
    expect($reports[1]->recipient)->toBe('full@example.com');
    expect($reports[1]->type)->toBe(BounceType::MailboxFull);
});

it('parses repeated delivery-status recipient blocks', function (): void {
    $body = implode("\r\n", [
        'Reporting-MTA: dns; mx.example.net',
        '',
        'Final-Recipient: rfc822; unknown@example.com',
        'Action: failed',
        'Status: 5.1.1',
        'Diagnostic-Code: smtp; 550 User unknown',
        '',
        'Final-Recipient: rfc822; blocked@example.com',
        'Action: failed',
        'Status: 5.7.1',
        'Diagnostic-Code: smtp; 550 blocked by policy',
    ]);

    $reports = (new DeliveryStatusParser)->parseMany($body);

    expect($reports)->toHaveCount(2);
    expect($reports[0]['final_recipient'])->toBe('unknown@example.com');
    expect($reports[0]['reporting_mta'])->toBe('mx.example.net');
    expect($reports[1]['final_recipient'])->toBe('blocked@example.com');
    expect($reports[1]['diagnostic_code'])->toContain('blocked by policy');
});

it('classifies blocked and temporary bounce diagnostics', function (): void {
    $blockedRaw = implode("\r\n", [
        'From: postmaster@example.com',
        'To: sender@example.com',
        'Subject: Undelivered Mail Returned to Sender',
        '',
        'Status: 5.7.1',
        'Diagnostic-Code: smtp; 550 blocked by policy',
    ]);

    $temporaryRaw = implode("\r\n", [
        'From: postmaster@example.com',
        'To: sender@example.com',
        'Subject: Mail delivery failed',
        '',
        'Status: 4.4.1',
        'Diagnostic-Code: smtp; Temporary failure, try again later',
    ]);

    $blocked = (new BounceParser)->parse((new RawEmailParser)->parse($blockedRaw));
    $temporary = (new BounceParser)->parse((new RawEmailParser)->parse($temporaryRaw));

    expect($blocked?->type)->toBe(BounceType::SpamRejected);
    expect($temporary?->type)->toBe(BounceType::Temporary);
});

it('classifies status-code specific bounce categories', function (): void {
    $cases = [
        ['status' => '5.1.1', 'diagnostic' => 'smtp; 550 5.1.1 User unknown', 'expected' => BounceType::UserUnknown],
        ['status' => '5.1.2', 'diagnostic' => 'smtp; 550 5.1.2 Domain not found', 'expected' => BounceType::DomainNotFound],
        ['status' => '5.2.2', 'diagnostic' => 'smtp; 552 5.2.2 Mailbox full', 'expected' => BounceType::MailboxFull],
        ['status' => '4.2.2', 'diagnostic' => 'smtp; 452 4.2.2 Mailbox full temporary', 'expected' => BounceType::MailboxFull],
        ['status' => '4.4.1', 'diagnostic' => 'smtp; 451 temporary failure', 'expected' => BounceType::Soft],
        ['status' => '5.7.1', 'diagnostic' => 'smtp; 550 rejected by policy spam', 'expected' => BounceType::SpamRejected],
    ];

    $parser = new BounceParser;

    foreach ($cases as $case) {
        $raw = implode("\r\n", [
            'From: MAILER-DAEMON@example.com',
            'To: sender@example.com',
            'Subject: Delivery Status Notification',
            'Content-Type: multipart/report; report-type=delivery-status; boundary="b1"',
            '',
            '--b1',
            'Content-Type: message/delivery-status',
            '',
            'Reporting-MTA: dns; mx.example.net',
            '',
            'Final-Recipient: rfc822; user@example.com',
            'Action: failed',
            'Status: '.$case['status'],
            'Diagnostic-Code: '.$case['diagnostic'],
            '',
            '--b1--',
        ]);

        $report = $parser->parse((new RawEmailParser)->parse($raw));
        expect($report)->not->toBeNull();
        expect($report?->type)->toBe($case['expected']);
    }
});
