<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Exception\EmailParseException;
use Infocyph\TalkingBytes\Email\Parser\EmailParser;
use Infocyph\TalkingBytes\Email\Parser\HeaderParser;
use Infocyph\TalkingBytes\Email\Parser\RawEmailParser;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\ValueObject\InMemoryAttachmentContentResolver;
use Infocyph\TalkingBytes\Email\ValueObject\ParsedEmail;
use Infocyph\TalkingBytes\Email\ValueObject\ReceivedAttachment;

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

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->subject)->toBe('Test ä');
    expect($parsed->headers['received'] ?? [])->toHaveCount(2);
    expect($parsed->headers['x-trace'][0] ?? null)->toBe('one continued');
    expect($parsed->from->count())->toBe(1);
    expect($parsed->to->count())->toBe(2);
});

it('preserves duplicate Authentication-Results headers', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Auth headers',
        'Authentication-Results: mx1.example.com; dkim=pass header.d=example.com',
        'Authentication-Results: mx2.example.com; spf=pass smtp.mailfrom=example.com',
        '',
        'Body',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->headers['authentication-results'] ?? [])->toHaveCount(2);
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

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->textBody)->toBe('Hello plain');
    expect($parsed->htmlBody)->toContain('Hello html');
    expect($parsed->attachments)->toHaveCount(1);
    expect($parsed->attachments[0]->filename)->toBe('report.pdf');
    expect($parsed->attachments[0]->contents())->toBe('PDF-CONTENT');
});

it('assigns stable MIME part numbers for nested multipart structures', function (): void {
    $boundaryMixed = 'mix-root';
    $boundaryRelated = 'rel-1';
    $boundaryAlt = 'alt-1';

    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Part numbers',
        sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundaryMixed),
        '',
        sprintf('--%s', $boundaryMixed),
        sprintf('Content-Type: multipart/related; boundary="%s"', $boundaryRelated),
        '',
        sprintf('--%s', $boundaryRelated),
        sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundaryAlt),
        '',
        sprintf('--%s', $boundaryAlt),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Plain',
        sprintf('--%s', $boundaryAlt),
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>Html</p>',
        sprintf('--%s--', $boundaryAlt),
        sprintf('--%s', $boundaryRelated),
        'Content-Type: image/png; name="logo.png"',
        'Content-Disposition: inline; filename="logo.png"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('image'),
        sprintf('--%s--', $boundaryRelated),
        sprintf('--%s', $boundaryMixed),
        'Content-Type: application/pdf; name="report.pdf"',
        'Content-Disposition: attachment; filename="report.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('pdf'),
        sprintf('--%s--', $boundaryMixed),
        '',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    $partNumbers = [];
    $collect = function (array $parts) use (&$collect, &$partNumbers): void {
        foreach ($parts as $part) {
            if ($part->filename !== null) {
                $partNumbers[] = $part->partNumber;
            }
            if ($part->children !== []) {
                $collect($part->children);
            }
        }
    };
    $collect($parsed->parts);

    expect($partNumbers)->toContain('1.2');
    expect($partNumbers)->toContain('2');
});

it('supports spool receiver peek and receiveMany with parser-backed output', function (): void {
    $directory = getcwd().'/tests/.tmp-spool-parse-'.bin2hex(random_bytes(4));
    mkdir($directory, 0775, true);

    file_put_contents($directory.'/20260101_000001_a.eml', "From: sender@example.com\r\nTo: a@example.com\r\nSubject: A\r\n\r\nBody A");
    file_put_contents($directory.'/20260101_000002_b.eml', "From: sender@example.com\r\nTo: b@example.com\r\nSubject: B\r\n\r\nBody B");

    $receiver = new SpoolEmailReceiver(new SpoolConfig($directory), deleteAfterRead: true);

    $peeked = $receiver->peek();
    expect($peeked?->subject)->toBe('A');

    $emails = $receiver->receiveMany(2);
    expect($emails)->toHaveCount(2);
    expect($emails[0]->subject)->toBe('A');
    expect($emails[1]->subject)->toBe('B');
    expect(glob($directory.'/*.eml') ?: [])->toHaveCount(0);

    rmdir($directory);
});

