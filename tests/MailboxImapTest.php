<?php

declare(strict_types=1);

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Email;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;
use Infocyph\TalkingBytes\Email\Mailbox\FakeMailbox;
use Infocyph\TalkingBytes\Email\Mailbox\ImapSocketTransport;
use Infocyph\TalkingBytes\Email\Mailbox\Mailbox;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxMessageRef;
use Infocyph\TalkingBytes\Email\Mailbox\MailboxSearch;
use PHPUnit\Framework\SkippedWithMessageException;

final class FakeImapServerProcess
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
        $workDir = sys_get_temp_dir().'/talkingbytes-imap-'.bin2hex(random_bytes(6));
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
            throw new RuntimeException('Unable to start fake IMAP server process.');
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
        $deadline = microtime(true) + 2.0;

        while (! is_file($reportPath) && microtime(true) < $deadline) {
            usleep(10000);
        }

        if (! is_file($reportPath)) {
            return ['commands' => [], 'mismatches' => ['report not found']];
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

                throw new RuntimeException('Fake IMAP server exited early: '.trim($stderr));
            }

            usleep(10000);
        }

        if (! is_file($readyPath)) {
            throw new RuntimeException('Fake IMAP server did not become ready in time.');
        }

        $ready = json_decode((string) file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
        $port = (int) ($ready['port'] ?? 0);
        if ($port < 1) {
            throw new RuntimeException('Fake IMAP server reported an invalid port.');
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

$writeJson = static function (string $path, array $payload): void {
    $tmp = $path . '.tmp';
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"commands":[],"mismatches":["encode failed"]}';
    }
    file_put_contents($tmp, $json);
    rename($tmp, $path);
};

$scenario = json_decode((string) file_get_contents($scenarioPath), true);
if (!is_array($scenario)) {
    $writeJson($reportPath, ['commands' => [], 'mismatches' => ['invalid scenario']]);
    exit(1);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    $writeJson($reportPath, ['commands' => [], 'mismatches' => [sprintf('bind failed: %s (%d)', $errstr, $errno)]]);
    exit(1);
}

$name = stream_socket_get_name($server, false);
$port = (int) substr((string) strrchr((string) $name, ':'), 1);
file_put_contents($readyPath, json_encode(['port' => $port]));

$client = @stream_socket_accept($server, 15);
$transcript = ['commands' => [], 'mismatches' => []];

if ($client === false) {
    $transcript['mismatches'][] = 'client did not connect';
    $writeJson($reportPath, $transcript);
    fclose($server);
    exit(1);
}

stream_set_timeout($client, 5);
fwrite($client, "* OK fake-imap ready\r\n");

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

    $tag = explode(' ', $command, 2)[0] ?? 'A0000';

    foreach (($entry['untagged'] ?? []) as $untagged) {
        fwrite($client, $untagged . "\r\n");
    }

    if (isset($entry['literal'])) {
        $literal = (string) $entry['literal'];
        fwrite($client, sprintf("* 1 FETCH (RFC822 {%d}\r\n", strlen($literal)));
        fwrite($client, $literal);
        fwrite($client, "\r\n)\r\n");
    }

    $status = strtoupper((string) ($entry['status'] ?? 'OK'));
    $text = (string) ($entry['text'] ?? 'done');
    fwrite($client, sprintf("%s %s %s\r\n", $tag, $status, $text));
}

fclose($client);
fclose($server);
$writeJson($reportPath, $transcript);
PHP;
    }
}

