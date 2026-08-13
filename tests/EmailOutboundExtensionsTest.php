<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\Dkim\DkimSigner;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\HeaderFolder;
use Infocyph\TalkingBytes\Email\System\MimeMessageBuilder;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilityParser;
use Infocyph\TalkingBytes\Email\Testing\FakeEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LogEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LoggingEmailTransport;

it('adds sender, one-click unsubscribe and custom headers to built headers', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com', 'Sender')
        ->sender('mailer@example.com')
        ->to('alice@example.com')
        ->subject('Welcome')
        ->text('Hello')
        ->oneClickUnsubscribe('https://example.com/unsubscribe')
        ->header('X-Campaign', 'welcome-v1');

    $mime = (new MimeMessageBuilder)->build($message);
    $headers = (new EmailHeaderBuilder)->build($message, $mime, includeSubject: true);

    expect($headers)->toContain('Sender: mailer@example.com');
    expect($headers)->toContain('List-Unsubscribe: <https://example.com/unsubscribe>');
    expect($headers)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click');
    expect($headers)->toContain('X-Campaign: welcome-v1');
});

it('builds multipart related with inline attachments', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Inline image')
        ->html('<img src="cid:logo">')
        ->attachInlineData('logo-bytes', 'logo.png', 'logo', 'image/png');

    $mime = (new MimeMessageBuilder)->build($message);

    expect($mime->contentType)->toContain('multipart/related');
    expect($mime->body)->toContain('Content-ID: <logo>');
    expect($mime->body)->toContain('Content-Disposition: inline;');
});

it('provides fake email assertions', function (): void {
    $emailer = Emailer::fake();

    $result = $emailer->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hello')
            ->text('Body')
            ->attachData('pdf-content', 'invoice.pdf', 'application/pdf'),
    );

    expect($result->successful)->toBeTrue();

    $assert = $emailer->assertable();
    $assert->assertSent();
    $assert->assertSentCount(1);
    $assert->assertSentTo('alice@example.com');
    $assert->assertSentSubject('Hello');
    $assert->assertHasAttachment('invoice.pdf');
});

it('parses smtp capabilities including auth mechanisms and size', function (): void {
    $parser = new SmtpCapabilityParser;

    $capabilities = $parser->parse([
        '250-mail.example.com',
        '250-STARTTLS',
        '250-SIZE 10485760',
        '250 AUTH PLAIN LOGIN',
    ]);

    expect($capabilities->has('STARTTLS'))->toBeTrue();
    expect($capabilities->sizeLimit())->toBe(10485760);
    expect($capabilities->authMechanisms)->toBe(['PLAIN', 'LOGIN']);
});

it('receives queued .eml files from spool receiver', function (): void {
    $directory = sys_get_temp_dir().'/talkingbytes-spool-'.bin2hex(random_bytes(4));

    $emailer = Emailer::usingSpool(new SpoolConfig($directory));

    $emailer->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Spool Test')
            ->text('Queued body'),
    );

    $receiver = new SpoolEmailReceiver(new SpoolConfig($directory), deleteAfterRead: true);
    $received = $receiver->receive();

    expect($received)->not->toBeNull();
    expect($received?->subject)->toBe('Spool Test');
    expect($received?->textBody)->toContain('Queued body');

    array_map(static fn ($path): bool => unlink($path), glob($directory.'/*') ?: []);
    if (is_dir($directory)) {
        rmdir($directory);
    }
});

it('honors queued fake transport results', function (): void {
    $transport = (new FakeEmailTransport)
        ->pushResult(CommunicationResult::failure('failed'));

    $emailer = new Emailer($transport);

    $result = $emailer->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Hello')
            ->text('Body'),
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toBe('failed');
});

it('logging email transport logs finish event when inner transport throws', function (): void {
    $events = [];

    $transport = new class implements EmailTransport
    {
        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            throw new RuntimeException('transport boom');
        }
    };

    $logger = static function (string $event, array $context) use (&$events): void {
        $events[] = ['event' => $event, 'context' => $context];
    };

    $loggingTransport = new LoggingEmailTransport($transport, $logger);

    expect(fn () => $loggingTransport->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Boom')
            ->text('Body'),
    ))->toThrow(RuntimeException::class, 'transport boom');

    expect($events)->toHaveCount(2);
    expect($events[0]['event'])->toBe('email.send.start');
    expect($events[1]['event'])->toBe('email.send.finish');
    expect($events[1]['context']['successful'])->toBeFalse();
    expect($events[1]['context']['error'])->toBe('transport boom');
});

it('fails clearly when log transport directory path is not a directory', function (): void {
    $filePath = sys_get_temp_dir().'/talkingbytes-log-file-'.bin2hex(random_bytes(4));
    file_put_contents($filePath, 'x');

    $transport = new LogEmailTransport(new LogEmailConfig($filePath));
    $result = $transport->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Log fail')
            ->text('Body'),
    );

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('not a directory');

    if (is_file($filePath)) {
        unlink($filePath);
    }
});

it('keeps spool success when metadata sidecar encoding fails', function (): void {
    $directory = sys_get_temp_dir().'/talkingbytes-spool-meta-'.bin2hex(random_bytes(4));
    $emailer = Emailer::usingSpool(new SpoolConfig($directory, writeMetadata: true));

    $result = $emailer->send(
        EmailMessage::new()
            ->from('sender@example.com')
            ->to('alice@example.com')
            ->subject('Spool metadata')
            ->text('Body')
            ->tag('invalid', NAN),
    );

    expect($result->successful)->toBeTrue();
    expect($result->metadata['metadata_write_failed'] ?? null)->toBeTrue();
    expect($result->metadata['metadata_write_error'] ?? null)->toBeString();

    array_map(static fn ($path): bool => unlink($path), glob($directory.'/*') ?: []);
    if (is_dir($directory)) {
        rmdir($directory);
    }
});

it('folds dkim headers on semicolon boundaries', function (): void {
    $folder = new HeaderFolder;
    $line = 'DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed; d=example.com; s=selector; t=123456789; h=from:to:subject:date:message-id; bh=abc; b='.str_repeat('x', 120);

    $folded = $folder->fold($line, 78);

    expect($folded)->toContain("\r\n ");
    expect($folded)->toContain('DKIM-Signature:');
});

it('dkim header parser unfolds lines and preserves duplicate headers', function (): void {
    $headers = implode("\r\n", [
        'From: sender@example.com',
        'Received: by mx1.example.net',
        "\twith ESMTP",
        'Received: by mx2.example.net',
        'Subject: Test',
        '',
    ]);

    $signer = new DkimSigner;
    $reflection = new ReflectionMethod($signer, 'parseHeaders');

    /** @var array<string, list<array{name:string,value:string}>> $parsed */
    $parsed = $reflection->invoke($signer, $headers);

    expect($parsed)->toHaveKey('received');
    expect($parsed['received'])->toHaveCount(2);
    expect($parsed['received'][0]['value'])->toContain('with ESMTP');
});