it('moves unreadable spool files to failed directory', function (): void {
    $directory = getcwd().'/tests/.tmp-spool-failed-'.bin2hex(random_bytes(4));
    $failed = $directory.'/failed';
    mkdir($directory, 0775, true);

    file_put_contents($directory.'/20260101_000001_bad.eml', '');

    $receiver = new SpoolEmailReceiver(
        new SpoolConfig($directory),
        parser: new class implements EmailParser
        {
            public function parse(string $rawEmail, array $metadata = []): ParsedEmail
            {
                unset($rawEmail, $metadata);

                throw new RuntimeException('corrupt');
            }
        },
        failedDirectory: $failed,
    );

    $email = $receiver->receiveParsed();

    expect($email)->toBeNull();
    expect(glob($failed.'/*.eml') ?: [])->toHaveCount(1);
    expect(glob($failed.'/*.error.txt') ?: [])->toHaveCount(1);

    foreach (glob($failed.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($failed);
    rmdir($directory);
});

it('decodes quoted printable body and converts charset to utf8', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Charset',
        'Content-Type: text/plain; charset=ISO-8859-1',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        'Caf=E9',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->textBody)->toBe('Café');
});

it('parses filename continuation parameters for attachments', function (): void {
    $boundary = 'mix-boundary';
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Filename star',
        sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundary),
        '',
        sprintf('--%s', $boundary),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Body',
        sprintf('--%s', $boundary),
        'Content-Type: application/octet-stream',
        "Content-Disposition: attachment; filename*0*=UTF-8''long-%E2%82%AC;",
        ' filename*1*=report.pdf',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('bytes'),
        sprintf('--%s--', $boundary),
        '',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->attachments)->toHaveCount(1);
    expect($parsed->attachments[0]->filename)->toBe('long-€report.pdf');
});

it('parses complex address formats and ignores invalid entries', function (): void {
    $raw = implode("\r\n", [
        'From: "Last, First" <sender@example.com>',
        'To: Group: a@example.com, b@example.com;, "=?UTF-8?B?Sm9zw6k=?=" <jose@example.com>, broken-address',
        'Subject: Addresses',
        '',
        'Body',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->from->count())->toBe(1);
    expect($parsed->to->count())->toBeGreaterThanOrEqual(2);
    expect($parsed->to->all()[0]->email)->not->toBeNull();
});

it('parses group, undisclosed recipients, and comments in address headers', function (): void {
    $raw = implode("\r\n", [
        'From: "Sender, Team" <sender@example.com>',
        'To: undisclosed-recipients:;, Group: "A, User" <a@example.com>, b@example.com; (comment)',
        'Cc: John Doe (Billing) <john@example.com>',
        'Subject: Address edge',
        '',
        'Body',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->to->count())->toBeGreaterThanOrEqual(2);
    expect(array_any(
        $parsed->to->all(),
        static fn ($addr): bool => $addr->email === 'a@example.com',
    ))->toBeTrue();
    expect(array_any(
        $parsed->to->all(),
        static fn ($addr): bool => $addr->email === 'b@example.com',
    ))->toBeTrue();
    expect($parsed->cc->first()?->email)->toBe('john@example.com');
});

it('handles multipart preamble and epilogue safely', function (): void {
    $boundary = 'safe-b';
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Preamble',
        sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundary),
        '',
        'This is preamble text',
        sprintf('--%s', $boundary),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Plain body',
        sprintf('--%s', $boundary),
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>Html body</p>',
        sprintf('--%s--', $boundary),
        'This is epilogue text',
        '',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->textBody)->toBe('Plain body');
    expect($parsed->htmlBody)->toContain('Html body');
});

it('exposes parsed email convenience helpers', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Mail delivery failed',
        'In-Reply-To: <abc@example.com>',
        '',
        'Body',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);

    expect($parsed->fromEmail())->toBe('sender@example.com');
    expect($parsed->subjectOrEmpty())->toBe('Mail delivery failed');
    expect($parsed->isReply())->toBeTrue();
    expect($parsed->isBounceCandidate())->toBeTrue();
    expect($parsed->headers('to'))->toHaveCount(1);
});

