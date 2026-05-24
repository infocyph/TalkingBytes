<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\ContentTransferEncoding;
use Infocyph\TalkingBytes\Email\System\AttachmentEncoder;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\MimeMessageBuilder;
use Infocyph\TalkingBytes\Email\System\RawEmailBuilder;
use Infocyph\TalkingBytes\Email\ValueObject\EmailAttachment;

function normalizeDynamicMimeOutput(string $raw): string
{
    $normalized = (string) preg_replace('/Message-ID:\s*<[^>]+>/', 'Message-ID: <normalized@example.com>', $raw);

    $boundaries = [];
    $matchCount = preg_match_all('/boundary="([^"]+)"/', $normalized, $matches);
    if (is_int($matchCount) && $matchCount > 0 && is_array($matches[1] ?? null)) {
        /** @var list<string> $values */
        $values = array_values(array_unique($matches[1]));
        foreach ($values as $index => $value) {
            $boundaries[$value] = sprintf('normalized-boundary-%d', $index + 1);
        }
    }

    foreach ($boundaries as $from => $to) {
        $normalized = str_replace($from, $to, $normalized);
    }

    return $normalized;
}

it('builds text-only MIME payload', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Text')
        ->text("Hello\nWorld");

    $mime = (new MimeMessageBuilder)->build($message);

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

    $mime = (new MimeMessageBuilder)->build($message);

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

    $mime = (new MimeMessageBuilder)->build($message);

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

    $mime = (new MimeMessageBuilder)->build($message);

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

    $mime = (new MimeMessageBuilder)->build($message);

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

    $raw = (new RawEmailBuilder)->build($message, includeSubject: true);

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

    $mime = (new MimeMessageBuilder)->build($message);
    $headers = (new EmailHeaderBuilder)->build($message, $mime, includeSubject: true);

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

    $mime = (new MimeMessageBuilder)->build($message);
    $headers = (new EmailHeaderBuilder)->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('To: recipient00.long.address@example.com');
    expect($headers)->toContain("\r\n ");
});

it('streams attachment encoding and keeps external streams open', function (): void {
    $stream = fopen('php://temp', 'w+b');
    expect($stream)->not->toBeFalse();
    fwrite($stream, str_repeat('A', 1024));
    rewind($stream);

    $attachment = EmailAttachment::fromStream(
        $stream,
        'payload.bin',
        'application/octet-stream',
    );

    $encoder = new AttachmentEncoder;
    $body = '';
    $encoder->encodeToStream($attachment, static function (string $chunk) use (&$body): void {
        $body .= $chunk;
    });

    expect($body)->toContain('Content-Transfer-Encoding: base64');
    expect($body)->toContain("\r\n\r\n");
    expect(is_resource($stream))->toBeTrue();

    fclose($stream);
});

it('builds raw email through stream writer', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Streamed')
        ->text('Body')
        ->messageDetails('streamed@example.com');

    $builder = new RawEmailBuilder;
    $raw = $builder->build($message, includeSubject: true);

    $output = '';
    $size = $builder->buildToStream(
        $message,
        static function (string $chunk) use (&$output): void {
            $output .= $chunk;
        },
        includeSubject: true,
    );

    expect($output)->toBe($raw->raw);
    expect($size)->toBe($raw->sizeBytes);
});

it('folds long references header and keeps encoded subject', function (): void {
    $refs = [];
    for ($i = 0; $i < 12; $i++) {
        $refs[] = sprintf('<ref-%d@example.com>', $i);
    }

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Résumé update')
        ->text('Body')
        ->messageDetails(references: $refs);

    $mime = (new MimeMessageBuilder)->build($message);
    $headers = (new EmailHeaderBuilder)->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('Subject: =?UTF-8?B?');
    expect($headers)->toContain('References:');
    expect($headers)->toContain("\r\n ");
});

it('renders mixed related alternative nesting with non-ascii filenames', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Nested')
        ->text('plain')
        ->html('<img src="cid:logo">')
        ->attachInlineData('img', 'logo 画像.png', 'logo', 'image/png')
        ->attachData('pdf', 'statement résumé.pdf', 'application/pdf');

    $mime = (new MimeMessageBuilder)->build($message);

    expect($mime->contentType)->toContain('multipart/mixed');
    expect($mime->body)->toContain('multipart/related');
    expect($mime->body)->toContain('multipart/alternative');
    expect($mime->body)->toContain("filename*=UTF-8''");
});

it('keeps build and buildToStream output equivalent for inline and regular attachments', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Streaming equivalence')
        ->text("Line 1\r\nLine 2")
        ->html('<p>Line 1</p><img src="cid:logo">')
        ->attachInlineData('logo-bytes', 'logo.png', 'logo', 'image/png')
        ->attachData('pdf-bytes', 'report.pdf', 'application/pdf');

    $builder = new RawEmailBuilder;
    $built = $builder->build($message, includeSubject: true);

    $streamed = '';
    $streamSize = $builder->buildToStream(
        $message,
        static function (string $chunk) use (&$streamed): void {
            $streamed .= $chunk;
        },
        includeSubject: true,
        maxBytes: $built->sizeBytes + 16,
    );

    expect($streamSize)->toBe(strlen($streamed));
    expect(normalizeDynamicMimeOutput($streamed))->toContain('multipart/mixed');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('multipart/related');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('multipart/alternative');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('Content-ID: <logo>');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('filename*=UTF-8\'\'report.pdf');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('bG9nby1ieXRlcw==');
    expect(normalizeDynamicMimeOutput($streamed))->toContain('cGRmLWJ5dGVz');

    expect(normalizeDynamicMimeOutput($built->raw))->toContain('multipart/mixed');
    expect(normalizeDynamicMimeOutput($built->raw))->toContain('multipart/related');
    expect(normalizeDynamicMimeOutput($built->raw))->toContain('multipart/alternative');
    expect($streamed)->toContain("\r\n");
    expect($streamed)->toContain('multipart/mixed');
    expect($streamed)->toContain('multipart/related');
    expect($streamed)->toContain('multipart/alternative');
});
