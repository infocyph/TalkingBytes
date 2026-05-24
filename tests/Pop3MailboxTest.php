<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Exception\MailboxAuthenticationException;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\Pop3Transport;
use PHPUnit\Framework\SkippedWithMessageException;

final class FakePop3ServerProcess
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

    /**
     * @param  array<string, mixed>  $scenario
     */
    public static function start(array $scenario): self
    {
        $workDir = sys_get_temp_dir().'/talkingbytes-pop3-'.bin2hex(random_bytes(6));
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
            throw new RuntimeException('Unable to start fake POP3 server process.');
        }

        if (is_resource($pipes[0] ?? null)) {
            fclose($pipes[0]);
        }

        $port = self::waitForReadyPort($readyPath, $process, $pipes);

        return new self($process, $pipes, $workDir, $port);
    }

    /**
     * @return array{commands:list<string>,mismatches:list<string>}
     */
    public function transcript(): array
    {
        $reportPath = $this->workDir.'/report.json';
        $deadline = microtime(true) + 1.0;

        while (! is_file($reportPath) && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! is_file($reportPath)) {
            return ['commands' => [], 'mismatches' => ['report not found']];
        }

        $decoded = json_decode((string) file_get_contents($reportPath), true);
        if (! is_array($decoded)) {
            return ['commands' => [], 'mismatches' => ['invalid report']];
        }

        /** @var list<string> $commands */
        $commands = is_array($decoded['commands'] ?? null) ? array_values($decoded['commands']) : [];
        /** @var list<string> $mismatches */
        $mismatches = is_array($decoded['mismatches'] ?? null) ? array_values($decoded['mismatches']) : [];

        return ['commands' => $commands, 'mismatches' => $mismatches];
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

    public function __destruct()
    {
        $this->stop();
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

                throw new RuntimeException('Fake POP3 server exited early: '.trim($stderr));
            }

            usleep(10000);
        }

        if (! is_file($readyPath)) {
            throw new RuntimeException('Fake POP3 server did not become ready in time.');
        }

        $ready = json_decode((string) file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
        $port = (int) ($ready['port'] ?? 0);
        if ($port < 1) {
            throw new RuntimeException('Fake POP3 server reported an invalid port.');
        }

        return $port;
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
    file_put_contents($reportPath, json_encode(['commands' => [], 'mismatches' => ['invalid scenario']]));
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    file_put_contents($reportPath, json_encode(['commands' => [], 'mismatches' => [sprintf('bind failed: %s (%d)', $errstr, $errno)]]));
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr((string) strrchr((string) $name, ':'), 1);
file_put_contents($readyPath, json_encode(['port' => $port]));

$client = @stream_socket_accept($server, 15);
$transcript = ['commands' => [], 'mismatches' => []];

if ($client === false) {
    $transcript['mismatches'][] = 'client did not connect';
    file_put_contents($reportPath, json_encode($transcript));
    fclose($server);
    exit(1);
}

stream_set_timeout($client, 5);
fwrite($client, "+OK fake-pop3 ready\r\n");

$expect = is_array($scenario['expect'] ?? null) ? $scenario['expect'] : [];
foreach ($expect as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $line = fgets($client, 8192);
    if ($line === false) {
        $transcript['mismatches'][] = 'expected command, got stream close';
        break;
    }

    $command = rtrim($line, "\r\n");
    $transcript['commands'][] = $command;

    if (isset($entry['regex']) && preg_match((string) $entry['regex'], $command) !== 1) {
        $transcript['mismatches'][] = sprintf('regex mismatch "%s" for "%s"', $entry['regex'], $command);
    }

    $status = (string) ($entry['status'] ?? '+OK');
    $text = (string) ($entry['text'] ?? 'done');
    fwrite($client, sprintf("%s %s\r\n", $status, $text));

    if (is_array($entry['multiline'] ?? null)) {
        foreach ($entry['multiline'] as $responseLine) {
            fwrite($client, (string) $responseLine . "\r\n");
        }

        if (!($entry['omit_terminator'] ?? false)) {
            fwrite($client, ".\r\n");
        }

        $sleepMs = (int) ($entry['sleep_ms'] ?? 0);
        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }
}

fclose($client);
fclose($server);
file_put_contents($reportPath, json_encode($transcript));
PHP;
    }
}

it('fetches status, list and parsed message over pop3 socket transport', function (): void {
    $rawLines = [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: POP3 hello',
        '',
        'Hello over POP3',
    ];

    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['PIPELINING', 'UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^STAT$/', 'text' => '2 456'],
            ['regex' => '/^LIST$/', 'multiline' => ['1 123', '2 333']],
            ['regex' => '/^UIDL$/', 'multiline' => ['1 uidl-1', '2 uidl-2']],
            ['regex' => '/^RETR 1$/', 'multiline' => $rawLines],
            ['regex' => '/^DELE 2$/'],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $status = $mailbox->status();
    $refs = $mailbox->listMessageRefs();
    $parsed = $mailbox->fetchParsed(1);
    $mailbox->delete(2);
    $mailbox->logout();

    $transcript = $server->transcript();
    $server->stop();

    expect($status->messages)->toBe(2);
    expect($refs)->toHaveCount(2);
    expect($refs[0]->uid)->toBe(1);
    expect($refs[0]->externalId)->toBe('uidl-1');
    expect($parsed->subject)->toBe('POP3 hello');
    expect($parsed->textBody)->toBe('Hello over POP3');
    expect($transcript['mismatches'])->toBe([]);
});

