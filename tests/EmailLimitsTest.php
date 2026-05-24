<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\EmailLimits;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

it('rejects raw email that exceeds max message bytes limit', function (): void {
    $limits = new EmailLimits(maxMessageBytes: 64);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Large',
        '',
        str_repeat('x', 100),
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'max message size');
});

it('rejects raw email that exceeds header limits', function (): void {
    $limits = new EmailLimits(maxHeaderBytes: 30, maxHeaderCount: 2);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Test',
        '',
        'Body',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class);
});

it('rejects invalid email limits configuration', function (): void {
    expect(fn () => new EmailLimits(maxMessageBytes: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new EmailLimits(maxAttachmentCount: 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => new EmailLimits(maxDecodedBodyBytes: 0))->toThrow(InvalidArgumentException::class);
});

it('rejects parsed emails that exceed attachment count limit', function (): void {
    $limits = new EmailLimits(maxAttachmentCount: 1);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Attachments',
        'Content-Type: multipart/mixed; boundary="mix"',
        '',
        '--mix',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Hello',
        '--mix',
        'Content-Type: application/octet-stream; name="a.txt"',
        'Content-Disposition: attachment; filename="a.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('a'),
        '--mix',
        'Content-Type: application/octet-stream; name="b.txt"',
        'Content-Disposition: attachment; filename="b.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('b'),
        '--mix--',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'Attachment count exceeds limit');
});

it('rejects parsed emails that exceed decoded body size limit', function (): void {
    $limits = new EmailLimits(maxDecodedBodyBytes: 8);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Body size',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        '123456789',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'Decoded MIME body exceeds limit');
});

it('rejects streamed raw email when output exceeds max bytes', function (): void {
    $builder = new RawEmailBuilder;
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Size limit')
        ->text(str_repeat('x', 64));

    expect(fn () => $builder->buildToStream(
        $message,
        static function (): void {},
        includeSubject: true,
        maxBytes: 32,
    ))->toThrow(RuntimeException::class, 'exceeds configured limit');
});

it('rejects parsed emails that exceed MIME depth limit', function (): void {
    $limits = new EmailLimits(maxMimeDepth: 2);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Deep nesting',
        'Content-Type: multipart/mixed; boundary="b1"',
        '',
        '--b1',
        'Content-Type: multipart/alternative; boundary="b2"',
        '',
        '--b2',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'hello',
        '--b2--',
        '--b1--',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'MIME nesting depth exceeds limit');
});

it('rejects parsed emails that exceed MIME part-count limit', function (): void {
    $limits = new EmailLimits(maxMimeParts: 2);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Part count',
        'Content-Type: multipart/mixed; boundary="m1"',
        '',
        '--m1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'one',
        '--m1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'two',
        '--m1--',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'MIME part count exceeds limit');
});

it('rejects parsed emails that exceed header-count limit specifically', function (): void {
    $limits = new EmailLimits(maxHeaderCount: 2, maxHeaderBytes: 1024);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Too many',
        '',
        'Body',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'Header count exceeds limit');
});

it('rejects parsed emails that exceed header-bytes limit specifically', function (): void {
    $limits = new EmailLimits(maxHeaderBytes: 48, maxHeaderCount: 100);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'X-Very-Long: '.str_repeat('x', 120),
        '',
        'Body',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'Header section exceeds limit');
});

it('rejects recursive-looking multipart payloads when depth limit is exceeded', function (): void {
    $limits = new EmailLimits(maxMimeDepth: 3, maxMimeParts: 64);
    $parser = new RawEmailParser(limits: $limits);

    $raw = implode("\r\n", [
        'From: a@example.com',
        'To: b@example.com',
        'Subject: Recursive multipart',
        'Content-Type: multipart/mixed; boundary="b1"',
        '',
        '--b1',
        'Content-Type: multipart/mixed; boundary="b2"',
        '',
        '--b2',
        'Content-Type: multipart/mixed; boundary="b3"',
        '',
        '--b3',
        'Content-Type: multipart/mixed; boundary="b4"',
        '',
        '--b4',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'payload',
        '--b4--',
        '--b3--',
        '--b2--',
        '--b1--',
    ]);

    expect(fn () => $parser->parse($raw))->toThrow(EmailParseException::class, 'MIME nesting depth exceeds limit');
});