it('fetches folders, status, search and parsed message over imap socket transport', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Inbox message',
        '',
        'Hello mailbox',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS MOVE STARTTLS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ LIST "" \*$/', 'untagged' => ['* LIST (\\HasNoChildren) "/" "INBOX"']],
            ['regex' => '/^A\\d+ STATUS "INBOX" \(MESSAGES RECENT UNSEEN UIDVALIDITY UIDNEXT\)$/', 'untagged' => ['* STATUS "INBOX" (MESSAGES 3 RECENT 0 UNSEEN 2 UIDVALIDITY 8 UIDNEXT 12)']],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 3 EXISTS']],
            ['regex' => '/^A\\d+ UID SEARCH UNSEEN$/', 'untagged' => ['* SEARCH 10 11']],
            ['regex' => '/^A\\d+ UID FETCH 10 \(RFC822\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $folders = $mailbox->folders();
    $status = $mailbox->status('INBOX');
    $messages = $mailbox->folder('INBOX')->query(MailboxSearch::new()->unseen());
    $parsed = $mailbox->folder('INBOX')->fetchParsed(10);

    $mailbox->transport()->logout();
    $transcript = $server->transcript();
    $server->stop();

    expect($folders)->toContain('INBOX');
    expect($status->messages)->toBe(3);
    expect($status->unseen)->toBe(2);
    expect($status->uidValidity)->toBe(8);
    expect($status->uidNext)->toBe(12);
    expect($messages)->toHaveCount(2);
    expect($parsed->subject)->toBe('Inbox message');
    expect($parsed->textBody)->toBe('Hello mailbox');
    expect($transcript['mismatches'])->toBe([]);
});

it('fails when starttls is required and server does not advertise capability', function (): void {
    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::StartTlsRequired,
        username: 'user',
        password: 'pass',
    ));

    expect(fn () => $transport->folders())->toThrow(MailboxConnectionException::class);

    $transport->logout();
    $server->stop();
});

it('attempts STARTTLS before LOGIN when server advertises capability', function (): void {
    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 STARTTLS UIDPLUS']],
            ['regex' => '/^A\\d+ STARTTLS$/', 'responses' => ['A0002 OK begin tls']],
        ],
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::StartTlsRequired,
        username: 'user',
        password: 'pass',
    ));

    expect(fn () => $transport->folders())->toThrow(MailboxConnectionException::class);

    $transport->logout();
    $transcript = $server->transcript();
    $server->stop();

    expect(array_any(
        $transcript['commands'],
        static fn (string $command): bool => str_contains($command, 'STARTTLS'),
    ))->toBeTrue();
    expect(array_any(
        $transcript['commands'],
        static fn (string $command): bool => str_contains($command, 'LOGIN "user" "pass"'),
    ))->toBeFalse();
});

it('supports fake mailbox operations and parsing workflow', function (): void {
    $fake = FakeMailbox::new();

    $rawA = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: A\r\n\r\nBody A";
    $rawB = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: B\r\n\r\nBody B";

    $transport = $fake->transport
        ->withMessage('INBOX', 1, $rawA)
        ->withMessage('INBOX', 2, $rawB, seen: true);

    $mailbox = new Mailbox($transport);

    $uids = $mailbox->folder('INBOX')->query(MailboxSearch::new()->unseen());
    $parsed = $mailbox->folder('INBOX')->fetchParsed(1);
    $summary = $mailbox->folder('INBOX')->fetchSummary(1);
    $headers = $mailbox->folder('INBOX')->fetchHeaders(1);
    $attachments = $mailbox->folder('INBOX')->fetchAttachments(1);
    $bodyStructure = $mailbox->folder('INBOX')->fetchBodyStructure(1);

    expect($uids)->toHaveCount(1);
    expect($uids[0]->uid)->toBe(1);
    expect($parsed->subject)->toBe('A');
    expect($summary->subject)->toBe('A');
    expect($headers['subject'][0] ?? null)->toBe('A');
    expect($attachments)->toBeArray();
    expect($bodyStructure)->toContain('CONTENT-TYPE');

    $mailbox->folder('INBOX')->copyMany([1], 'Archive');
    $mailbox->folder('INBOX')->moveMany([2], 'Archive');

    $mailbox->createFolder('Drafts');
    $mailbox->renameFolder('Drafts', 'Draft');
    $mailbox->subscribeFolder('Archive');
    $mailbox->unsubscribeFolder('Archive');
    $mailbox->noop();
    $mailbox->folder('INBOX')->addFlag(1, 'Flagged');
    $mailbox->folder('INBOX')->removeFlag(1, 'Flagged');
    $mailbox->archive('INBOX', 1, 'Archive');
    $events = [];
    $done = false;
    $mailbox->watch(
        'Archive',
        static function (string $event) use (&$events, &$done): void {
            $events[] = $event;
            $done = true;
        },
        timeoutSeconds: 1,
        shouldStop: static fn (): bool => $done,
    );

    $sorted = $mailbox->folder('Archive')->query(MailboxSearch::new()->all()->newestFirst());
    $dateSorted = $mailbox->folder('Archive')->query(MailboxSearch::new()->all()->sortByDateDesc());

    expect($mailbox->folder('Archive')->status()->messages)->toBe(2);
    expect($mailbox->folderExists('Draft'))->toBeTrue();
    expect($mailbox->folderExists('Drafts'))->toBeFalse();
    expect($mailbox->folderDetails())->toHaveCount(3);
    expect($sorted[0]->uid)->toBe(2);
    expect($dateSorted)->toHaveCount(2);
    expect($events)->not->toBeEmpty();
});

