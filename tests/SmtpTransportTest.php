<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\SmtpConfig;
use Infocyph\TalkingBytes\Email\Config\SmtpCredentials;
use Infocyph\TalkingBytes\Email\EmailMessage;
use Infocyph\TalkingBytes\Email\Enum\SmtpAuthMechanism;
use Infocyph\TalkingBytes\Email\Enum\SmtpSecurity;
use Infocyph\TalkingBytes\Email\Enum\SmtpUtf8Policy;
use Infocyph\TalkingBytes\Email\Result\EmailDeliveryReport;
use Infocyph\TalkingBytes\Email\Transport\SmtpTransport;
use PHPUnit\Framework\SkippedWithMessageException;

final class FakeSmtpServerProcess
{
    /**
     * @param  array<int, resource|null>  $pipes
     */
    private function __construct(
        private mixed $process,
        private array $pipes,
        private string $workDir,
        public int $port,
    ) {}

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * @param  array<string, mixed>  $scenario
     */
    public static function start(array $scenario): self
    {
        $workDir = sys_get_temp_dir().'/talkingbytes-smtp-'.bin2hex(random_bytes(6));
        mkdir($workDir, 0775, true);

        $scriptPath = $workDir.'/server.php';
        $scenarioPath = $workDir.'/scenario.json';
        $readyPath = $workDir.'/ready.json';
        file_put_contents($scriptPath, self::script());
        file_put_contents($scenarioPath, json_encode($scenario, JSON_THROW_ON_ERROR));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open([PHP_BINARY, $scriptPath, $scenarioPath, $readyPath], $descriptors, $pipes);
        if (! is_resource($process)) {
            self::cleanupDirectory($workDir);

            throw new RuntimeException('Unable to start fake SMTP server process.');
        }

        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }

        $port = self::waitForReadyPort($readyPath, $process, $pipes);

