<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Core\Event\BestEffortEventDispatcher;
use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Event\NullEventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\Sleeper;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Exception\MailboxAuthenticationException;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;

final class ImapSocketTransport implements BodyStructureMailboxTransport, EnvelopeSummaryMailboxTransport, MailboxTransport, RawHeadersMailboxTransport, RawPartMailboxTransport, WatchableMailboxTransport
{
    private readonly Clock $clock;

    private readonly EventDispatcher $events;

    private readonly Sleeper $sleeper;

    /**
     * @var list<string>
     */
    private array $capabilities = [];

    /**
     * @var resource|null
     */
    private mixed $connection = null;

    private ?string $selectedFolder = null;

    private int $tagCounter = 1;

    public function __construct(
        private readonly ImapConfig $config,
        private readonly ImapResponseParser $responseParser = new ImapResponseParser(),
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
        ?Sleeper $sleeper = null,
        private readonly ?CancellationSignal $cancellation = null,
        private readonly ?OperationDeadline $operationDeadline = null,
        private readonly ?StreamWaiter $streamWaiter = null,
    ) {
        $this->events = new BestEffortEventDispatcher($events ?? new NullEventDispatcher());
        $this->clock = $clock ?? Clock::system();
        $this->sleeper = $sleeper ?? Sleeper::system();
    }

    public function __destruct()
    {
        $this->logout();
    }

    public function addFlag(string $folder, int $uid, string $flag): void
    {
        $normalizedFlag = $this->normalizeFlag($flag);
        $this->runUidFetch($folder, $uid, sprintf('UID STORE %%d +FLAGS (%s)', $normalizedFlag), 'UID STORE +FLAGS');
    }

    public function bodyStructure(string $folder, int $uid): string
    {
        $response = $this->runUidFetch($folder, $uid, 'UID FETCH %d (BODYSTRUCTURE)', 'UID FETCH BODYSTRUCTURE');

        return $this->responseParser->parseBodyStructure($response);
    }

    public function close(string $folder): void
    {
        $this->runFolderCommand($folder, 'CLOSE', 'CLOSE');
        $this->selectedFolder = null;
    }

    public function connect(): void
    {
        $this->assertExecutionAllowed();

        if (is_resource($this->connection)) {
            return;
        }
        $this->connection = SocketMailboxRuntime::connect(
            $this->config->host,
            $this->config->port,
            $this->config->timeoutSeconds,
            'IMAP',
            $this->config->security === ImapSecurity::Ssl,
            $this->streamWaiter,
        );
        $this->selectedFolder = null;

        try {
            $greeting = $this->readLine();
            if (!str_starts_with(strtoupper($greeting), '* OK')) {
                throw new MailboxProtocolException(sprintf('Unexpected IMAP greeting: %s', trim($greeting)));
            }

            $this->refreshCapabilities();
            $this->negotiateStartTls();
            $this->login();
        } catch (\Throwable $exception) {
            $this->closeConnection();

            throw $exception;
        }
    }

    public function copy(string $folder, int $uid, string $targetFolder): void
    {
        MailboxFolderNameGuard::assertValid($targetFolder);
        $this->runUidFetch($folder, $uid, sprintf('UID COPY %%d %s', ImapStringEscaper::quote($targetFolder)), 'UID COPY');
    }

    public function createFolder(string $folder): void
    {
        $this->runUnselectedFolderCommand($folder, 'CREATE', 'CREATE');
    }

    public function delete(string $folder, int $uid): void
    {
        $this->runUidFetch($folder, $uid, 'UID STORE %d +FLAGS (\\Deleted)', 'UID STORE +FLAGS \\Deleted');
    }

    public function deleteFolder(string $folder): void
    {
        $this->runUnselectedFolderCommand($folder, 'DELETE', 'DELETE');
    }

    public function envelopeSummary(string $folder, int $uid): MailboxMessageRef
    {
        $response = $this->runUidFetch($folder, $uid, 'UID FETCH %d (ENVELOPE BODY.PEEK[HEADER])', 'UID FETCH ENVELOPE');

        return $this->responseParser->parseEnvelopeSummary($response, $uid);
    }