it('falls back to in-memory attachment resolver when MIME part number is missing', function (): void {
    $fake = FakeMailbox::new();
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: user@example.com',
        'Subject: Single attachment',
        'Content-Type: application/octet-stream; name="single.bin"',
        'Content-Disposition: attachment; filename="single.bin"',
        'Content-Transfer-Encoding: base64',
        '',
        base64_encode('single-content'),
        '',
    ]);

    $transport = $fake->transport->withMessage('INBOX', 10, $raw);
    $mailbox = new Mailbox($transport);

    $attachments = $mailbox->folder('INBOX')->fetchAttachments(10);

    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->filename)->toBe('single.bin');
    expect($attachments[0]->contents())->toBe('single-content');
});

it('rejects invalid mailbox uid values before issuing transport operations', function (): void {
    $fake = FakeMailbox::new();
    $transport = $fake->transport->withMessage('INBOX', 1, "Subject: A\r\n\r\nB");
    $mailbox = new Mailbox($transport);

    expect(fn () => $mailbox->folder('INBOX')->fetchRaw(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->folder('INBOX')->markSeen(-1))->toThrow(InvalidArgumentException::class);
});

it('validates folder names at mailbox and folder entry points', function (): void {
    $mailbox = FakeMailbox::new()->mailbox;

    expect(fn () => $mailbox->folder(''))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->status("INB\r\nOX"))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->watch("INB\0OX", static function (string $_event): void {}, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->createFolder(str_repeat('A', 300)))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->folder('INBOX')->copy(1, "Arc\r\nhive"))->toThrow(InvalidArgumentException::class);
    expect(fn () => $mailbox->archive('INBOX', 1, "Arc\0hive"))->toThrow(InvalidArgumentException::class);
});

it('fetches imap attachment content lazily via BODY.PEEK part fetch', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Attachment message',
        'Content-Type: multipart/mixed; boundary="b1"',
        '',
        '--b1',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Hello',
        '--b1',
        'Content-Type: application/octet-stream; name="a.txt"',
        'Content-Disposition: attachment; filename="a.txt"',
        'Content-Transfer-Encoding: base64',
        '',
        'QQ==',
        '--b1--',
        '',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 1 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(BODYSTRUCTURE\\)$/', 'untagged' => ['* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 5 1)("APPLICATION" "OCTET-STREAM" ("NAME" "a.txt") NIL NIL "BASE64" 4 NIL ("ATTACHMENT" ("FILENAME" "a.txt")) NIL) "MIXED"))']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(BODY\\.PEEK\\[2\\]\\)$/', 'literal' => 'QQ=='],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $attachments = $mailbox->folder('INBOX')->fetchAttachments(10);
    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->filename)->toBe('a.txt');
    expect($attachments[0]->contents())->toBe('A');

    $mailbox->transport()->logout();
    $transcript = $server->transcript();
    $server->stop();

    expect($transcript['mismatches'])->toBe([]);
    expect(array_any(
        $transcript['commands'],
        static fn (string $command): bool => preg_match('/UID FETCH 10 \(BODY\.PEEK\[2\]\)$/', $command) === 1,
    ))->toBeTrue();
});

