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
     * @param resource $connection
     */
    private static function applyBlockingTimeout(
        mixed $connection,
        OperationDeadline $deadline,
        string $protocol,
    ): void {
        $remainingMicros = $deadline->remainingMicroseconds();
        if ($remainingMicros === 0) {
            throw self::commandDeadlineExceeded($protocol);
        }

        if (!stream_set_timeout(
            $connection,
            intdiv($remainingMicros, 1_000_000),
            $remainingMicros % 1_000_000,
        )) {
            throw new MailboxConnectionException(sprintf(
                'Unable to bound %s socket write by the command deadline.',
                strtoupper($protocol),
            ));
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
        ?OperationDeadline $deadline = null,
    ): mixed {
        if ($deadline?->expired() === true) {
            throw new MailboxConnectionException(sprintf(
                '%s connection deadline exceeded.',
                strtoupper($protocolLabel),
            ));
        }

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
            $deadline === null
                ? (float) $timeoutSeconds
                : max(0.001, min((float) $timeoutSeconds, $deadline->remainingSeconds())),
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

        if ($deadline?->expired() === true) {
            fclose($connection);

            throw new MailboxConnectionException(sprintf(
                '%s connection deadline exceeded.',
                strtoupper($protocolLabel),
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
     * @param resource $connection
     */
    private static function readBlockingLine(mixed $connection, string $protocol, int $maxLength): string
    {
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

    /**
     * @param resource $connection
     */
    private static function readCooperativeLine(
        mixed $connection,
        string $protocol,
        int $maxLength,
        StreamWaiter $streamWaiter,
        ?CancellationSignal $cancellation,
        ?OperationDeadline $deadline,
    ): string {
        $line = '';
        $maxBytes = max(1, $maxLength - 1);

        while (strlen($line) < $maxBytes) {
            if (!$streamWaiter->waitReadable($connection, $deadline)) {
                throw self::readinessFailure($protocol, 'read', $cancellation, $deadline);
            }

            $chunk = fgets($connection, max(2, $maxLength - strlen($line)));
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
            return self::commandDeadlineExceeded($protocol);
        }

        return new MailboxConnectionException(sprintf(
            '%s cooperative %s wait was interrupted.',
            strtoupper($protocol),
            $operation,
        ));
    }

    /**
     * @param resource $connection
     */
    private static function writeFailure(
        mixed $connection,
        string $protocol,
        ?OperationDeadline $deadline,
    ): MailboxConnectionException {
        /** @var array<string, mixed> $metadata */
        $metadata = stream_get_meta_data($connection);
        if ($deadline !== null && ($metadata['timed_out'] ?? false) === true) {
            return self::commandDeadlineExceeded($protocol);
        }

        return new MailboxConnectionException(sprintf(
            'Failed writing to %s socket.',
            strtoupper($protocol),
        ));
    }
}