it('exposes received attachment helper methods', function (): void {
    $boundary = 'mix-img';
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Attach',
        sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundary),
        '',
        sprintf('--%s', $boundary),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Body',
        sprintf('--%s', $boundary),
        'Content-Type: image/png',
        'Content-Disposition: inline; filename="logo bad @2x.png"',
        'Content-ID: <logo>',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('png-bytes'),
        sprintf('--%s--', $boundary),
        '',
    ]);

    $parsed = (new RawEmailParser)->parse($raw);
    $attachment = $parsed->firstAttachment();

    expect($parsed->hasAttachments())->toBeTrue();
    expect($parsed->attachmentCount())->toBe(1);
    expect($attachment)->not->toBeNull();
    expect($attachment?->extension())->toBe('png');
    expect($attachment?->isImage())->toBeTrue();
    expect($attachment?->isInline())->toBeTrue();
    expect($attachment?->safeFilename())->toBe('logo_bad__2x.png');
    expect($attachment?->contentHash())->toBe(hash('sha256', 'png-bytes'));
});

it('normalizes unsafe attachment filenames for filesystem use', function (): void {
    $boundary = 'mix-safe-name';
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Unsafe name',
        sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundary),
        '',
        sprintf('--%s', $boundary),
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Body',
        sprintf('--%s', $boundary),
        'Content-Type: application/octet-stream',
        'Content-Disposition: attachment; filename="../etc/passwd"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('secret-bytes'),
        sprintf('--%s--', $boundary),
    ]);

    $parsed = (new RawEmailParser)->parse($raw);
    $attachment = $parsed->firstAttachment();

    expect($attachment)->not->toBeNull();
    expect($attachment?->safeFilename())->toBe('passwd');
});

it('sanitizes unsafe attachment filenames with control and traversal characters', function (): void {
    $attachment = new ReceivedAttachment(
        filename: "../bad\r\n\0name.txt",
        mimeType: 'application/octet-stream',
        sizeBytes: 5,
        contentId: null,
        inline: false,
        contentResolver: new InMemoryAttachmentContentResolver('bytes'),
    );

    expect($attachment->safeFilename())->toStartWith('bad');
    expect($attachment->safeFilename())->toEndWith('name.txt');
    expect($attachment->safeFilename())->not->toContain('/');
});

