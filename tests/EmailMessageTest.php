<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\Exception\AttachmentException;
use Infocyph\TalkingBytes\Email\Exception\InvalidHeaderValueException;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\MimeMessageBuilder;

it('builds headers without exposing bcc and generates message id when missing', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com', 'Sender')
        ->to('alice@example.com')
        ->cc('bob@example.com')
        ->bcc('hidden@example.com')
        ->subject('Welcome')
        ->text('Hello');

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('To: alice@example.com');
    expect($headers)->toContain('Cc: bob@example.com');
    expect($headers)->not->toContain('Bcc:');
    expect($headers)->toMatch('/Message-ID: <[a-f0-9]{32}@example\.com>/');
});

it('validates header injection attempts', function (): void {
    expect(fn() => EmailMessage::new()->subject("Hello\r\nBcc: x@example.com"))
        ->toThrow(InvalidHeaderValueException::class);

    expect(fn() => EmailMessage::new()->subject("Hello\x00World"))
        ->toThrow(InvalidHeaderValueException::class);
});

it('throws on missing attachment file', function (): void {
    expect(fn() => EmailMessage::new()->attach('/tmp/does-not-exist-file.txt'))
        ->toThrow(AttachmentException::class);
});

it('encodes utf8 plain text as quoted printable', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('utf8')
        ->text('Cafe élan');

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($mime->contentType)->toBe('text/plain; charset=UTF-8');
    expect($mime->contentTransferEncoding)->toBe(ContentTransferEncoding::QuotedPrintable);
    expect($mime->body)->toContain('=C3=A9');
    expect($headers)->toContain('Content-Transfer-Encoding: quoted-printable');
});

it('keeps explicit in-reply-to and references message ids intact', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('thread')
        ->text('Hello')
        ->messageDetails(
            messageId: 'custom@example.com',
            inReplyTo: '<reply@example.net>',
            references: ['<first@example.net>', 'second@example.org'],
        );

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('Message-ID: <custom@example.com>');
    expect($headers)->toContain('In-Reply-To: <reply@example.net>');
    expect($headers)->toContain('References: <first@example.net> <second@example.org>');
});

it('uses undisclosed recipients when to list is empty', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->cc('bob@example.com')
        ->subject('notice')
        ->text('Hello');

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('To: undisclosed-recipients:;');
    expect($headers)->toContain('Cc: bob@example.com');
});

it('appends recipients by default and allows replacing with withTo/withCc/withBcc', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('first@example.com')
        ->to('second@example.com')
        ->cc('copy-one@example.com')
        ->cc('copy-two@example.com')
        ->bcc('blind-one@example.com')
        ->bcc('blind-two@example.com');

    expect($message->envelope()->to)->toHaveCount(2);
    expect($message->envelope()->cc)->toHaveCount(2);
    expect($message->envelope()->bcc)->toHaveCount(2);

    $replaced = $message
        ->withTo('replace-to@example.com')
        ->withCc('replace-cc@example.com')
        ->withBcc('replace-bcc@example.com');

    expect($replaced->envelope()->to)->toHaveCount(1);
    expect($replaced->envelope()->cc)->toHaveCount(1);
    expect($replaced->envelope()->bcc)->toHaveCount(1);
});

it('deduplicates recipients case-insensitively for envelope delivery', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('User@example.com')
        ->cc('user@example.com')
        ->bcc('USER@example.com');

    expect($message->envelope()->recipients())->toHaveCount(1);
});

it('validates smtp config values', function (): void {
    expect(fn() => new SmtpConfig(''))->toThrow(InvalidArgumentException::class);
    expect(fn() => new SmtpConfig('smtp.example.com', 0))->toThrow(InvalidArgumentException::class);
    expect(fn() => new SmtpConfig('smtp.example.com', 587, timeoutSeconds: 0))->toThrow(InvalidArgumentException::class);
});

it('validates smtp credentials values', function (): void {
    expect(fn() => new SmtpCredentials('', 'password'))->toThrow(InvalidArgumentException::class);
    expect(fn() => new SmtpCredentials('username', ''))->toThrow(InvalidArgumentException::class);
});