        return new self($process, $pipes, $workDir, $port);
    }

    public function stop(): void
    {
        foreach ([1, 2] as $index) {
            if (! is_resource($this->pipes[$index] ?? null)) {
                continue;
            }

            fclose($this->pipes[$index]);
            $this->pipes[$index] = null;
        }

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if (($status['running'] ?? false) === true) {
                proc_terminate($this->process);
            }

            proc_close($this->process);
        }

        self::cleanupDirectory($this->workDir);
    }

    /**
     * @return array{commands:list<string>,data:list<string>,mismatches:list<string>}
     */
    public function transcript(): array
    {
        $reportPath = $this->workDir.'/report.json';
        $deadline = microtime(true) + 1.0;

        while (! is_file($reportPath) && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! is_file($reportPath)) {
            return ['commands' => [], 'data' => [], 'mismatches' => ['report not found']];
        }

        $decoded = null;
        while (microtime(true) < $deadline) {
            $decoded = json_decode((string) file_get_contents($reportPath), true);
            if (is_array($decoded)) {
                break;
            }

            usleep(10000);
        }

        if (! is_array($decoded)) {
            return ['commands' => [], 'data' => [], 'mismatches' => ['invalid report']];
        }

        /** @var list<string> $commands */
        $commands = is_array($decoded['commands'] ?? null) ? array_values($decoded['commands']) : [];
        /** @var list<string> $data */
        $data = is_array($decoded['data'] ?? null) ? array_values($decoded['data']) : [];
        /** @var list<string> $mismatches */
        $mismatches = is_array($decoded['mismatches'] ?? null) ? array_values($decoded['mismatches']) : [];

        return ['commands' => $commands, 'data' => $data, 'mismatches' => $mismatches];
    }

    private static function cleanupDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }

    private static function script(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$scenarioPath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
$reportPath = dirname($readyPath) . '/report.json';

$scenario = json_decode((string) file_get_contents($scenarioPath), true);
if (!is_array($scenario)) {
    file_put_contents($reportPath, json_encode(['commands' => [], 'data' => [], 'mismatches' => ['invalid scenario']]));
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    file_put_contents($reportPath, json_encode(['commands' => [], 'data' => [], 'mismatches' => [sprintf('bind failed: %s (%d)', $errstr, $errno)]]));
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr((string) strrchr((string) $name, ':'), 1);
$readyTempPath = $readyPath . '.tmp';
file_put_contents($readyTempPath, json_encode(['port' => $port]));
rename($readyTempPath, $readyPath);

$client = @stream_socket_accept($server, 15);
$transcript = ['commands' => [], 'data' => [], 'mismatches' => []];

if ($client === false) {
    $transcript['mismatches'][] = 'client did not connect';
    file_put_contents($reportPath, json_encode($transcript));
    fclose($server);
    exit(1);
}

stream_set_timeout($client, 5);

$greetingDelayMs = (int) ($scenario['greeting_delay_ms'] ?? 0);
if ($greetingDelayMs > 0) {
    usleep($greetingDelayMs * 1000);
}

if (($scenario['skip_greeting'] ?? false) !== true) {
    foreach (($scenario['greeting'] ?? ['220 fake-smtp.local ESMTP ready']) as $line) {
        fwrite($client, $line . "\r\n");
    }
}

$expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
$deferredResponses = [];
$deferUntilCommands = 0;

foreach ($expect as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $type = $entry['type'] ?? 'command';

    if ($type === 'data') {
        $lines = [];
        while (true) {
            $line = fgets($client, 8192);
            if ($line === false) {
                $transcript['mismatches'][] = 'expected DATA payload, got stream close';
                break 2;
            }

            $trimmed = rtrim($line, "\r\n");
            if ($trimmed === '.') {
                break;
            }

            $lines[] = $trimmed;
        }

        $transcript['data'][] = implode("\n", $lines);
    } else {
        $line = fgets($client, 8192);
        if ($line === false) {
            $transcript['mismatches'][] = 'expected command, got stream close';
            break;
        }

        $command = rtrim($line, "\r\n");
        $transcript['commands'][] = $command;

        if (isset($entry['equals']) && $command !== $entry['equals']) {
            $transcript['mismatches'][] = sprintf('expected "%s", got "%s"', $entry['equals'], $command);
        }

        if (isset($entry['regex']) && preg_match((string) $entry['regex'], $command) !== 1) {
            $transcript['mismatches'][] = sprintf('regex mismatch "%s" for "%s"', $entry['regex'], $command);
        }
    }

    $timeoutMs = (int) ($entry['timeout_ms'] ?? 0);
    if ($timeoutMs > 0) {
        usleep($timeoutMs * 1000);
        break;
    }

    $entryResponses = is_array($entry['responses'] ?? null) ? $entry['responses'] : [];
    $deferUntil = (int) ($entry['defer_responses_until_commands'] ?? 0);
    if ($deferUntil > 0) {
        $deferUntilCommands = max($deferUntilCommands, $deferUntil);
    }

    if ($deferUntilCommands > 0 && count($transcript['commands']) < $deferUntilCommands) {
        foreach ($entryResponses as $response) {
            $deferredResponses[] = $response;
        }

        continue;
    }

    foreach ($deferredResponses as $response) {
        fwrite($client, $response . "\r\n");
    }
    $deferredResponses = [];
    $deferUntilCommands = 0;

    foreach ($entryResponses as $response) {
        fwrite($client, $response . "\r\n");
    }
}

foreach ($deferredResponses as $response) {
    fwrite($client, $response . "\r\n");
}

fclose($client);
fclose($server);
file_put_contents($reportPath, json_encode($transcript));
PHP;
    }

    /**
     * @param  array<int, resource|null>  $pipes
     */
    private static function waitForReadyPort(string $readyPath, mixed $process, array $pipes): int
    {
        $deadline = microtime(true) + 5.0;

        while (! is_file($readyPath) && microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                $stderr = is_resource($pipes[2] ?? null) ? (string) stream_get_contents($pipes[2]) : '';
                $reportPath = dirname($readyPath).'/report.json';
                if (is_file($reportPath)) {
                    $report = json_decode((string) file_get_contents($reportPath), true);
                    $mismatch = is_array($report['mismatches'] ?? null) ? ($report['mismatches'][0] ?? '') : '';
                    if (is_string($mismatch) && str_contains($mismatch, 'bind failed')) {
                        throw new SkippedWithMessageException('TCP socket bind is unavailable in this environment.');
                    }
                }

                throw new RuntimeException('Fake SMTP server exited early: '.trim($stderr));
            }

            usleep(10000);
        }

        if (! is_file($readyPath)) {
            throw new RuntimeException('Fake SMTP server did not become ready in time.');
        }

        while (microtime(true) < $deadline) {
            $rawReady = file_get_contents($readyPath);
            if ($rawReady === false || $rawReady === '') {
                usleep(10000);
                continue;
            }

            try {
                $ready = json_decode($rawReady, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                usleep(10000);
                continue;
            }

            $port = (int) ($ready['port'] ?? 0);
            if ($port > 0) {
                return $port;
            }

            usleep(10000);
        }

        throw new RuntimeException('Fake SMTP server reported an invalid port.');
    }
}

