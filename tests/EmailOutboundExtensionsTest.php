<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Emailer;
use Infocyph\TalkingBytes\Email\Receiver\SpoolEmailReceiver;
use Infocyph\TalkingBytes\Email\System\EmailHeaderBuilder;
use Infocyph\TalkingBytes\Email\System\MimeMessageBuilder;
use Infocyph\TalkingBytes\Email\System\SmtpCapabilityParser;
use Infocyph\TalkingBytes\Email\Testing\FakeEmailTransport;

it('adds sender, one-click unsubscribe and custom headers to built headers', function (): void {
    $message = EmailMessage::new()
        ->from('sender@example.com', 'Sender')
        ->sender('mailer@example.com')
        ->to('alice@example.com')
        ->subject('Welcome')
        ->text('Hello')
        ->oneClickUnsubscribe('https://example.com/unsubscribe')
        ->header('X-Campaign', 'welcome-v1');

    $mime = (new MimeMessageBuilder())->build($message);
    $headers = (new EmailHeaderBuilder())->build($message, $mime, includeSubject: true);

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

    $mime = (new MimeMessageBuilder())->build($message);

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
    $parser = new SmtpCapabilityParser();

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
    $directory = sys_get_temp_dir() . '/talkingbytes-spool-' . bin2hex(random_bytes(4));

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
    expect($received?->header('Subject'))->toBe('Spool Test');
    expect($received?->body)->toContain('Queued body');

    array_map(static fn($path): bool => unlink($path), glob($directory . '/*') ?: []);
    if (is_dir($directory)) {
        rmdir($directory);
    }
});

it('honors queued fake transport results', function (): void {
    $transport = (new FakeEmailTransport())
        ->pushResult(Infocyph\TalkingBytes\Core\Result\CommunicationResult::failure('failed'));

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