it('maps nested multipart attachment part numbers to correct lazy BODY.PEEK fetches', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Nested lazy attachments',
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
        '<p>Html body</p>',
        '--alt--',
        '--rel',
        'Content-Type: image/png; name="logo.png"',
        'Content-Disposition: inline; filename="logo.png"',
        'Content-ID: <logo>',
        'Content-Transfer-Encoding: base64',
        '',
        'TE9HTw==',
        '--rel--',
        '--mix',
        'Content-Type: application/pdf; name="report.pdf"',
        'Content-Disposition: attachment; filename="report.pdf"',
        'Content-Transfer-Encoding: base64',
        '',
        'UERG',
        '--mix--',
        '',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 1 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 20 \\(BODYSTRUCTURE\\)$/', 'untagged' => ['* 1 FETCH (BODYSTRUCTURE ((("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 10 1)("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "7BIT" 16 1) "ALTERNATIVE")("IMAGE" "PNG" ("NAME" "logo.png") NIL "<logo>" "BASE64" 8 NIL ("INLINE" ("FILENAME" "logo.png")) NIL) "RELATED")("APPLICATION" "PDF" ("NAME" "report.pdf") NIL NIL "BASE64" 4 NIL ("ATTACHMENT" ("FILENAME" "report.pdf")) NIL) "MIXED"))']],
            ['regex' => '/^A\\d+ UID FETCH 20 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ UID FETCH 20 \\(BODY\\.PEEK\\[1\\.2\\]\\)$/', 'literal' => 'TE9HTw=='],
            ['regex' => '/^A\\d+ UID FETCH 20 \\(BODY\\.PEEK\\[2\\]\\)$/', 'literal' => 'UERG'],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $attachments = $mailbox->folder('INBOX')->fetchAttachments(20);
    expect($attachments)->toHaveCount(2);
    expect($attachments[0]->contents())->toBe('LOGO');
    expect($attachments[1]->contents())->toBe('PDF');

    $mailbox->transport()->logout();
    $transcript = $server->transcript();
    $server->stop();

    expect(array_any(
        $transcript['commands'],
        static fn (string $command): bool => preg_match('/BODY\.PEEK\[1\.2\]/', $command) === 1,
    ))->toBeTrue();
    expect(array_any(
        $transcript['commands'],
        static fn (string $command): bool => preg_match('/BODY\.PEEK\[2\]/', $command) === 1,
    ))->toBeTrue();
});

it('fetches summary over IMAP ENVELOPE command path', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Envelope summary',
        'Message-ID: <env@example.com>',
        '',
        'Hello summary',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 1 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(ENVELOPE BODY\\.PEEK\\[HEADER\\]\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $summary = $mailbox->folder('INBOX')->fetchSummary(10);
    $mailbox->transport()->logout();
    $transcript = $server->transcript();
    $server->stop();

    expect($summary->uid)->toBe(10);
    expect($summary->subject)->toBe('Envelope summary');
    expect($transcript['mismatches'])->toBe([]);
});

