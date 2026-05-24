<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Config\ImapConfig;
use Infocyph\TalkingBytes\Email\Enum\ImapSecurity;
use Infocyph\TalkingBytes\Email\Exception\MailboxAuthenticationException;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;

final class ImapSocketTransport implements BodyStructureMailboxTransport, EnvelopeSummaryMailboxTransport, MailboxTransport, RawHeadersMailboxTransport, RawPartMailboxTransport, WatchableMailboxTransport
{
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
    ) {}

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
        if (is_resource($this->connection)) {
            return;
        }
        $this->connection = SocketMailboxRuntime::connect(
            $this->config->host,
            $this->config->port,
            $this->config->timeoutSeconds,
            'IMAP',
            $this->config->security === ImapSecurity::Ssl,
        );
        $this->selectedFolder = null;

        $greeting = $this->readLine();
        if (!str_starts_with(strtoupper($greeting), '* OK')) {
            throw new MailboxProtocolException(sprintf('Unexpected IMAP greeting: %s', trim($greeting)));
        }

        $this->refreshCapabilities();
        $this->negotiateStartTls();
        $this->login();
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

        fclose($this->connection);
        $this->connection = null;
        $this->capabilities = [];
        $this->selectedFolder = null;
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

        $this->copy($folder, $uid, $targetFolder);
        $this->delete($folder, $uid);
        $this->expunge($folder);
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
        $this->selectFolder($folder);
        $stop = $shouldStop ?? static fn(): bool => false;

        if (!$this->hasCapability('IDLE')) {
            $this->watchWithNoopFallback($onEvent, $timeoutSeconds, $stop);

            return;
        }

        $this->watchWithIdle($onEvent, $timeoutSeconds, $stop);
    }

    private function authenticateStatus(ImapResponse $response): void
    {
        if ($response->isOk()) {
            return;
        }

        throw new MailboxAuthenticationException(implode("\n", $response->lines));
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

        $mustStartTls = $this->config->security === ImapSecurity::StartTlsRequired;
        if (!SocketMailboxRuntime::shouldStartTls($mustStartTls, $this->hasCapability('STARTTLS'), 'imap')) {
            return;
        }

        $response = $this->runCommand('STARTTLS');
        if (!$response->isOk()) {
            throw new MailboxConnectionException('IMAP STARTTLS negotiation command was rejected.');
        }

        SocketMailboxRuntime::enableTls($this->requireConnection(), 'imap');

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

    private function readExact(int $bytes): string
    {
        $buffer = '';
        $connection = $this->requireConnection();

        while (strlen($buffer) < $bytes) {
            $remaining = max(1, $bytes - strlen($buffer));
            $chunk = fread($connection, $remaining);
            if ($chunk === false || $chunk === '') {
                /** @var array<string, mixed> $meta */
                $meta = stream_get_meta_data($connection);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new MailboxConnectionException('IMAP literal read timed out.');
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
        return SocketMailboxRuntime::readLine($this->requireConnection(), 'imap');
    }

    private function readTaggedResponse(string $tag): ImapResponse
    {
        $lines = [];
        $literals = [];
        $status = 'NO';

        while (true) {
            $line = $this->readLine();
            $trimmed = rtrim($line, "\r\n");
            $lines[] = $trimmed;

            $literalSize = $this->parseLiteralSize($trimmed);
            if ($literalSize !== null) {
                $literals[] = $this->readExact($literalSize);
            }

            if (preg_match('/^' . preg_quote($tag, '/') . '\s+(OK|NO|BAD)\b/i', $trimmed, $matches) === 1) {
                $status = strtoupper($matches[1]);

                break;
            }
        }

        return new ImapResponse($tag, $status, $lines, $literals);
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

    private function runCommand(string $command): ImapResponse
    {
        $this->requireConnection();
        $start = SocketMailboxRuntime::dispatchStart('imap', $command, [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ]);

        $imapCommand = new ImapCommand($this->nextTag(), $command);
        $this->write($imapCommand->line() . "\r\n");

        $response = $this->readTaggedResponse($imapCommand->tag);
        SocketMailboxRuntime::dispatchFinish(
            'imap',
            $start['command'],
            $response->status,
            $start['duration_ms'],
            ['host' => $this->config->host, 'port' => $this->config->port],
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

        $deadline = time() + max(1, $timeoutSeconds);
        $socket = $this->requireConnection();

        while (time() < $deadline) {
            if ($stop()) {
                break;
            }

            $read = [$socket];
            $write = [];
            $except = [];
            $ready = stream_select($read, $write, $except, 0, 250000);
            if ($ready === false) {
                throw new MailboxConnectionException('IMAP IDLE stream_select failed.');
            }

            if ($ready < 1) {
                continue;
            }

            $line = rtrim($this->readLine(), "\r\n");
            if (str_starts_with($line, '* ')) {
                $onEvent($line);
            }
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
        $deadline = time() + max(1, $timeoutSeconds);

        while (time() < $deadline) {
            if ($stop()) {
                return;
            }

            $response = $this->runCommand('NOOP');
            foreach ($response->lines as $line) {
                if (str_starts_with($line, '* ')) {
                    $onEvent($line);
                }
            }

            usleep(250000);
        }
    }

    private function write(string $value): void
    {
        SocketMailboxRuntime::write($this->requireConnection(), $value, 'imap');
    }
}