function smtpMessage(string $text = 'Body'): EmailMessage
{
    return EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->subject('SMTP Test')
        ->text($text);
}

function smtpTransportFor(
    FakeSmtpServerProcess $server,
    SmtpSecurity $security = SmtpSecurity::None,
    ?SmtpCredentials $credentials = null,
    SmtpAuthMechanism $authMechanism = SmtpAuthMechanism::Auto,
    int $timeoutSeconds = 2,
    SmtpUtf8Policy $utf8Policy = SmtpUtf8Policy::Auto,
    bool $captureTranscript = false,
    ?int $maxMessageBytes = null,
): SmtpTransport {
    return new SmtpTransport(
        new SmtpConfig(
            host: '127.0.0.1',
            port: $server->port,
            security: $security,
            credentials: $credentials,
            timeoutSeconds: $timeoutSeconds,
            localDomain: 'localhost',
            authMechanism: $authMechanism,
            utf8Policy: $utf8Policy,
            captureTranscript: $captureTranscript,
            maxMessageBytes: $maxMessageBytes,
        ),
    );
}

it('fails when STARTTLS is required but not advertised', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SIZE 4096']],
            ['equals' => 'RSET', 'responses' => ['250 Reset']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, SmtpSecurity::StartTlsRequired)->send(smtpMessage());
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('does not advertise STARTTLS');
    expect($transcript['mismatches'])->toBe([]);
});

it('continues when STARTTLS is optional and not advertised', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 PIPELINING']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data with <CR><LF>.<CR><LF>']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, SmtpSecurity::StartTlsOptional)->send(smtpMessage());
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($transcript['commands'])->toContain('QUIT');
    expect($transcript['mismatches'])->toBe([]);
});

it('pipelines MAIL FROM and RCPT commands when PIPELINING is advertised', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 PIPELINING']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK'], 'defer_responses_until_commands' => 3],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'RCPT TO:<bob@example.com>', 'responses' => ['550 No such user']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->cc('bob@example.com')
        ->subject('Pipelined')
        ->text('Body');

    $result = smtpTransportFor($server)->send($message);
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->metadata['accepted_count'] ?? null)->toBe(1);
    expect($result->metadata['rejected_count'] ?? null)->toBe(1);
    expect($transcript['commands'])->toContain('DATA');
    expect($transcript['mismatches'])->toBe([]);
});

it('authenticates with AUTH PLAIN when available', function (): void {
    $credentials = new SmtpCredentials('user', 'pass');
    $plainPayload = base64_encode("\0user\0pass");

    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 AUTH PLAIN LOGIN']],
            ['equals' => 'AUTH PLAIN '.$plainPayload, 'responses' => ['235 Auth successful']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, credentials: $credentials)->send(smtpMessage());
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($result->metadata['auth_mechanism'] ?? null)->toBe('plain');
});

