<?php

declare(strict_types=1);

namespace Infocyph\TalkingBytes\Email\Mailbox;

use Infocyph\TalkingBytes\Core\Event\EventDispatcher;
use Infocyph\TalkingBytes\Core\Support\CancellationSignal;
use Infocyph\TalkingBytes\Core\Support\Clock;
use Infocyph\TalkingBytes\Core\Support\OperationDeadline;
use Infocyph\TalkingBytes\Core\Support\StreamWaiter;
use Infocyph\TalkingBytes\Email\Exception\MailboxConnectionException;
use Throwable;

final class SocketMailboxRuntime
{
    /**
     * @return resource
     */
    public static function connect(
        string $host,
        int $port,
        int $timeoutSeconds,
        string $protocolLabel,
        bool $ssl,
        ?StreamWaiter $streamWaiter = null,
    ): mixed {
        $targetHost = sprintf('%s://%s:%d', $ssl ? 'ssl' : 'tcp', $host, $port);
        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $connection = stream_socket_client(
            $targetHost,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($connection)) {
            throw new MailboxConnectionException(sprintf(
                'Unable to connect to %s server: %s (%d)',
                $protocolLabel,
                $errstr,
                $errno,
            ));
        }

        stream_set_timeout($connection, $timeoutSeconds);
        if ($streamWaiter !== null && !stream_set_blocking($connection, false)) {
            fclose($connection);

            throw new MailboxConnectionException(sprintf(
                'Unable to configure %s socket for cooperative I/O.',
                $protocolLabel,
            ));
        }

        return $connection;
    }

    /**
     * @param array{host:string,port:int} $endpoint
     */
    public static function dispatchFinish(
        string $protocol,
        string $command,
        string $status,
        int $startedAtMs,
        array $endpoint,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): void {
        $runtimeClock = $clock ?? Clock::system();
        self::dispatch($events, 'mailbox.command.finish', [
            'protocol' => $protocol,
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'command' => $command,
            'status' => $status,
            'duration_ms' => (int) round(($runtimeClock->monotonic() * 1000) - $startedAtMs),
        ]);
    }

    /**
     * @param array{host:string,port:int} $endpoint
     * @return array{command:string,duration_ms:int}
     */
    public static function dispatchStart(
        string $protocol,
        string $command,
        array $endpoint,
        ?EventDispatcher $events = null,
        ?Clock $clock = null,
    ): array {
        $redactedCommand = MailboxCommandRedactor::redact($protocol, $command);
        $runtimeClock = $clock ?? Clock::system();
        self::dispatch($events, 'mailbox.command.start', [
            'protocol' => $protocol,
            'host' => $endpoint['host'],
            'port' => $endpoint['port'],
            'command' => $redactedCommand,
        ]);

        return [
            'command' => $redactedCommand,
            'duration_ms' => (int) round($runtimeClock->monotonic() * 1000),
        ];
    }

    /**
     * @param resource $connection
     */
    public static function enableTls(
        mixed $connection,
        string $protocol,
        ?StreamWaiter $streamWaiter = null,
        ?OperationDeadline $deadline = null,
    ): void {
        if ($deadline?->expired() === true) {
            throw new MailboxConnectionException(sprintf(
                '%s TLS negotiation deadline exceeded.',
                strtoupper($protocol),
            ));
        }

        if ($deadline !== null) {
            $remainingMicros = $deadline->remainingMicroseconds();
            stream_set_timeout(
                $connection,
                intdiv($remainingMicros, 1_000_000),
                $remainingMicros % 1_000_000,
            );
        }

        if ($streamWaiter !== null && !stream_set_blocking($connection, true)) {
            throw new MailboxConnectionException(sprintf(
                'Unable to enter blocking mode for %s TLS negotiation.',
                strtoupper($protocol),
            ));
        }

        set_error_handler(
            static fn(): bool => true,
            E_WARNING,
        );

        try {
            $enabled = stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();

            if ($streamWaiter !== null && !stream_set_blocking($connection, false)) {
                throw new MailboxConnectionException(sprintf(
                    'Unable to restore cooperative %s socket mode after TLS negotiation.',
                    strtoupper($protocol),
                ));
            }
        }

        if ($enabled !== true) {
            throw new MailboxConnectionException(sprintf('Unable to enable TLS on %s socket.', strtoupper($protocol)));
        }
        if ($deadline?->expired() === true) {
            throw new MailboxConnectionException(sprintf(
                '%s TLS negotiation deadline exceeded.',
                strtoupper($protocol),
            ));
        }
    }

