<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Core\Result\CommunicationResult;
use Infocyph\TalkingBytes\Email\Config\LogEmailConfig;
use Infocyph\TalkingBytes\Email\Config\SendmailConfig;
use Infocyph\TalkingBytes\Email\Config\SpoolConfig;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Transport\EmailTransport;
use Infocyph\TalkingBytes\Email\Transport\FallbackEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\LogEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\NullEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\RateLimitedEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\RetryEmailTransport;
use Infocyph\TalkingBytes\Email\Transport\SendmailTransport;
use Infocyph\TalkingBytes\Email\Transport\SpoolEmailTransport;
use Infocyph\TalkingBytes\Resilience\RateLimiter;
use Infocyph\TalkingBytes\Retry\FixedDelayRetryPolicy;

function baselineEmail(): EmailMessage
{
    return EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('Transport')
        ->text('Body');
}

it('returns success metadata for null email transport', function (): void {
    $result = (new NullEmailTransport)->send(baselineEmail());

    expect($result->successful)->toBeTrue();
    expect($result->metadata['transport'] ?? null)->toBe('null-email');
    expect($result->metadata['recipient_count'] ?? null)->toBe(1);
});

it('writes log email payload to configured directory', function (): void {
    $directory = getcwd().'/tests/.tmp-log-'.bin2hex(random_bytes(4));
    $transport = new LogEmailTransport(new LogEmailConfig($directory, filenamePrefix: 'mail', dailyFiles: false));

    $result = $transport->send(baselineEmail());
    $files = glob($directory.'/*') ?: [];

    expect($result->successful)->toBeTrue();
    expect($files)->toHaveCount(1);
    expect((string) file_get_contents($files[0]))->toContain('Subject: Transport');

    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($directory);
});

it('fails log transport when configured max message size is exceeded', function (): void {
    $directory = getcwd().'/tests/.tmp-log-limit-'.bin2hex(random_bytes(4));
    $transport = new LogEmailTransport(new LogEmailConfig($directory, dailyFiles: false, maxMessageBytes: 32));

    $result = $transport->send(baselineEmail()->text(str_repeat('x', 256)));

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('exceeds configured limit');

    if (is_dir($directory)) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('writes spool eml and metadata sidecar when enabled', function (): void {
    $directory = getcwd().'/tests/.tmp-spool-'.bin2hex(random_bytes(4));
    $transport = new SpoolEmailTransport(new SpoolConfig($directory, writeMetadata: true));

    $result = $transport->send(baselineEmail()->tag('batch', 'alpha'));
    $files = glob($directory.'/*') ?: [];

    expect($result->successful)->toBeTrue();
    expect($files)->toHaveCount(2);
    expect((bool) preg_grep('/\.eml$/', $files))->toBeTrue();
    expect((bool) preg_grep('/\.json$/', $files))->toBeTrue();

    foreach ($files as $file) {
        unlink($file);
    }
    rmdir($directory);
});

it('fails spool transport when configured max message size is exceeded', function (): void {
    $directory = getcwd().'/tests/.tmp-spool-limit-'.bin2hex(random_bytes(4));
    $transport = new SpoolEmailTransport(new SpoolConfig($directory, writeMetadata: false, maxMessageBytes: 32));

    $result = $transport->send(baselineEmail()->text(str_repeat('x', 256)));

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('exceeds configured limit');

    if (is_dir($directory)) {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});

it('builds sendmail command with envelope sender argument', function (): void {
    $transport = new SendmailTransport(new SendmailConfig(PHP_BINARY, ['-v'], 2));
    $reflection = new ReflectionMethod($transport, 'buildCommand');
    $command = $reflection->invoke($transport, baselineEmail()->returnPath('bounce@example.com'));

    expect($command[0])->toBe(PHP_BINARY);
    expect($command[1])->toBe('-v');
    expect($command[2])->toBe('-fbounce@example.com');
});

it('fails clearly when sendmail binary is not executable', function (): void {
    $transport = new SendmailTransport(new SendmailConfig(getcwd().'/tests/not-a-binary', ['-t'], 1));
    $result = $transport->send(baselineEmail());

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('not executable');
});

it('retries failed transport result and succeeds on later attempt', function (): void {
    $attempts = 0;

    $inner = new class($attempts) implements EmailTransport
    {
        public function __construct(private int &$attempts) {}

        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            $this->attempts++;
            if ($this->attempts < 2) {
                return CommunicationResult::failure('temporary');
            }

            return CommunicationResult::success(metadata: ['attempt' => $this->attempts]);
        }
    };

    $transport = new RetryEmailTransport($inner, new FixedDelayRetryPolicy(3, 0));
    $result = $transport->send(baselineEmail());

    expect($result->successful)->toBeTrue();
    expect($attempts)->toBe(2);
});

it('uses fallback transport and records attempted transports metadata', function (): void {
    $primary = new class implements EmailTransport
    {
        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            return CommunicationResult::failure('primary failed');
        }
    };

    $fallback = new class implements EmailTransport
    {
        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            return CommunicationResult::success(metadata: ['transport' => 'fallback']);
        }
    };

    $result = (new FallbackEmailTransport($primary, [$fallback]))->send(baselineEmail());

    expect($result->successful)->toBeTrue();
    expect($result->metadata['fallback_used'] ?? null)->toBeTrue();
    expect($result->metadata['attempted_transports'] ?? [])->toHaveCount(2);
});

it('blocks when rate limited transport exceeds quota', function (): void {
    $inner = new class implements EmailTransport
    {
        public function send(EmailMessage $message): CommunicationResult
        {
            unset($message);

            return CommunicationResult::success();
        }
    };

    $transport = new RateLimitedEmailTransport($inner, new RateLimiter(1, 60));
    $first = $transport->send(baselineEmail());

    expect($first->successful)->toBeTrue();
    expect(fn () => $transport->send(baselineEmail()))->toThrow(RuntimeException::class, 'Rate limit exceeded');
});
