<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\MimeMessageBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;

it('builds text-only MIME payload', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Text')
        ->text("Hello\nWorld");

    $mime = (new MimeMessageBuilder())->build($message);

    expect($mime->contentType)->toBe('text/plain; charset=UTF-8');
    expect($mime->contentTransferEncoding)->toBe(ContentTransferEncoding::QuotedPrintable);
    expect($mime->body)->toContain('Hello');
});

it('builds html-only MIME payload', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Html')
        ->html('<p>Hello <strong>World</strong></p>');

    $mime = (new MimeMessageBuilder())->build($message);

    expect($mime->contentType)->toContain('multipart/alternative');
    expect($mime->contentTransferEncoding)->toBeNull();
    expect($mime->body)->toContain('Content-Type: text/plain; charset=UTF-8');
    expect($mime->body)->toContain('Content-Type: text/html; charset=UTF-8');
    expect($mime->body)->toContain('<p>Hello');
});

it('builds multipart alternative for text and html bodies', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Alt')
        ->text('Plain text')
        ->html('<p>HTML</p>');

    $mime = (new MimeMessageBuilder())->build($message);

    expect($mime->contentType)->toContain('multipart/alternative');
    expect($mime->body)->toContain('Content-Type: text/plain; charset=UTF-8');
    expect($mime->body)->toContain('Content-Type: text/html; charset=UTF-8');
});

it('wraps inline and regular attachments as related inside mixed', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Attachments')
        ->html('<img src="cid:logo">')
        ->attachInlineData('logo-bytes', 'logo.png', 'logo', 'image/png')
        ->attachData('invoice-content', 'invoice.pdf', 'application/pdf');

    $mime = (new MimeMessageBuilder())->build($message);

    expect($mime->contentType)->toContain('multipart/mixed');
    expect($mime->body)->toContain('Content-Type: multipart/related');
    expect($mime->body)->toContain('Content-ID: <logo>');
    expect($mime->body)->toContain('Content-Disposition: attachment;');
});

it('encodes attachment filename using filename* parameter', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Filename')
        ->text('Body')
        ->attachData('binary', 'statement résumé.pdf', 'application/pdf');

    $mime = (new MimeMessageBuilder())->build($message);

    expect($mime->body)->toContain("filename*=UTF-8''");
    expect($mime->body)->toContain('filename="');
});

it('does not expose return path as a header in raw email', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->returnPath('bounce@example.com')
        ->to('alice@example.com')
        ->subject('Envelope')
        ->text('Body');

    $raw = (new RawEmailBuilder())->build($message, includeSubject: true);

    expect($raw->headers)->not->toContain('Return-Path:');
});

it('adds read receipt and one-click unsubscribe headers', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Headers')
        ->text('Body')
        ->oneClickUnsubscribe('https://example.com/unsub')
        ->readReceiptTo('ops@example.com');

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('List-Unsubscribe: <https://example.com/unsub>');
    expect($headers)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click');
    expect($headers)->toContain('Disposition-Notification-To: ops@example.com');
});

it('folds long recipient headers safely', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->subject('Fold')
        ->text('Body');

    for ($i = 0; $i < 8; $i++) {
        $message = $message->to(sprintf('recipient%02d.long.address@example.com', $i));
    }

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

    expect($headers)->toContain("To: recipient00.long.address@example.com");
    expect($headers)->toContain("\r\n ");
});
