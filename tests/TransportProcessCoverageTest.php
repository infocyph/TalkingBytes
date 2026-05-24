<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Result\EmailSendResult;
use Infocyph\TalkingBytes\Email\Transport\MailFunctionTransport;
use Infocyph\TalkingBytes\Email\Transport\SendmailTransport;
use Infocyph\TalkingBytes\Email\Transport\SpoolEmailTransport;

it('captures sendmail stderr on non-zero exit', function (): void {
    $script = createSendmailTestScript();
    $transport = new SendmailTransport(new SendmailConfig($script, ['fail'], 2));

    $result = $transport->send(testMessage());

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('Sendmail exited with code 7');
    expect($result->error)->toContain('simulated failure');
});

it('fails sendmail transport on timeout', function (): void {
    $script = createSendmailTestScript();
    $transport = new SendmailTransport(new SendmailConfig($script, ['sleep', '2'], 1));

    $result = $transport->send(testMessage());

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('timed out');
});

it('fails sendmail transport when configured max message size is exceeded', function (): void {
    $script = createSendmailTestScript();
    $transport = new SendmailTransport(new SendmailConfig($script, ['ok'], 2, 32));

    $result = $transport->send(testMessage()->text(str_repeat('x', 256)));

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('exceeds configured limit');
});

it('streams large attachment payload to sendmail stdin path', function (): void {
    $script = createSendmailTestScript();
    $transport = new SendmailTransport(new SendmailConfig($script, ['ok'], 2));

    $stream = fopen('php://temp', 'w+b');
    expect($stream)->not->toBeFalse();
    fwrite($stream, str_repeat('A', 1024 * 1024));
    rewind($stream);

    $message = testMessage()->attachStream($stream, 'large.bin', 'application/octet-stream');
    $result = $transport->send($message);
    fclose($stream);

    expect($result->successful)->toBeTrue();
    expect(($result->metadata['size_bytes'] ?? 0))->toBeGreaterThan(1024 * 1024);
});

it('streams large attachment payload to spool temp file path', function (): void {
    $directory = sys_get_temp_dir().'/tb-spool-stream-'.bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);

    $transport = new SpoolEmailTransport(new SpoolConfig($directory));

    $stream = fopen('php://temp', 'w+b');
    expect($stream)->not->toBeFalse();
    fwrite($stream, str_repeat('B', 1024 * 1024));
    rewind($stream);

    $message = testMessage()->attachStream($stream, 'large.bin', 'application/octet-stream');
    $result = $transport->send($message);
    fclose($stream);

    expect($result->successful)->toBeTrue();
    expect(($result->metadata['size_bytes'] ?? 0))->toBeGreaterThan(1024 * 1024);

    foreach (glob($directory.'/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
});

it('mail function transport returns recipient-aware result and stable metadata', function (): void {
    $transport = new MailFunctionTransport;
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('to@example.com')
        ->cc('cc@example.com')
        ->bcc('bcc@example.com')
        ->subject('mail transport')
        ->text('body');

    $result = $transport->send($message);
    expect($result->response)->toBeInstanceOf(EmailSendResult::class);

    /** @var EmailSendResult $sendResult */
    $sendResult = $result->response;
    $allRecipients = [...$sendResult->acceptedRecipients, ...array_keys($sendResult->rejectedRecipients)];
    sort($allRecipients);

    expect($allRecipients)->toBe(['bcc@example.com', 'cc@example.com', 'to@example.com']);
    expect($sendResult->transport)->toBe('mail-function');
    expect($result->metadata['transport'] ?? null)->toBe('mail-function');
    expect($result->metadata['size_bytes'] ?? 0)->toBeGreaterThan(0);

    if ($result->successful) {
        expect($result->error)->toBeNull();
        expect($sendResult->acceptedRecipients)->not->toBe([]);
    } else {
        expect($result->error)->toContain('mail() transport failed');
        expect($sendResult->rejectedRecipients)->not->toBe([]);
    }
});

it('fails mail function transport when configured max message size is exceeded', function (): void {
    $transport = new MailFunctionTransport(maxMessageBytes: 32);
    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('to@example.com')
        ->subject('mail limit')
        ->text(str_repeat('x', 256));

    $result = $transport->send($message);

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('configured mail() max message size');
});

function createSendmailTestScript(): string
{
    $dir = sys_get_temp_dir().'/talkingbytes-sendmail-'.bin2hex(random_bytes(6));
    mkdir($dir, 0775, true);
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    });

    $script = $dir.'/sendmail-fixture';

    file_put_contents($script, <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'ok';
$stdin = stream_get_contents(STDIN);

if ($mode === 'sleep') {
    $seconds = (int) ($argv[2] ?? 2);
    sleep(max(1, $seconds));
}

if ($mode === 'fail') {
    fwrite(STDERR, "simulated failure\n");
    exit(7);
}

if ($stdin === false) {
    fwrite(STDERR, "stdin read failed\n");
    exit(8);
}

fwrite(STDOUT, "accepted\n");
exit(0);
PHP
    );

    chmod($script, 0775);

    return $script;
}

function testMessage(): EmailMessage
{
    return EmailMessage::new()
        ->from('sender@example.com')
        ->to('to@example.com')
        ->subject('process coverage')
        ->text('message body');
}