it('redacts IMAP LOGIN password in mailbox command events', function (): void {
    $events = [];
    Email::events(static function (string $event, array $payload) use (&$events): void {
        if (str_starts_with($event, 'mailbox.command.')) {
            $events[] = $payload;
        }
    });

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ LIST "" \*$/', 'untagged' => ['* LIST (\\HasNoChildren) "/" "INBOX"']],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $mailbox->folders();
    $mailbox->transport()->logout();
    $server->stop();
    Email::events(null);

    expect(array_any(
        $events,
        static fn (array $payload): bool => ($payload['command'] ?? null) === 'LOGIN "user" [REDACTED]',
    ))->toBeTrue();
    expect(array_any(
        $events,
        static fn (array $payload): bool => is_string($payload['command'] ?? null) && str_contains($payload['command'], 'LOGIN "user" "pass"'),
    ))->toBeFalse();
});

it('reuses selected imap folder between operations and clears state on logout', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Keep selected folder',
        '',
        'hello',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 2 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ UID FETCH 11 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $transport = new ImapSocketTransport(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));
    $mailbox = new Mailbox($transport);

    $mailbox->folder('INBOX')->fetchRaw(10);
    $mailbox->folder('INBOX')->fetchRaw(11);
    $mailbox->transport()->logout();

    $selectedFolder = new ReflectionProperty($transport, 'selectedFolder');
    expect($selectedFolder->getValue($transport))->toBeNull();

    $transcript = $server->transcript();
    $server->stop();

    expect($transcript['mismatches'])->toBe([]);
    expect(array_values(array_filter(
        $transcript['commands'],
        static fn (string $command): bool => preg_match('/SELECT "INBOX"$/', $command) === 1,
    )))->toHaveCount(1);
});

it('switches selected folder when mailbox operations target different folders', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Folder switching',
        '',
        'hello',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 1 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ SELECT "Archive"$/', 'untagged' => ['* 1 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 11 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $mailbox->folder('INBOX')->fetchRaw(10);
    $mailbox->folder('Archive')->fetchRaw(11);
    $mailbox->transport()->logout();

    $transcript = $server->transcript();
    $server->stop();

    expect($transcript['mismatches'])->toBe([]);
});

it('keeps selected folder state after failed command', function (): void {
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: alice@example.com',
        'Subject: Folder state after failure',
        '',
        'hello',
    ]);

    $server = FakeImapServerProcess::start([
        'expect' => [
            ['regex' => '/^A\\d+ CAPABILITY$/', 'untagged' => ['* CAPABILITY IMAP4rev1 UIDPLUS']],
            ['regex' => '/^A\\d+ LOGIN "user" "pass"$/'],
            ['regex' => '/^A\\d+ SELECT "INBOX"$/', 'untagged' => ['* 2 EXISTS']],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ UID FETCH 11 \\(RFC822\\)$/', 'status' => 'NO', 'text' => 'missing message'],
            ['regex' => '/^A\\d+ UID FETCH 10 \\(RFC822\\)$/', 'literal' => $raw],
            ['regex' => '/^A\\d+ LOGOUT$/', 'untagged' => ['* BYE Logging out']],
        ],
    ]);

    $mailbox = Mailbox::usingImap(new ImapConfig(
        host: '127.0.0.1',
        port: $server->port,
        security: ImapSecurity::None,
        username: 'user',
        password: 'pass',
    ));

    $mailbox->folder('INBOX')->fetchRaw(10);
    expect(fn () => $mailbox->folder('INBOX')->fetchRaw(11))->toThrow(MailboxProtocolException::class);
    $mailbox->folder('INBOX')->fetchRaw(10);
    $mailbox->transport()->logout();

    $transcript = $server->transcript();
    $server->stop();

    expect($transcript['mismatches'])->toBe([]);
    expect(array_values(array_filter(
        $transcript['commands'],
        static fn (string $command): bool => preg_match('/SELECT "INBOX"$/', $command) === 1,
    )))->toHaveCount(1);
});