    /**
     * @param resource $connection
     * @param positive-int $maxLength
     */
    public static function readLine(
        mixed $connection,
        string $protocol,
        int $maxLength = 8192,
        ?StreamWaiter $streamWaiter = null,
        ?CancellationSignal $cancellation = null,
        ?OperationDeadline $deadline = null,
    ): string {
        if ($streamWaiter === null) {
            $line = fgets($connection, max(1, $maxLength));
            if ($line === false) {
                /** @var array<string, mixed> $meta */
                $meta = stream_get_meta_data($connection);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new MailboxConnectionException(sprintf('%s server response timed out.', strtoupper($protocol)));
                }

                throw new MailboxConnectionException(sprintf('Failed to read from %s socket.', strtoupper($protocol)));
            }
            if (!str_ends_with($line, "\n") && !feof($connection)) {
                throw new MailboxConnectionException(sprintf(
                    '%s response line exceeds %d bytes.',
                    strtoupper($protocol),
                    $maxLength - 1,
                ));
            }

            return $line;
        }

        $line = '';
        $maxBytes = max(1, $maxLength - 1);

        while (strlen($line) < $maxBytes) {
            if (!$streamWaiter->waitReadable($connection, $deadline)) {
                throw self::readinessFailure($protocol, 'read', $cancellation, $deadline);
            }

            $remaining = max(2, $maxLength - strlen($line));
            $chunk = fgets($connection, $remaining);
            if ($chunk === false) {
                if (feof($connection)) {
                    throw new MailboxConnectionException(sprintf(
                        'Failed to read from %s socket.',
                        strtoupper($protocol),
                    ));
                }

                continue;
            }

            $line .= $chunk;
            if (str_ends_with($line, "\n") || feof($connection)) {
                return $line;
            }
        }

        throw new MailboxConnectionException(sprintf(
            '%s response line exceeds %d bytes.',
            strtoupper($protocol),
            $maxBytes,
        ));
    }

    public static function shouldStartTls(bool $required, bool $supported, string $protocol): bool
    {
        if ($supported) {
            return true;
        }

        if ($required) {
            throw new MailboxConnectionException(sprintf(
                '%s STARTTLS is required but not supported by server.',
                strtoupper($protocol),
            ));
        }

        return false;
    }

    /**
     * @param resource $connection
     */
    public static function write(
        mixed $connection,
        string $value,
        string $protocol,
        ?StreamWaiter $streamWaiter = null,
        ?CancellationSignal $cancellation = null,
        ?OperationDeadline $deadline = null,
    ): void {
        $remaining = $value;
        while ($remaining !== '') {
            if ($streamWaiter !== null && !$streamWaiter->waitWritable($connection, $deadline)) {
                throw self::readinessFailure($protocol, 'write', $cancellation, $deadline);
            }

            set_error_handler(
                static fn(): bool => true,
                E_NOTICE | E_WARNING,
            );

            try {
                $written = fwrite($connection, $remaining);
            } finally {
                restore_error_handler();
            }

            if ($written === false) {
                throw new MailboxConnectionException(sprintf('Failed writing to %s socket.', strtoupper($protocol)));
            }
            if ($written === 0) {
                if ($streamWaiter !== null) {
                    continue;
                }

                throw new MailboxConnectionException(sprintf('Failed writing to %s socket.', strtoupper($protocol)));
            }

            $remaining = substr($remaining, $written);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function dispatch(?EventDispatcher $events, string $event, array $payload): void
    {
        if ($events === null) {
            return;
        }

        try {
            $events->dispatch($event, $payload);
        } catch (Throwable) {
            // Observability must never affect mailbox protocol outcomes.
        }
    }

    private static function readinessFailure(
        string $protocol,
        string $operation,
        ?CancellationSignal $cancellation,
        ?OperationDeadline $deadline,
    ): MailboxConnectionException {
        if ($cancellation?->isRequested() === true) {
            return new MailboxConnectionException(sprintf('%s operation cancelled.', strtoupper($protocol)));
        }
        if ($deadline?->expired() === true) {
            return new MailboxConnectionException(sprintf(
                '%s command deadline exceeded.',
                strtoupper($protocol),
            ));
        }

        return new MailboxConnectionException(sprintf(
            '%s cooperative %s wait was interrupted.',
            strtoupper($protocol),
            $operation,
        ));
    }
}