    public function expunge(string $folder): void
    {
        $this->runFolderCommand($folder, 'EXPUNGE', 'EXPUNGE');
    }

    /**
     * @return list<MailboxFolderInfo>
     */
    public function folderDetails(): array
    {
        $response = $this->runCommand('LIST "" *');

        return $this->responseParser->folderDetails($response);
    }

    public function folderExists(string $folder): bool
    {
        return array_any(
            $this->folderDetails(),
            static fn(MailboxFolderInfo $details): bool => strcasecmp($details->path, $folder) === 0,
        );
    }

    /**
     * @return list<string>
     */
    public function folders(): array
    {
        $response = $this->runCommand('LIST "" *');

        return $this->responseParser->folders($response);
    }

    public function logout(): void
    {
        if (!is_resource($this->connection)) {
            return;
        }

        try {
            $this->runCommand('LOGOUT');
        } catch (\Throwable) {
            // Best effort for shutdown.
        }

        $this->closeConnection();
    }

    public function markSeen(string $folder, int $uid): void
    {
        $this->runUidFetch($folder, $uid, 'UID STORE %d +FLAGS (\\Seen)', 'UID STORE +FLAGS \\Seen');
    }

    public function markUnread(string $folder, int $uid): void
    {
        $this->runUidFetch($folder, $uid, 'UID STORE %d -FLAGS (\\Seen)', 'UID STORE -FLAGS \\Seen');
    }

    public function move(string $folder, int $uid, string $targetFolder): void
    {
        MailboxFolderNameGuard::assertValid($folder);
        MailboxFolderNameGuard::assertValid($targetFolder);
        MailboxUidGuard::assertValid($uid);
        $this->selectFolder($folder);

        if ($this->hasCapability('MOVE')) {
            $response = $this->runCommand(sprintf('UID MOVE %d %s', $uid, ImapStringEscaper::quote($targetFolder)));
            $this->expectOk($response, 'UID MOVE');

            return;
        }

        if (!$this->hasCapability('UIDPLUS')) {
            throw new MailboxProtocolException(
                'Safe IMAP move fallback requires MOVE or UIDPLUS capability.',
            );
        }

        $this->copy($folder, $uid, $targetFolder);
        $this->delete($folder, $uid);
        $this->expectOk(
            $this->runCommand(sprintf('UID EXPUNGE %d', $uid)),
            'UID EXPUNGE',
        );
    }