it('fails when pop3 starttls is required and stls capability is missing', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::StartTlsRequired,
        username: 'user',
        password: 'pass',
    ));

    expect(fn () => $mailbox->status())->toThrow(MailboxConnectionException::class);

    $mailbox->logout();
    $server->stop();
});

it('supports pop3 rset to clear pending deletions before quit', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^DELE 1$/'],
            ['regex' => '/^RSET$/'],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $mailbox->delete(1);
    $mailbox->reset();
    $mailbox->logout();

    $transcript = $server->transcript();
    $server->stop();

    expect($transcript['mismatches'])->toBe([]);
});

it('fails pop3 authentication when server rejects password', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS wrong$/', 'status' => '-ERR', 'text' => 'invalid login'],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'wrong',
    ));

    expect(fn () => $mailbox->status())->toThrow(MailboxAuthenticationException::class);

    $mailbox->logout();
    $server->stop();
});

it('rejects unsupported pop3 folder operations and non-all searches', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    expect(fn () => $mailbox->transport()->search(MailboxSearch::new()->unseen()))->toThrow(MailboxProtocolException::class);

    $mailbox->logout();
    $server->stop();
});

it('handles pop3 multiline dot-unescaping for retr', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^RETR 1$/', 'multiline' => [
                'From: sender@example.com',
                'To: user@example.com',
                'Subject: Dot lines',
                '',
                '..literal dot line',
                '..',
            ]],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $parsed = $mailbox->fetchParsed(1);
    $mailbox->logout();
    $server->stop();

    expect($parsed->textBody)->toContain('.literal dot line');
});

it('fails pop3 retr when multiline terminator is missing', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^RETR 1$/', 'multiline' => [
                'From: sender@example.com',
                'To: user@example.com',
                'Subject: Broken',
                '',
                'no terminator',
            ], 'omit_terminator' => true, 'sleep_ms' => 1500],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
        timeoutSeconds: 1,
    ));

    expect(fn () => $mailbox->fetchParsed(1))->toThrow(MailboxConnectionException::class);

    $mailbox->logout();
    $server->stop();
});

it('supports pop3 empty message body retrieval', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^RETR 1$/', 'multiline' => []],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $raw = $mailbox->fetchRaw(1);
    $mailbox->logout();
    $server->stop();

    expect($raw)->toBe('');
});

it('redacts POP3 PASS value in mailbox command events', function (): void {
    $events = [];
    Email::events(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'mailbox.command.')) {
            $events[] = $payload;
        }
    });

    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['UIDL']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^STAT$/', 'text' => '0 0'],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $mailbox->status();
    $mailbox->logout();
    $server->stop();
    Email::events(null);

    expect(array_any(
        $events,
        static fn (array $payload): bool => ($payload['command'] ?? null) === 'PASS [REDACTED]',
    ))->toBeTrue();
    expect(array_any(
        $events,
        static fn (array $payload): bool => is_string($payload['command'] ?? null) && str_contains($payload['command'], 'PASS pass'),
    ))->toBeFalse();
});

it('rejects invalid pop3 message numbers before issuing commands', function (): void {
    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: 110,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    expect(fn () => $mailbox->fetchRaw(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->delete(-1))->toThrow(InvalidArgumentException::class);
});

it('exposes dedicated pop3 transport contract without foldered mailbox operations', function (): void {
    $transport = new ReflectionClass(Pop3Transport::class);

    expect($transport->hasMethod('rawMessage'))->toBeTrue();
    expect($transport->hasMethod('uidl'))->toBeTrue();
    expect($transport->hasMethod('list'))->toBeTrue();
    expect($transport->hasMethod('createFolder'))->toBeFalse();
    expect($transport->hasMethod('move'))->toBeFalse();
    expect($transport->hasMethod('copy'))->toBeFalse();
});

it('lists message refs without uidl when server does not advertise capability', function (): void {
    $server = FakePop3ServerProcess::start([
        'expect' => [
            ['regex' => '/^CAPA$/', 'multiline' => ['PIPELINING']],
            ['regex' => '/^USER user$/'],
            ['regex' => '/^PASS pass$/'],
            ['regex' => '/^LIST$/', 'multiline' => ['1 123']],
            ['regex' => '/^QUIT$/'],
        ],
    ]);

    $mailbox = Pop3Mailbox::usingConfig(new Pop3Config(
        host: '127.0.0.1',
        port: $server->port,
        security: Pop3Security::None,
        username: 'user',
        password: 'pass',
    ));

    $refs = $mailbox->listMessageRefs();
    $mailbox->logout();
    $server->stop();

    expect($refs)->toHaveCount(1);
    expect($refs[0]->uid)->toBe(1);
    expect($refs[0]->externalId)->toBeNull();
});