it('enforces mailbox query cost controls for expensive searches', function (): void {
    $fake = FakeMailbox::new();
    $rawA = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: A\r\nDate: Tue, 07 Jan 2025 10:00:00 +0000\r\n\r\nBody A";
    $rawB = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: B\r\nDate: Tue, 08 Jan 2025 10:00:00 +0000\r\n\r\nBody B";
    $rawC = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: C\r\nDate: Tue, 09 Jan 2025 10:00:00 +0000\r\n\r\nBody C";

    $transport = $fake->transport
        ->withMessage('INBOX', 1, $rawA)
        ->withMessage('INBOX', 2, $rawB)
        ->withMessage('INBOX', 3, $rawC);

    $mailbox = new Mailbox($transport);

    expect(fn () => $mailbox->folder('INBOX')->query(
        MailboxSearch::new()->all()->sortByDateDesc()->maxSummaryFetches(2),
    ))->toThrow(MailboxProtocolException::class);

    expect(fn () => $mailbox->folder('INBOX')->query(
        MailboxSearch::new()->hasAttachment()->requireExplicitLimitForExpensiveSearch(),
    ))->toThrow(MailboxProtocolException::class);

    expect(fn () => $mailbox->folder('INBOX')->query(
        MailboxSearch::new()->all()->maxClientSideFilterFetches(2),
    ))->toThrow(MailboxProtocolException::class);
});

it('archives into default folder by creating it when missing', function (): void {
    $fake = FakeMailbox::new();
    $raw = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: A\r\n\r\nBody";
    $mailbox = new Mailbox($fake->transport->withMessage('INBOX', 1, $raw));

    $mailbox->archive('INBOX', 1);

    expect($mailbox->folderExists('Archive'))->toBeTrue();
    expect($mailbox->folder('INBOX')->status()->messages)->toBe(0);
    expect($mailbox->folder('Archive')->status()->messages)->toBe(1);
});

it('archives using provider strategy all_mail target', function (): void {
    $fake = FakeMailbox::new();
    $raw = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: A\r\n\r\nBody";
    $mailbox = new Mailbox($fake->transport->withMessage('INBOX', 1, $raw));

    $mailbox->archiveWithProviderStrategy('INBOX', 1, ['all_mail' => 'All Mail']);

    expect($mailbox->folderExists('All Mail'))->toBeTrue();
    expect($mailbox->folder('All Mail')->status()->messages)->toBe(1);
});

it('surfaces archive move failures', function (): void {
    $mailbox = FakeMailbox::new()->mailbox;

    expect(fn () => $mailbox->archive('INBOX', 999, 'Archive'))
        ->toThrow(MailboxProtocolException::class);
});

it('applies batch mailbox operations and stops on first failure', function (): void {
    $fake = FakeMailbox::new();
    $rawA = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: A\r\n\r\nBody A";
    $rawB = "From: sender@example.com\r\nTo: user@example.com\r\nSubject: B\r\n\r\nBody B";
    $mailbox = new Mailbox(
        $fake->transport
            ->withMessage('INBOX', 1, $rawA)
            ->withMessage('INBOX', 2, $rawB),
    );

    $folder = $mailbox->folder('INBOX');

    $folder->markSeenMany([1, 2]);
    expect($folder->query(MailboxSearch::new()->seen()))->toHaveCount(2);

    $folder->markUnreadMany([1, 2]);
    expect($folder->query(MailboxSearch::new()->unseen()))->toHaveCount(2);

    expect(fn () => $folder->markSeenMany([1, 999, 2]))->toThrow(MailboxProtocolException::class);
    $unseenAfterFailure = $mailbox->folder('INBOX')->query(MailboxSearch::new()->unseen());
    expect(array_any(
        $unseenAfterFailure,
        static fn (MailboxMessageRef $ref): bool => $ref->uid === 2,
    ))->toBeTrue();

    $folder->addFlagMany([1, 2], 'custom-flag');
    $folder->removeFlagMany([1, 2], 'custom-flag');
    $folder->copyMany([1], 'Archive');
    $folder->moveMany([2], 'Archive');
    expect($mailbox->folder('Archive')->status()->messages)->toBe(2);
});
