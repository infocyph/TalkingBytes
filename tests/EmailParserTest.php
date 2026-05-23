<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;

it('parses folded and duplicate headers', function (): void {
    $raw = implode("\r\n", [
        'From: "Sender Name" <sender@example.com>',
        'To: alice@example.com, Bob <bob@example.com>',
        'Subject: =?UTF-8?B?VGVzdCDDpA==?=',
        'Received: by mx1.example.net',
        'Received: by mx2.example.net',
        'X-Trace: one',
        "\tcontinued",
        '',
        'Body',
    ]);

    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->subject)->toBe('Test ä');
    expect($parsed->headers['received'] ?? [])->toHaveCount(2);
    expect($parsed->headers['x-trace'][0] ?? null)->toBe('one continued');
    expect($parsed->from->count())->toBe(1);
    expect($parsed->to->count())->toBe(2);
});

it('parses nested multipart email and extracts attachments', function (): void {
    $boundaryMixed = 'mixed-123';
    $boundaryAlt = 'alt-123';
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Multipart',
        sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundaryMixed),
        '',
        sprintf('--%s', $boundaryMixed),
        sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundaryAlt),
        '',
        sprintf('--%s', $boundaryAlt),
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        'Hello=20plain',
        sprintf('--%s', $boundaryAlt),
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        '<p>Hello html</p>',
        sprintf('--%s--', $boundaryAlt),
        sprintf('--%s', $boundaryMixed),
        'Content-Type: application/pdf; name="report.pdf"',
        'Content-Disposition: attachment; filename="report.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('PDF-CONTENT'),
        sprintf('--%s--', $boundaryMixed),
        '',
    ]);

    $parsed = (new RawEmailParser())->parse($raw);

    expect($parsed->textBody)->toBe('Hello plain');
    expect($parsed->htmlBody)->toContain('Hello html');
    expect($parsed->attachments)->toHaveCount(1);
    expect($parsed->attachments[0]->filename)->toBe('report.pdf');
    expect($parsed->attachments[0]->contents())->toBe('PDF-CONTENT');
});

it('supports spool receiver peek and receiveMany with parser-backed output', function (): void {
    $directory = getcwd() . '/tests/.tmp-spool-parse-' . bin2hex(random_bytes(4));
    mkdir($directory, 0775, true);

    file_put_contents($directory . '/20260101_000001_a.eml', "From: sender@example.com\r\nTo: a@example.com\r\nSubject: A\r\n\r\nBody A");
    file_put_contents($directory . '/20260101_000002_b.eml', "From: sender@example.com\r\nTo: b@example.com\r\nSubject: B\r\n\r\nBody B");

    $receiver = new SpoolEmailReceiver(new SpoolConfig($directory), deleteAfterRead: true);

    $peeked = $receiver->peek();
    expect($peeked?->subject)->toBe('A');

    $emails = $receiver->receiveMany(2);
    expect($emails)->toHaveCount(2);
    expect($emails[0]->subject)->toBe('A');
    expect($emails[1]->subject)->toBe('B');
    expect(glob($directory . '/*.eml') ?: [])->toHaveCount(0);

    rmdir($directory);
});

it('moves unreadable spool files to failed directory', function (): void {
    $directory = getcwd() . '/tests/.tmp-spool-failed-' . bin2hex(random_bytes(4));
    $failed = $directory . '/failed';
    mkdir($directory, 0775, true);

    file_put_contents($directory . '/20260101_000001_bad.eml', '');

    $receiver = new SpoolEmailReceiver(
        new SpoolConfig($directory),
        parser: new class implements EmailParser {
            public function parse(string $rawEmail, array $metadata = []): \Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail
            {
                unset($rawEmail, $metadata);

                throw new RuntimeException('corrupt');
            }
        },
        failedDirectory: $failed,
    );

    $email = $receiver->receiveParsed();

    expect($email)->toBeNull();
    expect(glob($failed . '/*.eml') ?: [])->toHaveCount(1);
    expect(glob($failed . '/*.error.txt') ?: [])->toHaveCount(1);

    foreach (glob($failed . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($failed);
    rmdir($directory);
});