it('authenticates with AUTH LOGIN when plain is unavailable', function (): void {
    $credentials = new SmtpCredentials('user', 'pass');

    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 AUTH LOGIN']],
            ['equals' => 'AUTH LOGIN', 'responses' => ['334 VXNlcm5hbWU6']],
            ['equals' => base64_encode('user'), 'responses' => ['334 UGFzc3dvcmQ6']],
            ['equals' => base64_encode('pass'), 'responses' => ['235 Auth successful']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, credentials: $credentials)->send(smtpMessage());
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($result->metadata['auth_mechanism'] ?? null)->toBe('login');
});

it('fails when explicit auth mechanism is not advertised', function (): void {
    $credentials = new SmtpCredentials('user', 'pass');

    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 AUTH LOGIN']],
            ['equals' => 'RSET', 'responses' => ['250 Reset']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, credentials: $credentials, authMechanism: SmtpAuthMechanism::Plain)->send(smtpMessage());
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('does not advertise AUTH PLAIN');
});

it('fails when message exceeds SMTP SIZE limit', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SIZE 128']],
            ['equals' => 'RSET', 'responses' => ['250 Reset']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server)->send(smtpMessage(str_repeat('x', 512)));
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('exceeds SMTP SIZE limit');
});

it('fails when message exceeds configured SMTP max message bytes', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [],
    ]);

    $result = smtpTransportFor($server, maxMessageBytes: 64)->send(smtpMessage(str_repeat('x', 512)));
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('configured SMTP max message size');
});

it('uses return-path as envelope sender and includes DSN arguments', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 DSN']],
            ['regex' => '/^MAIL FROM:<bounce@example\.com> RET=HDRS ENVID=[a-f0-9]{16}$/', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com> NOTIFY=SUCCESS,FAILURE,DELAY', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = smtpMessage()
        ->returnPath('bounce@example.com')
        ->deliveryNotification(success: true, failure: true, delay: true, returnFull: false);

    $result = smtpTransportFor($server)->send($message);
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($transcript['mismatches'])->toBe([]);
});

it('reports partial success per recipient and preserves metadata', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 OK']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'RCPT TO:<bob@example.com>', 'responses' => ['550 No such user']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->cc('bob@example.com')
        ->subject('Partial')
        ->text('Body');

    $result = smtpTransportFor($server)->send($message);
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->response)->toBeInstanceOf(EmailDeliveryReport::class);
    expect($result->response->acceptedRecipients)->toBe(['alice@example.com']);
    expect($result->response->rejectedRecipients)->toHaveKey('bob@example.com');
    expect($result->metadata['partial_success'] ?? null)->toBeTrue();
    expect($result->metadata['accepted_count'] ?? null)->toBe(1);
    expect($result->metadata['rejected_count'] ?? null)->toBe(1);
});

it('fails when all recipients are rejected and skips DATA', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 OK']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['550 No such user']],
            ['equals' => 'RCPT TO:<bob@example.com>', 'responses' => ['550 No such user']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = EmailMessage::new()
        ->from('sender@example.com')
        ->to('alice@example.com')
        ->cc('bob@example.com')
        ->subject('Rejected')
        ->text('Body');

    $result = smtpTransportFor($server)->send($message);
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('SMTP rejected all recipients');
    expect($transcript['commands'])->not->toContain('DATA');
});

it('dot-stuffs DATA payload lines that start with a dot', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 OK']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = smtpMessage(".first\n..second\nthird");
    $result = smtpTransportFor($server)->send($message);
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($transcript['data'])->toHaveCount(1);
    expect($transcript['data'][0])->toContain('..first');
    expect($transcript['data'][0])->toContain('..second');
});

it('times out when server does not send greeting in time', function (): void {
    $server = FakeSmtpServerProcess::start([
        'greeting_delay_ms' => 1500,
    ]);

    $result = smtpTransportFor($server, timeoutSeconds: 1)->send(smtpMessage());
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('timed out');
});