it('supports processing directory flow and metadata on spool receive', function (): void {
    $baseDirectory = getcwd().'/tests/.tmp-spool-processing-'.bin2hex(random_bytes(4));
    $processingDirectory = $baseDirectory.'/processing';
    $processedDirectory = $baseDirectory.'/processed';
    mkdir($baseDirectory, 0775, true);

    file_put_contents($baseDirectory.'/20260101_000001_a.eml', "From: sender@example.com\r\nTo: a@example.com\r\nSubject: A\r\n\r\nBody A");

    $receiver = new SpoolEmailReceiver(
        new SpoolConfig($baseDirectory, processingDirectory: $processingDirectory),
        deleteAfterRead: false,
        moveAfterRead: $processedDirectory,
    );

    $email = $receiver->receiveParsed();

    expect($email)->not->toBeNull();
    expect($email?->metadata['source'] ?? null)->toBe('spool');
    expect($email?->metadata['original_path'] ?? null)->toContain('.eml');
    expect($email?->metadata['processing_path'] ?? null)->toContain('/processing/');
    expect(glob($baseDirectory.'/*.eml') ?: [])->toHaveCount(0);
    expect(glob($processingDirectory.'/*.eml') ?: [])->toHaveCount(0);
    expect(glob($processedDirectory.'/*.eml') ?: [])->toHaveCount(1);

    foreach (glob($processedDirectory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($processedDirectory);
    rmdir($processingDirectory);
    rmdir($baseDirectory);
});

it('supports strict and tolerant header parsing modes', function (): void {
    $headerBlock = implode("\r\n", [
        ' malformed-leading-fold',
        'Subject: Hello',
        'X-Test Broken',
    ]);

    $tolerant = (new HeaderParser(HeaderParser::MODE_TOLERANT))->parse($headerBlock);

    expect($tolerant->first('Subject'))->toBe('Hello');
    expect($tolerant->asMap()['x-invalid-header'] ?? [])->toHaveCount(2);

    expect(fn () => (new HeaderParser(HeaderParser::MODE_STRICT))->parse($headerBlock))
        ->toThrow(EmailParseException::class);
});

it('handles long and multi-encoded header values', function (): void {
    $longSegment = str_repeat('x', 240);
    $headerBlock = implode("\r\n", [
        'Subject: =?UTF-8?Q?Hello?= plain =?UTF-8?Q?_World?=',
        'X-Long: '.$longSegment,
        '',
    ]);

    $parsed = (new HeaderParser)->parse($headerBlock);

    expect($parsed->first('subject'))->toBe('Hello plain  World');
    expect($parsed->first('x-long'))->toBe($longSegment);
});

it('decodes mixed and fallback encoded words in header values', function (): void {
    $headerBlock = "Subject: Hello =?UTF-8?Q?Jos=C3=A9?= =?UTF-8?Q?World?=\r\n";

    $parsed = (new HeaderParser)->parse($headerBlock);

    expect($parsed->first('subject'))->toBe('Hello JoséWorld');
});

it('assigns imap-compatible part numbers for mixed-related-alternative nesting', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: MIME map',
        'Content-Type: multipart/mixed; boundary="mix"',
        '',
        '--mix',
        'Content-Type: multipart/related; boundary="rel"',
        '',
        '--rel',
        'Content-Type: multipart/alternative; boundary="alt"',
        '',
        '--alt',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Plain body',
        '--alt',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>HTML body</p>',
        '--alt--',
        '--rel',
        'Content-Type: image/png',
        'Content-Disposition: inline; filename="logo.png"',
        'Content-ID: <logo>',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('logo'),
        '--rel--',
        '--mix',
        'Content-Type: application/pdf',
        'Content-Disposition: attachment; filename="report.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('pdf'),
        '--mix--',
    ]);

    $email = (new RawEmailParser)->parse($raw);

    expect($email->textBody)->toContain('Plain body');
    expect($email->htmlBody)->toContain('<p>HTML body</p>');
    expect($email->attachments)->toHaveCount(2);
    expect($email->attachments[0]->filename)->toBe('logo.png');
    expect($email->attachments[1]->filename)->toBe('report.pdf');

    $partNumbers = [];
    $collect = function (?array $parts) use (&$collect, &$partNumbers): void {
        if (! is_array($parts)) {
            return;
        }

        foreach ($parts as $part) {
            if ($part->partNumber !== null) {
                $partNumbers[] = $part->partNumber;
            }

            if (is_array($part->children) && $part->children !== []) {
                $collect($part->children);
            }
        }
    };
    $collect($email->parts);

    expect($partNumbers)->toContain('1', '1.1', '1.1.1', '1.1.2', '1.2', '2');
});

it('keeps attachment parts out of selected text and html body content', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Body selection',
        'Content-Type: multipart/mixed; boundary="mix"',
        '',
        '--mix',
        'Content-Type: multipart/alternative; boundary="alt"',
        '',
        '--alt',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Chosen plain text',
        '--alt',
        'Content-Type: text/html; charset=UTF-8',
        '',
        '<p>Chosen html</p>',
        '--alt--',
        '--mix',
        'Content-Type: text/plain; charset=UTF-8; name="notes.txt"',
        'Content-Disposition: attachment; filename="notes.txt"',
        '',
        'attachment plain content',
        '--mix--',
    ]);

    $email = (new RawEmailParser)->parse($raw);

    expect($email->textBody)->toBe('Chosen plain text');
    expect($email->htmlBody)->toBe('<p>Chosen html</p>');
    expect($email->attachments)->toHaveCount(1);
    expect($email->attachments[0]->filename)->toBe('notes.txt');
});