    public function noop(): void
    {
        $response = $this->runCommand('NOOP');
        $this->expectOk($response, 'NOOP');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rawHeaders(string $folder, int $uid): array
    {
        $response = $this->runUidFetch($folder, $uid, 'UID FETCH %d (BODY.PEEK[HEADER])', 'UID FETCH BODY.PEEK[HEADER]');

        return $this->responseParser->fetchHeaderMap($response);
    }

    public function rawMessage(string $folder, int $uid): string
    {
        $response = $this->runUidFetch($folder, $uid, 'UID FETCH %d (RFC822)', 'UID FETCH RFC822');
        $raw = $this->responseParser->fetchRawMessage($response);

        if ($raw === '') {
            throw new MailboxProtocolException(sprintf('IMAP server returned empty message body for UID %d.', $uid));
        }

        return $raw;
    }

    public function rawPart(string $folder, int $uid, string $partNumber): string
    {
        MailboxFolderNameGuard::assertValid($folder);
        MailboxUidGuard::assertValid($uid);
        ImapPartNumberGuard::assertValid($partNumber);
        $response = $this->runUidFetch(
            $folder,
            $uid,
            sprintf('UID FETCH %%d (BODY.PEEK[%s])', $partNumber),
            sprintf('UID FETCH BODY.PEEK[%s]', $partNumber),
        );
        $raw = $this->responseParser->fetchSectionLiteral($response, sprintf('BODY.PEEK[%s]', $partNumber));
        if ($raw === '') {
            $raw = $this->responseParser->fetchSectionLiteral($response, sprintf('BODY[%s]', $partNumber));
        }
        if ($raw === '') {
            $raw = $this->responseParser->fetchRawMessage($response);
        }

        if ($raw === '') {
            throw new MailboxProtocolException(sprintf('IMAP server returned empty body for UID %d part %s.', $uid, $partNumber));
        }

        return $raw;
    }

    public function removeFlag(string $folder, int $uid, string $flag): void
    {
        $normalizedFlag = $this->normalizeFlag($flag);
        $this->runUidFetch($folder, $uid, sprintf('UID STORE %%d -FLAGS (%s)', $normalizedFlag), 'UID STORE -FLAGS');
    }

    public function renameFolder(string $folder, string $targetFolder): void
    {
        MailboxFolderNameGuard::assertValid($folder);
        MailboxFolderNameGuard::assertValid($targetFolder);
        $this->expectOk($this->runCommand(sprintf(
            'RENAME %s %s',
            ImapStringEscaper::quote($folder),
            ImapStringEscaper::quote($targetFolder),
        )), 'RENAME');
    }

    /**
     * @return list<int>
     */
    public function search(string $folder, MailboxSearch $search): array
    {
        MailboxFolderNameGuard::assertValid($folder);
        $this->selectFolder($folder);

        $uids = [];
        if ($this->hasCapability('SORT') && in_array($search->sortOrder, ['date_asc', 'date_desc'], true)) {
            $sortKey = $search->sortOrder === 'date_desc' ? '(REVERSE DATE)' : '(DATE)';
            $sortResponse = $this->expectOk(
                $this->runCommand(sprintf('UID SORT %s UTF-8 %s', $sortKey, $search->toCriteriaString())),
                'UID SORT',
            );
            $uids = $this->responseParser->sortUids($sortResponse);
        }

        if ($uids === []) {
            $response = $this->expectOk(
                $this->runCommand('UID SEARCH ' . $search->toCriteriaString()),
                'UID SEARCH',
            );
            $uids = $this->responseParser->searchUids($response);
        }

        if ($search->limit !== null) {
            return array_slice($uids, 0, $search->limit);
        }

        return $uids;
    }

    public function status(string $folder): MailboxStatus
    {
        $response = $this->runFolderCommand(
            $folder,
            sprintf('STATUS %s (MESSAGES RECENT UNSEEN UIDVALIDITY UIDNEXT)', ImapStringEscaper::quote($folder)),
            'STATUS',
            select: false,
        );

        return $this->responseParser->status($response);
    }

    public function subscribeFolder(string $folder): void
    {
        $this->runUnselectedFolderCommand($folder, 'SUBSCRIBE', 'SUBSCRIBE');
    }

    public function unsubscribeFolder(string $folder): void
    {
        $this->runUnselectedFolderCommand($folder, 'UNSUBSCRIBE', 'UNSUBSCRIBE');
    }

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(string $folder, callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void
    {
        $this->assertExecutionAllowed();
        $this->selectFolder($folder);
        $callerStop = $shouldStop ?? static fn(): bool => false;
        $stop = fn(): bool => $callerStop() || $this->executionStopRequested();

        if (!$this->hasCapability('IDLE')) {
            $this->watchWithNoopFallback($onEvent, $timeoutSeconds, $stop);

            return;
        }

        $this->watchWithIdle($onEvent, $timeoutSeconds, $stop);
    }

    /**
     * @param resource $connection
     */
    private function applyReadDeadline(mixed $connection, OperationDeadline $deadline): void
    {
        $remainingMicros = $deadline->remainingMicroseconds();
        if ($remainingMicros === 0) {
            throw new MailboxConnectionException('IMAP command deadline exceeded.');
        }

        $seconds = intdiv($remainingMicros, 1_000_000);
        $microseconds = $remainingMicros % 1_000_000;
        stream_set_timeout($connection, $seconds, $microseconds);
    }

    private function assertExecutionAllowed(): void
    {
        if ($this->cancellation?->isRequested() === true) {
            throw new MailboxConnectionException('IMAP operation cancelled.');
        }

        if ($this->operationDeadline?->expired() === true) {
            throw new MailboxConnectionException('IMAP operation deadline exceeded.');
        }
    }

    private function authenticateStatus(ImapResponse $response): void
    {
        if ($response->isOk()) {
            return;
        }

        throw new MailboxAuthenticationException(implode("\n", $response->lines));
    }

    private function closeConnection(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }

        $this->connection = null;
        $this->capabilities = [];
        $this->selectedFolder = null;
    }

    private function commandDeadline(): OperationDeadline
    {
        $deadline = OperationDeadline::after((float) $this->config->timeoutSeconds, $this->clock);

        return $this->operationDeadline?->earliest($deadline) ?? $deadline;
    }

    /** @phpstan-impure */
    private function executionStopRequested(): bool
    {
        return $this->cancellation?->isRequested() === true
            || $this->operationDeadline?->expired() === true;
    }

    private function expectOk(ImapResponse $response, string $stage): ImapResponse
    {
        if ($response->isOk()) {
            return $response;
        }

        throw new MailboxProtocolException(sprintf(
            'IMAP %s failed: %s',
            $stage,
            implode(' | ', $response->lines),
        ));
    }

    private function hasCapability(string $capability): bool
    {
        return in_array(strtoupper($capability), $this->capabilities, true);
    }

    private function login(): void
    {
        $response = $this->runCommand(sprintf(
            'LOGIN %s %s',
            ImapStringEscaper::quote($this->config->username),
            ImapStringEscaper::quote($this->config->password),
        ));

        $this->authenticateStatus($response);
    }

    private function negotiateStartTls(): void
    {
        if (!in_array($this->config->security, [ImapSecurity::StartTlsOptional, ImapSecurity::StartTlsRequired], true)) {
            return;
        }

        $mustStartTls = true;
        if (!SocketMailboxRuntime::shouldStartTls($mustStartTls, $this->hasCapability('STARTTLS'), 'imap')) {
            return;
        }

        $response = $this->runCommand('STARTTLS');
        if (!$response->isOk()) {
            throw new MailboxConnectionException('IMAP STARTTLS negotiation command was rejected.');
        }

        SocketMailboxRuntime::enableTls(
            $this->requireConnection(),
            'imap',
            $this->streamWaiter,
            $this->commandDeadline(),
        );

        $this->refreshCapabilities();
    }

    private function nextTag(): string
    {
        $tag = sprintf('A%04d', $this->tagCounter);
        $this->tagCounter++;

        return $tag;
    }

    private function normalizeFlag(string $flag): string
    {
        $trimmed = trim($flag);
        MailboxFlagGuard::assertValid($trimmed);

        return $trimmed;
    }

    private function parseLiteralSize(string $line): ?int
    {
        if (preg_match('/\{(\d+)\}\s*$/', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function readExact(int $bytes, ?OperationDeadline $deadline = null): string
    {
        if ($bytes < 0 || $bytes > $this->config->maxLiteralBytes) {
            throw new MailboxProtocolException(sprintf(
                'IMAP literal exceeds configured limit (%d bytes).',
                $this->config->maxLiteralBytes,
            ));
        }

        $buffer = '';
        $connection = $this->requireConnection();

        while (strlen($buffer) < $bytes) {
            $this->assertExecutionAllowed();

            if ($deadline !== null) {
                $this->applyReadDeadline($connection, $deadline);
            }

            if ($this->streamWaiter !== null && !$this->streamWaiter->waitReadable($connection, $deadline)) {
                $this->assertExecutionAllowed();
                if ($deadline?->expired() === true) {
                    throw new MailboxConnectionException('IMAP command deadline exceeded.');
                }

                throw new MailboxConnectionException('IMAP cooperative read wait was interrupted.');
            }

            $remaining = max(1, $bytes - strlen($buffer));
            $chunk = fread($connection, $remaining);
            $this->assertExecutionAllowed();
            if ($deadline?->expired() === true) {
                throw new MailboxConnectionException('IMAP command deadline exceeded.');
            }
            if ($chunk === false || $chunk === '') {
                /** @var array<string, mixed> $meta */
                $meta = stream_get_meta_data($connection);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new MailboxConnectionException('IMAP literal read timed out.');
                }
                if ($this->streamWaiter !== null && !feof($connection)) {
                    continue;
                }

                throw new MailboxProtocolException(sprintf(
                    'Unexpected end of stream while reading IMAP literal (expected %d bytes, received %d bytes).',
                    $bytes,
                    strlen($buffer),
                ));
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function readLine(): string
    {
        return $this->readLineUntil($this->commandDeadline());
    }

    private function readLineUntil(OperationDeadline $deadline): string
    {
        $this->assertExecutionAllowed();
        $connection = $this->requireConnection();
        $this->applyReadDeadline($connection, $deadline);
        $line = SocketMailboxRuntime::readLine(
            $connection,
            'imap',
            streamWaiter: $this->streamWaiter,
            cancellation: $this->cancellation,
            deadline: $deadline,
        );
        $this->assertExecutionAllowed();
        if ($deadline->expired()) {
            throw new MailboxConnectionException('IMAP command deadline exceeded.');
        }

        return $line;
    }

    private function readTaggedResponse(string $tag): ImapResponse
    {
        $lines = [];
        $literals = [];
        $status = 'NO';
        $totalBytes = 0;
        $deadline = $this->commandDeadline();

        try {
            while (true) {
                $line = $this->readLineUntil($deadline);
                $trimmed = rtrim($line, "\r\n");
                $lines[] = $trimmed;
                $totalBytes += strlen($line);

                if (count($lines) > $this->config->maxResponseLines || $totalBytes > $this->config->maxResponseBytes) {
                    throw new MailboxProtocolException('IMAP response exceeds configured bounds.');
                }

                $literalSize = $this->parseLiteralSize($trimmed);
                if ($literalSize !== null) {
                    $literal = $this->readExact($literalSize, $deadline);
                    $totalBytes += strlen($literal);
                    if ($totalBytes > $this->config->maxResponseBytes) {
                        throw new MailboxProtocolException('IMAP response exceeds configured byte limit.');
                    }

                    $literals[] = $literal;
                }

                if (preg_match('/^' . preg_quote($tag, '/') . '\\s+(OK|NO|BAD)\\b/i', $trimmed, $matches) === 1) {
                    $status = strtoupper($matches[1]);

                    break;
                }
            }

            return new ImapResponse($tag, $status, $lines, $literals);
        } finally {
            $this->restoreReadTimeout();
        }
    }

    private function refreshCapabilities(): void
    {
        $response = $this->runCommand('CAPABILITY');
        $this->capabilities = $this->responseParser->capabilities($response);
    }

    /**
     * @return resource
     */
    private function requireConnection()
    {
        if (!is_resource($this->connection)) {
            $this->connect();
        }

        if (!is_resource($this->connection)) {
            throw new MailboxConnectionException('IMAP connection is not available.');
        }

        return $this->connection;
    }

    private function restoreReadTimeout(): void
    {
        if (is_resource($this->connection)) {
            stream_set_timeout($this->connection, $this->config->timeoutSeconds);
        }
    }

    private function runCommand(string $command): ImapResponse
    {
        $this->assertExecutionAllowed();
        $this->requireConnection();
        $start = SocketMailboxRuntime::dispatchStart('imap', $command, [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ], $this->events, $this->clock);

        $imapCommand = new ImapCommand($this->nextTag(), $command);
        $this->write($imapCommand->line() . "\r\n");

        $response = $this->readTaggedResponse($imapCommand->tag);
        SocketMailboxRuntime::dispatchFinish(
            'imap',
            $start['command'],
            $response->status,
            $start['duration_ms'],
            ['host' => $this->config->host, 'port' => $this->config->port],
            $this->events,
            $this->clock,
        );

        return $response;
    }

    private function runFolderCommand(
        string $folder,
        string $command,
        string $stage,
        bool $select = true,
    ): ImapResponse {
        MailboxFolderNameGuard::assertValid($folder);
        if ($select) {
            $this->selectFolder($folder);
        }

        return $this->expectOk($this->runCommand($command), $stage);
    }

    private function runUidFetch(string $folder, int $uid, string $commandPattern, string $stage): ImapResponse
    {
        MailboxUidGuard::assertValid($uid);

        return $this->runFolderCommand($folder, sprintf($commandPattern, $uid), $stage);
    }

    private function runUnselectedFolderCommand(string $folder, string $verb, string $stage): ImapResponse
    {
        return $this->runFolderCommand(
            $folder,
            sprintf('%s %s', $verb, ImapStringEscaper::quote($folder)),
            $stage,
            select: false,
        );
    }

    private function selectFolder(string $folder): void
    {
        if ($this->selectedFolder !== null && strcasecmp($this->selectedFolder, $folder) === 0) {
            return;
        }

        $response = $this->runCommand('SELECT ' . ImapStringEscaper::quote($folder));

        if (!$response->isOk()) {
            throw new MailboxProtocolException(sprintf('Unable to select IMAP folder "%s".', $folder));
        }

        $this->selectedFolder = $folder;
    }

    /**
     * @param callable(string):void $onEvent
     * @param callable():bool $stop
     */
    private function watchWithIdle(callable $onEvent, int $timeoutSeconds, callable $stop): void
    {
        $tag = $this->nextTag();
        $this->write($tag . " IDLE\r\n");
        $continuation = $this->readLine();
        if (!str_starts_with($continuation, '+')) {
            throw new MailboxProtocolException(sprintf('IMAP IDLE was not accepted: %s', trim($continuation)));
        }

        $deadline = $this->clock->monotonic() + max(1, $timeoutSeconds);
        $socket = $this->requireConnection();

        while ($this->clock->monotonic() < $deadline) {
            if ($stop()) {
                break;
            }

            $read = [$socket];
            $write = [];
            $except = [];
            $microseconds = $this->streamWaiter === null ? 250000 : 0;
            $ready = stream_select($read, $write, $except, 0, $microseconds);
            if ($ready === false) {
                throw new MailboxConnectionException('IMAP IDLE stream_select failed.');
            }

            if ($ready < 1) {
                if ($this->streamWaiter !== null) {
                    $this->sleeper->milliseconds(10);
                }

                continue;
            }

            $line = rtrim($this->readLine(), "\r\n");
            if (str_starts_with($line, '* ')) {
                $onEvent($line);
            }
        }

        if ($this->executionStopRequested()) {
            $this->closeConnection();

            return;
        }

        $this->write("DONE\r\n");
        $doneResponse = $this->readTaggedResponse($tag);
        if (!$doneResponse->isOk()) {
            throw new MailboxProtocolException('IMAP IDLE did not terminate cleanly.');
        }
    }

    /**
     * @param callable(string):void $onEvent
     * @param callable():bool $stop
     */
    private function watchWithNoopFallback(callable $onEvent, int $timeoutSeconds, callable $stop): void
    {
        $deadline = $this->clock->monotonic() + max(1, $timeoutSeconds);

        while ($this->clock->monotonic() < $deadline) {
            if ($stop()) {
                return;
            }

            $response = $this->runCommand('NOOP');
            foreach ($response->lines as $line) {
                if (str_starts_with($line, '* ')) {
                    $onEvent($line);
                }
            }

            $this->sleeper->milliseconds(250);
        }
    }

    private function write(string $value): void
    {
        $this->assertExecutionAllowed();
        $deadline = $this->commandDeadline();
        SocketMailboxRuntime::write(
            $this->requireConnection(),
            $value,
            'imap',
            $this->streamWaiter,
            $this->cancellation,
            $deadline,
        );
        $this->assertExecutionAllowed();
        if ($deadline->expired()) {
            throw new MailboxConnectionException('IMAP command deadline exceeded.');
        }
    }
}
