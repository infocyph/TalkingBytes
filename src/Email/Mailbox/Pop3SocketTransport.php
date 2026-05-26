<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Email\Config\Pop3Config;
use Infocyph\TalkingBytes\Email\Enum\Pop3Security;
use Infocyph\TalkingBytes\Email\Exception\MailboxAuthenticationException;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Infocyph\TalkingBytes\Email\Exception\MailboxProtocolException;

final class Pop3SocketTransport implements Pop3Transport
{
    /**
     * @var list<string>
     */
    private array $capabilities = [];

    /**
     * @var resource|null
     */
    private mixed $connection = null;

    public function __construct(private readonly Pop3Config $config) {}

    public function __destruct()
    {
        $this->logout();
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $this->connect();

        return $this->capabilities;
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
            'POP3',
            $this->config->security === Pop3Security::Ssl,
        );

        $greeting = $this->readLine();
        if (!$this->isOkResponse($greeting)) {
            throw new MailboxProtocolException(sprintf('Unexpected POP3 greeting: %s', trim($greeting)));
        }

        $this->refreshCapabilities();
        $this->negotiateStartTls();
        $this->login();
    }

    public function delete(int $messageNumber): void
    {
        Pop3MessageNumberGuard::assertValid($messageNumber);
        $response = $this->runSingleCommand(sprintf('DELE %d', $messageNumber));
        $this->expectOk($response, 'DELE');
    }

    /**
     * @return array<int, int>
     */
    public function list(): array
    {
        $status = $this->runSingleCommand('LIST');
        $this->expectOk($status, 'LIST');

        $result = [];
        foreach ($this->readMultilineResponse() as $line) {
            if (preg_match('/^(\d+)\s+(\d+)$/', $line, $matches) !== 1) {
                continue;
            }

            $messageNumber = (int) $matches[1];
            $size = (int) $matches[2];
            if ($messageNumber < 1) {
                continue;
            }

            $result[$messageNumber] = $size;
        }

        ksort($result);

        return $result;
    }

    public function logout(): void
    {
        if (!is_resource($this->connection)) {
            return;
        }

        try {
            $this->runSingleCommand('QUIT');
        } catch (\Throwable) {
            // Best effort for shutdown.
        }

        $this->closeConnection();
    }

    public function noop(): void
    {
        $response = $this->runSingleCommand('NOOP');
        $this->expectOk($response, 'NOOP');
    }

    public function rawMessage(int $messageNumber): string
    {
        Pop3MessageNumberGuard::assertValid($messageNumber);
        $status = $this->runSingleCommand(sprintf('RETR %d', $messageNumber));
        $this->expectOk($status, 'RETR');
        $lines = $this->readMultilineResponse();

        return implode("\r\n", $lines);
    }

    public function reset(): void
    {
        $response = $this->runSingleCommand('RSET');
        $this->expectOk($response, 'RSET');
    }

    /**
     * @return list<int>
     */
    public function search(MailboxSearch $search): array
    {
        $criteria = strtoupper($search->toCriteriaString());
        if ($criteria !== 'ALL') {
            throw new MailboxProtocolException('POP3 search only supports ALL criteria.');
        }
        $items = $this->list();

        $uids = [];
        foreach ($items as $messageNumber => $_size) {
            $uids[] = $messageNumber;
        }

        sort($uids);

        if ($search->limit !== null) {
            return array_slice($uids, 0, $search->limit);
        }

        return $uids;
    }

    public function status(): MailboxStatus
    {
        $response = $this->runSingleCommand('STAT');
        $this->expectOk($response, 'STAT');

        if (preg_match('/^\+OK\s+(\d+)\s+\d+/i', $response, $matches) !== 1) {
            throw new MailboxProtocolException(sprintf('Unexpected POP3 STAT response: %s', trim($response)));
        }

        $messages = (int) $matches[1];

        return new MailboxStatus($messages, 0, 0);
    }

    /**
     * @return array<int, string>
     */
    public function uidl(): array
    {
        if (!$this->hasCapability('UIDL')) {
            return [];
        }

        $status = $this->runSingleCommand('UIDL');
        $this->expectOk($status, 'UIDL');

        $result = [];
        foreach ($this->readMultilineResponse() as $line) {
            if (preg_match('/^(\d+)\s+(\S+)$/', $line, $matches) !== 1) {
                continue;
            }

            $messageNumber = (int) $matches[1];
            if ($messageNumber < 1) {
                continue;
            }

            $result[$messageNumber] = $matches[2];
        }

        ksort($result);

        return $result;
    }

    /**
     * @param callable(string):void $onEvent
     * @param null|callable():bool $shouldStop
     */
    public function watch(callable $onEvent, int $timeoutSeconds = 30, ?callable $shouldStop = null): void
    {
        $stop = $shouldStop ?? static fn(): bool => false;
        $deadline = time() + max(1, $timeoutSeconds);

        while (time() < $deadline && !$stop()) {
            $status = $this->status();
            $onEvent(sprintf('+OK %d messages', $status->messages));
            usleep(250000);
        }
    }

    private function closeConnection(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }

        $this->connection = null;
        $this->capabilities = [];
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $exceptionClass
     */
    private function expectOk(string $response, string $stage, string $exceptionClass = MailboxProtocolException::class): string
    {
        if ($this->isOkResponse($response)) {
            return $response;
        }

        throw new $exceptionClass(sprintf('POP3 %s failed: %s', $stage, trim($response)));
    }

    private function hasCapability(string $name): bool
    {
        return in_array(strtoupper($name), $this->capabilities, true);
    }

    private function isOkResponse(string $response): bool
    {
        return str_starts_with(strtoupper($response), '+OK');
    }

    private function login(): void
    {
        foreach ([
            'USER' => $this->config->username,
            'PASS' => $this->config->password,
        ] as $verb => $value) {
            $response = $this->runSingleCommand(sprintf('%s %s', $verb, $value));
            $this->expectOk($response, $verb, MailboxAuthenticationException::class);
        }
    }

    private function negotiateStartTls(): void
    {
        $mustUseTls = match ($this->config->security) {
            Pop3Security::StartTlsRequired => true,
            Pop3Security::StartTlsOptional => false,
            default => null,
        };
        if ($mustUseTls === null) {
            return;
        }

        if (!SocketMailboxRuntime::shouldStartTls($mustUseTls, $this->hasCapability('STLS'), 'pop3')) {
            return;
        }

        $response = $this->runSingleCommand('STLS');
        $this->expectOk($response, 'STLS', MailboxConnectionException::class);

        SocketMailboxRuntime::enableTls($this->requireConnection(), 'pop3');

        $this->refreshCapabilities();
    }

    private function readLine(): string
    {
        return SocketMailboxRuntime::readLine($this->requireConnection(), 'pop3');
    }

    /**
     * @return list<string>
     */
    private function readMultilineResponse(): array
    {
        $lines = [];

        while (true) {
            $line = $this->readLine();
            $trimmed = rtrim($line, "\r\n");

            if ($trimmed === '.') {
                break;
            }

            if (str_starts_with($trimmed, '..')) {
                $trimmed = substr($trimmed, 1);
            }

            $lines[] = $trimmed;
        }

        return $lines;
    }

    private function refreshCapabilities(): void
    {
        $this->write("CAPA\r\n");
        $status = $this->readLine();

        if (!$this->isOkResponse($status)) {
            $this->capabilities = [];

            return;
        }

        $capabilities = [];
        foreach ($this->readMultilineResponse() as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if ($parts === []) {
                continue;
            }

            $capabilities[] = strtoupper($parts[0]);
        }

        $this->capabilities = $capabilities;
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
            throw new MailboxConnectionException('POP3 connection is not available.');
        }

        return $this->connection;
    }

    private function runSingleCommand(string $command): string
    {
        $start = SocketMailboxRuntime::dispatchStart('pop3', $command, [
            'host' => $this->config->host,
            'port' => $this->config->port,
        ]);
        $this->write($command . "\r\n");
        $response = $this->readLine();
        SocketMailboxRuntime::dispatchFinish(
            'pop3',
            $start['command'],
            trim($response),
            $start['duration_ms'],
            ['host' => $this->config->host, 'port' => $this->config->port],
        );

        return $response;
    }

    private function write(string $value): void
    {
        SocketMailboxRuntime::write($this->requireConnection(), $value, 'pop3');
    }
}