it('adds SMTPUTF8 to MAIL FROM when unicode mailbox is used and server supports it', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SMTPUTF8']],
            ['equals' => 'MAIL FROM:<séndér@example.com> SMTPUTF8', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = EmailMessage::new()
        ->from('séndér@example.com')
        ->to('alice@example.com')
        ->subject('SMTPUTF8')
        ->text('Body');

    $result = smtpTransportFor($server)->send($message);
    $server->stop();

    expect($result->successful)->toBeTrue();
});

it('fails when utf8 policy rejects unicode mailbox addresses', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SMTPUTF8']],
            ['equals' => 'RSET', 'responses' => ['250 Reset']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $message = EmailMessage::new()
        ->from('séndér@example.com')
        ->to('alice@example.com')
        ->subject('SMTPUTF8')
        ->text('Body');

    $result = smtpTransportFor($server, utf8Policy: SmtpUtf8Policy::Reject)->send($message);
    $server->stop();

    expect($result->successful)->toBeFalse();
    expect($result->error)->toContain('not allowed by current policy');
});

it('captures redacted SMTP transcript metadata when enabled', function (): void {
    $credentials = new SmtpCredentials('user', 'pass');

    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 AUTH LOGIN']],
            ['equals' => 'AUTH LOGIN', 'responses' => ['334 VXNlcm5hbWU6']],
            ['equals' => base64_encode('user'), 'responses' => ['334 UGFzc3dvcmQ6']],
            ['equals' => base64_encode('pass'), 'responses' => ['235 Auth successful']],
            ['equals' => 'MAIL FROM:<sender@example.com>', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server, credentials: $credentials, captureTranscript: true)->send(smtpMessage());
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($result->metadata['smtp_transcript'] ?? null)->toBeArray();
    expect(implode("\n", $result->metadata['smtp_transcript'] ?? []))->toContain('C: [REDACTED]');
});

it('uses sequential MAIL and RCPT flow when PIPELINING is not advertised', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SIZE 4096']],
            ['regex' => '/^MAIL FROM:<sender@example\.com>(?: SIZE=\d+)?$/', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $result = smtpTransportFor($server)->send(smtpMessage());
    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($transcript['commands'][0] ?? null)->toBe('EHLO localhost');
    expect($transcript['commands'][1] ?? '')->toStartWith('MAIL FROM:<sender@example.com>');
    expect($transcript['commands'][2] ?? null)->toBe('RCPT TO:<alice@example.com>');
    expect($transcript['commands'][3] ?? null)->toBe('DATA');
    expect($transcript['commands'][4] ?? null)->toBe('QUIT');
});

it('streams large attachment payload through smtp DATA flow', function (): void {
    $server = FakeSmtpServerProcess::start([
        'expect' => [
            ['equals' => 'EHLO localhost', 'responses' => ['250-localhost', '250 SIZE 20971520']],
            ['regex' => '/^MAIL FROM:<sender@example\.com> SIZE=\d+$/', 'responses' => ['250 Sender OK']],
            ['equals' => 'RCPT TO:<alice@example.com>', 'responses' => ['250 Recipient OK']],
            ['equals' => 'DATA', 'responses' => ['354 End data']],
            ['type' => 'data', 'responses' => ['250 Queued']],
            ['equals' => 'QUIT', 'responses' => ['221 Bye']],
        ],
    ]);

    $stream = fopen('php://temp', 'w+b');
    expect($stream)->not->toBeFalse();
    fwrite($stream, str_repeat('S', 1024 * 1024));
    rewind($stream);

    $message = smtpMessage()
        ->attachStream($stream, 'large.bin', 'application/octet-stream');

    $result = smtpTransportFor($server)->send($message);
    fclose($stream);

    $transcript = $server->transcript();
    $server->stop();

    expect($result->successful)->toBeTrue();
    expect($transcript['mismatches'])->toBe([]);
    expect($transcript['data'])->toHaveCount(1);
    expect(strlen($transcript['data'][0]))->toBeGreaterThan(1024 * 1024);
});
